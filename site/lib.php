<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

date_default_timezone_set('Europe/Warsaw');

/* ---------- konfiguracja i pliki danych ---------- */

function cfg(string $key, mixed $default = null): mixed {
    static $c = null;
    $c ??= require __DIR__ . '/config.php';
    return $c[$key] ?? $default;
}

function data_dir(): string {
    $d = rtrim((string)cfg('data_dir', __DIR__ . '/data'), '/');
    if (!is_dir($d)) { @mkdir($d, 0750, true); }
    return $d;
}

function retention_days(): int { return max(1, (int)cfg('retention_days', 14)); }

function jread(string $name, mixed $default): mixed {
    $f = data_dir() . "/$name.json";
    if (!is_file($f)) return $default;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return $default;
    $j = json_decode($raw, true);
    return $j === null ? $default : $j;
}

function jwrite(string $name, mixed $data): void {
    $f = data_dir() . "/$name.json";
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException("Nie można zapisać $name.json");
    }
    rename($tmp, $f);
}

function locked(callable $fn): mixed {
    $fh = fopen(data_dir() . '/.lock', 'c');
    flock($fh, LOCK_EX);
    try { return $fn(); } finally { flock($fh, LOCK_UN); fclose($fh); }
}

/* ---------- sesja, logowanie, CSRF ---------- */

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function boot_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $life = 14 * 86400;
    $sd = data_dir() . '/sessions';
    if (!is_dir($sd)) { @mkdir($sd, 0700, true); }
    if (is_dir($sd) && is_writable($sd)) { session_save_path($sd); }
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('okz2');
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function auth_user(): ?string {
    boot_session();
    return isset($_SESSION['u']) && is_string($_SESSION['u']) ? $_SESSION['u'] : null;
}

function csrf(): string {
    boot_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_ok(?string $t): bool {
    boot_session();
    return !empty($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 45); }

/** @return array{0:bool,1:string} */
function login_try(string $user, string $pass): array {
    $ip = client_ip();
    $now = time();
    $th = jread('throttle', []);
    foreach ($th as $k => $v) { if (($v['until'] ?? 0) < $now && ($v['t'] ?? 0) < $now - 3600) unset($th[$k]); }
    $rec = $th[$ip] ?? ['n' => 0, 'until' => 0, 't' => 0];
    if (($rec['until'] ?? 0) > $now) {
        $min = (int)ceil(($rec['until'] - $now) / 60);
        return [false, "Za dużo prób. Spróbuj za $min min."];
    }
    $okUser = hash_equals((string)cfg('user'), $user);
    $okPass = password_verify($pass, (string)cfg('pass_hash'));
    if ($okUser && $okPass) {
        unset($th[$ip]);
        locked(fn() => jwrite('throttle', $th));
        session_regenerate_id(true);
        $_SESSION['u'] = $user;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return [true, ''];
    }
    $rec['n'] = (int)$rec['n'] + 1;
    $rec['t'] = $now;
    if ($rec['n'] >= 5) { $rec['until'] = $now + 900; $rec['n'] = 0; }
    $th[$ip] = $rec;
    locked(fn() => jwrite('throttle', $th));
    usleep(400000);
    return [false, 'Nieprawidłowy login lub hasło.'];
}

function logout(): void {
    boot_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
}

/* ---------- pomocnicze ---------- */

function slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

function norm_url(string $u): string {
    $u = trim($u);
    $p = parse_url($u);
    if (!$p || empty($p['host'])) return strtolower($u);
    $host = strtolower((string)preg_replace('/^www\./i', '', $p['host']));
    $path = rtrim($p['path'] ?? '/', '/');
    $q = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $arr);
        foreach (array_keys($arr) as $k) { if (stripos((string)$k, 'utm_') === 0) unset($arr[$k]); }
        ksort($arr);
        $q = $arr ? '?' . http_build_query($arr) : '';
    }
    return $host . $path . $q;
}

function oid(string $url): string { return substr(sha1(norm_url($url)), 0, 12); }

function parse_price(?string $s): ?float {
    if ($s === null) return null;
    if (!preg_match('/(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+)(?:[.,](\d{1,2}))?/u', $s, $m)) return null;
    $int = preg_replace('/[^\d]/u', '', $m[1]);
    $v = (float)($int . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : ''));
    return $v > 0 ? $v : null;
}

