<?php
declare(strict_types=1);
// Test oceny atrakcyjności promocji: php deal_test.php <site> <data>
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

eqv(50, $pct('drugi za 1 zł'), 'drugi za 1 zł = 50%');
eqv(50, $pct('Drugi za 1 zł (drugi tańszy produkt za 1 zł)'), 'drugi za 1 zł, dłuższy opis');
eqv(50, $pct('1+1 gratis'), '1+1 gratis');
eqv(33, $pct('2+1 gratis'), '2+1');
eqv(25, $pct('3+1'), '3+1');
eqv(33, $pct('3 za 2'), '3 za 2');
eqv(null, $pct('2 za 10 zł'), '2 za 10 zł bez ceny bazowej');
eqv(30, $pct('-30%'), '-30%');
eqv(25, $pct('drugi produkt -50%'), 'drugi -50% = 25% średnio');
eqv(25, $pct('druga sztuka 50% taniej'), 'druga sztuka 50% taniej');
eqv(50, $pct('druga sztuka gratis'), 'druga sztuka gratis');
eqv(33, $pct('trzecia sztuka gratis'), 'trzecia gratis');
eqv(40, $pct(null, '29,99 zł', '49,99 zł'), 'rabat z ceny i ceny poprzedniej');
eqv(null, $pct(null, '29,99 zł', null), 'sama cena: bez oceny');
eqv(null, $pct(''), 'pusty warunek');
eqv(null, $pct('karta MB, limit 2 szt.'), 'warunki bez rabatu');
eqv(null, $pct('drugi za 5 zł'), 'drugi za 5 zł: cena bazowa nieznana');

$d = deal_score('do -50%');
eqv([50, true], [$d['pct'] ?? null, $d['upto'] ?? null], '"do -50%" oznaczone jako maksimum');
eqv('dobra', $d['rating'] ?? null, 'do -50% liczone jak 30%: dobra');
eqv('bardzo dobra', deal_score('drugi za 1 zł')['rating'] ?? null, 'ocena drugi za 1 zł');
eqv('średnia', deal_score('-20%')['rating'] ?? null, 'ocena -20%');
eqv('słaba', deal_score('-10%')['rating'] ?? null, 'ocena -10%');

// lista promocji: pozycje z kilku gazetek, trafienie z Moich produktów na górze
$ld = leaflets_db();
$u1 = 'https://www.biedronka.pl/pl/gazetka-test';
$id1 = leaflet_id($u1, '2026-01-01');
$ld['leaflets'][$id1] = ['id' => $id1, 'shop' => 'Biedronka', 'title' => 'Test', 'url' => $u1, 'valid_from' => '2026-01-01', 'valid_to' => '2099-12-31', 'items' => [
    ['name' => 'Taft lakier do włosów 250 ml', 'deal' => '1+1 gratis', 'price' => null],
    ['name' => 'Wszystkie produkty Dove', 'scope' => 'brand', 'applies_to' => 'Dove', 'deal' => 'drugi za 1 zł'],
    ['name' => 'Masło 200 g', 'price' => '5,99 zł', 'old_price' => '7,99 zł'],
    ['name' => 'Chleb', 'price' => '3,00 zł'],
]];
jwrite('leaflets', $ld);
$prod = product_save(['name' => 'Dove Hydrate żel pod prysznic bez SLES 450 ml', 'active' => true])['product'] ?? null;
rematch_hits();
$pr = leaflet_promos();
eqv(4, count($pr), 'lista promocji: cztery pozycje');
$by = array_column($pr, null, 'name');
eqv(true, !empty($by['Wszystkie produkty Dove']['mine']), 'pozycja z oznaczeniem "mine"');
eqv('brand', $by['Wszystkie produkty Dove']['mine'][0]['match'] ?? null, 'typ dopasowania brand');
eqv([8, 8, 5, 3], array_map(fn($r) => $r['score']['v'], $pr), 'od najlepszej oceny, bez przypinania moich produktów');
eqv(true, $pr[3]['score']['unsure'], 'brak podstaw: 3?');
eqv([null, 'Chleb'], [$pr[3]['discount'], $pr[3]['name']], 'pozycja bez rabatu na końcu');
$pv = products_view();
eqv(50, $pv[0]['hits'][0]['discount']['pct'] ?? null, 'trafienie w widoku produktu ma ocenę rabatu');

echo $err ? "BŁĘDY (" . count($err) . " z $n sprawdzeń):\n - " . implode("\n - ", $err) . "\n" : "OK ($n sprawdzeń)\n";
exit($err ? 1 : 0);
