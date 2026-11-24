<?php
declare(strict_types=1);
// Test CLI v3 (uruchamiany przez run.sh): php v3_test.php <site> <data> <tmp>
if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');

[$site, $data, $tmp] = [rtrim($argv[1] ?? '', '/'), rtrim($argv[2] ?? '', '/'), rtrim($argv[3] ?? '', '/')];
if ($site === '' || $data === '' || $tmp === '') { fwrite(STDERR, "Użycie: v3_test.php <site> <data> <tmp>\n"); exit(2); }

$errors = [];
$checks = 0;
/** Wartość klucza albo '__brak__' (odróżnia null od braku klucza). */
function g(mixed $a, string|int $k): mixed { return is_array($a) && array_key_exists($k, $a) ? $a[$k] : '__brak__'; }
function check(bool $ok, string $msg): void {
    global $errors, $checks;
    $checks++;
    if (!$ok) $errors[] = $msg;
}
function eq(mixed $exp, mixed $act, string $msg): void {
    $same = (is_float($exp) || is_float($act)) && is_numeric($exp) && is_numeric($act) ? abs((float)$exp - (float)$act) < 1e-9 : $exp === $act;
    check($same, $msg . ' (oczekiwano ' . json_encode($exp, JSON_UNESCAPED_UNICODE) . ', jest ' . json_encode($act, JSON_UNESCAPED_UNICODE) . ')');
}
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    global $errors;
    if (!(error_reporting() & $no)) return false;
    $errors[] = "ostrzeżenie PHP [$no] $str ($file:$line)";
    return true;
});

define('OKZ', 1);
chdir($site);
require $site . '/lib.php';
require $site . '/lib3.php';

/** @return array{0:string,1:int} */
function sh(array $cmd): array {
    $out = [];
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
}
function ingest(string $site, string $arg = '--local'): array {
    return sh(['php', '-d', 'error_reporting=-1', '-d', 'display_errors=1', $site . '/ingest.php', $arg]);
}
function no_php_errors(string $out, string $what): void {
    check(!preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)/', $out), "$what: ostrzeżenia lub błędy PHP w wyjściu: " . mb_substr($out, 0, 400));
}
function dsha(string $data, array $names): string {
    $h = '';
    foreach ($names as $n) { $f = "$data/$n.json"; $h .= is_file($f) ? md5((string)file_get_contents($f)) : '-'; }
    return $h;
}
function dayoff(int $n, string $fmt = 'Y-m-d'): string { return date($fmt, strtotime(($n >= 0 ? '+' : '') . $n . ' days')); }

/* ================= 1. funkcje danych (lib3) ================= */

eq('2026-11-24', norm_date('24.11.2026'), 'norm_date DD.MM.RRRR');
eq('2026-11-24', norm_date('2026-11-24'), 'norm_date RRRR-MM-DD');
eq('2026-01-05', norm_date('2026-1-5'), 'norm_date bez zer');
eq('2026-11-24', norm_date('2026-11-24T10:00:00+01:00'), 'norm_date z godziną');
eq(null, norm_date('31.02.2026'), 'norm_date nieistniejący dzień');
eq(null, norm_date('jutro'), 'norm_date tekst');
eq(null, norm_date(null), 'norm_date null');
eq(null, norm_date(['x']), 'norm_date tablica');

eq(null, v3_str(['a'], 10), 'v3_str tablica');
eq('ab c', v3_str("  ab\x00\n c  ", 10), 'v3_str znaki sterujące');
eq(5, mb_strlen((string)v3_str('żółćżółć', 5)), 'v3_str przycina znaki wielobajtowe');
eq('12', v3_str(12, 10), 'v3_str liczba');

[$s, $err] = parse_sites("botland.com.pl\nhttps://www.allegro.pl\nhttps://botland.com.pl/\n\n");
eq(['https://botland.com.pl', 'https://www.allegro.pl'], $s, 'parse_sites dopisuje https i usuwa duplikaty');
[, $err] = parse_sites(['https://ok.pl', 'nie adres']);
check($err !== null, 'parse_sites odrzuca błędny adres');
[, $err] = parse_sites(array_map(fn($i) => "https://s$i.example.com", range(1, 16)));
check($err !== null, 'parse_sites odrzuca więcej niż 15 adresów');

foreach (['12345678', '123456789012', '1234567890123', '12345678901234', '692 007-5740219'] as $e) {
    [$n, $err] = norm_product(['name' => 'x', 'ean' => $e], null, []);
    check($n !== null, "EAN '$e' powinien być poprawny");
}
foreach (['1234567', '1234567890', 'abcdefgh', '123456789012345'] as $e) {
    [$n, $err] = norm_product(['name' => 'x', 'ean' => $e], null, []);
    check($n === null, "EAN '$e' powinien być odrzucony");
}
[$n] = norm_product(['name' => str_repeat('ą', 200)], null, []);
eq(160, mb_strlen($n['name']), 'nazwa produktu przycięta do 160');

// gazetka: limit 400 pozycji, nieparsowalne daty
$items = array_map(fn($i) => ['name' => "Produkt $i", 'valid_from' => 'zły', 'valid_to' => '01.12.2026'], range(1, 450));
$nl = norm_leaflet(['url' => 'https://example.com/g', 'title' => 'T', 'valid_to' => 'xx', 'items' => $items], []);
eq(400, count($nl['items']), 'limit 400 pozycji w gazetce');
eq(null, $nl['items'][0]['valid_from'], 'pozycja: nieparsowalna data -> null');
eq('2026-12-01', $nl['items'][0]['valid_to'], 'pozycja: data DD.MM.RRRR');
eq(null, norm_leaflet(['url' => 'javascript:alert(1)', 'title' => 'x'], []), 'gazetka z błędnym adresem odrzucona');

// partie bez nowych pól i zbyt stare
eq(['status' => 'none'], ingest_extras(['offers' => []], 'a.json'), 'ingest_extras: partia bez nowych pól -> none');
eq('too_old', ingest_extras(['run' => date('c', time() - 40 * 86400), 'leaflets' => []], 'b.json')['status'], 'ingest_extras: za stara partia');
eq([], extras_files(), 'none i too_old nie trafiają na listę plików');

