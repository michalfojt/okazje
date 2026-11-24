<?php
declare(strict_types=1);
// Import partii ofert. Uruchamiany z crona (CLI), nie z przeglądarki.
//
//   php ingest.php            partie z inbox/ oraz z GitHub (jeśli skonfigurowany)
//   php ingest.php --local    tylko inbox/
//   php ingest.php --prune    tylko czyszczenie starych ofert
//   php ingest.php --images   tylko szukanie zdjęć dla ofert bez zdjęcia (ignoruje odstępy między próbami)
//
// Format pliku partii: patrz README.md. Nazwa: RRRR-MM-DD-GGMM.json

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OKZ', 1);
require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
$localOnly = in_array('--local', $args, true);
$pruneOnly = in_array('--prune', $args, true);
$imagesOnly = in_array('--images', $args, true);
$log = fn(string $m) => print(date('Y-m-d H:i:s') . " $m\n");
$namePat = '/^\d{4}-\d{2}-\d{2}-\d{4}\.json$/';
if (($n = purge_search_images()) > 0) $log("usunięto zdjęcia z wyszukiwarki: $n");

if (!$pruneOnly && !$imagesOnly) {
    // 1. pliki lokalne
    $files = glob(__DIR__ . '/inbox/*.json') ?: [];
    sort($files);
    foreach ($files as $f) {
        $name = basename($f);
        $j = json_decode((string)file_get_contents($f), true);
        if (!is_array($j)) { $log("pominięto $name: niepoprawny JSON"); continue; }
        $r = ingest_batch($j, $name);
        if ($r['status'] !== 'duplicate') $log("$name: " . $r['status'] . (isset($r['count']) ? ", ofert: {$r['count']}" : ''));
        foreach ($GLOBALS['img_hint_errs'] ?? [] as $e) $log("  zdjęcie z paczki odrzucone, $e");
    }

    // 2. GitHub (publiczne repo, API bez tokena: limit 60 zapytań/h, cron co 15 min wystarcza)
    $gh = cfg('github_inbox');
    if (!$localOnly && is_array($gh) && !empty($gh['repo'])) {
        $db = jread('offers', ['offers' => [], 'batches' => []]);
        $done = array_merge(array_column($db['batches'], 'file'), $db['img_files'] ?? []);
        $api = 'https://api.github.com/repos/' . $gh['repo'] . '/contents/' . trim((string)$gh['path'], '/') . '?ref=' . rawurlencode((string)($gh['ref'] ?? 'main'));
        $raw = http_get($api, 1000000, 15, ['Accept: application/vnd.github+json']);
        $list = $raw ? json_decode($raw, true) : null;
        if (!is_array($list)) {
            $log('GitHub: brak listy plików (katalog nie istnieje albo brak połączenia)');
        } else {
            $cutName = date('Y-m-d', time() - retention_days() * 86400);
            usort($list, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
            foreach ($list as $it) {
                $name = (string)($it['name'] ?? '');
                if (!preg_match($namePat, $name) || substr($name, 0, 10) < $cutName || in_array($name, $done, true)) continue;
                $url = (string)($it['download_url'] ?? '');
                $body = $url ? http_get($url, 2000000) : null;
                $j = $body ? json_decode($body, true) : null;
                if (!is_array($j)) { $log("GitHub $name: nie udało się pobrać"); continue; }
                $r = ingest_batch($j, $name);
                $log("GitHub $name: " . $r['status'] . (isset($r['count']) ? ", ofert: {$r['count']}" : ''));
                foreach ($GLOBALS['img_hint_errs'] ?? [] as $e) $log("  zdjęcie z paczki odrzucone, $e");
            }
        }
    }
}

// 3. zdjęcia dla ofert, którym brakuje (strona oferty, potem wyszukiwarka obrazów)
if (!$pruneOnly) {
    $found = resolve_missing_images($imagesOnly ? 40 : 8, $imagesOnly, $log);
    if ($found > 0 || $imagesOnly) $log("zdjęcia: znaleziono $found");
}

// 4. czyszczenie
$removed = locked(function () {
    $db = jread('offers', ['offers' => [], 'batches' => []]);
    $n = prune_db($db);
    jwrite('offers', $db);
    return $n;
});
if ($removed > 0) $log("usunięto starych ofert: $removed");

foreach (glob(__DIR__ . '/inbox/*.json') ?: [] as $f) {
    if (filemtime($f) < time() - (retention_days() + 2) * 86400) @unlink($f);
}
