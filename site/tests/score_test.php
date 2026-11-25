<?php
declare(strict_types=1);
// Test oceny 1-10, cen jednostkowych i archiwum: php score_test.php <site> <data>
if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
[$site, $data] = [rtrim($argv[1] ?? '', '/'), rtrim($argv[2] ?? '', '/')];
$err = []; $n = 0;
function eqv(mixed $exp, mixed $act, string $m): void { global $err, $n; $n++; if ($exp !== $act) $err[] = $m . ' (oczekiwano ' . json_encode($exp, JSON_UNESCAPED_UNICODE) . ', jest ' . json_encode($act, JSON_UNESCAPED_UNICODE) . ')'; }
define('OKZ', 1);
chdir($site);
require $site . '/lib.php';
require $site . '/lib3.php';
$pct = fn(?string $d, $p = null, $o = null) => (deal_score($d, $p, $o)['pct'] ?? null);
$r2 = fn(?float $v) => $v === null ? null : round($v, 2);

// rabaty: przypadki z gazetek
eqv(20, $pct('trzeci za 1 zł', '1 zł', '2,49 zł'), 'trzeci za 1 zł liczony od trzech sztuk');
eqv(37, $pct('drugi za 5 zł', null, '19,99 zł'), 'drugi za 5 zł z ceną regularną');
eqv(38, $pct('drugi 76% taniej', '4,49 zł', '7,29 zł'), 'średnia cena sztuki przy drugim taniej');
eqv(25, $pct('drugi -50%', '5,00 zł', '10,00 zł'), 'cena samej drugiej sztuki nie podwaja rabatu');
eqv(23, $pct('trzeci 70% taniej'), 'trzeci -70% to 23%');
eqv(50, $pct('drugi za grosz', null, null), 'drugi za grosz');
eqv(43, $pct('4+3 gratis'), '4+3');
eqv(null, $pct(null, '1,99 zł/100 g', '24,99 zł/kg'), 'różne jednostki: bez rabatu z ceny');
eqv(1299.0, money_num('1 299,00 zł'), 'tysiące ze spacją');

// punkty
eqv([2, 3, 4, 5, 6, 7, 8, 9, 10], array_map('score_from_pct', [9, 10, 20, 25, 33, 40, 45, 55, 65]), 'progi punktów');
$one = fn(array $it) => score_items([$it + ['valid_from' => '2026-06-01']])[0];
eqv(9, $one(['name' => 'Pomarańcze 1 kg', 'price' => '2,99 zł', 'old_price' => '6,99 zł', 'deal' => '-57%', 'notes' => 'z kartą'])['v'], 'karta bez kary');
eqv(8, $one(['name' => 'Kawa mielona Eduscho 500 g', 'old_price' => '37,99 zł', 'deal' => '1+1 gratis'])['v'], 'multipak bez kary');
eqv(4, $one(['name' => 'Chałwa 100 g', 'price' => '1 zł', 'old_price' => '2,49 zł', 'deal' => 'trzeci za 1 zł'])['v'], 'chałwa');
$u = $one(['name' => 'Sushi 170 g', 'price' => '19,99 zł', 'deal' => 'supercena']);
eqv([3, true], [$u['v'], $u['unsure']], 'brak podstaw');

// ilości i cechy
eqv([0.72, 'l'], parse_qty('Lody na patyku 6x120 ml'), '6x120 ml');
eqv([1.75, 'l'], parse_qty('Napój Pepsi 1,75 l'), '1,75 l');
eqv([16.0, 'szt'], parse_qty('Papier toaletowy 3-warstwowy 16 rolek'), 'rolki');
eqv(null, parse_qty('Wiertarko-wkrętarka 12 V, akumulator 5 Ah'), 'bez gramatury');
$f = item_features(['name' => 'ŁOWICZ Ser edamski 1 kg', 'price' => '17,99 zł', 'old_price' => '29,89 zł']);
eqv(['edamski lowicz ser', 'kg', 17.99], [$f['tk'], $f['u'], $r2($f['val'])], 'klucz i cena za kg');
eqv('edamski lowicz ser', item_features(['name' => 'Ser edamski Łowicz (różne rodzaje) 500 g'])['tk'], 'kolejność słów i nawiasy bez znaczenia');
eqv(119.9, $r2(item_features(['name' => 'Szynka', 'price' => '11,99 zł/100 g'])['val']), 'cena za 100 g na kg');
eqv(false, tokens_match('2 laciate mleko uht', '3p2 laciate mleko uht'), 'inny procent tłuszczu to inny produkt');
eqv(18.995, item_features(['name' => 'Kawa 500 g', 'old_price' => '37,99 zł', 'deal' => '1+1'])['val'] / 2, 'cena efektywna przy 1+1');

