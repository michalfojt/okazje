<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

// Archiwum pozycji gazetek i ocena atrakcyjności 1-10. Dołączany z lib3.php.
//
// Archiwum: data/arch-RRRR-MM.json (miesiąc początku promocji), nigdy nie kasowane. Trzyma surowe pola pozycji;
// klucz produktu, gramaturę i cenę jednostkową liczymy przy odczycie, więc poprawka parsera działa też wstecz.
//
// Ocena: rabat przy spełnieniu warunku (bez kar za kartę i zakup kilku sztuk) -> punkty 2-10, a gdy są
// wcześniejsze promocje tego produktu, miesza się z oceną ceny na tle mediany z archiwum.

const ARCH_LOOKBACK_DAYS = 365;
const ARCH_CMP_MAX = 8;
const ARCH_SPLIT_MAX = 2000;
const SCORE_UNKNOWN = 3;

/* ---------- liczby i warunki promocji ---------- */

/** Pierwsza liczba z tekstu ceny ("1 299,00 zł" -> 1299.0) albo null. */
function money_num(mixed $v): ?float {
    if (!is_string($v) && !is_numeric($v)) return null;
    $s = (string)preg_replace('/(?<=\d)[ \x{00a0}](?=\d{3}(?!\d))/u', '', (string)$v);
    return preg_match('/(\d+(?:[.,]\d+)?)/', $s, $m) ? (float)str_replace(',', '.', $m[1]) : null;
}

/** Jednostka z ceny: "11,99 zł/kg" -> 'kg', "/100 g" -> '100g', "/szt." i brak -> ''. */
function price_suffix(mixed $v): string {
    if (!is_string($v) || !preg_match('#/\s*(100\s*g|100\s*ml|kg|l)(?![a-z])#iu', $v, $m)) return '';
    return str_replace(' ', '', strtolower($m[1]));
}

