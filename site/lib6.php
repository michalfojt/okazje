<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

// Import cyfrowych paragonów do "Moich produktów": JSON z aplikacji Biedronki oraz tekst odczytany
// w przeglądarce ze zdjęcia paragonu Lidla. Bierzemy tylko pozycje sprzedaży, datę i numer paragonu;
// karta, płatność i reszta nie są zapisywane.
// Zakłada załadowane lib.php i lib3.php.

const RECEIPT_FILES_MAX = 30;
const RECEIPT_LINES_MAX = 400;
const RECEIPT_IGNORED_MAX = 1000;
const RECEIPT_TEXT_MAX = 30000;

/**
 * Zakupy jednorazowe (narzędzia, elektronarzędzia, akumulatory, sprzęt ogrodowy) i torby:
 * nie są dodawane do produktów. Wzorzec dla nazwy po fold_text(), dopasowanie od początku słowa.
 */
const RECEIPT_ONEOFF = '/ (?:narzedzi|elektronarz|akumulator|ladowark|prostownik|wiert|wkretark|wkretak|srubokr|srubokret|szlifierk|wyrzynark|pilark|pilarsk'
    . '|pila |mlot |mlotek|mlotow|klucz|kombinerk|szczypce|obcegi|imadlo|suwmiar|poziomic|miarka |miernik|lutownic|opalark|sprezark|kompresor|odkurzacz warszt'
    . '|nozyc|sekator|grabi|lopat|szpadel|motyk|siekier|kosiar|podkaszar|wykaszark|dmuchaw|konewk|zraszacz|waz ogrod|opryskiw|taczk|doniczk|nasion|cebulk|sadzonk'
    . '|rekawice rob|latark|przedluzacz|drabin)/';

function receipt_oneoff(string $name): bool {
    return (bool)preg_match(RECEIPT_ONEOFF, fold_text($name));
}

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
        'Pampers', 'Always', 'Gillette', 'Rexona', 'Velvet', 'Regina', 'Foxy', 'Delma', 'Rama',
        'Pilos', 'Koło', 'Jogobella', 'Łaciaty', 'YoPro', 'Monte', 'Fantasia', 'Pawełek', 'Kit Kat', 'Roshen', 'Toffifee', 'Delicje',
        'Juicy Splash', 'Mentos', 'Inka', 'Dilmah', 'Lech', 'Piratki', 'Zott'];
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
        ' sok tloczony jablk' => 'sok tłoczony, sok jabłkowy', ' sok klementynk' => 'sok klementynkowy, sok z klementynek', '^ mleko ' => 'mleko',
        ' losos ' => 'łosoś', ' maliny ' => 'maliny', ' papryka czerwona ' => 'papryka czerwona', ' kalafior ' => 'kalafior', ' brokul' => 'brokuły',
        ' ogorki ' => 'ogórki', ' kiwi ' => 'kiwi', ' szpinak ' => 'szpinak', ' marchew| minimarchew' => 'marchewki',
        ' papier toaletowy ' => 'papier toaletowy', ' recznik papierowy ' => 'ręcznik papierowy', ' chusteczki ' => 'chusteczki',
        ' pielusz| pieluch' => 'pieluchy, pieluszki', ' jogurt pitny ' => 'jogurt pitny', ' jogurt naturalny ' => 'jogurt naturalny',
        ' filet z indyka ' => 'filet z indyka', ' filet z kurczaka | kurczak\S* .*filet ' => 'filet z kurczaka', ' oliwa ' => 'oliwa',
        ' musztarda ' => 'musztarda', ' herbata ' => 'herbata', ' kawa zbozowa ' => 'kawa zbożowa', ' woda niegazowana ' => 'woda niegazowana',
        '^ lody ' => 'lody', ' piwo ' => 'piwo', '^ czekolada ' => 'czekolada', ' delicje ' => 'delicje', ' tulipany ' => 'tulipany',
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
    $items = []; $date = null; $num = ''; $oneoff = [];
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
        if (receipt_oneoff(receipt_name($name))) { $oneoff[$key] = receipt_name($name); continue; }
        $qty = v3_str($s['quantity'] ?? null, 20) ?? '1'; // sztuki: "2", waga: "0,382"
        $price = is_numeric($s['price'] ?? null) ? max(0, min(100000000, (int)$s['price'])) : 0;
        if (v3_bool($s['isStorno'] ?? null, false)) { // storno cofa wcześniejszą pozycję
            if (isset($items[$key]) && --$items[$key]['n'] <= 0) unset($items[$key]);
            continue;
        }
        $n = ($items[$key]['n'] ?? 0) + 1;
        $items[$key] = ['raw' => $name, 'price' => $price, 'unit' => preg_match('/[.,]/', $qty) ? 'kg' : 'szt', 'n' => $n];
    }
    if (!$items && !$oneoff) return null;
    return ['id' => substr(sha1($num . '|' . implode('|', array_keys($items))), 0, 12), 'date' => $date ?? '', 'shop' => 'biedronka',
            'items' => $items, 'oneoff' => $oneoff];
}