// archiwum i historia
$mk = function (string $id, string $shop, string $from, string $name, string $price, ?string $old = null, ?string $deal = null): array {
    return ['id' => $id, 'shop' => $shop, 'url' => 'https://example.com/' . $id, 'title' => $id, 'valid_from' => $from,
            'valid_to' => date('Y-m-d', strtotime("$from +6 days")), 'first_seen' => $from . 'T06:00:00+01:00',
            'items' => [['name' => $name, 'price' => $price, 'old_price' => $old, 'deal' => $deal]]];
};
$past = [
    $mk('aaaaaaaaaaa1', 'Biedronka', '2026-03-02', 'Masło Extra Mlekpol 200 g', '6,00 zł', '8,00 zł'),
    $mk('aaaaaaaaaaa2', 'Lidl', '2026-04-06', 'Mlekpol masło extra 200 g', '5,80 zł', '8,00 zł'),
    $mk('aaaaaaaaaaa3', 'Biedronka', '2026-05-04', 'Masło extra Mlekpol 200 g', '6,20 zł', '8,00 zł'),
];
eqv(3, locked(fn() => arch_put($past)), 'trzy nowe rekordy');
eqv(0, locked(fn() => arch_put($past)), 'ponowny zapis nic nie dodaje');
eqv(['2026-03', '2026-04', '2026-05'], arch_months(), 'pliki miesięczne');

$now = fn(string $price, ?string $old = '8,00 zł', string $name = 'Masło Extra Mlekpol 200 g') =>
    score_items([['name' => $name, 'price' => $price, 'old_price' => $old, 'valid_from' => '2026-06-01']])[0];
$t = $now('3,99 zł');
eqv([3, true, false, 9], [$t['n'], $t['turbo'], $t['fake'], $t['v']], 'turbo okazja');
$x = $now('6,00 zł');
eqv([false, true, 5], [$x['turbo'], $x['fake'], $x['v']], 'zwykła cena promocyjna: pozorna okazja, bo -25% to norma');
$h = $now('6,60 zł', '12,00 zł');
eqv([true, 5], [$h['fake'], $h['v']], 'duży rabat od zawyżonej ceny przy drogiej cenie');
$s = $now('7,99 zł', null, 'Masło Extra Mlekpol 300 g');
eqv(true, $s['n'] === 3 && !$s['turbo'] && !$s['cmp'][0]['same_size'], 'inna gramatura: porównanie po cenie za kg, bez turbo');
eqv(0, score_items([['name' => 'Masło Extra Mlekpol 200 g', 'price' => '3,99 zł', 'valid_from' => '2026-03-02']])[0]['n'], 'przyszłe i trwające promocje nie są historią');
$p = $now('4,00 zł', null);
eqv([false, true], [$p['unsure'], $p['v'] >= 9], 'sama cena, ale jest historia');

$pair = $t['cmp'][0]['pair'];
eqv(true, arch_split_set($pair, true), 'rozłączenie');
$t2 = $now('3,99 zł');
eqv([2, true, false], [$t2['n'], $t2['cmp'][0]['excluded'], $t2['turbo']], 'rozłączona pozycja nie liczy się do historii');
arch_split_set($pair, false);
eqv(3, $now('3,99 zł')['n'], 'przywrócenie');
eqv(false, arch_split_set('x:../..', true), 'zły identyfikator pary');

// promocje na markę: porównanie głębokości rabatu
$w = fn(string $id, string $from, string $deal) => ['items' => [['name' => 'Wszystkie produkty Dove', 'scope' => 'brand', 'applies_to' => 'Dove', 'deal' => $deal]]]
    + $mk($id, 'Biedronka', $from, 'x', '');
locked(fn() => arch_put([$w('bbbbbbbbbbb1', '2026-03-02', '-30%'), $w('bbbbbbbbbbb2', '2026-04-06', '-30%'), $w('bbbbbbbbbbb3', '2026-05-04', '2+1')]));
$b = score_items([['name' => 'Dove wszystkie', 'scope' => 'brand', 'applies_to' => 'Dove', 'deal' => 'drugi za 1 zł', 'valid_from' => '2026-06-01']])[0];
eqv([3, true, 9], [$b['n'], $b['turbo'], $b['v']], 'marka: najgłębszy rabat w archiwum');

// lista bez limitu 600 i archiwizacja przed czyszczeniem
$ld = leaflets_db();
$items = [];
for ($i = 0; $i < 400; $i++) $items[] = ['name' => "Produkt testowy numer $i 1 kg", 'price' => '5,00 zł', 'old_price' => '10,00 zł'];
foreach (['ccccccccccc1', 'ccccccccccc2'] as $id) $ld['leaflets'][$id] = ['id' => $id, 'shop' => $id, 'url' => "https://example.com/$id", 'valid_from' => '2026-01-01', 'valid_to' => '2099-12-31', 'items' => $items];
$ld['leaflets']['ddddddddddd1'] = $mk('ddddddddddd1', 'Lidl', '2020-01-06', 'Stara pozycja 1 kg', '1,00 zł');
jwrite('leaflets', $ld);
eqv(800, count(leaflet_promos()), 'ponad 600 pozycji na liście');
prune_extras();
eqv(true, !isset(leaflets_db()['leaflets']['ddddddddddd1']) && count(jread('arch-2020-01', [])) === 1, 'usunięta gazetka zostaje w archiwum');
eqv(800, arch_sync(), 'synchronizacja archiwum z bazą');
$v = leaflet_view('ccccccccccc1');
eqv(true, $v === null || isset($v['leaflet']['items'][0]['score']['v']), 'ocena w widoku gazetki');

echo $err ? "BŁĘDY (" . count($err) . " z $n sprawdzeń):\n - " . implode("\n - ", $err) . "\n" : "OK ($n sprawdzeń)\n";
exit($err ? 1 : 0);
