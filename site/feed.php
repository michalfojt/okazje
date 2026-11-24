<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib3.php';
require_once __DIR__ . '/lib4.php';

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

echo json_encode(feed_payload(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