function is_http_url(string $u): bool {
    return (bool)preg_match('#^https?://[^\s/$.?\#][^\s]*$#i', $u) && strlen($u) <= 1000;
}

function host_is_public(string $url): bool {
    if (cfg('allow_private_hosts', false)) return true;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/**
 * Pobiera URL (curl, a gdy go brak, strumienie). Zwraca treść albo null.
 * $partial: dla stron HTML, gdy wystarczy początek dokumentu (ucina po $maxBytes zamiast zwracać błąd).
 */
function http_get(string $url, int $maxBytes = 3000000, int $timeout = 15, array $headers = [], bool $partial = false): ?string {
    if (!is_http_url($url) || !host_is_public($url)) return null;
    $hasUa = false;
    foreach ($headers as $h) { if (stripos($h, 'user-agent:') === 0) $hasUa = true; }
    if (!$hasUa) $headers[] = 'User-Agent: Mozilla/5.0 (okazje)';
    if (function_exists('curl_init')) {
        $buf = '';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($partial) {
            $opts[CURLOPT_RETURNTRANSFER] = false;
            $opts[CURLOPT_WRITEFUNCTION] = function ($c, $d) use (&$buf, $maxBytes) {
                $buf .= $d;
                return strlen($buf) > $maxBytes ? 0 : strlen($d);
            };
        } else {
            $opts[CURLOPT_NOPROGRESS] = false;
            $opts[CURLOPT_PROGRESSFUNCTION] = function ($r, $dt, $d) use ($maxBytes) { return $d > $maxBytes ? 1 : 0; };
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) return null;
        if ($partial) return $buf !== '' ? substr($buf, 0, $maxBytes) : null;
        return ($body !== false && strlen((string)$body) <= $maxBytes) ? (string)$body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => implode("\r\n", $headers), 'follow_location' => 1, 'max_redirects' => 4]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($body === false) return null;
    if (strlen($body) > $maxBytes) return $partial ? substr($body, 0, $maxBytes) : null;
    return $body;
}

/** Pobiera obraz i sprawdza, że to faktycznie zdjęcie (typ, rozmiar, proporcje). @return array{bin:string,ext:string}|null */
function image_from_url(string $src, int $minSide = 0): ?array {
    if (!is_http_url($src)) return null;
    $GLOBALS['img_last_err'] = '';
    // bez AVIF w Accept, bo część CDN-ów odsyła wtedy format, którego nie zapisujemy; Referer z domeny obrazka omija prosty hotlink-protection
    $origin = (parse_url($src, PHP_URL_SCHEME) ?: 'https') . '://' . parse_url($src, PHP_URL_HOST) . '/';
    $bin = http_get($src, 4000000, 15, ['Accept: image/webp,image/jpeg,image/png,image/*;q=0.8', 'User-Agent: ' . BROWSER_UA, 'Referer: ' . $origin]);
    if ($bin === null) { $GLOBALS['img_last_err'] = 'pobranie nie powiodło się (blokada, kod inny niż 2xx lub za duży plik)'; return null; }
    if (strlen($bin) < 500) { $GLOBALS['img_last_err'] = 'odpowiedź za krótka (' . strlen($bin) . ' B)'; return null; }
    $info = @getimagesizefromstring($bin);
    if (!$info) { $GLOBALS['img_last_err'] = 'to nie jest obraz'; return null; }
    $ext = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif',
        default => null,
    };
    if ($ext === null) { $GLOBALS['img_last_err'] = 'nieobsługiwany format obrazu (typ ' . ($info[2] ?? '?') . ')'; return null; }
    if ($minSide > 0) {
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        if ($w < $minSide || $h < $minSide) { $GLOBALS['img_last_err'] = "za mały ({$w}x{$h})"; return null; }
        $ratio = $w / max(1, $h);
        if ($ratio > 2.6 || $ratio < 0.38) { $GLOBALS['img_last_err'] = "proporcje {$w}x{$h}"; return null; } // banery i paski, nie zdjęcia produktu
    }
    return ['bin' => $bin, 'ext' => $ext];
}

