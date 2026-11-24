<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

if (auth_user() === null) { header('Location: ./'); exit; }

const DEPLOY_MAX_FILES = 60;
const DEPLOY_MAX_BYTES = 2097152; // 2 MB na plik

/* ---------- logika wdrażania ---------- */

/** Biała lista ścieżek (względem katalogu strony). config.php jest wykluczony jawnie. */
function dp_allowed(string $p): bool {
    if ($p === 'config.php') return false;
    return (bool)preg_match('/^(?:[a-z0-9_]+\.php|assets\/[a-z0-9_]+\.(?:js|css)|\.htaccess)$/D', $p);
}

function dp_base(): string {
    $repo = (string)cfg('deploy_repo', 'michalfojt/okazje');
    $ref = (string)cfg('deploy_ref', 'main');
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo) || !preg_match('#^[A-Za-z0-9_./-]+$#D', $ref) || str_contains($ref, '..')) {
        throw new RuntimeException('Niepoprawne deploy_repo albo deploy_ref w config.php.');
    }
    $base = (string)cfg('deploy_base', 'https://raw.githubusercontent.com/' . $repo . '/' . $ref . '/site/');
    return rtrim($base, '/') . '/';
}

/** Pobranie z parametrem przeciw cache CDN. Zwraca treść albo null. */
function dp_fetch(string $url, int $max): ?string {
    $url .= (str_contains($url, '?') ? '&' : '?') . 't=' . time();
    return http_get($url, $max, 20, ['Cache-Control: no-cache', 'Pragma: no-cache']);
}

/** @return array{0:?array{generated:string,commit:string,files:array<string,string>},1:string} */
function dp_manifest(string $base): array {
    $raw = dp_fetch($base . 'MANIFEST.json', 1000000);
    if ($raw === null) return [null, 'Nie udało się pobrać manifestu. Sprawdź połączenie, repozytorium i gałąź.'];
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['files']) || !is_array($j['files']) || $j['files'] === [] || array_is_list($j['files'])) {
        return [null, 'Manifest jest niepoprawny: brak pola files z listą plików.'];
    }
    if (count($j['files']) > DEPLOY_MAX_FILES) {
        return [null, 'Manifest zawiera za dużo plików (limit ' . DEPLOY_MAX_FILES . ').'];
    }
    $files = [];
    foreach ($j['files'] as $p => $h) {
        if (!is_string($h) || !preg_match('/^[0-9a-f]{64}$/D', $h)) {
            return [null, 'Manifest zawiera niepoprawną sumę sha256 dla pliku ' . mb_substr((string)$p, 0, 80) . '.'];
        }
        $files[(string)$p] = $h;
    }
    ksort($files, SORT_STRING);
    return [[
        'generated' => is_string($j['generated'] ?? null) ? mb_substr($j['generated'], 0, 40) : '?',
        'commit'    => is_string($j['commit'] ?? null) ? mb_substr($j['commit'], 0, 40) : '',
        'files'     => $files,
    ], ''];
}

/**
 * Porównanie z plikami na serwerze.
 * st: zgodny | inny | brak | odrzucony (ścieżka spoza białej listy)
 * @return list<array{path:string,st:string,want:string,have:string}>
 */
function dp_rows(array $files): array {
    $rows = [];
    foreach ($files as $p => $want) {
        $p = (string)$p;
        if (!dp_allowed($p)) { $rows[] = ['path' => $p, 'st' => 'odrzucony', 'want' => $want, 'have' => '']; continue; }
        $f = __DIR__ . '/' . $p;
        $have = is_file($f) ? (string)hash_file('sha256', $f) : '';
        $st = $have === '' ? 'brak' : (hash_equals($want, $have) ? 'zgodny' : 'inny');
        $rows[] = ['path' => $p, 'st' => $st, 'want' => $want, 'have' => $have];
    }
    return $rows;
}

