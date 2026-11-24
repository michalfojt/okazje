<?php
declare(strict_types=1);
// Test migawki feedu (lib4.php): php snap_test.php <site> <data>
if (PHP_SAPI !== 'cli') { exit(1); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
[$site, $data] = [rtrim($argv[1] ?? '', '/'), rtrim($argv[2] ?? '', '/')];
$err = [];
$n = 0;
function chk(bool $ok, string $m): void { global $err, $n; $n++; if (!$ok) $err[] = $m; }
define('OKZ', 1);
chdir($site);
require $site . '/lib.php';
require $site . '/lib3.php';
require $site . '/lib4.php';

// 1. treść feedu
$p = feed_payload();
foreach (['generated', 'categories', 'extra_sources', 'missing_images', 'products', 'leaflet_shops', 'leaflets_known', 'known_hits'] as $k) {
    chk(array_key_exists($k, $p), "feed_payload: brak klucza $k");
}
chk(!str_contains(json_encode($p), (string)cfg('feed_token')), 'feed_payload nie może zawierać feed_token');

// 2. szyfrowanie zgodne z openssl CLI
$plain = json_encode(['x' => 'zażółć gęślą jaźń', 'n' => range(1, 300)], JSON_UNESCAPED_UNICODE);
$enc = snap_encrypt($plain, 'sekret-feedu');
chk(!str_contains($enc, 'zażółć') && !str_contains($enc, 'sekret'), 'szyfrogram nie może zawierać jawnego tekstu');
$tmp = tempnam(sys_get_temp_dir(), 'snap');
file_put_contents($tmp, $enc);
$out = shell_exec('export FEED_TOKEN=' . escapeshellarg('sekret-feedu') . '; base64 -d ' . escapeshellarg($tmp) . ' | openssl enc -d -aes-256-cbc -pbkdf2 -pass env:FEED_TOKEN 2>&1');
chk($out === $plain, 'odszyfrowanie openssl CLI daje inny tekst: ' . substr((string)$out, 0, 80));
$bad = shell_exec('export FEED_TOKEN=zly; base64 -d ' . escapeshellarg($tmp) . ' | openssl enc -d -aes-256-cbc -pbkdf2 -pass env:FEED_TOKEN 2>&1');
chk($bad !== $plain, 'zły klucz nie może dawać jawnego tekstu');
unlink($tmp);
chk(snap_encrypt($plain, 'a') !== snap_encrypt($plain, 'a'), 'losowa sól: dwa szyfrowania muszą się różnić');

// 3. walidatory
chk(snap_valid_repo('michalfojt/okazje') && !snap_valid_repo('a/b/c') && !snap_valid_repo('x y/z'), 'snap_valid_repo');
chk(snap_valid_path('okazje/feed/feed.enc') && !snap_valid_path('../x') && !snap_valid_path('/abs') && !snap_valid_path('site/api.php') && !snap_valid_path('a b'), 'snap_valid_path');
chk(snap_valid_token('github_pat_11ABCDEFG0abcdefghijkl_mnopqrstuvwxyz') && !snap_valid_token('krótki') && !snap_valid_token("abc def ghijklmnopqrstuvwxyz"), 'snap_valid_token');

// 4. bez tokenu nic nie wychodzi
$s = snap_settings();
chk($s['token'] === '' && $s['repo'] === 'michalfojt/okazje' && $s['path'] === 'okazje/feed/feed.enc', 'domyślne ustawienia migawki');
$r = snap_push();
chk($r['ok'] === false && $r['pushed'] === false && str_contains($r['msg'], 'token'), 'snap_push bez tokenu: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

// 5. kody błędów API
chk(str_contains(snap_err('zapis', 401, ['message' => 'Bad credentials']), 'wygasł'), 'snap_err 401');
chk(str_contains(snap_err('zapis', 404, null), 'dostępu'), 'snap_err 404');
chk(str_contains(snap_err('zapis', 0, null), 'brak połączenia'), 'snap_err 0');

if ($err) { echo "BŁĘDY (" . count($err) . " z $n):\n - " . implode("\n - ", $err) . "\n"; exit(1); }
echo "OK ($n sprawdzeń)\n";