// sklepy
$shops = shops();
eq(['Biedronka', 'Lidl'], array_column($shops, 'name'), 'domyślne sklepy');
check(is_file("$data/shops.json"), 'domyślne sklepy zapisane do shops.json');
eq('https://www.biedronka.pl/pl/gazetki', $shops[0]['url'], 'adres Biedronki');
$bied = $shops[0];
$lidl = $shops[1];
$r = shop_save(['name' => 'Kaufland', 'url' => 'kaufland.pl/gazetka', 'note' => 'test', 'active' => true]);
check(isset($r['ok']) && preg_match('/^s[a-f0-9]{8}$/', $r['shop']['id']) === 1, 'shop_save: nowy sklep');
eq('https://kaufland.pl/gazetka', $r['shop']['url'] ?? null, 'shop_save: dopisany https');
$kaufId = $r['shop']['id'] ?? '';
eq(400, shop_save(['name' => 'X', 'url' => 'nie url'])['code'] ?? 0, 'shop_save: błędny adres');
eq(400, shop_save(['name' => '', 'url' => 'https://a.pl'])['code'] ?? 0, 'shop_save: brak nazwy');
eq(404, shop_save(['id' => 'sdeadbeef', 'name' => 'X', 'url' => 'https://a.pl'])['code'] ?? 0, 'shop_save: nieznane id');
eq(3, count(shops()), 'trzy sklepy');

// produkty
eq(400, product_save(['name' => ''])['code'] ?? 0, 'product_save: brak nazwy');
eq(400, product_save(['name' => 'X', 'ean' => '123'])['code'] ?? 0, 'product_save: zły EAN');
eq(400, product_save(['name' => 'X', 'sites' => 'to nie adres'])['code'] ?? 0, 'product_save: zły adres strony');
$r = product_save([
    'name' => 'Sonoff ZBDongle-E', 'ean' => '6920075740219', 'desc' => "koordynator Zigbee 3.0 USB\nwersja E", 'category' => 'zigbee',
    'sites' => "botland.com.pl\nhttps://www.allegro.pl\nhttps://botland.com.pl/", 'active' => true,
]);
check(isset($r['ok']) && preg_match('/^p[a-f0-9]{8}$/', $r['product']['id']) === 1, 'product_save: nowy produkt A');
$pa = $r['product'];
eq(['https://botland.com.pl', 'https://www.allegro.pl'], $pa['sites'], 'produkt A: sites bez duplikatów');
eq('zigbee', $pa['category'], 'produkt A: kategoria');
eq(null, $pa['last_searched'], 'produkt A: last_searched null');
$pb = product_save(['name' => 'Masło Extra 200 g', 'category' => 'nie-ma-takiej', 'sites' => ['https://lidl.pl']])['product'];
eq('', $pb['category'], 'produkt B: nieznana kategoria -> pusta');
$A = $pa['id'];
$B = $pb['id'];
$up = product_save(['id' => $A, 'name' => 'Sonoff ZBDongle-E v2', 'ean' => '6920075740219', 'category' => 'zigbee', 'sites' => $pa['sites'], 'active' => true]);
eq($A, $up['product']['id'] ?? null, 'product_save: aktualizacja zachowuje id');
eq($pa['added'], $up['product']['added'] ?? null, 'product_save: aktualizacja zachowuje added');
product_save(['id' => $A, 'name' => 'Sonoff ZBDongle-E', 'ean' => '6920075740219', 'category' => 'zigbee', 'sites' => $pa['sites'], 'active' => true]);
eq(404, product_save(['id' => 'pdeadbeef', 'name' => 'X'])['code'] ?? 0, 'product_save: nieznane id');
eq(2, count(products()), 'dwa produkty');

/* ================= 2. import partii ================= */

$now = time();
$runIso = date('c', $now);
$lidlUrl = 'https://www.lidl.pl/c/nasze-gazetki/gazetka-' . date('md');
$biedUrl = 'https://www.biedronka.pl/pl/gazetka,id,test';
$p1 = 'https://botland.com.pl/p1';
$p2 = 'https://allegro.pl/oferta/p2';
$biedFrom = dayoff(0);
$lidlFrom = dayoff(1);

