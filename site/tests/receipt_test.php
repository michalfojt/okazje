<?php
// Test importu paragonów (lib6.php) na zmyślonych danych: php receipt_test.php <site> <data>
declare(strict_types=1);
define('OKZ', 1);
[, $site] = $argv;
require $site . '/lib.php';
require $site . '/lib3.php';

$n = 0;
function ok(bool $c, string $m): void { global $n; $n++; if (!$c) { fwrite(STDERR, "BŁĄD: $m\n"); exit(1); } }
function line(string $name, string $vat = 'C', string $qty = '1', int $price = 499, bool $storno = false): array {
    return ['sellLine' => ['name' => str_pad($name, 25) . $vat, 'vatId' => $vat, 'price' => $price, 'quantity' => $qty, 'isStorno' => $storno]];
}
function rc(int $no, string $date, array $lines): array {
    return ['body' => [...$lines, ['fiscalFooter' => ['uniqueNumber' => 'TEST', 'billNumber' => $no, 'date' => $date . 'T10:00:00.000Z']]]];
}

foreach (['JogPSkyrWanil330g' => 'Jogurt pitny Skyr waniliowy 330g', 'SokJabVitatlFres1l' => 'Sok jabłkowy Vital Fresh 1l',
          'BorówkaAmeryk 300g' => 'Borówka amerykańska 300g', 'OgórekSzkLuz' => 'Ogórek szklarniowy', 'AwokadoHass500gOpa' => 'Awokado Hass 500g',
          'DoveŻelHydrate450' => 'Dove Żel Hydrate 450', 'Jog.SkyrWys.Wan.360g' => 'Jogurt Skyr wysokobiałkowy Wan 360g'] as $raw => $want) {
    ok(receipt_name($raw) === $want, "nazwa $raw: " . receipt_name($raw));
}
ok(receipt_brand('Sok jabłkowy Vital Fresh 1l') === 'Vital Fresh', 'marka');
ok(receipt_brand('Banan') === '', 'bez marki');
ok(receipt_keywords('Banan', '') === 'banany', 'słowa banan');
ok(receipt_keywords('Masło Extra 200g', '') === 'masło extra', 'słowa nieznane');
ok(receipt_parse(['x' => 1]) === null, 'zły plik');

$a = rc(1, '2026-11-20', [line('Banan Luz', 'C', '1,200', 699), line('Banan Luz', 'C', '0,500', 699), line('DoveŻelHydrate450', 'A', '4', 1899),
    line('Torba papierowa', 'A'), line('But Plastik kaucja', 'A'), line('Cofnięty', 'C'), line('Cofnięty', 'C', '1', 499, true)]);
$r = receipt_import([$a]);
ok(($r['added'] ?? 0) === 2, 'dodane 2: ' . json_encode($r));
$r = receipt_import([$a]);
ok($r['added'] === 0 && $r['updated'] === 0, 'ten sam paragon nic nie zmienia');
$r = receipt_import([rc(2, '2026-11-24', [line('Banan Luz', 'C', '1,000', 599)])]);
ok($r['added'] === 0 && $r['updated'] === 1, 'kolejny zakup');
$by = array_column(products(), null, 'name');
ok(count($by['Banan']['receipt']['bought']) === 2 && $by['Banan']['receipt']['price'] === 599 && $by['Banan']['receipt']['unit'] === 'kg', 'statystyki');
receipt_import([rc(3, '2026-11-01', [line('Banan Luz', 'C', '1,000', 999)])]);
ok(products()[0]['receipt']['price'] === 599, 'starszy paragon nie nadpisuje ceny');

// edycja zachowuje dane paragonu
$id = $by['Banan']['id'];
$s = product_save(['id' => $id, 'name' => 'Banany luzem', 'keywords' => 'banany', 'brand' => '']);
ok(($s['product']['source'] ?? '') === 'receipt' && !empty($s['product']['receipt']['keys']), 'edycja zachowuje paragon');
ok(receipt_import([$a])['added'] === 0, 'po zmianie nazwy bez duplikatu');

// dopasowanie
$ban = $s['product'];
$dove = $by['Dove Żel Hydrate 450'];
$m = fn(array $p, string $name, string $scope = 'item', ?string $to = null) => match_item_to_product($p, ['name' => $name, 'scope' => $scope, 'applies_to' => $to]);
ok($m($ban, 'Banany luz 1 kg') === 'keyword', 'banany');
ok($m($ban, 'Banan') === 'keyword', 'banan');
ok($m($ban, 'Jogurt bananowy 150 g') === null, 'jogurt bananowy');
ok($m($ban, 'Chipsy bananowe') === null, 'chipsy');
ok($m($ban, 'Owoce -30%', 'category', 'banany') === 'keyword', 'kategoria');
ok($m($dove, 'Dove żel pod prysznic 450 ml') === 'keyword', 'dove żel');
ok($m($dove, 'Dove - wszystkie produkty', 'brand', 'Dove') === 'brand', 'dove marka');
ok($m($dove, 'Nivea żel pod prysznic') === null, 'inna marka, zwykła pozycja');
ok($m($dove, 'Dove antyperspirant') === null, 'inny produkt marki');
$serek = ['source' => 'receipt', 'name' => 'Serek wiejski 200g', 'brand' => '', 'keywords' => 'serek wiejski'];
ok($m($serek, 'Serki wiejskie Piątnica 200 g') === 'keyword', 'serek');
ok($m($serek, 'Serek homogenizowany') === null, 'inny serek');
ok(product_brand($serek) === '', 'brak marki z paragonu');

// usunięte nie wracają, chyba że na życzenie
product_delete($id);
$r = receipt_import([$a]);
ok($r['added'] === 0 && $r['ignored'] === 1, 'usunięty pominięty');
$r = receipt_import([$a], true);
ok($r['added'] === 1 && $r['ignored'] === 0 && receipt_ignored() === [], 'przywrócenie');
ok(isset(receipt_import([['body' => []]])['err']), 'pusty paragon');

echo "OK ($n sprawdzeń)\n";
