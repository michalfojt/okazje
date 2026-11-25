<?php
declare(strict_types=1);
// Test kategorii pozycji gazetek. Użycie (przez run.sh): php group_test.php <katalog_site> <katalog_data>
define('OKZ', 1);
require $argv[1] . '/lib.php';
require $argv[1] . '/lib3.php';

$n = 0;
function ok(bool $c, string $m): void { global $n; $n++; if (!$c) { echo "BŁĄD: $m\n"; exit(1); } }

foreach ([
    'Karma dla kota w saszetkach Sheba, 4x85 g' => 'zwierzeta',
    'PEDIGREE Sucha karma dla psów różne rodzaje 2,6–1,7 kg opakowanie' => 'zwierzeta',
    'Żwirek bentonitowy Kitty, 5 l' => 'zwierzeta',
    'LISTERINE Płyn do płukania jamy ustnej 500 ml' => 'kosmetyki',
    'LENOR Płyn do płukania, XXL 2,65 L' => 'chemia',
    'Wszystkie pieluszki Dada Extra Care' => 'dzieci',
    'Piwo Żubr jasne pełne, 500 ml' => 'alkohol',
    'Pierogi z kaczką, jabłkiem i kapustą 350 g' => 'dania',
    'Serek homogenizowany Danio 120 g (czekoladowo-orzechowy)' => 'nabial',
    'Czekolada Milka 250 g' => 'slodycze',
    'Coś zupełnie nieznanego' => 'inne',
] as $name => $want) ok(group_guess($name) === $want, "$name: " . group_guess($name) . " zamiast $want");

ok(group_guess('Wszystko Marki X', 'karmy dla psów') === 'zwierzeta', 'applies_to jako zapas');
ok(group_hide('zwierzeta', true) && group_hide('zwierzeta', true), 'ukrycie');
ok(groups_db()['hidden'] === ['zwierzeta'], 'bez duplikatów');
ok(!group_hide('nie-ma', true), 'nieznana grupa');
$name = 'Coś zupełnie nieznanego';
ok(group_set($name, 'dom') && group_of(['name' => $name], groups_db()['over']) === 'dom', 'ręczne przeniesienie');
ok(groups_db()['hidden'] === ['zwierzeta'], 'przeniesienie nie kasuje ukrytych');
ok(group_set($name, '') && group_of(['name' => $name], groups_db()['over']) === 'inne', 'powrót do automatu');
ok(!group_set($name, 'zla'), 'zła grupa');
ok(group_hide('zwierzeta', false) && groups_db()['hidden'] === [], 'przywrócenie');
echo "OK ($n sprawdzeń)\n";