$mkBatch = function (string $run, bool $second) use ($A, $B, $bied, $p1, $p2, $lidlUrl, $biedUrl, $biedFrom, $lidlFrom): array {
    return [
        'run' => $run,
        'offers' => [['title' => 'Zwykła oferta', 'url' => 'http://127.0.0.1/o1', 'price' => '10 zł', 'category' => 'zigbee', 'shop' => 'Test']],
        'searched_products' => [$A, 'pnonexist', '../etc', 42],
        'product_offers' => [
            ['product_id' => $A, 'title' => 'Sonoff ZBDongle-E', 'url' => $p1 . '?utm_source=x', 'price' => '89,90 zł', 'original_price' => '99 zł',
             'shop' => 'Botland', 'image_url' => 'http://127.0.0.1:9/p1.jpg', 'confidence' => 'high', 'notes' => 'ok'],
            ['product_id' => $A, 'title' => 'duplikat', 'url' => $p1, 'price' => '1 zł'],
            ['product_id' => $A, 'title' => str_repeat('x', 400), 'url' => $p2, 'price' => '79,00 zł', 'shop' => 'Allegro', 'notes' => str_repeat('n', 2000), 'confidence' => 'zły'],
            ['product_id' => $B, 'title' => 'Masło w Botlandzie', 'url' => $p1, 'price' => '5 zł', 'shop' => 'Botland'],
            ['product_id' => 'pdeadbeef', 'title' => 'Nieistniejący produkt', 'url' => 'https://x.pl/1', 'price' => '1 zł'],
            ['product_id' => $A, 'title' => 'Zły adres', 'url' => 'ftp://x.pl/1', 'price' => '1 zł'],
            'śmieć',
        ],
        'leaflets' => [
            [
                'shop_id' => $bied['id'], 'shop' => 'Biedronka', 'title' => 'Gazetka Biedronki', 'url' => $biedUrl,
                'valid_from' => date('d.m.Y', strtotime($biedFrom)), 'valid_to' => dayoff(5, 'd.m.Y'), 'cover_url' => 'http://127.0.0.1:9/c.jpg',
                'items' => [
                    ['name' => 'Masło Extra 200 g', 'price' => $second ? '4,99 zł' : '5,99 zł', 'old_price' => '8,49 zł', 'valid_from' => $biedFrom, 'valid_to' => dayoff(2), 'page' => 3, 'notes' => 'przy zakupie 2 szt.'],
                    ['name' => '', 'price' => '1 zł'],
                    ['name' => 'Mleko 3,2% 1 l', 'price' => str_repeat('9', 100), 'page' => 'abc'],
                    ['name' => 'Chleb', 'page' => '7'],
                ],
            ],
            [
                'shop' => 'lidl', 'title' => 'Gazetka Lidl', 'url' => $lidlUrl, 'valid_from' => $lidlFrom, 'valid_to' => 'jutro',
                'items' => [['name' => 'Sonoff ZBDongle-E', 'price' => '79 zł'], ['name' => 'Masło Extra 200 g', 'price' => '5,49 zł']],
            ],
            ['title' => 'Zły adres', 'url' => 'javascript:alert(1)', 'items' => [['name' => 'x']]],
        ],
        'leaflet_hits' => [
            ['product_id' => $A, 'shop' => 'Lidl', 'leaflet_url' => $lidlUrl, 'leaflet_title' => 'Gazetka Lidl', 'item_name' => 'Sonoff ZBDongle-E', 'price' => '79 zł',
             'old_price' => '99 zł', 'valid_from' => $lidlFrom, 'valid_to' => dayoff(3), 'page' => 12, 'notes' => 'test', 'alerted' => !$second],
            ['product_id' => $B, 'shop' => 'Kaufland', 'leaflet_url' => 'https://kaufland.pl/gazetka', 'item_name' => 'Masło Extra 200 g', 'price' => '5 zł', 'valid_to' => dayoff(4), 'alerted' => false],
            ['product_id' => $A, 'shop' => 'Lidl', 'leaflet_url' => $lidlUrl, 'item_name' => 'Sonoff stary', 'valid_from' => dayoff(-5), 'valid_to' => dayoff(-1), 'alerted' => true],
            ['product_id' => 'pdeadbeef', 'shop' => 'Lidl', 'leaflet_url' => $lidlUrl, 'item_name' => 'Cudzy produkt'],
        ],
    ];
};

$name1 = date('Y-m-d-Hi', $now) . '.json';
file_put_contents("$site/inbox/$name1", json_encode($mkBatch($runIso, false), JSON_UNESCAPED_UNICODE));
[$out, $code] = ingest($site);
eq(0, $code, 'ingest.php: kod wyjścia');
no_php_errors($out, 'ingest.php');
check(str_contains($out, "$name1: ok, ofert: 1"), "ingest.php: log partii ofert: $out");
check(str_contains($out, "$name1: extras ok, produkty: 3, gazetki: 2, pozycji: 5, trafień: 3, odrzuconych: 5"), "ingest.php: log extras: $out");

$po = jread('prodoffers', []);
$k1 = $A . ':' . oid($p1);
$k2 = $A . ':' . oid($p2);
$k3 = $B . ':' . oid($p1);
eq([$k1, $k2, $k3], array_map('strval', array_keys($po['offers'] ?? [])), 'prodoffers: klucze product_id:oid');
$o1 = $po['offers'][$k1] ?? [];
eq(oid($p1), $o1['id'] ?? null, 'oferta: id = oid(url)');
eq($A, $o1['product_id'] ?? null, 'oferta: product_id');
eq(89.9, $o1['price_num'] ?? null, 'oferta: price_num');
eq(99.0, $o1['original_num'] ?? null, 'oferta: original_num');
eq('high', $o1['confidence'] ?? null, 'oferta: confidence');
check(!array_key_exists('category', $o1) && !array_key_exists('image_src', $o1), 'oferta: bez category i image_src');
eq(null, g($o1, 'image'), 'oferta: nieosiągalne zdjęcie nie blokuje importu');
check(($o1['first_seen'] ?? '') === $runIso && ($o1['last_seen'] ?? '') === $runIso, 'oferta: first_seen i last_seen');
eq(300, mb_strlen($po['offers'][$k2]['title'] ?? ''), 'oferta: tytuł przycięty do 300');
eq(700, mb_strlen($po['offers'][$k2]['notes'] ?? ''), 'oferta: notatka przycięta do 700');
eq(null, g($po['offers'][$k2] ?? [], 'confidence'), 'oferta: zła confidence -> null');
check(!isset($po['offers']['pdeadbeef:' . oid('https://x.pl/1')]), 'oferta z nieistniejącym product_id odrzucona');

