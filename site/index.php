<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $err = 'Sesja formularza wygasła. Spróbuj jeszcze raz.';
    } elseif ($act === 'login') {
        [$ok, $msg] = login_try(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''));
        if ($ok) { header('Location: ./'); exit; }
        $err = $msg;
    } elseif ($act === 'logout') {
        logout();
        header('Location: ./');
        exit;
    }
}

$user = auth_user();
$owner = $user !== null && is_owner();
$v = fn(string $f) => (string)(@filemtime(__DIR__ . "/assets/$f") ?: 1);
$e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tag = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>';
?>
<!doctype html>
<html lang="pl" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="color-scheme" content="dark light">
<meta name="csrf" content="<?= $e(csrf()) ?>">
<title>okazje</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238aa4ff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z'/%3E%3C/svg%3E">
<script>
(function () {
  var t = null;
  try { t = localStorage.getItem('okz-theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') t = window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
<link rel="stylesheet" href="assets/app3.css?v=<?= $v('app3.css') ?>">
</head>
<?php if (!$user): ?>
<body class="login-page">
<main class="login">
  <div class="brand big"><?= $tag ?><span>okazje</span></div>
  <form method="post" class="login-card" autocomplete="on">
    <input type="hidden" name="act" value="login">
    <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>">
    <label for="u">Login</label>
    <input id="u" name="user" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    <label for="p">Hasło</label>
    <input id="p" name="pass" type="password" autocomplete="current-password" required>
    <?php if ($err): ?><p class="form-err" role="alert"><?= $e($err) ?></p><?php endif; ?>
    <button type="submit" class="btn primary">Zaloguj</button>
  </form>
</main>
</body>
<?php else: ?>
<body<?= $owner ? '' : ' data-limited' ?>>
<header class="top">
  <a class="brand" href="<?= $owner ? '#/oferty' : '#/produkty' ?>"><?= $tag ?><span>okazje</span></a>
  <nav class="tabs" aria-label="Sekcje">
    <?php if ($owner): ?>
    <a href="#/oferty" data-tab="oferty">Oferty</a>
    <a href="#/wybrane" data-tab="wybrane">Wybrane<span class="count" id="nFav" hidden></span></a>
    <?php endif; ?>
    <a href="#/produkty" data-tab="produkty">Moje produkty</a>
    <a href="#/gazetki" data-tab="gazetki">Gazetki</a>
    <a href="#/kategorie" data-tab="kategorie">Kategorie</a>
  </nav>
  <div class="tools">
    <button class="icon-btn" id="theme" type="button" aria-label="Przełącz motyw"></button>
    <form method="post" class="logout">
      <input type="hidden" name="act" value="logout">
      <input type="hidden" name="csrf" value="<?= $e(csrf()) ?>">
      <?php if ($owner): ?><a class="btn ghost" href="users.php">Użytkownicy</a><?php endif; ?>
      <span class="who"><?= $e($user) ?></span>
      <button class="btn ghost" type="submit">Wyloguj</button>
    </form>
  </div>
</header>
<main id="view" aria-live="polite"></main>
<div id="toasts" role="status"></div>
<script src="assets/app.js?v=<?= $v('app.js') ?>"></script>
<script src="assets/app3.js?v=<?= $v('app3.js') ?>"></script>
</body>
<?php endif; ?>
</html>
