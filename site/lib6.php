<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

// Import cyfrowych paragonów (JSON z aplikacji Biedronki) do "Moich produktów".
// Z pliku bierzemy tylko pozycje sprzedaży, datę i numer paragonu; karta, płatność i reszta nie są zapisywane.
// Zakłada załadowane lib.php i lib3.php.

const RECEIPT_FILES_MAX = 30;
const RECEIPT_LINES_MAX = 400;
const RECEIPT_IGNORED_MAX = 1000;

/** Nazwy z paragonu to skróty bez spacji ("JogPSkyrWanil330g"): rozdziela słowa i liczby. */
function receipt_split(string $raw): string {
    $s = str_replace(['.', '_'], ' ', $raw);
    $s = (string)preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $s);
    $s = (string)preg_replace('/(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', ' ', $s);
    $s = (string)preg_replace('/(?<=\p{L}{2})(?=\d)/u', ' ', $s);
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

/** Czytelna nazwa: rozwija tylko skróty, których znaczenie jest jednoznaczne; resztę zostawia. */
function receipt_name(string $raw): string {
    $s = ' ' . receipt_split($raw) . ' ';
    $rules = [
        'Jog P(?= Skyr)' => 'Jogurt pitny', 'Jog' => 'Jogurt', 'Cuk Schok Bon' => 'Cukierki Schoko-Bons',
        'Sok Jab' => 'Sok jabłkowy', 'Sok Pom' => 'Sok pomarańczowy', 'Vita(?:l|tl) Fres' => 'Vital Fresh',
        'Ogórek Szk' => 'Ogórek szklarniowy', 'Szynk Scha' => 'Szynka ze schabu', 'Szynk' => 'Szynka',
        'Ser Gouda' => 'Ser gouda', 'Włoszcz' => 'Włoszczowa', 'Borówka Ameryk' => 'Borówka amerykańska',
        'Serek Wiejski' => 'Serek wiejski', 'Ziemniaki Myte' => 'Ziemniaki myte', 'Filet Piec Kur' => 'Filet pieczony z kurczaka',
        'Wanil' => 'waniliowy', 'Natural' => 'naturalny', 'Wys' => 'wysokobiałkowy', 'Beskid' => 'beskidzka',
        'Polędw' => 'z polędwicy',
    ];
    foreach ($rules as $re => $to) $s = (string)preg_replace('/(?<= )' . $re . '(?= )/u', $to, $s);
    $s = (string)preg_replace('/ (?:Luz|Opa)(?= )/u', '', $s);
    $s = (string)preg_replace('/(\d(?:g|ml|l|kg))Opa(?= )/u', '$1', $s);
    return mb_substr(trim($s), 0, 160);
}

/** Marka, tylko gdy da się ją rozpoznać w nazwie; pusta = produkt bez marki (np. warzywa). */
function receipt_brand(string $name): string {
    $known = ['Vital Fresh', 'Coca-Cola', 'Dove', 'Queen', 'Bounty', 'Bakello', 'Włoszczowa', 'Duda', 'Piątnica', 'Łaciate', 'Mlekovita',
        'Mlekpol', 'Danone', 'Activia', 'Bakoma', 'Zott', 'Hortex', 'Tymbark', 'Pepsi', 'Nivea', 'Colgate', 'Persil', 'Ariel', 'Lenor', 'Domestos',
        'Winiary', 'Knorr', 'Pudliszki', 'Lays', 'Milka', 'Wedel', 'Wawel', 'Haribo', 'Kinder', 'Lindt', 'Snickers', 'Mars', 'Twix',
        'Tarczyński', 'Sokołów', 'Krakus', 'Jacobs', 'Lipton', 'Nescafe', 'Muszynianka', 'Cisowianka', 'Żywiec', 'Tyskie', 'Lech',
        'Pampers', 'Always', 'Gillette', 'Rexona', 'Velvet', 'Regina', 'Foxy', 'Delma', 'Rama'];
    $text = fold_text($name);
    if (str_contains($text, ' schoko bons ')) return 'Kinder';
    foreach ($known as $b) { if (str_contains($text, fold_text($b))) return $b; }
    return '';
}