/** Pobiera, weryfikuje, robi kopię zapasową i zapisuje atomowo jeden plik. @return array{0:bool,1:string} */
function dp_apply(string $base, string $path, string $sha, string $ts): array {
    $body = dp_fetch($base . $path, DEPLOY_MAX_BYTES);
    if ($body === null) return [false, 'pobranie nie powiodło się albo plik przekracza 2 MB, nie zapisano'];
    if (!hash_equals($sha, hash('sha256', $body))) return [false, 'suma sha256 pobranej treści nie zgadza się z manifestem, nie zapisano'];

    $dest = __DIR__ . '/' . $path;
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return [false, 'nie można utworzyć katalogu docelowego'];

    $existed = is_file($dest);
    if ($existed) {
        $bk = data_dir() . '/backup/' . $ts . '/' . $path;
        $bd = dirname($bk);
        if (!is_dir($bd) && !@mkdir($bd, 0750, true) && !is_dir($bd)) return [false, 'nie można utworzyć katalogu kopii zapasowej, nie zapisano'];
        if (!@copy($dest, $bk)) return [false, 'kopia zapasowa nie powiodła się, nie zapisano'];
    }

    $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp'; // ten sam katalog, więc rename jest atomowy
    if (@file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) { @unlink($tmp); return [false, 'zapis pliku tymczasowego nie powiódł się']; }
    @chmod($tmp, $existed ? ((int)@fileperms($dest) & 0777) : 0644);
    if (!@rename($tmp, $dest)) { @unlink($tmp); return [false, 'podmiana pliku nie powiodła się']; }
    if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) { @opcache_invalidate($dest, true); }
    return [true, $existed ? 'zaktualizowano, kopia zapasowa: backup/' . $ts : 'utworzono nowy plik'];
}

/**
 * Wdraża pliki o statusie inny/brak. deploy.php zawsze na końcu.
 * @return array{res:list<array{path:string,st:string,msg:string}>,same:int}
 */
function dp_run(string $base, array $rows): array {
    $ts = date('Ymd-His');
    $res = [];
    $todo = [];
    $same = 0;
    foreach ($rows as $r) {
        if ($r['st'] === 'odrzucony') { $res[] = ['path' => $r['path'], 'st' => 'odrzucony', 'msg' => 'ścieżka spoza białej listy, pominięto']; continue; }
        if ($r['st'] === 'zgodny') { $same++; continue; }
        $todo[] = $r;
    }
    usort($todo, fn($a, $b) => ($a['path'] === 'deploy.php') <=> ($b['path'] === 'deploy.php'));
    foreach ($todo as $r) {
        try { [$ok, $msg] = dp_apply($base, $r['path'], $r['want'], $ts); }
        catch (Throwable $ex) { [$ok, $msg] = [false, 'błąd: ' . $ex->getMessage()]; }
        $res[] = ['path' => $r['path'], 'st' => $ok ? 'ok' : 'blad', 'msg' => $msg];
    }
    return ['res' => $res, 'same' => $same];
}

/* ---------- obsługa żądania ---------- */

$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$short = fn(string $h) => $h === '' ? '-' : substr($h, 0, 8);
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$err = '';
$man = null;
$rows = [];
$out = null;

if ($post && !csrf_ok($_POST['csrf'] ?? null)) {
    http_response_code(403);
    $err = 'Sesja formularza wygasła albo żądanie jest niepoprawne. Wróć i spróbuj jeszcze raz.';
} else {
    try {
        $base = dp_base();
        [$man, $err] = dp_manifest($base);
        if ($man) $rows = dp_rows($man['files']);
    } catch (Throwable $ex) {
        $base = '';
        $err = $ex->getMessage();
    }
    if ($post && $man) {
        @set_time_limit(180);
        $lk = @fopen(data_dir() . '/.deploy.lock', 'c');
        if ($lk && flock($lk, LOCK_EX | LOCK_NB)) {
            try { $out = dp_run($base, $rows); } finally { flock($lk, LOCK_UN); fclose($lk); }
        } else {
            $err = 'Wdrożenie już trwa. Spróbuj za chwilę.';
        }
    }
}