/* ---------- Lidl: tekst odczytany ze zdjęcia ---------- */

/** Liczba z paragonu ("69.9", "1,248") jako float. */
function receipt_num(string $s): float { return (float)str_replace(',', '.', $s); }

/** Wiersz z ilością i ceną ("2 * 1.19 2.38 C", "1,248kg x 69.9 87.24 C"): prawie same cyfry. */
function lidl_is_qty(string $l): bool {
    $rest = (string)preg_replace('/\s+[A-G€©]$|kg/iu', '', $l);
    return preg_match_all('/\d/', $rest) >= 3 && preg_match_all('/\p{L}/u', $rest) <= 1 && preg_match('/^[\d\s]/', $l);
}

/** Wiersz rabatu ("Lidl Plus voucher -4,90", "Taniej za 2 -0,24"), także z literówkami z odczytu. */
function lidl_is_discount(string $l): bool {
    return (bool)preg_match('/-\s?\d+[.,]\d\d$|^\W*(?:\S{0,5}\s?plus\s+(?:kupon|voucher)|t[ao]niej|rabat)\b/iu', $l);
}

/** Czytelna nazwa z paragonu Lidla; rozwija tylko jednoznaczne skróty. */
function lidl_name(string $raw): string {
    $s = (string)preg_replace('/(?<=\p{L}{3})(?=\d)/u', ' ', $raw);
    $s = (string)preg_replace('/(?<=\.)(?=\p{L})/u', ' ', $s);
    $s = ' ' . trim((string)preg_replace('/\s+/u', ' ', $s)) . ' ';
    $rules = [
        'amery?k?\.' => 'amerykańska', 'wędz\.' => 'wędzony', 'tłocz\.' => 'tłoczony', 'ciast\.' => 'ciasteczka',
        'natur\.' => 'naturalny', 'niegaz\.' => 'niegazowana', 'toal\.' => 'toaletowy', 'papier\.' => 'papierowy', 'kurcz\.' => 'kurczaka',
        'ziel\. dł\.' => 'zielone długie', 'Banany' => 'Banan', 'JuicySplash' => 'Juicy Splash', 'LechFree' => 'Lech Free', 'PilosPure' => 'Pilos Pure',
    ];
    foreach ($rules as $re => $to) $s = (string)preg_replace('/(?<= )' . $re . '(?=[ \d])/u', $to . ' ', $s);
    $s = (string)preg_replace('/ (?:luz|szt\.?)(?= )/u', '', $s);
    // typowe błędy odczytu: litera "l" jako "1" ("60l" → "601") i nieczytelna gramatura ("500g" → "5089")
    $s = (string)preg_replace(['/ ([1-9]0{1,2}|[1-9],\d)1(?= |$)/u', '/(?<=\p{L}) \d{4,5}(?= *$)/u'], [' $1l', ''], rtrim($s));
    return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $s)), 0, 160);
}

