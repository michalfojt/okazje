<?php
declare(strict_types=1);
// Test dopasowania produktów do pozycji gazetek: php match_test.php <site> <data>
if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
[$site, $data] = [rtrim($argv[1] ?? '', '/'), rtrim($argv[2] ?? '', '/')];
$err = [];
$n = 0;
function chk(bool $ok, string $m): void { global $err, $n; $n++; if (!$ok) $err[] = $m; }
function eqv(mixed $exp, mixed $act, string $m): void { chk($exp === $act, $m . ' (oczekiwano ' . json_encode($exp, JSON_UNESCAPED_UNICODE) . ', jest ' . json_encode($act, JSON_UNESCAPED_UNICODE) . ')'); }
define('OKZ', 1);
chdir($site);
require $site . '/lib.php';
require $site . '/lib3.php';

$dove = ['id' => 'p1', 'name' => 'Dove Hydrate żel pod prysznic bez SLES 450 ml', 'ean' => '8720181466502', 'brand' => '', 'keywords' => '', 'active' => true];
$doveKw = array_merge($dove, ['keywords' => 'żel pod prysznic, kosmetyki do mycia']);
$pamp = ['id' => 'p2', 'name' => 'Pampers Night Pants 6', 'ean' => '8006530320511', 'brand' => '', 'keywords' => '', 'active' => true];

// marka i słowa
eqv('Dove', product_brand($dove), 'marka z pierwszego słowa nazwy');
eqv('Pampers', product_brand($pamp), 'marka Pampers');
eqv('Head & Shoulders', product_brand(['name' => 'Szampon', 'brand' => 'Head & Shoulders']), 'marka z pola brand');
eqv(['żel pod prysznic', 'kosmetyki do mycia'], product_keywords($doveKw), 'słowa kluczowe');
eqv(['hydrate', 'zel', 'prysznic', 'sles'], name_tokens($dove), 'istotne słowa nazwy (bez marki, liczb i jednostek)');

// pozycje
$wide = ['name' => 'Wszystkie produkty Dove', 'applies_to' => 'wszystkie produkty Dove', 'deal' => 'drugi za 1 zł', 'scope' => 'brand'];
eqv('brand', match_item_to_product($dove, $wide), 'promocja na całą markę Dove');
eqv(null, match_item_to_product($pamp, $wide), 'promocja na Dove nie dotyczy Pampers');
eqv('item', match_item_to_product($dove, ['name' => 'Dove Hydrate żel pod prysznic 450 ml', 'scope' => 'item']), 'ta sama pozycja');
eqv(null, match_item_to_product($dove, ['name' => 'Dove antyperspirant 150 ml', 'scope' => 'item']), 'inny produkt tej samej marki to nie trafienie');
eqv('item', match_item_to_product($dove, ['name' => 'Dove 8720181466502', 'scope' => 'item']), 'EAN w nazwie pozycji');
eqv('item', match_item_to_product($pamp, ['name' => 'Pieluchomajtki Pampers Night Pants 6', 'scope' => 'item']), 'Pampers Night Pants');
eqv(null, match_item_to_product($pamp, ['name' => 'Pampers Pants rozmiar 6', 'scope' => 'item']), 'Pampers Pants bez Night to inny wariant');
eqv(null, match_item_to_product($pamp, ['name' => 'Pampersy', 'applies_to' => 'Pampersy', 'scope' => 'brand']), 'marka tylko jako całe słowo');
$cat = ['name' => 'Żele pod prysznic -30%', 'applies_to' => 'wszystkie żele pod prysznic', 'scope' => 'category'];
eqv('keyword', match_item_to_product($doveKw, $cat), 'promocja na kategorię przez słowa kluczowe');
eqv(null, match_item_to_product($dove, $cat), 'bez słów kluczowych kategoria nie pasuje');
eqv('keyword', match_item_to_product($doveKw, ['name' => 'Kosmetyki do mycia', 'applies_to' => 'kosmetyki do mycia ciała', 'scope' => 'category']), 'odmiana i dodatkowe słowa');
eqv(null, match_item_to_product($doveKw, ['name' => 'Żele pod prysznic Nivea', 'scope' => 'item']), 'zwykła pozycja nie łapie się po słowach kluczowych');
eqv('brand', match_item_to_product(['name' => 'Żywiec Zdrój 1,5 l', 'brand' => '', 'ean' => '', 'keywords' => ''], ['name' => 'ŻYWIEC', 'applies_to' => 'wszystkie napoje ŻYWIEC', 'scope' => 'brand']), 'polskie znaki i wielkość liter');

