<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';

// Pliki silnika OCR (Tesseract) do odczytu paragonów ze zdjęć. Odczyt działa w przeglądarce.
// Hosting pobiera każdy plik raz, w ustalonej wersji, sprawdza sha256 i trzyma w data/ocr/,
// więc strona nie ładuje niczego z zewnątrz, a obrazek paragonu nigdzie nie jest wysyłany.

const OCR_MAX_BYTES = 6291456; // 6 MB na plik
const OCR_FILES = [
    'tesseract.min.js' => ['tesseract.js@7.0.0/dist/tesseract.min.js', '000c27d9cd0def655f77b36c72a389c0ab13793aa31cb4d7aab56d09c0afbc7e', 'application/javascript'],
    'worker.min.js' => ['tesseract.js@7.0.0/dist/worker.min.js', '576b7df7e3393e137e51849357c9adb53fe7ac1bb69bfa06cf3d61520f182c6d', 'application/javascript'],
    'core-simd.wasm.js' => ['tesseract.js-core@7.0.0/tesseract-core-simd-lstm.wasm.js', 'c58b46a4c796c0b8afccf77591d5b875b6896b45d402bbce8caa6f5362447b38', 'application/javascript'],
    'core-basic.wasm.js' => ['tesseract.js-core@7.0.0/tesseract-core-lstm.wasm.js', 'eef5f8b2f8e20e150680b20adaec4a60babafee3adbe8a94583c81fee46e8680', 'application/javascript'],
    'lang/pol.traineddata.gz' => ['@tesseract.js-data/pol@1.0.0/4.0.0_best_int/pol.traineddata.gz', 'a20fdec4ff99d8f8e84c708da3e42a4e935c26863055a0ed88aef5c66a59b91b', 'application/octet-stream'],
];

function ocr_stop(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!auth_user()) ocr_stop(401, 'Zaloguj się.');
session_write_close(); // pliki pobierane są równolegle

$name = (string)($_GET['f'] ?? '');
if (!isset(OCR_FILES[$name])) ocr_stop(404, 'Nie ma takiego pliku.');
[$src, $sha, $type] = OCR_FILES[$name];

$dir = data_dir() . '/ocr';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$file = $dir . '/' . $sha; // nazwa od sumy, więc zmiana wersji pobiera plik od nowa
if (!is_file($file)) {
    @set_time_limit(120);
    $body = null;
    foreach (['https://cdn.jsdelivr.net/npm/', 'https://unpkg.com/'] as $base) {
        $b = http_get((string)cfg('ocr_base', $base) . $src, OCR_MAX_BYTES, 60);
        if ($b !== null && hash_equals($sha, hash('sha256', $b))) { $body = $b; break; }
    }
    if ($body === null) ocr_stop(502, 'Nie udało się pobrać plików do odczytu zdjęć.');
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $body) !== strlen($body) || !@rename($tmp, $file)) { @unlink($tmp); ocr_stop(500, 'Nie można zapisać pliku w data/ocr.'); }
}

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
// worker potrzebuje zgody na WebAssembly; dotyczy tylko odpowiedzi tego pliku
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval'");
readfile($file);