$ld = jread('leaflets', []);
$bidLid = leaflet_id($biedUrl, $biedFrom);
$lidlLid = leaflet_id($lidlUrl, $lidlFrom);
eq(2, count($ld['leaflets'] ?? []), 'dwie gazetki');
$lb = $ld['leaflets'][$bidLid] ?? [];
$ll = $ld['leaflets'][$lidlLid] ?? [];
eq($bied['id'], $lb['shop_id'] ?? null, 'gazetka Biedronki: shop_id z partii');
eq($biedFrom, $lb['valid_from'] ?? null, 'gazetka: valid_from znormalizowane z DD.MM.RRRR');
eq(dayoff(5), $lb['valid_to'] ?? null, 'gazetka: valid_to znormalizowane z DD.MM.RRRR');
eq(3, count($lb['items'] ?? []), 'gazetka Biedronki: 3 pozycje (pusta nazwa odrzucona)');
eq(3, $lb['items'][0]['page'] ?? null, 'pozycja: page int');
eq(null, g($lb['items'][1] ?? [], 'page'), 'pozycja: zły page -> null');
eq(7, $lb['items'][2]['page'] ?? null, 'pozycja: page z tekstu cyfr');
eq(40, mb_strlen($lb['items'][1]['price'] ?? ''), 'pozycja: cena przycięta do 40');
eq($lidl['id'], $ll['shop_id'] ?? null, 'gazetka Lidla: shop_id dopasowane po nazwie');
eq('Lidl', $ll['shop'] ?? null, 'gazetka Lidla: nazwa z partii');
eq(null, g($ll, 'valid_to'), 'gazetka: nieparsowalne valid_to -> null');
eq(null, g($lb, 'cover'), 'gazetka: nieosiągalna okładka -> null bez błędu');
eq([], glob("$site/img/lf-*") ?: [], 'brak plików okładek');
eq($runIso, $lb['first_seen'] ?? null, 'gazetka: first_seen');

$hd = jread('hits', []);
eq(5, count($hd['hits'] ?? []), 'pięć trafień: 3 z partii i 2 automatyczne (masło w gazetkach Biedronki i Lidla)');
$hidA = hit_id($A, $lidlUrl, 'Sonoff ZBDongle-E', $lidlFrom);
$hA = $hd['hits'][$hidA] ?? [];
eq($lidlLid, g($hA, 'leaflet_id'), 'trafienie: leaflet_id dopasowany po adresie gazetki');
eq(true, $hA['alerted'] ?? null, 'trafienie: alerted');
eq($runIso, $hA['found'] ?? null, 'trafienie: found');
eq(12, $hA['page'] ?? null, 'trafienie: page');
$hidB = hit_id($B, 'https://kaufland.pl/gazetka', 'Masło Extra 200 g', null);
eq(null, g($hd['hits'][$hidB] ?? [], 'leaflet_id'), 'trafienie bez znanej gazetki: leaflet_id null');
check(!in_array('Cudzy produkt', array_column($hd['hits'], 'item_name'), true), 'trafienie z nieistniejącym product_id odrzucone');

$prods = array_column(products(), null, 'id');
eq($runIso, $prods[$A]['last_searched'] ?? null, 'last_searched ustawione dla A');
eq(null, g($prods[$B] ?? [], 'last_searched'), 'last_searched nietknięte dla B');
eq([$name1], extras_files(), 'extras_files zawiera plik');

// idempotencja: ponowny import tego samego pliku
$snap = dsha($data, ['products', 'prodoffers', 'leaflets', 'hits', 'extras_files', 'shops']);
[$out, $code] = ingest($site);
eq(0, $code, 'ingest.php (2): kod wyjścia');
no_php_errors($out, 'ingest.php (2)');
check(!str_contains($out, 'extras'), "ingest.php (2): duplicate nie jest logowany: $out");
eq($snap, dsha($data, ['products', 'prodoffers', 'leaflets', 'hits', 'extras_files', 'shops']), 'idempotencja: dane bez zmian po drugim imporcie');

// ta sama zawartość w nowym pliku (inny czas): aktualizacja bez duplikatów
$run2 = date('c', $now + 60);
$name2 = date('Y-m-d-Hi', $now + 60) . '.json';
file_put_contents("$site/inbox/$name2", json_encode($mkBatch($run2, true), JSON_UNESCAPED_UNICODE));
[$out, $code] = ingest($site);
no_php_errors($out, 'ingest.php (3)');
check(str_contains($out, "$name2: extras ok, produkty: 3, gazetki: 2, pozycji: 5, trafień: 3"), "ingest.php (3): log extras: $out");
$po = jread('prodoffers', []);
$ld = jread('leaflets', []);
$hd = jread('hits', []);
eq(3, count($po['offers']), 'ponowny import: 3 oferty bez duplikatów');
eq(2, count($ld['leaflets']), 'ponowny import: 2 gazetki bez duplikatów');
eq(5, count($hd['hits']), 'ponowny import: 5 trafień bez duplikatów');
eq($runIso, $po['offers'][$k1]['first_seen'] ?? null, 'oferta: first_seen zostaje');
eq($run2, $po['offers'][$k1]['last_seen'] ?? null, 'oferta: last_seen odświeżone');
eq($runIso, $ld['leaflets'][$bidLid]['first_seen'] ?? null, 'gazetka: first_seen zostaje');
eq($run2, $ld['leaflets'][$bidLid]['last_seen'] ?? null, 'gazetka: last_seen odświeżone');
eq('4,99 zł', $ld['leaflets'][$bidLid]['items'][0]['price'] ?? null, 'gazetka: items nadpisane');
eq($runIso, $hd['hits'][$hidA]['found'] ?? null, 'trafienie: found zostaje');
eq(true, $hd['hits'][$hidA]['alerted'] ?? null, 'trafienie: alerted = stare || nowe');
eq($run2, array_column(products(), null, 'id')[$A]['last_searched'] ?? null, 'last_searched przesunięte na nowszy przebieg');
eq([$name1, $name2], extras_files(), 'extras_files: oba pliki');

// dopisanie nowej pozycji do znanej gazetki dokłada ją do zapisanych (nie kasuje reszty), a ta sama nazwa nadpisuje starą
$before = count($ld['leaflets'][$bidLid]['items']);
ingest_extras(['run' => date('c', $now + 120), 'leaflets' => [['shop_id' => $ld['leaflets'][$bidLid]['shop_id'], 'shop' => 'Biedronka', 'title' => 'Gazetka Biedronka', 'url' => $biedUrl,
    'valid_from' => $biedFrom, 'valid_to' => 'jutro', 'items' => [['name' => 'Dove - wszystkie produkty', 'scope' => 'brand', 'applies_to' => 'Dove', 'deal' => 'drugi za 1 zł']]]]], 'merge-test.json');