function save_image(array $im, string $id): ?string {
    if (!is_dir(__DIR__ . '/img')) @mkdir(__DIR__ . '/img', 0755, true);
    foreach (glob(__DIR__ . '/img/' . $id . '.*') ?: [] as $f) { @unlink($f); }
    $path = __DIR__ . "/img/$id.{$im['ext']}";
    return file_put_contents($path, $im['bin'], LOCK_EX) !== false ? "img/$id.{$im['ext']}" : null;
}

function fetch_image(?string $src, string $id): ?string {
    foreach (glob(__DIR__ . '/img/' . $id . '.*') ?: [] as $f) { return 'img/' . basename($f); }
    if (!$src) return null;
    $im = image_from_url($src);
    return $im ? save_image($im, $id) : null;
}

/* ---------- wyszukiwanie zdjęć dla ofert bez zdjęcia ---------- */

const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

function abs_url(string $href, string $base): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, 'data:')) return null;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return null;
    $scheme = $b['scheme'] ?? 'https';
    if (preg_match('#^https?://#i', $href)) return $href;
    if (str_starts_with($href, '//')) return $scheme . ':' . $href;
    $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '/')) return $origin . $href;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $origin . $dir . $href;
}

function json_ld_images(mixed $node, array &$out, int $depth = 0): void {
    if ($depth > 6 || !is_array($node)) return;
    foreach ($node as $k => $v) {
        if ($k === 'image' || $k === 'thumbnailUrl') {
            foreach ((is_array($v) && array_is_list($v)) ? $v : [$v] as $im) {
                if (is_string($im)) $out[] = $im;
                elseif (is_array($im) && isset($im['url']) && is_string($im['url'])) $out[] = $im['url'];
            }
        } elseif (is_array($v)) {
            json_ld_images($v, $out, $depth + 1);
        }
    }
}

