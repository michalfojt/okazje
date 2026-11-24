<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib3.php';

// Lista kategorii i stron do przeszukiwania dla zadania wyszukującego.
// Dostęp: feed.php?token=... (token z config.php). Tylko odczyt.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$tok = (string)cfg('feed_token', '');
$given = (string)($_GET['token'] ?? '');
if ($tok === '' || !hash_equals($tok, $given)) {
    http_response_code(403);
    echo '{"error":"forbidden"}';
    exit;
}

$cats = array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'what' => $c['what'], 'how' => $c['how']], categories());
$src = array_values(array_map(
    fn($s) => ['url' => $s['url'], 'category' => $s['category'] ?: null, 'note' => $s['note'] ?: null],
    array_filter(sources_list(), fn($s) => !empty($s['active']))
));
// oferty bez zdjęcia, którym hosting nie znalazł go po co najmniej dwóch próbach; zadanie wyszukujące może dostarczyć adres zdjęcia
$tries = jread('imgtries', []);
$miss = [];
foreach (offers_missing_image() as $id => $o) {
    if ((int)($tries[(string)$id]['n'] ?? 0) < 2) continue;
    $miss[] = ['url' => $o['url'], 'title' => $o['title'], 'shop' => $o['shop'] ?? null];
    if (count($miss) >= 15) break;
}
echo json_encode([
    'generated' => date('c'), 'categories' => $cats, 'extra_sources' => $src, 'missing_images' => $miss,
    'products' => feed_products(), 'leaflet_shops' => feed_leaflet_shops(),
    'leaflets_known' => feed_leaflets_known(), 'known_hits' => feed_known_hits(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