$ldm = jread('leaflets', []);
eq($before + 1, count($ldm['leaflets'][$bidLid]['items']), 'gazetka: nowa pozycja dołączona do zapisanych');
eq('4,99 zł', $ldm['leaflets'][$bidLid]['items'][0]['price'] ?? null, 'gazetka: stare pozycje zostają po dopisaniu');

/* ================= 3. serwer HTTP: feed i API ================= */

$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1];
fclose($sock);
$errLog = "$tmp/server-errors.log";
$proc = proc_open(
    ['php', '-d', 'error_reporting=-1', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$errLog", '-S', "127.0.0.1:$port", '-t', $site],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/server.out", 'w'], 2 => ['file', "$tmp/server.err", 'w']],
    $pipes
);
register_shutdown_function(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } });
$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if ($c) { fclose($c); $ready = true; } else { usleep(100000); }
}
check($ready, 'serwer php -S nie wystartował');
if (!$ready) { finish($errors, $checks); }

$jar = "$tmp/jar.txt";
/** @return array{0:int,1:string,2:mixed,3:string} kod, treść, JSON, nagłówki */
function http(int $port, string $jar, string $method, string $path, ?array $json = null, array $headers = [], ?array $form = null): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false];
    if ($json !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
    if ($form !== null) $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($raw, $hs);
    return [$code, $body, json_decode($body, true), substr($raw, 0, $hs)];
}
$H = fn(string $m, string $p, ?array $j = null, array $h = []) => http($port, $jar, $m, $p, $j, $h);

// feed
[$code, , $f] = $H('GET', '/feed.php?token=tok');
eq(200, $code, 'feed: kod 200');
eq(['generated', 'categories', 'extra_sources', 'missing_images', 'products', 'leaflet_shops', 'leaflets_known', 'known_hits'], array_keys((array)$f), 'feed: klucze i ich kolejność');
eq([$B, $A], array_column($f['products'] ?? [], 'id'), 'feed: produkty (najpierw bez last_searched)');
eq(['id', 'name', 'ean', 'desc', 'brand', 'keywords', 'category', 'sites', 'last_searched', 'added'], array_keys($f['products'][0] ?? []), 'feed: pola produktu');
eq(['Biedronka', 'Lidl', 'Kaufland'], array_column($f['leaflet_shops'] ?? [], 'name'), 'feed: aktywne sklepy');
eq(['id', 'name', 'url', 'note'], array_keys($f['leaflet_shops'][0] ?? []), 'feed: pola sklepu');
eq(2, count($f['leaflets_known'] ?? []), 'feed: znane gazetki');
$lk = array_column($f['leaflets_known'] ?? [], null, 'url');
eq(4, $lk[$biedUrl]['items_count'] ?? null, 'feed: items_count gazetki');
eq(['shop_id', 'shop', 'url', 'title', 'valid_from', 'valid_to', 'items_count', 'first_seen'], array_keys($lk[$biedUrl] ?? []), 'feed: pola gazetki');
eq(4, count($f['known_hits'] ?? []), 'feed: znane trafienia bez przeterminowanych');
eq(['product_id', 'product_name', 'shop', 'leaflet_url', 'leaflet_title', 'item_name', 'price', 'old_price', 'deal', 'applies_to', 'match', 'notes', 'page', 'valid_from', 'valid_to', 'alerted'], array_keys($f['known_hits'][0] ?? []), 'feed: pola trafienia');
check(!in_array('Sonoff stary', array_column($f['known_hits'] ?? [], 'item_name'), true), 'feed: przeterminowane trafienie pominięte');
eq(403, $H('GET', '/feed.php?token=zly')[0], 'feed: zły token');
eq(403, $H('GET', '/feed.php')[0], 'feed: brak tokenu');
$r = $H('GET', '/lib3.php');
check($r[0] === 403 && $r[1] === '', 'lib3.php wywołany wprost: 403 bez treści');
$r = $H('GET', '/api3.php');
check($r[0] === 403 && $r[1] === '', 'api3.php wywołany wprost: 403 bez treści');

// logowanie
eq(401, $H('GET', '/api.php?a=products')[0], 'API bez sesji: 401');
[, $html] = $H('GET', '/index.php');
preg_match('/<meta name="csrf" content="([a-f0-9]+)"/', $html, $m);
$csrf0 = $m[1] ?? '';
check($csrf0 !== '', 'strona logowania zawiera token csrf');
$r = http($port, $jar, 'POST', '/index.php', null, [], ['act' => 'login', 'csrf' => $csrf0, 'user' => 'michal', 'pass' => 'zle-haslo']);
check($r[0] === 200 && str_contains($r[1], 'Nieprawidłowy login'), 'logowanie ze złym hasłem odrzucone');
$r = http($port, $jar, 'POST', '/index.php', null, [], ['act' => 'login', 'csrf' => $csrf0, 'user' => 'michal', 'pass' => 'testtesttest']);
eq(302, $r[0], 'logowanie: przekierowanie po sukcesie');
[, $html] = $H('GET', '/index.php');
check(str_contains($html, 'id="view"'), 'po logowaniu widać aplikację');
preg_match('/<meta name="csrf" content="([a-f0-9]+)"/', $html, $m);
$csrf = $m[1] ?? '';
check($csrf !== '', 'token csrf po zalogowaniu');
$HP = fn(string $a, array $j, ?string $tok = null) => $H('POST', "/api.php?a=$a", $j, $tok === null ? ['X-CSRF: ' . $csrf] : ($tok === '' ? [] : ['X-CSRF: ' . $tok]));

// CSRF
eq(403, $HP('prod_save', ['name' => 'Bez CSRF'], '')[0], 'POST bez CSRF: 403');
eq(403, $HP('prod_save', ['name' => 'Zły CSRF'], 'abc')[0], 'POST ze złym CSRF: 403');
eq(403, $HP('shop_save', ['name' => 'Bez CSRF', 'url' => 'https://a.pl'], '')[0], 'shop_save bez CSRF: 403');
eq(2, count(products()), 'odrzucone POST-y nic nie zapisały');