$changed = count(array_filter($rows, fn($r) => $r['st'] === 'inny' || $r['st'] === 'brak'));
$icon = ['ok' => '✓', 'blad' => '✕', 'odrzucony' => '⊘'];
$label = ['ok' => 'zapisano', 'blad' => 'błąd', 'odrzucony' => 'odrzucono'];
?>
<!doctype html>
<html lang="pl" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="dark light">
<title>okazje - wdrażanie</title>
<script>
(function () {
  var t = null;
  try { t = localStorage.getItem('okz-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<style>
:root {
  --bg: #0d1014; --s1: #13181e; --s2: #1a2027; --line: #242c35; --line2: #323c48;
  --tx: #e9edf2; --mut: #929dab; --faint: #626d7b; --ac: #8aa4ff; --ac-bg: rgba(138, 164, 255, .13);
  --ok: #4cc38a; --ok-bg: rgba(76, 195, 138, .13); --warn: #e6b155; --warn-bg: rgba(230, 177, 85, .13);
  --heart: #ff6b81; --r: 10px;
  --sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --mono: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
}
html[data-theme="light"] {
  --bg: #f5f6f8; --s1: #ffffff; --s2: #f0f2f5; --line: #e1e5ea; --line2: #cfd5dd;
  --tx: #141a21; --mut: #5b6675; --faint: #8a94a2; --ac: #3358d4; --ac-bg: rgba(51, 88, 212, .09);
  --ok: #0e8a5b; --ok-bg: rgba(14, 138, 91, .1); --warn: #a96a07; --warn-bg: rgba(169, 106, 7, .1);
  --heart: #e0304d;
}
* { box-sizing: border-box; }
html { color-scheme: dark; -webkit-text-size-adjust: 100%; }
html[data-theme="light"] { color-scheme: light; }
body { margin: 0; background: var(--bg); color: var(--tx); font: 400 15px/1.5 var(--sans); -webkit-font-smoothing: antialiased; min-height: 100vh; }
a { color: var(--ac); text-decoration: none; }
a:hover { text-decoration: underline; }
:focus-visible { outline: 2px solid var(--ac); outline-offset: 2px; border-radius: 6px; }
.wrap { max-width: 860px; margin: 0 auto; padding: 28px 18px 60px; }
h1 { font: 600 20px var(--mono); letter-spacing: -.01em; margin: 0 0 4px; }
.sub { color: var(--mut); font-size: 13.5px; margin: 0 0 18px; word-break: break-all; }
.panel { background: var(--s1); border: 1px solid var(--line); border-radius: var(--r); padding: 16px; margin-bottom: 16px; }
.meta { display: flex; flex-wrap: wrap; gap: 6px 22px; color: var(--mut); font-size: 13.5px; }
.meta b { color: var(--tx); font-weight: 500; font-family: var(--mono); }
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th { text-align: left; color: var(--mut); font-weight: 500; font-size: 12.5px; padding: 6px 10px; border-bottom: 1px solid var(--line2); white-space: nowrap; }
td { padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: middle; }
tr:last-child td { border-bottom: 0; }
.mono { font-family: var(--mono); font-size: 13px; }
.sha { color: var(--faint); }
.tag { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 12.5px; font-weight: 500; background: var(--s2); color: var(--mut); border: 1px solid var(--line); }
.tag.zgodny { background: var(--ok-bg); color: var(--ok); border-color: transparent; }
.tag.inny { background: var(--warn-bg); color: var(--warn); border-color: transparent; }
.tag.brak { background: var(--ac-bg); color: var(--ac); border-color: transparent; }
.tag.odrzucony { color: var(--heart); border-color: var(--heart); background: transparent; }
.btn { display: inline-flex; align-items: center; justify-content: center; height: 38px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--line2); background: var(--s1); color: var(--tx); font: 500 14px var(--sans); cursor: pointer; }
.btn:hover { border-color: var(--faint); text-decoration: none; }
.btn.primary { background: var(--ac); border-color: var(--ac); color: #0a0d12; }
.btn[disabled] { opacity: .45; cursor: not-allowed; }
.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 4px; }
.form-err { color: var(--heart); background: color-mix(in srgb, var(--heart) 10%, transparent); border: 1px solid var(--heart); border-radius: 8px; padding: 10px 12px; margin: 0 0 16px; font-size: 14px; }
.hint { color: var(--mut); font-size: 13px; margin: 10px 0 0; }
ul.res { list-style: none; margin: 0; padding: 0; }
ul.res li { display: flex; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--line); align-items: baseline; }
ul.res li:last-child { border-bottom: 0; }
.ic { flex: none; width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }
.ic.ok { background: var(--ok-bg); color: var(--ok); }
.ic.blad { background: color-mix(in srgb, var(--heart) 14%, transparent); color: var(--heart); }
.ic.odrzucony { background: var(--warn-bg); color: var(--warn); }
.res .msg { color: var(--mut); font-size: 13.5px; }
@media (max-width: 520px) { .wrap { padding: 18px 12px 40px; } .hide-s { display: none; } }
</style>
</head>
<body>
<main class="wrap">
  <h1>Wdrażanie z GitHub</h1>
  <p class="sub">Źródło: <span class="mono"><?= $e($base ?? '') ?></span></p>

<?php if ($err): ?>
  <p class="form-err" role="alert"><?= $e($err) ?></p>
<?php endif; ?>

<?php if ($out !== null): ?>
  <section class="panel" aria-label="Wynik wdrożenia">
    <?php if ($out['res']): ?>
    <ul class="res">
      <?php foreach ($out['res'] as $r): ?>
      <li data-st="<?= $e($r['st']) ?>">
        <span class="ic <?= $e($r['st']) ?>" title="<?= $e($label[$r['st']]) ?>"><?= $icon[$r['st']] ?></span>
        <span><span class="mono"><?= $e($r['path']) ?></span><br><span class="msg"><?= $e($r['msg']) ?></span></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="sub" style="margin:0">Nic do wdrożenia, wszystkie pliki są zgodne.</p>
    <?php endif; ?>
    <p class="hint">Bez zmian: <?= (int)$out['same'] ?> plików.</p>
  </section>
  <div class="actions"><a class="btn primary" href="deploy.php">Wróć</a><a href="./">Do strony</a></div>

<?php elseif ($man): ?>
  <section class="panel">
    <div class="meta">
      <span>Manifest wygenerowano: <b><?= $e($man['generated']) ?></b></span>
      <span>Commit: <b><?= $e($man['commit'] !== '' ? substr($man['commit'], 0, 10) : 'brak') ?></b></span>
      <span>Plików: <b><?= count($rows) ?></b></span>
    </div>
  </section>
  <section class="panel tbl-wrap" aria-label="Pliki z manifestu">
    <table>
      <thead><tr><th>Plik</th><th>Status</th><th class="hide-s">Serwer</th><th class="hide-s">Repo</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-st="<?= $e($r['st']) ?>">
          <td class="mono"><?= $e($r['path']) ?></td>
          <td><span class="tag <?= $e($r['st']) ?>"><?= $r['st'] === 'odrzucony' ? 'odrzucony (spoza listy)' : $e($r['st']) ?></span></td>
          <td class="mono sha hide-s"><?= $e($short($r['have'])) ?></td>
          <td class="mono sha hide-s"><?= $e($short($r['want'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <form method="post" action="deploy.php" class="actions">
    <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>">
    <button type="submit" class="btn primary"<?= $changed ? '' : ' disabled' ?>>Wdróż zmienione (<?= $changed ?>)</button>
    <a href="./">Wróć do strony</a>
  </form>
  <p class="hint">Przed podmianą poprzednia wersja każdego pliku trafia do data/backup/. Pliki spoza manifestu nie są ruszane.</p>

<?php else: ?>
  <div class="actions"><a class="btn primary" href="deploy.php">Spróbuj ponownie</a><a href="./">Wróć do strony</a></div>
<?php endif; ?>
</main>
</body>
</html>
