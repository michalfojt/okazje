<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib3.php';
require_once __DIR__ . '/lib4.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

if (auth_user() === null) { header('Location: ./'); exit; }

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$err = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $act = (string)($_POST['act'] ?? '');
        $s = snap_settings();
        if ($act === 'save') {
            $repo = trim((string)($_POST['repo'] ?? ''));
            $path = trim((string)($_POST['path'] ?? ''));
            $tok = trim((string)($_POST['token'] ?? ''));
            if (!snap_valid_repo($repo)) $err = 'Repozytorium w formie wlasciciel/nazwa.';
            elseif (!snap_valid_path($path)) $err = 'Niepoprawna ścieżka pliku (bez "..", bez katalogu site/).';
            elseif ($tok !== '' && !snap_valid_token($tok)) $err = 'Token ma niepoprawny format.';
            else {
                $s['repo'] = $repo;
                $s['path'] = $path;
                if ($tok !== '') $s['token'] = $tok;
                $s['last'] = [];
                locked(fn() => jwrite('gh_snapshot', $s));
                $info = $tok !== '' ? 'Zapisano ustawienia i token.' : 'Zapisano ustawienia (token bez zmian).';
            }
        } elseif ($act === 'remove') {
            $s['token'] = '';
            $s['last'] = [];
            locked(fn() => jwrite('gh_snapshot', $s));
            $info = 'Token usunięty z serwera.';
        } elseif ($act === 'push') {
            @set_time_limit(60);
            $r = snap_push(true);
            if ($r['ok']) $info = $r['msg']; else $err = $r['msg'];
        }
    }
}

$s = snap_settings();
$last = is_array($s['last'] ?? null) ? $s['last'] : [];
?>
<!doctype html>
<html lang="pl" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="dark light">
<title>okazje - migawka feedu</title>
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
  <h1>Migawka feedu w GitHub</h1>
  <p class="sub">Zadanie cykliczne odczytuje z repozytorium zaszyfrowaną kopię listy produktów i sklepów. Cron hostingu odnawia ją co 15 minut, gdy dane się zmienią.</p>

<?php if ($err): ?>
  <p class="form-err" role="alert"><?= $e($err) ?></p>
<?php endif; ?>
<?php if ($info): ?>
  <p class="panel" role="status"><?= $e($info) ?></p>
<?php endif; ?>

  <section class="panel">
    <div class="meta">
      <span>Token: <b><?= $s['token'] !== '' ? 'zapisany' : 'brak' ?></b></span>
      <span>Plik: <b><?= $e($s['repo']) ?> / <?= $e($s['path']) ?></b></span>
      <?php if ($last): ?>
      <span>Ostatnia próba: <b><?= $e(date('Y-m-d H:i', (int)($last['at'] ?? 0))) ?></b></span>
      <span>Wynik: <b><?= !empty($last['ok']) ? 'OK' : 'błąd' ?></b></span>
      <?php endif; ?>
    </div>
    <?php if ($last && empty($last['ok'])): ?><p class="hint"><?= $e($last['msg'] ?? '') ?></p><?php endif; ?>
  </section>

  <form method="post" action="snapshot.php" class="panel" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>">
    <input type="hidden" name="act" value="save">
    <p style="margin:0 0 6px"><label for="repo">Repozytorium (wlasciciel/nazwa)</label></p>
    <p style="margin:0 0 12px"><input id="repo" name="repo" value="<?= $e($s['repo']) ?>" class="mono" style="width:100%;height:38px;padding:0 10px" required></p>
    <p style="margin:0 0 6px"><label for="path">Ścieżka pliku w repozytorium</label></p>
    <p style="margin:0 0 12px"><input id="path" name="path" value="<?= $e($s['path']) ?>" class="mono" style="width:100%;height:38px;padding:0 10px" required></p>
    <p style="margin:0 0 6px"><label for="token">Token GitHub (zostaw puste, żeby nie zmieniać)</label></p>
    <p style="margin:0 0 12px"><input id="token" name="token" type="password" value="" class="mono" style="width:100%;height:38px;padding:0 10px" autocomplete="new-password" placeholder="github_pat_..."></p>
    <div class="actions"><button type="submit" class="btn primary">Zapisz</button><a href="./">Wróć do strony</a></div>
    <p class="hint">Token jest zapisany tylko w katalogu danych hostingu i nigdy nie jest wyświetlany ani wysyłany nigdzie poza api.github.com.</p>
  </form>

  <div class="actions">
    <form method="post" action="snapshot.php">
      <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>"><input type="hidden" name="act" value="push">
      <button type="submit" class="btn"<?= $s['token'] === '' ? ' disabled' : '' ?>>Wyślij migawkę teraz</button>
    </form>
    <form method="post" action="snapshot.php">
      <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>"><input type="hidden" name="act" value="remove">
      <button type="submit" class="btn"<?= $s['token'] === '' ? ' disabled' : '' ?>>Usuń token z serwera</button>
    </form>
  </div>
</main>
</body>
</html>