// products
[$code, , $j] = $H('GET', '/api.php?a=products');
eq(200, $code, 'products: 200');
$pv = array_column($j['products'] ?? [], null, 'id');
eq(2, count($pv), 'products: dwa produkty');
$va = $pv[$A] ?? [];
eq(2, $va['offers_count'] ?? null, 'products: offers_count A');
eq([79.0, 89.9], array_map('floatval', array_column($va['offers'] ?? [], 'price_num')), 'products: oferty od najtańszej');
eq(79.0, $va['best']['price_num'] ?? null, 'products: best.price_num');
eq('Allegro', $va['best']['shop'] ?? null, 'products: best.shop');
eq(['price', 'price_num', 'shop', 'url'], array_keys($va['best'] ?? []), 'products: pola best');
eq(1, count($va['hits'] ?? []), 'products: tylko aktualne trafienia A');
eq('Sonoff ZBDongle-E', $va['hits'][0]['item_name'] ?? null, 'products: trafienie A');
check(!array_key_exists('image_src', $va['offers'][0] ?? []), 'products: oferty bez image_src');
$vb = $pv[$B] ?? [];
eq(1, $vb['offers_count'] ?? null, 'products: offers_count B');
eq(3, count($vb['hits'] ?? []), 'products: trafienie B');
eq(['offers', 'best', 'hits', 'offers_count'], array_slice(array_keys($va), -4), 'products: kolejność nowych pól');

// prod_save
[$code, , $j] = $HP('prod_save', ['name' => 'Aqara czujnik', 'ean' => '4710 1234-56789', 'desc' => 'opis', 'category' => 'zigbee', 'sites' => "https://botland.com.pl\ndomadoo.pl", 'active' => true]);
eq(200, $code, 'prod_save: nowy produkt');
$C = $j['product']['id'] ?? '';
eq(['https://botland.com.pl', 'https://domadoo.pl'], $j['product']['sites'] ?? null, 'prod_save: sites z tekstu');
eq('4710123456789', $j['product']['ean'] ?? null, 'prod_save: EAN bez spacji i myślników');
[$code, , $j] = $HP('prod_save', ['id' => $C, 'name' => 'Aqara czujnik temperatury', 'ean' => '', 'sites' => ['https://botland.com.pl'], 'active' => false]);
eq(200, $code, 'prod_save: edycja');
eq($C, $j['product']['id'] ?? null, 'prod_save: edycja zachowuje id');
eq(false, $j['product']['active'] ?? null, 'prod_save: active=false');
eq(400, $HP('prod_save', ['name' => 'X', 'ean' => '12345'])[0], 'prod_save: zły EAN -> 400');
eq(400, $HP('prod_save', ['name' => ''])[0], 'prod_save: brak nazwy -> 400');
eq(404, $HP('prod_save', ['id' => 'pdeadbeef', 'name' => 'X'])[0], 'prod_save: nieznane id -> 404');
eq(405, $H('GET', '/api.php?a=prod_save')[0], 'prod_save przez GET: 405');
eq(405, $H('GET', '/api.php?a=prod_delete')[0], 'prod_delete przez GET: 405');
eq(405, $H('GET', '/api.php?a=shop_save')[0], 'shop_save przez GET: 405');

// prod_toggle
eq(200, $HP('prod_toggle', ['id' => $C, 'active' => true])[0], 'prod_toggle: 200');
$pv = array_column($H('GET', '/api.php?a=products')[2]['products'] ?? [], null, 'id');
eq(true, $pv[$C]['active'] ?? null, 'prod_toggle: aktywny');
eq(200, $HP('prod_toggle', ['id' => $C, 'active' => false])[0], 'prod_toggle: wyłączenie');
eq(false, array_column(products(), null, 'id')[$C]['active'] ?? null, 'prod_toggle: zapisane');
eq(404, $HP('prod_toggle', ['id' => 'pdeadbeef', 'active' => true])[0], 'prod_toggle: nieznane id');
$f = $H('GET', '/feed.php?token=tok')[2];
check(!in_array($C, array_column($f['products'] ?? [], 'id'), true), 'feed: wyłączony produkt pominięty');

// shops
[$code, , $j] = $H('GET', '/api.php?a=shops');
eq(200, $code, 'shops: 200');
eq(3, count($j['shops'] ?? []), 'shops: trzy sklepy');
eq(2, count($j['leaflets'] ?? []), 'shops: dwie gazetki');
$lv = array_column($j['leaflets'] ?? [], null, 'id');
check(!array_key_exists('items', $lv[$bidLid] ?? ['items' => 1]), 'shops: gazetka bez items');
eq(4, $lv[$bidLid]['items_count'] ?? null, 'shops: items_count');
eq(true, $lv[$bidLid]['current'] ?? null, 'shops: current');
eq(2, $lv[$lidlLid]['items_count'] ?? null, 'shops: items_count Lidla');
eq(2, $lv[$lidlLid]['hits_count'] ?? null, 'shops: hits_count Lidla (tylko aktualne)');

// shop_save / toggle / delete
[$code, , $j] = $HP('shop_save', ['name' => 'Netto', 'url' => 'https://www.netto.pl/gazetka', 'note' => 'Leszno', 'active' => true]);
eq(200, $code, 'shop_save: nowy sklep');
$N = $j['shop']['id'] ?? '';
check(preg_match('/^s[a-f0-9]{8}$/', $N) === 1, 'shop_save: id sklepu');
[$code, , $j] = $HP('shop_save', ['id' => $N, 'name' => 'Netto Leszno', 'url' => 'https://www.netto.pl/gazetka', 'note' => '', 'active' => true]);
eq('Netto Leszno', $j['shop']['name'] ?? null, 'shop_save: edycja');
eq(400, $HP('shop_save', ['name' => 'X', 'url' => 'to nie adres'])[0], 'shop_save: zły adres -> 400');
eq(400, $HP('shop_save', ['name' => '', 'url' => 'https://a.pl'])[0], 'shop_save: brak nazwy -> 400');
eq(200, $HP('shop_toggle', ['id' => $N, 'active' => false])[0], 'shop_toggle');
eq(false, array_column(shops(), null, 'id')[$N]['active'] ?? null, 'shop_toggle: zapisane');
eq(404, $HP('shop_toggle', ['id' => 'sdeadbeef', 'active' => true])[0], 'shop_toggle: nieznane id');
eq(200, $HP('shop_delete', ['id' => $N])[0], 'shop_delete');
check(!isset(array_column(shops(), null, 'id')[$N]), 'shop_delete: sklep usunięty');
eq(200, $HP('shop_delete', ['id' => $N])[0], 'shop_delete: ponowne usunięcie jest idempotentne');
eq(3, count(shops()), 'sklepy po usunięciu Netto');