/** Frazy, po których gazetki mają rozpoznać produkt. Użytkownik może je poprawić w edycji. */
function receipt_keywords(string $name, string $brand): string {
    $text = fold_text($name);
    $rules = [
        '^ papier .*queen| papier toaletowy ' => 'papier toaletowy', ' dove .* zel | zel .* dove | dove zel ' => 'żel pod prysznic',
        '^ banan' => 'banany', '^ ziemniak' => 'ziemniaki', '^ ogorek szklarn' => 'ogórki szklarniowe', ' borowk' => 'borówka',
        '^ serek wiejski ' => 'serek wiejski', ' skyr ' => 'skyr, jogurt skyr', ' sok jablkow' => 'sok jabłkowy',
        ' sok pomaranczow' => 'sok pomarańczowy', ' erytr[yo]l' => 'erytrytol, erytrol', ' gouda ' => 'ser gouda',
        '^ szynka ' => 'szynka', ' filet pieczony ' => 'filet pieczony', ' awokado ' => 'awokado', ' schoko bons ' => 'schoko bons',
        ' lunchbox ' => 'lunchbox, sałatka cezar', '^ baton ' => 'baton',
    ];
    foreach ($rules as $re => $kw) { if (preg_match('/' . $re . '/', $text)) return $kw; }
    // nieznany produkt: dwa pierwsze słowa nazwy spoza marki
    $skip = array_flip(explode(' ', trim(fold_text($brand))));
    $words = [];
    foreach (preg_split('/\s+/u', $name) ?: [] as $w) {
        if (preg_match('/\d/', $w) || mb_strlen($w) < 3 || isset($skip[trim(fold_text($w))])) continue;
        $words[] = mb_strtolower($w);
        if (count($words) === 2) break;
    }
    return implode(' ', $words);
}

/**
 * Pozycje z jednego paragonu. Pomija pozycje stornowane, torby i kaucje.
 * @return ?array{id:string,date:string,items:array<string,array{raw:string,price:int,unit:string}>} null = to nie paragon
 */
function receipt_parse(mixed $r): ?array {
    if (!is_array($r) || !is_array($r['body'] ?? null)) return null;
    $items = []; $date = null; $num = '';
    foreach (array_slice($r['body'], 0, RECEIPT_LINES_MAX) as $row) {
        if (!is_array($row)) continue;
        if (is_array($f = $row['fiscalFooter'] ?? null)) {
            $date = norm_date($f['date'] ?? null);
            $num = (v3_str($f['uniqueNumber'] ?? null, 40) ?? '') . '|' . (v3_str($f['billNumber'] ?? null, 20) ?? '') . '|' . (v3_str($f['date'] ?? null, 40) ?? '');
        }
        $s = $row['sellLine'] ?? null;
        if (!is_array($s)) continue;
        $name = v3_str($s['name'] ?? null, 120);
        if ($name === null) continue;
        $vat = v3_str($s['vatId'] ?? null, 1) ?? '';
        // paragon dopisuje na końcu nazwy literę stawki VAT
        if ($vat !== '') $name = trim((string)preg_replace('/\s+' . preg_quote($vat, '/') . '$/u', '', $name));
        $key = trim(fold_text($name));
        if ($key === '' || preg_match('/^(torba|reklamowka|kaucja|but .*kaucja|opakowanie zwrotne)|kaucja$/', $key)) continue;
        $qty = v3_str($s['quantity'] ?? null, 20) ?? '1'; // sztuki: "2", waga: "0,382"
        $price = is_numeric($s['price'] ?? null) ? max(0, min(100000000, (int)$s['price'])) : 0;
        if (v3_bool($s['isStorno'] ?? null, false)) { // storno cofa wcześniejszą pozycję
            if (isset($items[$key]) && --$items[$key]['n'] <= 0) unset($items[$key]);
            continue;
        }
        $n = ($items[$key]['n'] ?? 0) + 1;
        $items[$key] = ['raw' => $name, 'price' => $price, 'unit' => preg_match('/[.,]/', $qty) ? 'kg' : 'szt', 'n' => $n];
    }
    if (!$items) return null;
    return ['id' => substr(sha1($num . '|' . implode('|', array_keys($items))), 0, 12), 'date' => $date ?? '', 'items' => $items];
}

function receipt_ignored(): array {
    $l = jread('receipt_ignored', []);
    return is_array($l) ? $l : [];
}

/**
 * Dodaje produkty z paragonów. Ten sam produkt nie powstaje dwa razy, a usunięte wcześniej
 * są pomijane, chyba że $restore.
 * @return array{ok?:true,err?:string,code?:int,receipts?:int,added?:int,updated?:int,ignored?:int,over_limit?:int,bad?:int}
 */