/** Adresy zdjęcia z kodu strony: og:image, twitter:image, image_src, JSON-LD, itemprop. */
function image_candidates(string $html, string $base): array {
    $html = substr($html, 0, 900000);
    $prio = [];
    if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            $attr = [];
            if (preg_match_all('/([a-zA-Z:_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $am, PREG_SET_ORDER)) {
                foreach ($am as $a) { $attr[strtolower($a[1])] = $a[2] !== '' ? $a[2] : ($a[3] !== '' ? $a[3] : ($a[4] ?? '')); }
            }
            $key = strtolower($attr['property'] ?? $attr['name'] ?? $attr['itemprop'] ?? '');
            $val = $attr['content'] ?? '';
            if ($val === '') continue;
            $rank = match ($key) {
                'og:image:secure_url' => 0, 'og:image' => 1, 'og:image:url' => 1,
                'twitter:image', 'twitter:image:src' => 2, 'image' => 4, default => null,
            };
            if ($rank !== null) $prio[] = [$rank, $val];
        }
    }
    if (preg_match('/<link\b[^>]*rel=["\']image_src["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m)) $prio[] = [3, $m[1]];
    if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ld)) {
        foreach ($ld[1] as $blob) {
            $j = json_decode(trim(html_entity_decode($blob, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
            $found = [];
            if (is_array($j)) json_ld_images($j, $found);
            foreach ($found as $f) $prio[] = [3, $f];
        }
    }
    usort($prio, fn($a, $b) => $a[0] <=> $b[0]);
    $out = [];
    foreach ($prio as [, $u]) {
        $abs = abs_url($u, $base);
        if (!$abs || !is_http_url($abs)) continue;
        if (preg_match('#(logo|sprite|favicon|placeholder|blank|pixel|avatar|default[-_]?image|no[-_]?image)#i', $abs)) continue;
        $out[$abs] = true;
    }
    return array_keys($out);
}

function title_query(string $title): string {
    $t = preg_replace('/\([^)]*\)/u', ' ', $title);
    $t = preg_replace('/\b\d+\s*(szt\.?|x|×)\b/iu', ' ', (string)$t);
    $t = preg_replace('/\s+/u', ' ', trim((string)$t));
    return mb_substr($t, 0, 90);
}

/** Adresy obrazów z kodu strony wyników wyszukiwarki obrazów (pole murl). */
function parse_search_images(string $html): array {
    $out = [];
    if (preg_match_all('/murl(?:&quot;|")\s*:\s*(?:&quot;|")(https?:[^"&]+?)(?:&quot;|")/i', $html, $m)) {
        foreach ($m[1] as $u) {
            $u = html_entity_decode(str_replace('\\/', '/', $u), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (is_http_url($u) && !preg_match('#(logo|sprite|placeholder|pinterest|facebook|instagram)#i', $u)) $out[$u] = true;
        }
    }
    return array_slice(array_keys($out), 0, 8);
}

/** Wyniki wyszukiwarki obrazów (ostateczność, gdy strona oferty nie daje zdjęcia). */
function search_image_candidates(string $query): array {
    if ($query === '') return [];
    $html = http_get('https://www.bing.com/images/search?q=' . rawurlencode($query) . '&adlt=strict&first=1', 1500000, 12,
        ['User-Agent: ' . BROWSER_UA, 'Accept: text/html', 'Accept-Language: pl-PL,pl;q=0.9'], true);
    return $html ? parse_search_images($html) : [];
}

/**
 * Szuka zdjęcia oferty: najpierw na stronie oferty, potem w wyszukiwarce obrazów.
 * @return array{path:string,via:string}|null  via: 'page' (ze strony oferty) lub 'search' (poglądowe)
 */
function resolve_image(array $o, ?bool $useSearch = null): ?array {
    // Wyszukiwarka obrazów daje zdjęcia przypadkowe, więc jest domyślnie wyłączona (config.php: 'image_search' => true).
    $useSearch ??= (bool)cfg('image_search', false);
    $id = (string)($o['id'] ?? '');
    $url = (string)($o['url'] ?? '');
    if ($id === '' || $url === '') return null;
    $html = http_get($url, 1200000, 12, [
        'User-Agent: ' . BROWSER_UA, 'Accept: text/html,application/xhtml+xml', 'Accept-Language: pl-PL,pl;q=0.9',
    ], true);
    if ($html) {
        foreach (array_slice(image_candidates($html, $url), 0, 5) as $u) {
            $im = image_from_url($u, 150);
            if ($im && ($p = save_image($im, $id))) return ['path' => $p, 'via' => 'page'];
        }
    }
    if ($useSearch) {
        foreach (search_image_candidates(title_query((string)($o['title'] ?? ''))) as $u) {
            $im = image_from_url($u, 200);
            if ($im && ($p = save_image($im, $id))) return ['path' => $p, 'via' => 'search'];
        }
    }
    return null;
}

/** Ustawia zdjęcie oferty (baza ofert i kopia w ulubionych). Wywołuj pod blokadą. */
function apply_image(string $id, string $path, string $via, bool $force = false): void {
    $db = jread('offers', ['offers' => [], 'batches' => []]);
    if (isset($db['offers'][$id]) && ($force || empty($db['offers'][$id]['image']))) {
        $db['offers'][$id]['image'] = $path;
        $db['offers'][$id]['image_via'] = $via;
        jwrite('offers', $db);
    }
    $fav = jread('favorites', []);
    if (isset($fav[$id]) && ($force || empty($fav[$id]['offer']['image']))) {
        $fav[$id]['offer']['image'] = $path;
        $fav[$id]['offer']['image_via'] = $via;
        jwrite('favorites', $fav);
    }
}

/**
 * Usuwa zdjęcia znalezione wyszukiwarką obrazów (image_via = 'search'), gdy ta jest wyłączona.
 * Tanie wywołanie: bez takich zdjęć kończy się po odczycie plików, bez blokady.
 * @return int liczba usuniętych zdjęć
 */
function purge_search_images(): int {
    if (cfg('image_search', false)) return 0;
    $has = function (array $rows, bool $fav): bool {
        foreach ($rows as $r) { $o = $fav ? ($r['offer'] ?? []) : $r; if (($o['image_via'] ?? '') === 'search') return true; }
        return false;
    };
    if (!$has(jread('offers', ['offers' => []])['offers'] ?? [], false) && !$has(jread('favorites', []), true)) return 0;
    return locked(function () {
        $n = 0;
        $drop = function (array &$o) use (&$n) {
            if (($o['image_via'] ?? '') !== 'search') return;
            $p = (string)($o['image'] ?? '');
            if (preg_match('#^img/[a-f0-9]{12}\.(jpg|png|webp|gif)$#', $p) && is_file(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
            $o['image'] = null; $o['image_via'] = null; $n++;
        };
        $db = jread('offers', ['offers' => [], 'batches' => []]);
        foreach ($db['offers'] as $id => $_) $drop($db['offers'][$id]);
        $fav = jread('favorites', []);
        foreach ($fav as $id => $_) { if (isset($fav[$id]['offer'])) $drop($fav[$id]['offer']); }
        if ($n) { jwrite('offers', $db); jwrite('favorites', $fav); jwrite('imgtries', []); }
        return $n;
    });
}

/** Oferty bez zdjęcia: z aktualnych partii (14 dni) oraz wszystkie zapisane z serduszkiem. @return array<string,array> */
function offers_missing_image(): array {
    $db = jread('offers', ['offers' => [], 'batches' => []]);
    $cut = date('c', time() - retention_days() * 86400);
    $active = [];
    foreach ($db['batches'] as $b) { if ($b['ts'] >= $cut) foreach ($b['offer_ids'] as $id) $active[(string)$id] = true; }
    $out = [];
    foreach ($active as $id => $_) {
        $o = $db['offers'][$id] ?? null;
        if ($o && empty($o['image'])) $out[(string)$id] = $o;
    }
    foreach (jread('favorites', []) as $id => $f) {
        if (empty($f['offer']['image'])) $out[(string)$id] = $f['offer'];
    }
    return $out;
}

/**
 * Cykliczne uzupełnianie zdjęć (cron). Każda oferta ma do 6 prób, między próbami minimum 50 minut.
 * @return int liczba ofert, którym znaleziono zdjęcie
 */
function resolve_missing_images(int $limit = 8, bool $ignoreBackoff = false, ?callable $log = null): int {
    $tries = jread('imgtries', []);
    $now = time();
    $done = 0; $n = 0;
    foreach (offers_missing_image() as $id => $o) {
        $id = (string)$id;
        $t = $tries[$id] ?? ['n' => 0, 't' => 0];
        if (!$ignoreBackoff && ($t['n'] >= 6 || $now - (int)$t['t'] < 3000)) continue;
        if ($n++ >= $limit) break;
        $o['id'] = $id;
        $r = resolve_image($o);
        if ($log) $log('zdjęcie ' . mb_substr((string)($o['title'] ?? $id), 0, 45) . ': ' . ($r ? $r['via'] : 'nie znaleziono'));
        if ($r) {
            locked(function () use ($id, $r) { apply_image($id, $r['path'], $r['via']); });
            unset($tries[$id]);
            $done++;
        } else {
            $tries[$id] = ['n' => (int)$t['n'] + 1, 't' => $now];
        }
    }
    $keep = array_keys(offers_missing_image());
    $tries = array_intersect_key($tries, array_flip(array_map('strval', $keep)));
    jwrite('imgtries', $tries);
    return $done;
}

/* ---------- kategorie ---------- */

function default_categories(): array {
    return [
        [
            'key' => 'komputery', 'name' => 'Komputery i gadżety', 'custom' => false,
            'aliases' => ['komputer i gadżety', 'komputery i gadżety', 'komputery'],
            'what' => 'Sprzęt komputerowy i drobiazgi do niego. Nacisk na NAS (QNAP, Synology, Ugreen, dyski i akcesoria), mini PC (ThinkCentre Tiny, NUC, Beelink, Minisforum), retro komputery oraz Lenovo ThinkPad z akcesoriami: zasilacze, stacje dokujące, dyski M.2, pamięci.',
            'how' => 'Wyszukiwanie trzy razy dziennie (6:00, 14:00, 20:00) w Pepper, x-kom, Morele, Komputronik i serwisach technicznych. Osobno szukany jest poleasingowy ThinkPad X1 Carbon z 32 GB RAM: przy ofercie podawana jest generacja, procesor, dysk, stan i gwarancja, a gdy RAM nie jest potwierdzony w ofercie, jest o tym uwaga. Oferty używane i poleasingowe są oznaczane. Kategoria działa do 31.12.2026.',
        ],
        [
            'key' => 'apple', 'name' => 'Apple', 'custom' => false, 'aliases' => ['apple'],
            'what' => 'Mac mini, Mac Studio, MacBooki, iPhone, iPody, AirPods, Apple Watch, Apple TV oraz drobne akcesoria do MacBooka i Maca mini: huby i stacje USB-C/Thunderbolt, kable, ładowarki, etui, dyski zewnętrzne. Bez iPadów i akcesoriów do nich.',
            'how' => 'Źródła: iSpot, iMad, Jabłka Adama, Apple Refurbished, x-kom, Media Expert i ThinkApple. Sprzęt odnowiony jest uwzględniany i oznaczany. Kategoria działa do 31.12.2026.',
        ],
        [
            'key' => 'smarthome', 'name' => 'Smart home', 'custom' => false, 'aliases' => ['smart home', 'smarthome'],
            'what' => 'Smart home bez Zigbee: żarówki, gniazdka, czujniki, kamery, urządzenia z Matterem i przyjazne Home Assistant.',
            'how' => 'Wyszukiwanie w Pepper, Amazon, Allegro, Media Expert i serwisach o smart home. Produkty Zigbee trafiają do osobnej kategorii. Kategoria działa do 31.12.2026.',
        ],
        [
            'key' => 'dzialka', 'name' => 'Działka i narzędzia', 'custom' => false, 'aliases' => ['działka i narzędzia', 'dzialka'],
            'what' => 'Drobne narzędzia na działkę: akumulatorowe wkrętarki i wiertarki, mierniki, zestawy bitów, latarki.',
            'how' => 'Źródła: Castorama, Leroy Merlin, Pepper, Allegro. Liczy się realnie niska cena, nie sam rabat od ceny wyjściowej. Kategoria działa do 31.12.2026.',
        ],
        [
            'key' => 'foto', 'name' => 'Foto i video', 'custom' => false, 'aliases' => ['foto i video', 'foto'],
            'what' => 'Statywy, karty pamięci, filtry, oświetlenie, mikrofony, torby i akcesoria do kamer sportowych.',
            'how' => 'Źródła: Fotojoker, Cyfrowe.pl, Pepper, Fotopolis, Fotoblogia. Kategoria działa do 31.12.2026.',
        ],
        [
            'key' => 'zigbee', 'name' => 'Zigbee', 'custom' => false, 'aliases' => ['zigbee'],
            'what' => 'Sprzęt Zigbee dostępny w Polsce, z priorytetem dla zgodności z Home Assistant (ZHA, Zigbee2MQTT): koordynatory, czujniki, przełączniki, żarówki, gniazdka z pomiarem energii, piloty.',
            'how' => 'Źródła: Botland, Domadoo, Ceneo, AliExpress (tylko z ceną w PLN i dostawą do Polski), Pepper. Produkty Wi-Fi, Tuya Wi-Fi i Bluetooth są pomijane. Ceny katalogowe bez potwierdzonej promocji są oznaczane jako katalogowe. Kategoria działa bez daty końcowej.',
        ],
    ];
}

function categories(): array {
    $c = jread('categories', null);
    if (!is_array($c)) {
        $c = default_categories();
        locked(function () use ($c) { if (jread('categories', null) === null) jwrite('categories', $c); });
    }
    return $c;
}

function match_category(string $raw, array $cats): string {
    $s = slug($raw);
    if ($s === '') return 'inne';
    foreach ($cats as $c) {
        if ($c['key'] === $s || slug($c['name']) === $s) return $c['key'];
        foreach ($c['aliases'] ?? [] as $a) { if (slug((string)$a) === $s) return $c['key']; }
    }
    return 'inne';
}

/* ---------- oferty i partie ---------- */

function norm_offer(array $o, array $cats): ?array {
    $url = trim((string)($o['url'] ?? ''));
    $title = trim((string)($o['title'] ?? ''));
    if ($title === '' || !is_http_url($url)) return null;
    $str = function (string $k, int $max) use ($o): ?string {
        if (!isset($o[$k]) || $o[$k] === '' || !is_scalar($o[$k])) return null;
        return mb_substr(trim((string)$o[$k]), 0, $max);
    };
    $price = $str('price', 80);
    $orig = $str('original_price', 80);
    $img = $str('image_url', 1000);
    $conf = $str('confidence', 10);
    return [
        'id'             => oid($url),
        'category'       => match_category((string)($o['category'] ?? ''), $cats),
        'title'          => mb_substr($title, 0, 300),
        'url'            => $url,
        'price'          => $price,
        'price_num'      => parse_price($price),
        'original_price' => $orig,
        'original_num'   => parse_price($orig),
        'shop'           => $str('shop', 80),
        'promo_end'      => $str('promo_end', 60),
        'notes'          => $str('notes', 700),
        'confidence'     => in_array($conf, ['high', 'medium', 'low'], true) ? $conf : null,
        'image_src'      => ($img !== null && is_http_url($img)) ? $img : null,
    ];
}

/**
 * Wczytuje jedną partię ofert (wynik jednego wyszukiwania).
 * @return array{status:string,count?:int,batch?:string}
 */
function ingest_batch(array $batch, string $file): array {
    $cats = categories();
    $runTs = isset($batch['run']) ? strtotime((string)$batch['run']) : false;
    if ($runTs === false) $runTs = time();
    if ($runTs < time() - retention_days() * 86400) return ['status' => 'too_old'];

    $offers = [];
    foreach (($batch['offers'] ?? []) as $o) {
        if (!is_array($o)) continue;
        $n = norm_offer($o, $cats);
        if ($n && !isset($offers[$n['id']])) $offers[$n['id']] = $n;
    }
    $hints = is_array($batch['images'] ?? null) ? $batch['images'] : [];
    if (!$offers && !$hints) return ['status' => 'empty'];

    $known = jread('offers', ['offers' => [], 'batches' => []]);
    foreach ($known['batches'] as $b) { if (($b['file'] ?? '') === $file) return ['status' => 'duplicate']; }
    if (in_array($file, $known['img_files'] ?? [], true)) return ['status' => 'duplicate'];

    // podpowiedzi zdjęć (np. od zadania wyszukującego) dla ofert, które nadal nie mają zdjęcia
    $hinted = $hints ? apply_image_hints($hints) : 0;
    if (!$offers) {
        locked(function () use ($file) {
            $db = jread('offers', ['offers' => [], 'batches' => []]);
            $db['img_files'] = array_slice(array_merge($db['img_files'] ?? [], [$file]), -300);
            jwrite('offers', $db);
        });
        return ['status' => 'images', 'count' => $hinted];
    }

    // zdjęcia pobieramy przed blokadą, bo to najwolniejszy krok
    foreach ($offers as $id => $o) { $offers[$id]['image'] = fetch_image($o['image_src'], (string)$id); }

    return locked(function () use ($offers, $file, $runTs) {
        $db = jread('offers', ['offers' => [], 'batches' => []]);
        foreach ($db['batches'] as $b) { if (($b['file'] ?? '') === $file) return ['status' => 'duplicate']; }
        $bid = date('Ymd-Hi', $runTs);
        $ids = array_column($db['batches'], 'id');
        for ($i = 2; in_array($bid, $ids, true); $i++) { $bid = date('Ymd-Hi', $runTs) . "-$i"; }
        $iso = date('c', $runTs);

        foreach ($offers as $id => $o) {
            $id = (string)$id;
            $old = $db['offers'][$id] ?? null;
            $img = $o['image'] ?? null;
            unset($o['image']);
            $o['image'] = $img ?: ($old['image'] ?? null);
            $o['image_via'] = ($o['image'] && ($old['image'] ?? null) === $o['image']) ? ($old['image_via'] ?? 'src') : ($o['image'] ? 'src' : null);
            $o['first_batch'] = $old['first_batch'] ?? $bid;
            $o['first_seen'] = $old['first_seen'] ?? $iso;
            $o['last_seen'] = max($old['last_seen'] ?? '', $iso);
            $db['offers'][$id] = $o;
        }
        $db['batches'][] = ['id' => $bid, 'ts' => $iso, 'file' => $file, 'offer_ids' => array_map('strval', array_keys($offers))];
        usort($db['batches'], fn($a, $b) => strcmp($b['ts'], $a['ts']));
        prune_db($db);
        jwrite('offers', $db);
        return ['status' => 'ok', 'count' => count($offers), 'batch' => $bid];
    });
}

/** Zdjęcia podane razem z partią: [{url, image_url}]. Dotyczą tylko ofert, które jeszcze nie mają zdjęcia. */
function apply_image_hints(array $hints): int {
    $GLOBALS['img_hint_errs'] = [];
    $missing = offers_missing_image();
    $n = 0;
    foreach (array_slice($hints, 0, 60) as $h) {
        if (!is_array($h)) continue;
        $url = trim((string)($h['url'] ?? ''));
        $src = trim((string)($h['image_url'] ?? ''));
        if (!is_http_url($url) || !is_http_url($src)) continue;
        $id = oid($url);
        if (!isset($missing[$id])) { $GLOBALS['img_hint_errs'][] = $id . ': oferta nie czeka na zdjęcie (brak w bazie lub ma już zdjęcie)'; continue; }
        $im = image_from_url($src, 120);
        if (!$im || !($path = save_image($im, $id))) {
            $GLOBALS['img_hint_errs'][] = $id . ': ' . ($GLOBALS['img_last_err'] ?? '') . ' [' . $src . ']';
            continue;
        }
        locked(function () use ($id, $path) { apply_image($id, $path, 'src'); });
        $n++;
    }
    return $n;
}

/** Usuwa partie starsze niż retention_days i oferty, których nie ma już w żadnej partii. */
function prune_db(array &$db): int {
    $cut = date('c', time() - retention_days() * 86400);
    $db['batches'] = array_values(array_filter($db['batches'], fn($b) => $b['ts'] >= $cut));
    $keep = [];
    foreach ($db['batches'] as $b) foreach ($b['offer_ids'] as $id) $keep[$id] = true;
    $fav = jread('favorites', []);
    $removed = 0;
    foreach (array_map('strval', array_keys($db['offers'])) as $id) {
        if (isset($keep[$id])) continue;
        $img = $db['offers'][$id]['image'] ?? null;
        if ($img && !isset($fav[$id]) && is_file(__DIR__ . '/' . $img)) @unlink(__DIR__ . '/' . $img);
        unset($db['offers'][$id]);
        $removed++;
    }
    return $removed;
}

function runs_list(array $db): array {
    $out = [];
    foreach ($db['batches'] as $b) {
        $t = strtotime($b['ts']);
        $out[] = ['id' => $b['id'], 'ts' => $b['ts'], 'day' => date('Y-m-d', $t), 'time' => date('H:i', $t), 'count' => count($b['offer_ids'])];
    }
    return $out;
}

/** @return array{offers:array,meta:array} */
function offers_view(string $run): array {
    $db = jread('offers', ['offers' => [], 'batches' => []]);
    $cut = date('c', time() - retention_days() * 86400);
    $batches = array_values(array_filter($db['batches'], fn($b) => $b['ts'] >= $cut));
    usort($batches, fn($a, $b) => strcmp($b['ts'], $a['ts']));
    $sel = [];
    $label = '';
    if ($run === 'all') {
        $sel = $batches; $label = 'all';
    } elseif (str_starts_with($run, 'day:')) {
        $d = substr($run, 4);
        $sel = array_values(array_filter($batches, fn($b) => date('Y-m-d', strtotime($b['ts'])) === $d)); $label = $run;
    } elseif ($run === 'latest' || $run === '') {
        $sel = $batches ? [$batches[0]] : []; $label = $sel ? $sel[0]['id'] : 'latest';
    } else {
        $sel = array_values(array_filter($batches, fn($b) => $b['id'] === $run)); $label = $run;
    }
    $fav = jread('favorites', []);
    $newest = [];
    $rows = [];
    foreach ($sel as $b) {
        foreach ($b['offer_ids'] as $id) {
            if (!isset($db['offers'][$id])) continue;
            $newest[$id] = true;
        }
    }
    foreach (array_keys($newest) as $id) {
        $o = $db['offers'][$id];
        $o['fav'] = isset($fav[$id]);
        $o['new'] = count($sel) === 1 && ($o['first_batch'] ?? '') === $sel[0]['id'];
        unset($o['image_src']);
        $rows[] = $o;
    }
    usort($rows, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
    $meta = [
        'run' => $label,
        'from' => $sel ? $sel[count($sel) - 1]['ts'] : null,
        'to' => $sel ? $sel[0]['ts'] : null,
        'batches' => count($sel),
    ];
    return ['offers' => $rows, 'meta' => $meta];
}

function sources_list(): array {
    $s = jread('sources', []);
    return is_array($s) ? $s : [];
}