// leaflet / leaflet_search
[$code, , $j] = $H('GET', "/api.php?a=leaflet&id=$lidlLid");
eq(200, $code, 'leaflet: 200');
eq(2, count($j['leaflet']['items'] ?? []), 'leaflet: items');
eq(3, count($j['hits'] ?? []), 'leaflet: trafienia tej gazetki');
eq('Sonoff ZBDongle-E', $j['hits'][0]['product_name'] ?? null, 'leaflet: product_name w trafieniu');
eq(400, $H('GET', '/api.php?a=leaflet&id=zle')[0], 'leaflet: złe id');
eq(404, $H('GET', '/api.php?a=leaflet&id=' . str_repeat('a', 12))[0], 'leaflet: nieznane id');
[$code, , $j] = $H('GET', '/api.php?a=leaflet_search&q=' . rawurlencode('maslo'));
eq(200, $code, 'leaflet_search: 200');
eq(2, count($j['results'] ?? []), 'leaflet_search: wyniki bez polskich znaków i wielkości liter');
eq(['leaflet_id', 'shop', 'title', 'valid_from', 'valid_to', 'item'], array_keys($j['results'][0] ?? []), 'leaflet_search: pola wyniku');
eq([], $H('GET', '/api.php?a=leaflet_search&q=' . rawurlencode('ł'))[2]['results'] ?? null, 'leaflet_search: poniżej 2 znaków');
eq([], $H('GET', '/api.php?a=leaflet_search&q=zzzzqq')[2]['results'] ?? null, 'leaflet_search: brak wyników');
eq(1, count($H('GET', '/api.php?a=leaflet_search&q=' . rawurlencode('MLEKO'))[2]['results'] ?? []), 'leaflet_search: wielkość liter');

// nieznana akcja
[$code, , $j] = $H('GET', '/api.php?a=cos_nieznanego');
eq(404, $code, 'nieznana akcja: 404');
eq('Nieznana akcja.', $j['error'] ?? null, 'nieznana akcja: komunikat');
eq(200, $H('GET', '/api.php?a=sources')[0], 'istniejące akcje nadal działają (sources)');

// prod_delete: usuwa oferty i trafienia produktu B, nie rusza A
eq(200, $HP('prod_delete', ['id' => $B])[0], 'prod_delete');
$po = jread('prodoffers', []);
check(!isset($po['offers'][$k3]) && isset($po['offers'][$k1]) && isset($po['offers'][$k2]), 'prod_delete: oferty B usunięte, A zostają');
check(!isset($hd['hits'][$hidB]) || !isset(jread('hits', [])['hits'][$hidB]), 'prod_delete: trafienia B usunięte');
eq(2, count(jread('hits', [])['hits']), 'prod_delete: trafienia A zostają');
check(!isset(array_column(products(), null, 'id')[$B]), 'prod_delete: produkt usunięty');
eq(200, $HP('prod_delete', ['id' => $B])[0], 'prod_delete: ponowne usunięcie jest idempotentne');
eq(200, $HP('prod_delete', ['id' => $C])[0], 'prod_delete: produkt C');

/* ================= 4. czyszczenie ================= */

if (!is_dir("$site/img")) mkdir("$site/img", 0755, true);
$lid = fn(string $c) => str_repeat($c, 12);
$mkLeaf = fn(string $id, ?string $vt, string $lastSeen) => ['id' => $id, 'shop_id' => null, 'shop' => 'T', 'title' => "T $id", 'url' => "https://t.pl/$id", 'valid_from' => null,
    'valid_to' => $vt, 'cover' => "img/lf-$id.jpg", 'items' => [], 'first_seen' => $lastSeen, 'last_seen' => $lastSeen];
$iso = fn(int $daysAgo) => date('c', time() - $daysAgo * 86400);
$leaves = [
    $lid('a') => $mkLeaf($lid('a'), dayoff(-8), $iso(1)),   // znika
    $lid('b') => $mkLeaf($lid('b'), dayoff(-6), $iso(1)),   // zostaje
    $lid('c') => $mkLeaf($lid('c'), null, $iso(22)),        // znika
    $lid('d') => $mkLeaf($lid('d'), null, $iso(20)),        // zostaje
];
foreach (array_keys($leaves) as $id) file_put_contents("$site/img/lf-$id.jpg", 'x');
locked(function () use ($leaves) {
    $db = leaflets_db();
    foreach ($leaves as $k => $v) $db['leaflets'][$k] = $v;
    jwrite('leaflets', $db);
});
$mkHit = fn(string $id, ?string $vt, string $found) => ['id' => $id, 'product_id' => $A, 'leaflet_id' => null, 'shop' => 'T', 'leaflet_url' => null, 'leaflet_title' => null,
    'item_name' => "Hit $id", 'price' => null, 'old_price' => null, 'valid_from' => null, 'valid_to' => $vt, 'page' => null, 'notes' => null, 'alerted' => false, 'found' => $found];