/** Dla słabej jakości zdjęć: ten sam produkt, gdy różnią się tylko liczby (zwykle źle odczytane). */
function receipt_skeleton_key(string $key, array $byKey): ?int {
    $bare = fn(string $k) => trim((string)preg_replace(['/\d+\S*/', '/\s+/'], ['', ' '], $k));
    $want = $bare($key); $best = null; $dist = PHP_INT_MAX;
    if (strlen($want) < 9) return null;
    foreach ($byKey as $k => $i) {
        $k = (string)$k;
        if ($bare($k) === $want && ($d = levenshtein($k, $key)) < $dist) { $best = $i; $dist = $d; }
    }
    return $best;
}

/** @return ?array to samo co receipt_parse(); null = to nie paragon Lidla albo nic nie odczytano */
function receipt_parse_lidl(string $text): ?array {
    $lines = array_values(array_filter(array_map(fn($l) => trim((string)preg_replace('/\s+/u', ' ', $l)),
        preg_split('/\R/u', mb_substr($text, 0, RECEIPT_TEXT_MAX)) ?: []), fn($l) => $l !== ''));
    if (!preg_match('/l[il1s]d[lł1i]/iu', implode(' ', array_slice($lines, 0, 15)))) return null;
    $start = null; $date = '';
    foreach ($lines as $i => $l) {
        if (preg_match('/^(20\d\d-\d\d-\d\d)\b/', $l, $m) && ($d = norm_date($m[1])) !== null) { $start = $i + 1; $date = $d; break; }
    }
    if ($start === null) return null;
    $items = []; $oneoff = []; $end = $start;
    for ($i = $start; $i < count($lines); $i++) {
        $l = $lines[$i]; $end = $i;
        if (preg_match('/^(?:p\s?t\s?u|suma|razem)\b/iu', $l)) break;
        if (lidl_is_qty($l) || lidl_is_discount($l) || !isset($lines[$i + 1]) || !lidl_is_qty($lines[$i + 1])) continue;
        if (preg_match_all('/\p{L}/u', $l) < 3) continue;
        $raw = mb_substr($l, 0, 120);
        $q = $lines[$i + 1];
        $price = 0; $unit = 'szt';
        if (preg_match('/^(\d+[.,]\d{3})\s?k\S?\s*\S\s*(\d+[.,]\d+)/u', $q, $m)) {
            $unit = 'kg'; $price = (int)round(receipt_num($m[2]) * 100);
        } elseif (preg_match('/^(\d+)\s*(?:[^\d\s]\s?){1,3}\s*(\d+[.,]\d+)\s+(\d+[.,]\d+)/u', $q, $m)) {
            $n = (int)$m[1]; $price = (int)round(receipt_num($m[2]) * 100); $total = (int)round(receipt_num($m[3]) * 100);
            // odczyt myli czasem cyfry: gdy cena nie zgadza się z sumą, bierzemy sumę podzieloną przez ilość
            if ($n > 0 && $n * $price !== $total && $total % $n === 0) $price = intdiv($total, $n);
        }
        $key = trim(fold_text($raw));
        if ($key === '' || preg_match('/^(torba|reklam|rekla\S*wka|kaucja|zwrot kaucji|opakowani)/', $key)) continue;
        // artykuły przemysłowe Lidla mają w nazwie długi numer artykułu (np. "Wiertła0540674")
        $article = '/\s*\d[\dSOBQ]{5,}\s*$/'; // odczyt myli czasem cyfry numeru z literami
        if (preg_match($article, $raw) || receipt_oneoff($raw)) {
            $oneoff[$key] = trim((string)preg_replace($article, '', $raw)) ?: $raw;
            continue;
        }
        $items[$key] = ['raw' => $raw, 'price' => max(0, min(100000000, $price)), 'unit' => $unit];
    }
    if (!$items && !$oneoff) return null;
    // numer paragonu i godzina odróżniają dwa zakupy z tego samego dnia
    $num = preg_match('/nr\s?:?\s*(\d{4,})\s+(\d\d:\d\d)/iu', implode("\n", array_slice($lines, $end)), $m) ? $m[1] . '|' . $m[2] : implode('|', array_keys($items));
    return ['id' => substr(sha1('lidl|' . $date . '|' . $num), 0, 12), 'date' => $date, 'shop' => 'lidl', 'items' => $items, 'oneoff' => $oneoff];
}