function receipt_import(mixed $files, bool $restore = false): array {
    if (!is_array($files) || !$files) return ['err' => 'Nie wybrano plików.', 'code' => 400];
    if (count($files) > RECEIPT_FILES_MAX) return ['err' => 'Maksymalnie ' . RECEIPT_FILES_MAX . ' plików naraz.', 'code' => 400];
    $receipts = []; $bad = 0;
    foreach ($files as $f) {
        $r = receipt_parse($f);
        if ($r === null) { $bad++; continue; }
        $receipts[$r['id']] = $r;
    }
    if (!$receipts) return ['err' => 'To nie wygląda na paragon z Biedronki: brak pozycji sprzedaży.', 'code' => 400];
    uasort($receipts, fn($a, $b) => strcmp($a['date'], $b['date']));

    return locked(function () use ($receipts, $restore, $bad) {
        $list = products();
        $byKey = []; $byName = [];
        foreach ($list as $i => $p) {
            foreach ($p['receipt']['keys'] ?? [] as $k) $byKey[$k] = $i;
            $byName[trim(fold_text((string)$p['name']))] = $i;
        }
        $ignored = array_flip(receipt_ignored());
        $new = []; $upd = []; $skipped = []; $over = [];
        $now = date('c');
        foreach ($receipts as $rid => $r) {
            foreach ($r['items'] as $key => $it) {
                $key = (string)$key;
                if (isset($ignored[$key])) {
                    if (!$restore) { $skipped[$key] = true; continue; }
                    unset($ignored[$key]);
                }
                $name = receipt_name($it['raw']);
                $i = $byKey[$key] ?? $byName[trim(fold_text($name))] ?? null;
                if ($i === null) {
                    if (count($list) >= PRODUCT_LIMIT) { $over[$key] = true; continue; }
                    $brand = receipt_brand($name);
                    $id = v3_new_id('p', array_column($list, 'id'));
                    $list[] = [
                        'id' => $id, 'name' => $name, 'ean' => '',
                        'desc' => 'Kupowane w Biedronce. Nazwa na paragonie (skrót): ' . $it['raw'],
                        'category' => '', 'brand' => $brand, 'keywords' => receipt_keywords($name, $brand), 'sites' => [],
                        'active' => true, 'added' => $now, 'updated' => $now, 'last_searched' => null,
                        'source' => 'receipt', 'receipt' => ['keys' => [], 'raw' => $it['raw'], 'bought' => [], 'last' => '', 'price' => 0, 'unit' => 'szt'],
                    ];
                    $i = count($list) - 1;
                    $byName[trim(fold_text($name))] = $i;
                    $new[$id] = true;
                }
                $p = $list[$i];
                $rc = $p['receipt'] ?? ['keys' => [], 'raw' => $it['raw'], 'bought' => [], 'last' => '', 'price' => 0, 'unit' => 'szt'];
                $before = $rc;
                if (!in_array($key, $rc['keys'], true)) $rc['keys'][] = $key;
                if (!in_array($rid, $rc['bought'], true)) $rc['bought'] = array_slice([...$rc['bought'], (string)$rid], -200);
                if ($r['date'] >= $rc['last']) { $rc['last'] = $r['date']; $rc['price'] = $it['price']; $rc['unit'] = $it['unit']; $rc['raw'] = $it['raw']; }
                $byKey[$key] = $i;
                if ($rc !== $before) {
                    $list[$i]['receipt'] = $rc;
                    if (!isset($new[$p['id']])) $upd[$p['id']] = true;
                }
            }
        }
        if ($new || $upd) jwrite('products', $list);
        if ($restore) jwrite('receipt_ignored', array_keys($ignored));
        return ['ok' => true, 'receipts' => count($receipts), 'added' => count($new), 'updated' => count($upd),
                'ignored' => count($skipped), 'over_limit' => count($over), 'bad' => $bad];
    });
}

/* ---------- dopasowanie produktów z paragonów ---------- */
// Wiele produktów z paragonu nie ma marki (banany, borówki), a gazetka pisze je jako zwykłe pozycje.
// Dla nich fraza ze słów kluczowych jest porównywana z nazwą pozycji, ale ostrzej niż przy promocjach
// na kategorię, żeby "banany" nie łapały "jogurtu bananowego".

/** Odmiana jednego słowa: jak word_like(), ale długość może różnić się najwyżej o 1 znak. */
function word_strict(string $w, string $t): bool {
    return abs(strlen($w) - strlen($t)) <= 1 && word_like($w, $t);
}

/** Jedno słowo frazy: musi być pierwszym słowem tekstu. Kilka słów: po kolei, z przerwą do 2 słów. */
function phrase_strict(string $text, string $phrase): bool {
    $words = array_values(array_filter(explode(' ', trim($text)), fn($x) => $x !== ''));
    $toks = array_values(array_filter(explode(' ', trim($phrase)), fn($x) => $x !== ''));
    if (!$words || !$toks) return false;
    if (count($toks) === 1) return word_strict($words[0], $toks[0]);
    for ($s = 0; $s < count($words); $s++) {
        if (!word_strict($words[$s], $toks[0])) continue;
        $pos = $s; $ok = true;
        for ($j = 1; $j < count($toks) && $ok; $j++) {
            $ok = false;
            for ($k = $pos + 1; $k <= $pos + 3 && $k < count($words); $k++) {
                if (word_strict($words[$k], $toks[$j])) { $pos = $k; $ok = true; break; }
            }
        }
        if ($ok) return true;
    }
    return false;
}