$hitsAdd = [
    $lid('1') => $mkHit($lid('1'), dayoff(-15), $iso(1)),   // znika
    $lid('2') => $mkHit($lid('2'), dayoff(-13), $iso(1)),   // zostaje
    $lid('3') => $mkHit($lid('3'), null, $iso(31)),         // znika
    $lid('4') => $mkHit($lid('4'), null, $iso(29)),         // zostaje
];
locked(function () use ($hitsAdd) {
    $db = hits_db();
    foreach ($hitsAdd as $k => $v) $db['hits'][$k] = $v;
    jwrite('hits', $db);
});
$mkOffer = fn(string $pid, string $url, string $lastSeen) => ['id' => oid($url), 'product_id' => $pid, 'title' => 'T', 'url' => $url, 'price' => '1 zł', 'price_num' => 1.0, 'original_price' => null,
    'original_num' => null, 'shop' => 'T', 'promo_end' => null, 'notes' => null, 'confidence' => null, 'image' => 'img/po-' . oid($url) . '.jpg', 'first_seen' => $lastSeen, 'last_seen' => $lastSeen];
$oldUrl = 'https://old.pl/o'; $newUrl = 'https://new.pl/o'; $orphUrl = 'https://orphan.pl/o';
foreach ([$oldUrl, $newUrl, $orphUrl] as $u) file_put_contents("$site/img/po-" . oid($u) . '.jpg', 'x');
locked(function () use ($mkOffer, $A, $oldUrl, $newUrl, $orphUrl, $iso) {
    $db = prodoffers_db();
    $db['offers'][$A . ':' . oid($oldUrl)] = $mkOffer($A, $oldUrl, $iso(31));     // znika razem ze zdjęciem
    $db['offers'][$A . ':' . oid($newUrl)] = $mkOffer($A, $newUrl, $iso(29));     // zostaje
    $db['offers']['pdeadbeef:' . oid($orphUrl)] = $mkOffer('pdeadbeef', $orphUrl, $iso(1)); // oferta nieistniejącego produktu
    jwrite('prodoffers', $db);
});

[$out, $code] = ingest($site, '--prune');
eq(0, $code, 'ingest.php --prune: kod wyjścia');
no_php_errors($out, 'ingest.php --prune');
check(str_contains($out, 'usunięto: gazetek 2, ofert produktów 2, trafień 2'), "prune: log usunięć: $out");
$ldk = array_map('strval', array_keys(jread('leaflets', [])['leaflets'] ?? []));
check(!in_array($lid('a'), $ldk, true), 'prune: gazetka z valid_to sprzed 8 dni znika');
check(in_array($lid('b'), $ldk, true), 'prune: gazetka z valid_to sprzed 6 dni zostaje');
check(!in_array($lid('c'), $ldk, true), 'prune: gazetka bez valid_to, widziana 22 dni temu, znika');
check(in_array($lid('d'), $ldk, true), 'prune: gazetka bez valid_to, widziana 20 dni temu, zostaje');
check(in_array($bidLid, $ldk, true) && in_array($lidlLid, $ldk, true), 'prune: aktualne gazetki zostają');
check(!is_file("$site/img/lf-" . $lid('a') . '.jpg') && !is_file("$site/img/lf-" . $lid('c') . '.jpg'), 'prune: okładki usuniętych gazetek skasowane');
check(is_file("$site/img/lf-" . $lid('b') . '.jpg'), 'prune: okładka zachowanej gazetki zostaje');
$hk = array_map('strval', array_keys(jread('hits', [])['hits'] ?? []));
check(!in_array($lid('1'), $hk, true), 'prune: trafienie z valid_to sprzed 15 dni znika');
check(in_array($lid('2'), $hk, true), 'prune: trafienie z valid_to sprzed 13 dni zostaje');
check(!in_array($lid('3'), $hk, true), 'prune: trafienie bez valid_to sprzed 31 dni znika');
check(in_array($lid('4'), $hk, true), 'prune: trafienie bez valid_to sprzed 29 dni zostaje');
check(in_array($hidA, $hk, true), 'prune: aktualne trafienie zostaje');
$ok = array_map('strval', array_keys(jread('prodoffers', [])['offers'] ?? []));
check(!in_array($A . ':' . oid($oldUrl), $ok, true), 'prune: oferta sprzed 31 dni znika');
check(in_array($A . ':' . oid($newUrl), $ok, true), 'prune: oferta sprzed 29 dni zostaje');
check(!in_array('pdeadbeef:' . oid($orphUrl), $ok, true), 'prune: oferta nieistniejącego produktu znika');
check(!is_file("$site/img/po-" . oid($oldUrl) . '.jpg') && !is_file("$site/img/po-" . oid($orphUrl) . '.jpg'), 'prune: zdjęcia usuniętych ofert skasowane');
check(is_file("$site/img/po-" . oid($newUrl) . '.jpg'), 'prune: zdjęcie zachowanej oferty zostaje');
[$out] = ingest($site, '--prune');
check(!str_contains($out, 'usunięto: gazetek'), "prune: drugie uruchomienie nic nie usuwa: $out");

// limit sklepów (na końcu, bo zapełnia listę)
$last = [];
for ($i = count(shops()); $i < SHOP_LIMIT; $i++) $last = shop_save(['name' => "S$i", 'url' => "https://s$i.example.com"]);
check(isset($last['ok']) || count(shops()) === SHOP_LIMIT, 'limit sklepów: dojście do 40');
eq(409, shop_save(['name' => 'Ponad limit', 'url' => 'https://x.example.com'])['code'] ?? 0, 'limit sklepów: 41. sklep odrzucony');

// brak błędów PHP po stronie serwera (w tym wyjątków z api.php)
clearstatcache();
$srvErr = is_file($errLog) ? trim((string)file_get_contents($errLog)) : '';
eq('', $srvErr, 'log błędów serwera php -S powinien być pusty');

finish($errors, $checks);

function finish(array $errors, int $checks): never {
    if ($errors) {
        fwrite(STDOUT, "BŁĘDY (" . count($errors) . " z $checks sprawdzeń):\n");
        foreach ($errors as $e) fwrite(STDOUT, " - $e\n");
        exit(1);
    }
    fwrite(STDOUT, "OK ($checks sprawdzeń)\n");
    exit(0);
}