/** Ten sam produkt Lidla odczytany z jedną literówką (przy tych samych liczbach w nazwie). */
function receipt_near_key(string $key, array $byKey): ?int {
    if (strlen($key) < 9) return null;
    $digits = preg_replace('/\D+/', '', $key);
    foreach ($byKey as $k => $i) {
        $k = (string)$k;
        if (abs(strlen($k) - strlen($key)) <= 1 && preg_replace('/\D+/', '', $k) === $digits && levenshtein($k, $key) <= 1) return $i;
    }
    return null;
}

function receipt_ignored(): array {
    $l = jread('receipt_ignored', []);
    return is_array($l) ? $l : [];
}

/**
 * Dodaje produkty z paragonów. Ten sam produkt nie powstaje dwa razy, a usunięte wcześniej
 * są pomijane, chyba że $restore.
 * @return array{ok?:true,err?:string,code?:int,receipts?:int,added?:int,updated?:int,ignored?:int,over_limit?:int,bad?:int,oneoff?:list<string>}
 */
function receipt_import(mixed $files, bool $restore = false): array {
    if (!is_array($files) || !$files) return ['err' => 'Nie wybrano plików.', 'code' => 400];
    if (count($files) > RECEIPT_FILES_MAX) return ['err' => 'Maksymalnie ' . RECEIPT_FILES_MAX . ' plików naraz.', 'code' => 400];
    $receipts = []; $bad = 0;
    $oneoff = [];
    foreach ($files as $f) {
        $r = is_array($f) && is_string($f['text'] ?? null) ? receipt_parse_lidl($f['text']) : receipt_parse($f);
        if ($r === null) { $bad++; continue; }
        $r['low'] = is_array($f) && !empty($f['low']);
        $receipts[$r['id']] = $r;
        foreach ($r['oneoff'] as $name) $oneoff[$name] = true;
    }
    if (!$receipts) return ['err' => 'Nie rozpoznano paragonu (obsługiwane: JSON z Biedronki i zdjęcie paragonu z aplikacji Lidla).', 'code' => 400];
    uasort($receipts, fn($a, $b) => strcmp($a['date'], $b['date']));

    return locked(function () use ($receipts, $restore, $bad, $oneoff) {
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
                $lidl = $r['shop'] === 'lidl';
                $name = $lidl ? lidl_name($it['raw']) : receipt_name($it['raw']);
                $i = $byKey[$key] ?? $byName[trim(fold_text($name))] ?? ($lidl ? receipt_near_key($key, $byKey) : null)
                    ?? ($lidl && $r['low'] ? receipt_skeleton_key($key, $byKey) : null);
                if ($i === null) {
                    if (count($list) >= PRODUCT_LIMIT) { $over[$key] = true; continue; }
                    $brand = receipt_brand($name);
                    $id = v3_new_id('p', array_column($list, 'id'));
                    $list[] = [
                        'id' => $id, 'name' => $name, 'ean' => '',
                        'desc' => 'Kupowane w ' . ($lidl ? 'Lidlu' : 'Biedronce') . '. Nazwa na paragonie (skrót): ' . $it['raw'],
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
                'ignored' => count($skipped), 'over_limit' => count($over), 'bad' => $bad,
                'oneoff' => array_slice(array_map('strval', array_keys($oneoff)), 0, 40)];
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
