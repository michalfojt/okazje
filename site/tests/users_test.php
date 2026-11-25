<?php
// php users_test.php <katalog_site> <katalog_danych>
define('OKZ', 1);
require $argv[1] . '/lib.php';
ob_start(); boot_session();
$fail = 0;
function ok(bool $c, string $n): void { global $fail; if (!$c) { $fail++; echo "BŁĄD: $n\n"; } }

ok(user_save('Klaudia', 'krotkie') !== null, 'krótkie hasło odrzucone');
ok(user_save('Michal', 'dlugiehaslo123') !== null, 'login właściciela zajęty');
ok(user_save('Klaudia', 'dlugiehaslo123') === null, 'zapis konta');
ok(!str_contains((string)file_get_contents($argv[2] . '/users.json'), 'dlugiehaslo123'), 'tylko hash');
ok(login_try('klaudia', 'zlehaslo1234')[0] === false, 'złe hasło');
ok(login_try('klaudia', 'dlugiehaslo123')[0] === true, 'logowanie bez względu na wielkość liter');
ok(auth_user() === 'Klaudia' && !is_owner(), 'konto ograniczone');
user_delete('Klaudia');
ok(auth_user() === null, 'usunięte konto traci sesję');
ok(login_try('michal', 'testtesttest')[0] === true && is_owner(), 'właściciel');
echo $fail ? "users_test: $fail błędów\n" : "users_test: OK\n";
exit($fail ? 1 : 0);
