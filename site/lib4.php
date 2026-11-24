<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(404); exit; }

// Migawka feedu w repozytorium GitHub. Zadanie cykliczne nie ma dostępu do michalfojt.pl
// (WebFetch prosi o zgodę, powłoka jest za proxy), ale ma dostęp do repozytorium.
// Hosting zapisuje więc zaszyfrowaną kopię feedu w repo, a zadanie ją odczytuje.
//
// Szyfrowanie: AES-256-CBC, klucz z feed_token (PBKDF2-SHA256, 10000 iteracji), format
// zgodny z `openssl enc -aes-256-cbc -pbkdf2` (nagłówek "Salted__"), zapis base64.
// Odczyt w zadaniu:
//   base64 -d feed.enc | openssl enc -d -aes-256-cbc -pbkdf2 -pass env:FEED_TOKEN

const SNAP_HEARTBEAT = 21600; // bez zmian treści też odnawiamy plik co 6 h

/** Treść feedu (to samo, co zwraca feed.php). */
function feed_payload(): array {
    $cats = array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'what' => $c['what'], 'how' => $c['how']], categories());
    $src = array_values(array_map(
        fn($s) => ['url' => $s['url'], 'category' => $s['category'] ?: null, 'note' => $s['note'] ?: null],
        array_filter(sources_list(), fn($s) => !empty($s['active']))
    ));
    // oferty bez zdjęcia, którym hosting nie znalazł go po co najmniej dwóch próbach
    $tries = jread('imgtries', []);
    $miss = [];
    foreach (offers_missing_image() as $id => $o) {
        if ((int)($tries[(string)$id]['n'] ?? 0) < 2) continue;
        $miss[] = ['url' => $o['url'], 'title' => $o['title'], 'shop' => $o['shop'] ?? null];
        if (count($miss) >= 15) break;
    }
    return [
        'generated' => date('c'), 'categories' => $cats, 'extra_sources' => $src, 'missing_images' => $miss,
        'products' => feed_products(), 'leaflet_shops' => feed_leaflet_shops(),
        'leaflets_known' => feed_leaflets_known(), 'known_hits' => feed_known_hits(),
    ];
}

function snap_encrypt(string $plain, string $pass): string {
    $salt = random_bytes(8);
    $kd = hash_pbkdf2('sha256', $pass, $salt, 10000, 48, true);
    $ct = openssl_encrypt($plain, 'aes-256-cbc', substr($kd, 0, 32), OPENSSL_RAW_DATA, substr($kd, 32, 16));
    if ($ct === false) throw new RuntimeException('Szyfrowanie nie powiodło się (brak rozszerzenia openssl?).');
    return chunk_split(base64_encode('Salted__' . $salt . $ct), 76, "\n");
}

/** Ustawienia migawki: ['token','repo','path','last'=>[at,ok,msg,hash]]. */
function snap_settings(): array {
    $s = jread('gh_snapshot', []);
    $s = is_array($s) ? $s : [];
    $s['repo'] = (string)($s['repo'] ?? cfg('deploy_repo', 'michalfojt/okazje'));
    $s['path'] = (string)($s['path'] ?? 'okazje/feed/feed.enc');
    $s['token'] = (string)($s['token'] ?? '');
    return $s;
}

function snap_valid_repo(string $r): bool { return (bool)preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $r); }
function snap_valid_path(string $p): bool {
    return (bool)preg_match('#^[A-Za-z0-9_./-]+$#D', $p) && !str_contains($p, '..') && !str_starts_with($p, '/') && !str_starts_with($p, 'site/');
}
function snap_valid_token(string $t): bool { return (bool)preg_match('/^[A-Za-z0-9_]{20,255}$/D', $t); }

/** @return array{0:int,1:?array} kod HTTP i odpowiedź JSON */
function snap_api(string $method, string $url, string $token, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json', 'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: okazje-snapshot', 'Content-Type: application/json',
        ],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, is_array($j) ? $j : null];
}

/**
 * Zapisuje zaszyfrowaną migawkę feedu w repozytorium. Zapis tylko do jednej ścieżki z ustawień.
 * @return array{ok:bool,msg:string,pushed:bool}
 */
function snap_push(bool $force = false): array {
    $s = snap_settings();
    if ($s['token'] === '') return ['ok' => false, 'msg' => 'Brak tokenu GitHub.', 'pushed' => false];
    $feedTok = (string)cfg('feed_token', '');
    if ($feedTok === '') return ['ok' => false, 'msg' => 'Brak feed_token w config.php.', 'pushed' => false];
    if (!snap_valid_repo($s['repo']) || !snap_valid_path($s['path'])) return ['ok' => false, 'msg' => 'Niepoprawne repozytorium albo ścieżka.', 'pushed' => false];

    $payload = feed_payload();
    $cmp = $payload;
    unset($cmp['generated']);
    $hash = hash('sha256', json_encode($cmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $last = is_array($s['last'] ?? null) ? $s['last'] : [];
    if (!$force && ($last['ok'] ?? false) && ($last['hash'] ?? '') === $hash && time() - (int)($last['at'] ?? 0) < SNAP_HEARTBEAT) {
        return ['ok' => true, 'msg' => 'Bez zmian od ostatniego zapisu.', 'pushed' => false];
    }

    $url = 'https://api.github.com/repos/' . $s['repo'] . '/contents/' . $s['path'];
    [$gc, $gj] = snap_api('GET', $url . '?ref=main', $s['token']);
    $sha = null;
    if ($gc === 200 && isset($gj['sha'])) $sha = (string)$gj['sha'];
    elseif ($gc !== 404) return snap_done($s, $hash, false, snap_err('odczyt pliku', $gc, $gj));

    $enc = snap_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $feedTok);
    $body = ['message' => 'Migawka feedu ' . date('Y-m-d H:i'), 'content' => base64_encode($enc), 'branch' => 'main'];
    if ($sha !== null) $body['sha'] = $sha;
    [$pc, $pj] = snap_api('PUT', $url, $s['token'], $body);
    if ($pc === 200 || $pc === 201) return snap_done($s, $hash, true, 'Zapisano migawkę.');
    return snap_done($s, $hash, false, snap_err('zapis pliku', $pc, $pj));
}

function snap_err(string $what, int $code, ?array $j): string {
    $m = $code === 0 ? 'brak połączenia z api.github.com' : 'HTTP ' . $code . (isset($j['message']) ? ': ' . mb_substr((string)$j['message'], 0, 160) : '');
    $hint = match (true) {
        $code === 401 => ' (token nieprawidłowy lub wygasł)',
        $code === 403 => ' (token bez prawa Contents: write albo limit zapytań)',
        $code === 404 => ' (repozytorium nie istnieje albo token nie ma do niego dostępu)',
        $code === 409 || $code === 422 => ' (konflikt wersji pliku, następna próba powinna się udać)',
        default => '',
    };
    return "Błąd: $what, $m$hint";
}

/** Zapisuje wynik ostatniej próby (bez zmiany tokenu). */
function snap_done(array $s, string $hash, bool $ok, string $msg): array {
    $s['last'] = ['at' => time(), 'ok' => $ok, 'msg' => $msg, 'hash' => $ok ? $hash : ($s['last']['hash'] ?? '')];
    locked(fn() => jwrite('gh_snapshot', $s));
    return ['ok' => $ok, 'msg' => $msg, 'pushed' => $ok];
}