// pozycje z partii: scope i applies_to
$it = norm_leaflet_item(['name' => 'Dove', 'applies_to' => 'wszystkie produkty Dove', 'deal' => '2. za 1 zł']);
eqv('brand', $it['scope'], 'applies_to bez scope daje brand');
eqv('item', norm_leaflet_item(['name' => 'Mleko', 'scope' => 'brand'])['scope'], 'scope brand bez applies_to wraca do item');
eqv('category', norm_leaflet_item(['name' => 'Żele', 'applies_to' => 'żele', 'scope' => 'category'])['scope'], 'scope category');
eqv('2. za 1 zł', $it['deal'], 'pole deal');

// trafienia przez rematch_hits
$today = date('Y-m-d');
$to = date('Y-m-d', time() + 5 * 86400);
jwrite('products', [$dove, $pamp, ['id' => 'p3', 'name' => 'Nieaktywny Dove', 'ean' => '', 'brand' => 'Dove', 'keywords' => '', 'active' => false]]);
jwrite('leaflets', ['leaflets' => [[
    'id' => 'l1', 'shop_id' => 's1', 'shop' => 'Biedronka', 'title' => 'Gazetka Biedronka', 'url' => 'https://www.biedronka.pl/pl/gazetki/x',
    'valid_from' => $today, 'valid_to' => $to,
    'items' => [$wide + ['price' => null, 'old_price' => null, 'valid_from' => null, 'valid_to' => null, 'page' => 3, 'notes' => null],
                ['name' => 'Mleko 2%', 'scope' => 'item', 'applies_to' => null, 'deal' => null]],
], [
    'id' => 'l2', 'shop' => 'Lidl', 'title' => 'Stara', 'url' => 'https://www.lidl.pl/stara', 'valid_from' => '2020-01-01', 'valid_to' => '2020-01-08',
    'items' => [$wide],
]], 'files' => []]);
eqv(1, rematch_hits(), 'jedno nowe trafienie (aktualna gazetka, aktywny produkt)');
$hd = hits_db()['hits'];
$h = array_values($hd)[0] ?? [];
eqv(1, count($hd), 'stara gazetka i nieaktywny produkt nie dają trafień');
eqv('p1', $h['product_id'] ?? null, 'trafienie dotyczy żelu Dove');
eqv('brand', $h['match'] ?? null, 'typ trafienia');
eqv('drugi za 1 zł', $h['deal'] ?? null, 'promocja w trafieniu');
eqv(false, $h['alerted'] ?? null, 'nowe trafienie czeka na alert');
eqv(3, $h['page'] ?? null, 'strona gazetki');
eqv(0, rematch_hits(), 'drugie wywołanie nie dubluje');
$hid = (string)array_key_first($hd);
$hd2 = hits_db();
$hd2['hits'][$hid]['alerted'] = true;
jwrite('hits', $hd2);
rematch_hits();
eqv(true, hits_db()['hits'][$hid]['alerted'] ?? null, 'rematch nie kasuje alerted');

// feed
$fk = feed_known_hits()[0] ?? [];
eqv('Dove Hydrate żel pod prysznic bez SLES 450 ml', $fk['product_name'] ?? null, 'feed: nazwa produktu');
eqv('drugi za 1 zł', $fk['deal'] ?? null, 'feed: deal');
eqv('brand', $fk['match'] ?? null, 'feed: match');
eqv('Dove', feed_products()[0]['brand'] ?? null, 'feed: marka produktu');

// norm_product
[$np, $e] = norm_product(['name' => 'Test', 'brand' => ' Dove ', 'keywords' => 'żel, szampon']);
eqv('Dove', $np['brand'] ?? null, 'norm_product: brand');
[$np2] = norm_product(['name' => 'Test2'], $np);
eqv('Dove', $np2['brand'] ?? null, 'norm_product: brak pola zachowuje starą markę');

if ($err) { echo "BŁĘDY (" . count($err) . " z $n):\n - " . implode("\n - ", $err) . "\n"; exit(1); }
echo "OK ($n sprawdzeń)\n";