function deal_fold(?string $deal): string {
    $t = mb_strtolower(trim((string)$deal), 'UTF-8');
    $t = strtr($t, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    return str_replace(',', '.', $t);
}

/**
 * Rozbiór warunku z tekstu.
 * @return array{type:string,n?:int,x?:float,pct?:float,upto?:bool,basis?:string}
 *   type: multi ("2+1"), nth_zl ("drugi za 5 zł"), nth_pct ("drugi 50% taniej"), pct ("-30%"), none
 */
function deal_parse(?string $deal): array {
    $t = deal_fold($deal);
    if ($t === '') return ['type' => 'none'];
    if (preg_match('/(?<!\d)(\d)\s*\+\s*(\d)(?!\d)/', $t, $m) && (int)$m[2] > 0) {
        return ['type' => 'multi', 'pct' => 100 * (int)$m[2] / ((int)$m[1] + (int)$m[2]), 'basis' => $m[1] . '+' . $m[2]];
    }
    if (preg_match('/(?<!\d)(\d)\s*za\s*(\d)(?!\s*(?:zl|pln|gr|\.|\d))/', $t, $m) && (int)$m[1] > (int)$m[2] && (int)$m[2] > 0) {
        return ['type' => 'multi', 'pct' => 100 * ((int)$m[1] - (int)$m[2]) / (int)$m[1], 'basis' => $m[1] . ' za ' . $m[2]];
    }
    $n = null; $word = '';
    if (preg_match('/\b(drugi|druga|drugie|kolejn\w*|2\.?\s*sztuk\w*|trzeci|trzecia|trzecie|czwart\w*)\b/', $t, $m)) {
        $word = $m[1];
        $n = str_starts_with($word, 'trzec') ? 3 : (str_starts_with($word, 'czwart') ? 4 : 2);
    }
    if ($n !== null) {
        if (preg_match('/\bgratis/', $t)) return ['type' => 'multi', 'pct' => 100 / $n, 'basis' => $word . ' gratis'];
        if (preg_match('/\bza\s*(\d+(?:\.\d+)?)\s*(zl|pln|gr\b|groszy)/', $t, $m)) {
            $x = (float)$m[1];
            if (str_starts_with($m[2], 'gr')) $x /= 100;
            return ['type' => 'nth_zl', 'n' => $n, 'x' => $x, 'basis' => $word . ' za ' . $m[1] . ' ' . ($m[2] === 'pln' || $m[2] === 'zl' ? 'zł' : 'gr')];
        }
        if (preg_match('/\bza\s*grosz\b/', $t)) return ['type' => 'nth_zl', 'n' => $n, 'x' => 0.01, 'basis' => $word . ' za grosz'];
        if (preg_match('/\bza\s*pol\s*ceny/', $t)) return ['type' => 'nth_pct', 'n' => $n, 'x' => 50.0, 'upto' => false, 'basis' => $word . ' za pół ceny'];
    }
    if (preg_match('/(\d{1,2}(?:\.\d+)?)\s*%/', $t, $m)) {
        $x = (float)$m[1];
        if ($x > 0 && $x <= 90) {
            $upto = (bool)preg_match('/\bdo\s*-?\s*\d/', $t);
            if ($n !== null) return ['type' => 'nth_pct', 'n' => $n, 'x' => $x, 'upto' => $upto, 'basis' => $word . ' -' . (int)$x . '%'];
            return ['type' => 'pct', 'pct' => $x, 'upto' => $upto, 'basis' => '-' . (int)$x . '%'];
        }
    }
    return ['type' => 'none'];
}

/**
 * Realny rabat przy spełnieniu warunku, z warunku promocji albo z ceny i ceny poprzedniej.
 * "Drugi za 1 zł" bez ceny bazowej liczone jest jak darmowa sztuka (50%, trzecia 33%).
 * @return ?array{pct:int,upto:bool,rating:string,basis:string}
 */
function deal_score(?string $deal, mixed $price = null, mixed $old = null): ?array {
    $d = deal_parse($deal);
    $p = money_num($price); $o = money_num($old);
    $sameUnit = price_suffix($price) === price_suffix($old);
    $byPrice = $p !== null && $o !== null && $p > 0 && $o > $p && $sameUnit;
    $pct = null; $upto = false; $basis = '';

    if ($d['type'] === 'nth_zl') {
        $n = $d['n']; $x = $d['x'];
        if ($o !== null && $o > 0 && $x < $o) {
            $pct = 100 * ($o - $x) / ($n * $o); $basis = $d['basis'] . ', cena regularna';
        } elseif ($x <= 1.0) {
            $pct = 100 / $n; $basis = $d['basis'];
        }
    } elseif ($d['type'] === 'nth_pct' && $byPrice && abs($p - $o * (1 - $d['x'] / 100)) <= 0.02 * $o) {
        // cena to cena samej przecenionej sztuki, a nie średnia
        $pct = $d['x'] / $d['n']; $upto = $d['upto']; $basis = $d['basis'];
    }
    if ($pct === null && $byPrice && $d['type'] !== 'nth_zl') {
        $pct = 100 * ($o - $p) / $o; $basis = 'cena';
    }
    if ($pct === null) {
        if ($d['type'] === 'multi' || $d['type'] === 'pct') {
            $pct = $d['pct']; $upto = $d['upto'] ?? false; $basis = $d['basis'];
        } elseif ($d['type'] === 'nth_pct') {
            $pct = $d['x'] / $d['n']; $upto = $d['upto']; $basis = $d['basis'];
        }
    }
    if ($pct === null) return null;
    $pct = (int)round($pct);
    if ($pct <= 0) return null;
    $eff = $upto ? $pct * 0.6 : $pct;
    $rating = $eff >= 45 ? 'bardzo dobra' : ($eff >= 30 ? 'dobra' : ($eff >= 15 ? 'średnia' : 'słaba'));
    return ['pct' => $pct, 'upto' => $upto, 'rating' => $rating, 'basis' => $basis];
}

/** Punkty 2-10 za realny rabat. */
function score_from_pct(float $pct): int {
    $e = (int)round($pct);
    foreach ([65 => 10, 55 => 9, 45 => 8, 40 => 7, 33 => 6, 25 => 5, 20 => 4, 10 => 3] as $min => $pts) {
        if ($e >= $min) return $pts;
    }
    return 2;
}

/** Cena płacona za sztukę (albo za kg/l, gdy tak podana) po spełnieniu warunku. Niepewna -> null. */
function effective_price(array $it): ?float {
    $price = $it['price'] ?? null; $old = $it['old_price'] ?? null;
    $d = deal_parse($it['deal'] ?? null);
    $p = money_num($price); $o = money_num($old);
    if (is_string($price) && preg_match('/^\s*od\b/iu', $price)) $p = null;
    if (!empty($d['upto'])) return null;
    if ($d['type'] === 'nth_zl') {
        return ($o !== null && $d['x'] < $o) ? (($d['n'] - 1) * $o + $d['x']) / $d['n'] : null;
    }
    $sameUnit = price_suffix($price) === price_suffix($old);
    if ($p !== null && $o !== null && $o > $p && $sameUnit) {
        if ($d['type'] === 'nth_pct' && abs($p - $o * (1 - $d['x'] / 100)) <= 0.02 * $o) return $o * (1 - $d['x'] / 100 / $d['n']);
        return $p;
    }
    if ($d['type'] === 'multi' || $d['type'] === 'nth_pct') {
        $f = 1 - ($d['type'] === 'multi' ? $d['pct'] : $d['x'] / $d['n']) / 100;
        if ($o !== null) return $o * $f;
        // sama cena przy promocji na kilka sztuk bywa ceną regularną, chyba że opisana jako cena za każdą
        return ($p !== null && is_string($price) && preg_match('/każd/iu', $price)) ? $p : null;
    }
    if ($p !== null) return $p;
    if ($d['type'] === 'pct' && $o !== null) return $o * (1 - $d['pct'] / 100);
    return null;
}

/* ---------- klucz produktu i gramatura ---------- */

const QTY_RE = '/(?<![\p{L}\d])(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|g|ml|l|litr\w*|szt\w*|rolek|rolki|rolka|tabletek|tabletki|kapsu\p{L}+|saszet\p{L}+|toreb\p{L}+|prań)(?![\p{L}])\.?/iu';

/** @return ?array{0:float,1:string} ilość w kg, l albo szt; waga i objętość mają pierwszeństwo przed sztukami */
function parse_qty(string $s): ?array {
    if (!preg_match_all(QTY_RE, $s, $all, PREG_SET_ORDER)) return null;
    $weight = null; $count = null;
    foreach ($all as $m) {
        $mult = $m[1] !== '' ? (int)$m[1] : 1;
        $v = (float)str_replace(',', '.', $m[2]) * $mult;
        if ($v <= 0) continue;
        $u = mb_strtolower($m[3], 'UTF-8');
        if ($u === 'kg') $weight = [$v, 'kg'];
        elseif ($u === 'g') $weight = [$v / 1000, 'kg'];
        elseif ($u === 'ml') $weight = [$v / 1000, 'l'];
        elseif ($u === 'l' || str_starts_with($u, 'litr')) $weight = [$v, 'l'];
        else $count = [$v, 'szt'];
    }
    return $weight ?? $count;
}

/** Posortowane słowa nazwy bez gramatury, nawiasów i słów o opakowaniu. */
function product_tokens(string $name): array {
    static $stop = null;
    $stop ??= array_flip(['rozne', 'rodzaje', 'rozny', 'rodzaj', 'wybrane', 'wszystkie', 'wszystkich', 'opakowanie', 'opakowaniu', 'opak',
        'butelka', 'butelce', 'puszka', 'puszce', 'sztuka', 'sztuki', 'sztuk', 'szt', 'luzem', 'na', 'wage', 'wagi', 'wadze', 'kg', 'g', 'ml', 'l', 'i', 'z', 'ze',
        'w', 'we', 'o', 'do', 'lub', 'oraz', 'a', 'pakowane', 'pakowana', 'pakowany', 'wzory', 'kolory', 'roznej']);
    $s = (string)preg_replace('/\([^)]*\)?/u', ' ', $name);
    $s = (string)preg_replace(QTY_RE, ' ', $s);
    $s = (string)preg_replace('/(?<=\d)[.,](?=\d)/', 'p', $s); // "3,2%" zostaje jednym słowem
    $out = [];
    foreach (explode(' ', trim(fold_text($s))) as $t) {
        if ($t === '' || isset($stop[$t])) continue;
        $out[$t] = true;
    }
    $out = array_map('strval', array_keys($out));
    sort($out, SORT_STRING);
    return $out;
}

/**
 * Cechy pozycji do porównań w czasie.
 * Pozycje na markę i kategorię: u = 'pct', val = głębokość rabatu. Produkty: val = cena za kg, l, szt albo za opakowanie.
 * @return array{tk:string,u:string,q:?float,val:?float}
 */
function item_features(array $it): array {
    if (($it['scope'] ?? 'item') !== 'item') {
        $tk = implode(' ', product_tokens((string)(($it['applies_to'] ?? '') ?: ($it['name'] ?? ''))));
        $d = deal_score($it['deal'] ?? null, $it['price'] ?? null, $it['old_price'] ?? null);
        return ['tk' => $tk, 'u' => 'pct', 'q' => null, 'val' => ($d && !$d['upto']) ? (float)$d['pct'] : null];
    }
    $name = (string)($it['name'] ?? '');
    $tok = product_tokens($name);
    if (!empty($it['brand'])) { $tok = array_values(array_unique(array_merge($tok, product_tokens((string)$it['brand'])))); sort($tok, SORT_STRING); }
    $qty = parse_qty($name) ?? (!empty($it['size']) ? parse_qty((string)$it['size']) : null);
    $eff = effective_price($it);
    $suffix = price_suffix($it['price'] ?? null) ?: price_suffix($it['old_price'] ?? null);
    if ($suffix !== '') {
        $mul = ($suffix === '100g' || $suffix === '100ml') ? 10 : 1;
        $u = ($suffix === 'kg' || $suffix === '100g') ? 'kg' : 'l';
        return ['tk' => implode(' ', $tok), 'u' => $u, 'q' => null, 'val' => $eff === null ? null : $eff * $mul];
    }
    if ($qty !== null) return ['tk' => implode(' ', $tok), 'u' => $qty[1], 'q' => $qty[0], 'val' => $eff === null ? null : $eff / $qty[0]];
    $up = $it['unit_price'] ?? null;
    $us = price_suffix($up);
    if ($us !== '' && money_num($up) !== null) {
        $mul = ($us === '100g' || $us === '100ml') ? 10 : 1;
        return ['tk' => implode(' ', $tok), 'u' => ($us === 'kg' || $us === '100g') ? 'kg' : 'l', 'q' => null, 'val' => money_num($up) * $mul];
    }
    return ['tk' => implode(' ', $tok), 'u' => 'item', 'q' => null, 'val' => $eff];
}

/** Ten sam produkt: identyczne słowa albo co najmniej 80% wspólnych (przy tych samych liczbach). */
function tokens_match(string $a, string $b): bool {
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $x = explode(' ', $a); $y = explode(' ', $b);
    if (min(count($x), count($y)) < 4) return false;
    $common = count(array_intersect($x, $y));
    if ($common / (count($x) + count($y) - $common) < 0.8) return false;
    $num = fn(array $t) => array_values(preg_grep('/\d/', $t));
    return $num($x) === $num($y);
}

/* ---------- archiwum ---------- */

/** @return list<string> miesiące RRRR-MM, dla których jest plik */
function arch_months(): array {
    $out = [];
    foreach (glob(data_dir() . '/arch-*.json') ?: [] as $f) {
        if (preg_match('/^arch-(\d{4}-\d{2})\.json$/', basename($f), $m)) $out[] = $m[1];
    }
    sort($out);
    return $out;
}

function arch_record_id(string $leafletId, string $name): string { return substr(sha1($leafletId . '|' . slug($name)), 0, 16); }

/**
 * Dopisuje pozycje gazetek do archiwum (aktualizuje istniejące). Wywoływać pod locked().
 * @return int liczba nowych rekordów
 */
function arch_put(array $leaflets): int {
    $byMonth = [];
    foreach ($leaflets as $l) {
        $lid = (string)($l['id'] ?? '');
        $seen = substr((string)($l['first_seen'] ?? date('c')), 0, 10);
        $month = substr((string)($l['valid_from'] ?? $seen), 0, 7);
        if ($lid === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) continue;
        foreach ($l['items'] ?? [] as $it) {
            $name = (string)($it['name'] ?? '');
            if ($name === '') continue;
            $from = $it['valid_from'] ?? ($l['valid_from'] ?? $seen);
            $to = $it['valid_to'] ?? ($l['valid_to'] ?? date('Y-m-d', strtotime($from . ' +6 days')));
            $rec = ['shop' => $l['shop'] ?? null, 'lid' => $lid, 'name' => $name, 'from' => $from, 'to' => $to];
            foreach (['scope', 'applies_to', 'deal', 'price', 'old_price', 'brand', 'size', 'unit_price', 'notes'] as $k) {
                if (($it[$k] ?? null) !== null && $it[$k] !== '') $rec[$k] = $it[$k];
            }
            $byMonth[$month][arch_record_id($lid, $name)] = $rec;
        }
    }
    $added = 0;
    foreach ($byMonth as $month => $recs) {
        $cur = jread("arch-$month", []);
        $changed = false;
        foreach ($recs as $id => $rec) {
            if (!isset($cur[$id])) { $added++; $changed = true; $cur[$id] = $rec; }
            elseif ($cur[$id] != $rec) { $changed = true; $cur[$id] = $rec; }
        }
        // uszkodzony plik czyta się jako pusty: nie nadpisujemy go, żeby nie stracić historii
        $file = data_dir() . "/arch-$month.json";
        if ($changed && is_file($file) && filesize($file) > 2 && jread("arch-$month", null) === null) {
            throw new RuntimeException("Uszkodzone archiwum arch-$month.json");
        }
        if ($changed) jwrite("arch-$month", $cur);
    }
    return $added;
}

/** Archiwizuje wszystko, co jest teraz w bazie gazetek (cron i pierwszy przebieg po wdrożeniu). */
function arch_sync(): int {
    return (int)locked(fn() => arch_put(leaflets_db()['leaflets']));
}

/** @return array<string,true> rozłączone pary "to inny produkt" */
function arch_splits(): array {
    $l = jread('arch_split', []);
    return is_array($l) ? array_fill_keys(array_map('strval', $l), true) : [];
}

function arch_split_pair(string $tk, string $otherTk, string $otherId): string {
    if ($tk === $otherTk) return 'r:' . substr(sha1($tk), 0, 16) . ':' . $otherId;
    $a = [substr(sha1($tk), 0, 16), substr(sha1($otherTk), 0, 16)];
    sort($a, SORT_STRING);
    return 'k:' . $a[0] . ':' . $a[1];
}

function arch_split_set(string $pair, bool $split): bool {
    if (!preg_match('/^[rk]:[a-f0-9]{16}:[a-f0-9]{16}$/', $pair)) return false;
    return (bool)locked(function () use ($pair, $split) {
        $s = arch_splits();
        if ($split) $s[$pair] = true; else unset($s[$pair]);
        jwrite('arch_split', array_slice(array_map('strval', array_keys($s)), -ARCH_SPLIT_MAX));
        return true;
    });
}

/**
 * Wcześniejsze promocje dla podanych cech.
 * @param array<int,array> $cur cechy (item_features) uzupełnione o 'from'
 * @return array<int,list<array>> dla każdego indeksu rekordy zakończone przed początkiem bieżącej promocji
 */
function arch_history(array $cur): array {
    $out = array_fill_keys(array_keys($cur), []);
    $wanted = []; $byToken = []; $minFrom = null; $maxFrom = null;
    foreach ($cur as $i => $c) {
        if ($c['tk'] === '' || $c['val'] === null) continue;
        $wanted[$c['u'] . '|' . $c['tk']][] = $i;
        $minFrom = $minFrom === null ? $c['from'] : min($minFrom, $c['from']);
        $maxFrom = $maxFrom === null ? $c['from'] : max($maxFrom, $c['from']);
    }
    if (!$wanted) return $out;
    foreach (array_keys($wanted) as $key) {
        [$u, $tk] = explode('|', $key, 2);
        foreach (explode(' ', $tk) as $t) $byToken[$u . '|' . $t][$key] = true;
    }
    $floor = date('Y-m-d', strtotime($minFrom . ' -' . ARCH_LOOKBACK_DAYS . ' days'));
    $memo = [];
    foreach (arch_months() as $month) {
        if ($month < substr($floor, 0, 7) || $month > substr($maxFrom, 0, 7)) continue;
        foreach (jread("arch-$month", []) as $id => $r) {
            if (($r['to'] ?? '') >= $maxFrom || ($r['from'] ?? '') < $floor) continue;
            $f = item_features($r);
            if ($f['tk'] === '' || $f['val'] === null) continue;
            $mk = $f['u'] . '|' . $f['tk'];
            if (!isset($memo[$mk])) {
                $cands = [];
                foreach (explode(' ', $f['tk']) as $t) $cands += $byToken[$f['u'] . '|' . $t] ?? [];
                $memo[$mk] = array_values(array_filter(array_keys($cands), fn($key) => tokens_match(explode('|', $key, 2)[1], $f['tk'])));
            }
            foreach ($memo[$mk] as $key) {
                foreach ($wanted[$key] as $i) {
                    $from = $cur[$i]['from'];
                    if ($r['to'] >= $from || $r['from'] < date('Y-m-d', strtotime($from . ' -' . ARCH_LOOKBACK_DAYS . ' days'))) continue;
                    $out[$i][] = $f + ['id' => (string)$id, 'shop' => $r['shop'] ?? null, 'name' => $r['name'], 'from' => $r['from'], 'to' => $r['to']];
                }
            }
        }
    }
    return $out;
}

/* ---------- ocena 1-10 ---------- */

function median(array $v): float {
    sort($v, SORT_NUMERIC);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

function fmt_val(float $v, string $u): string {
    if ($u === 'pct') return (int)round($v) . '%';
    return number_format($v, 2, ',', ' ') . ' zł' . ($u === 'item' ? '' : '/' . $u);
}

/**
 * Ocena jednej pozycji.
 * @return array{v:int,unsure:bool,turbo:bool,fake:bool,pct:?int,eff:?float,n:int,why:list<string>,cmp:list<array>}
 */
function score_one(array $it, array $f, array $history, array $splits): array {
    $d = deal_score($it['deal'] ?? null, $it['price'] ?? null, $it['old_price'] ?? null);
    $base = $d ? score_from_pct($d['upto'] ? $d['pct'] * 0.6 : $d['pct']) : null;
    $why = [];
    if ($d) $why[] = 'Rabat ' . ($d['upto'] ? 'do ' : '') . $d['pct'] . '% (' . $d['basis'] . '): ' . $base . ' pkt';

    // jedna obserwacja na sklep i początek promocji; ta sama gramatura ma pierwszeństwo przed inną
    $cmp = []; $same = []; $other = [];
    $isPct = $f['u'] === 'pct';
    usort($history, fn($a, $b) => strcmp($b['from'], $a['from']) ?: ($a['val'] <=> $b['val']));
    foreach ($history as $h) {
        $pair = arch_split_pair($f['tk'], $h['tk'], $h['id']);
        $excluded = isset($splits[$pair]) || isset($splits[arch_split_pair($f['tk'], $f['tk'], $h['id'])]);
        $sameSize = $f['q'] === null || $h['q'] === null ? $f['q'] === $h['q'] : abs($f['q'] - $h['q']) <= 0.02 * $f['q'];
        if (count($cmp) < ARCH_CMP_MAX) {
            $cmp[] = ['pair' => $pair, 'excluded' => $excluded, 'name' => $h['name'], 'shop' => $h['shop'], 'from' => $h['from'],
                      'to' => $h['to'], 'value' => fmt_val($h['val'], $h['u']), 'same_size' => $sameSize];
        }
        if ($excluded) continue;
        $g = $h['shop'] . '|' . $h['from'];
        if ($sameSize) $bucket = &$same; else $bucket = &$other;
        if (!isset($bucket[$g]) || ($isPct ? $h['val'] > $bucket[$g]['val'] : $h['val'] < $bucket[$g]['val'])) $bucket[$g] = $h;
        unset($bucket);
    }
    $obs = array_values($same ?: $other);
    $exact = (bool)$same;
    $n = $f['val'] === null ? 0 : count($obs);

    $turbo = false; $fake = false; $hist = null; $w = 0.0;
    if ($n > 0) {
        $vals = array_column($obs, 'val');
        $med = median($vals);
        $val = $f['val'];
        if ($isPct) {
            $diff = $val - $med;
            $hist = $diff >= 15 ? 10 : ($diff >= 10 ? 9 : ($diff >= 7 ? 8 : ($diff > 3 ? 7 : ($diff >= -3 ? 5 : ($diff >= -10 ? 3 : 2)))));
            $best = max($vals);
            if ($val > $best + 0.5) $hist = min(10, $hist + 1);
        } else {
            $r = $med > 0 ? $val / $med : 1.0;
            $hist = $r <= 0.80 ? 10 : ($r <= 0.85 ? 9 : ($r <= 0.90 ? 8 : ($r < 0.97 ? 7 : ($r <= 1.03 ? 5 : ($r <= 1.10 ? 3 : 2)))));
            $best = min($vals);
            if ($val < $best * 0.995) $hist = min(10, $hist + 1);
        }
        $w = [1 => 0.3, 2 => 0.5][$n] ?? 0.7;
        if (!$exact) $w /= 2;
        $span = (strtotime($f['from']) - strtotime(min(array_column($obs, 'from')))) / 86400;
        if ($exact && $n >= 3 && $span >= 27.5) {
            $turbo = $isPct ? ($val >= $best && $val >= $med + 10) : ($val <= $best * 1.0001 && $val <= $med * 0.85);
        }
        $fake = !$isPct && $exact && $n >= 2 && $d !== null && $d['pct'] >= 25 && $val >= $med * 0.9999;
        $why[] = ($isPct ? 'Teraz ' : 'Cena ') . fmt_val($val, $f['u']) . ', mediana z ' . $n . ' wcześniejszych: ' . fmt_val($med, $f['u'])
            . ', ' . ($isPct ? 'najwyżej ' : 'najniżej ') . fmt_val($best, $f['u']) . ($exact ? '' : ' (inna gramatura)') . ': ' . $hist . ' pkt, waga ' . (int)round($w * 100) . '%';
    }

    $unsure = false;
    if ($base !== null && $hist !== null) $v = (int)round((1 - $w) * $base + $w * $hist);
    elseif ($base !== null) $v = $base;
    elseif ($hist !== null) { $v = $hist; $unsure = $n < 2 || !$exact; $why[] = 'Brak rabatu w gazetce, ocena tylko z historii'; }
    else { $v = SCORE_UNKNOWN; $unsure = true; $why[] = 'Brak rabatu i historii, nie ma podstaw do oceny'; }
    if ($n === 0 && $base !== null) $why[] = 'Brak wcześniejszych promocji w archiwum';
    if ($fake) { $v = min($v, 5); $why[] = 'Pozorna okazja: duży rabat, ale cena nie jest niższa niż zwykle w promocjach'; }
    if ($turbo) $why[] = 'Turbo okazja: najlepsza cena w archiwum i co najmniej ' . ($isPct ? '10 pkt proc. lepsza' : '15% niższa') . ' od mediany';

    return ['v' => max(1, min(10, $v)), 'unsure' => $unsure, 'turbo' => $turbo, 'fake' => $fake,
            'pct' => $d['pct'] ?? null, 'eff' => effective_price($it), 'n' => $n, 'why' => $why, 'cmp' => $cmp];
}

/**
 * Oceny dla listy pozycji (pola jak w gazetce plus valid_from). Klucze wyniku jak na wejściu.
 * @return array<int|string,array>
 */
function score_items(array $items): array {
    $feat = [];
    foreach ($items as $k => $it) $feat[$k] = item_features($it) + ['from' => (string)(($it['valid_from'] ?? null) ?: date('Y-m-d'))];
    $hist = arch_history($feat);
    $splits = arch_splits();
    $out = [];
    foreach ($items as $k => $it) $out[$k] = score_one($it, $feat[$k], $hist[$k], $splits);
    return $out;
}
