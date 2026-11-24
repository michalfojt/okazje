<?php
// Zmiana hasła: php passwd.php 'nowe-haslo'
// Skrypt wypisuje hash do wklejenia w config.php (klucz pass_hash).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$p = $argv[1] ?? '';
if (strlen($p) < 10) { fwrite(STDERR, "Podaj hasło o długości min. 10 znaków.\n"); exit(1); }
echo password_hash($p, PASSWORD_DEFAULT), "\n";
