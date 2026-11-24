<?php
declare(strict_types=1);
define('OKZ', 1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function out(mixed $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $m, int $code = 400): never { out(['error' => $m], $code); }
function text(mixed $v, int $max): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }

if (!auth_user()) fail('Sesja wygasła. Zaloguj się ponownie.', 401);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$a = (string)($_GET['a'] ?? '');
$body = [];
if ($method === 'POST') {
    if (!csrf_ok($_SERVER['HTTP_X_CSRF'] ?? null)) fail('Nieprawidłowy token. Odśwież stronę.', 403);
    $raw = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode((string)$raw, true) ?? []) : [];
    if (!is_array($body)) fail('Nieprawidłowe dane.');
}

try {
    if ($a === 'state') purge_search_images();
    switch ($a) {

        case 'state': {
            $db = jread('offers', ['offers' => [], 'batches' => []]);
            $cut = date('c', time() - retention_days() * 86400);
            $db['batches'] = array_values(array_filter($db['batches'], fn($b) => $b['ts'] >= $cut));
            $fav = jread('favorites', []);
            $counts = []; $favCounts = [];
            $seen = [];
            foreach ($db['batches'] as $b) foreach ($b['offer_ids'] as $id) {
                if (isset($seen[$id]) || !isset($db['offers'][$id])) continue;
                $seen[$id] = true;
                $k = $db['offers'][$id]['category'];
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            foreach ($fav as $f) { $k = $f['offer']['category'] ?? 'inne'; $favCounts[$k] = ($favCounts[$k] ?? 0) + 1; }
            $cats = array_map(function ($c) use ($counts, $favCounts) {
                unset($c['aliases']);
                $c['count'] = $counts[$c['key']] ?? 0;
                $c['fav'] = $favCounts[$c['key']] ?? 0;
                return $c;
            }, categories());
            if (!empty($counts['inne']) || !empty($favCounts['inne'])) {
                $cats[] = ['key' => 'inne', 'name' => 'Inne', 'custom' => false, 'what' => '', 'how' => '', 'count' => $counts['inne'] ?? 0, 'fav' => $favCounts['inne'] ?? 0, 'virtual' => true];
            }
            out([
                'user' => auth_user(), 'today' => date('Y-m-d'), 'retention' => retention_days(),
                'runs' => runs_list($db), 'categories' => $cats, 'fav_ids' => array_keys($fav),
            ]);
        }

        case 'offers': {
            out(offers_view((string)($_GET['run'] ?? 'latest')));
        }

        case 'favorites': {
            $fav = jread('favorites', []);
            $rows = [];
            foreach ($fav as $id => $f) {
                $o = $f['offer']; $o['fav'] = true; $o['added'] = $f['added'] ?? null; unset($o['image_src']);
                $rows[] = $o;
            }
            usort($rows, fn($x, $y) => strcmp((string)$y['added'], (string)$x['added']));
            out(['offers' => $rows]);
        }

        case 'fav': {
            if ($method !== 'POST') fail('Metoda niedozwolona.', 405);
            $id = text($body['id'] ?? '', 20);
            $on = (bool)($body['on'] ?? false);
            if (!preg_match('/^[a-f0-9]{12}$/', $id)) fail('Nieprawidłowe id oferty.');
            $n = locked(function () use ($id, $on) {
                $fav = jread('favorites', []);
                if ($on) {
                    $db = jread('offers', ['offers' => [], 'batches' => []]);
                    if (isset($fav[$id])) return count($fav);
                    if (!isset($db['offers'][$id])) return -1;
                    $fav[$id] = ['added' => date('c'), 'offer' => $db['offers'][$id]];
                } else {
                    $img = $fav[$id]['offer']['image'] ?? null;
                    unset($fav[$id]);
                    // zdjęcie oferty, której nie ma już na liście ofert, nie jest nikomu potrzebne
                    $db = jread('offers', ['offers' => [], 'batches' => []]);
                    if ($img && !isset($db['offers'][$id]) && preg_match('#^img/[a-f0-9]{12}\.(jpg|png|webp|gif)$#', $img) && is_file(__DIR__ . '/' . $img)) {
                        @unlink(__DIR__ . '/' . $img);
                    }
                }
                jwrite('favorites', $fav);
                return count($fav);
            });
            if ($n < 0) fail('Tej oferty nie ma już na liście.', 404);
            out(['ok' => true, 'count' => $n]);
        }

        case 'img_find': {
            if ($method !== 'POST') fail('Metoda niedozwolona.', 405);
            $id = text($body['id'] ?? '', 20);
            if (!preg_match('/^[a-f0-9]{12}$/', $id)) fail('Nieprawidłowe id oferty.');
            $db = jread('offers', ['offers' => [], 'batches' => []]);
            $fav = jread('favorites', []);
            $o = $db['offers'][$id] ?? ($fav[$id]['offer'] ?? null);
            if (!$o) fail('Tej oferty nie ma już na liście.', 404);
            if (!empty($o['image']) && is_file(__DIR__ . '/' . $o['image'])) out(['ok' => true, 'image' => $o['image'], 'via' => $o['image_via'] ?? 'src']);
            $o['id'] = $id;
            @set_time_limit(90);
            $r = resolve_image($o);
            if (!$r) {
                locked(function () use ($id) { $t = jread('imgtries', []); $t[$id] = ['n' => (int)($t[$id]['n'] ?? 0) + 1, 't' => time()]; jwrite('imgtries', $t); });
                fail('Nie udało się znaleźć zdjęcia na stronie oferty (sklep je blokuje albo go nie podaje). Zdjęcie dołoży zadanie wyszukujące oferty, albo oferta zostanie bez zdjęcia.', 404);
            }
            locked(function () use ($id, $r) {
                apply_image($id, $r['path'], $r['via'], true);
                $t = jread('imgtries', []); unset($t[$id]); jwrite('imgtries', $t);
            });
            out(['ok' => true, 'image' => $r['path'], 'via' => $r['via']]);
        }

        case 'cat_save': {
            $key = text($body['key'] ?? '', 40);
            $what = text($body['what'] ?? '', 1500);
            $how = text($body['how'] ?? '', 1500);
            $name = text($body['name'] ?? '', 60);
            $res = locked(function () use ($key, $what, $how, $name) {
                $cats = categories();
                foreach ($cats as &$c) {
                    if ($c['key'] !== $key) continue;
                    $c['what'] = $what; $c['how'] = $how;
                    if ($c['custom'] && $name !== '') $c['name'] = $name;
                    jwrite('categories', $cats);
                    return true;
                }
                return false;
            });
            if (!$res) fail('Nie ma takiej kategorii.', 404);
            out(['ok' => true]);
        }

        case 'cat_add': {
            $name = text($body['name'] ?? '', 60);
            if ($name === '') fail('Podaj nazwę kategorii.');
            $what = text($body['what'] ?? '', 1500);
            $how = text($body['how'] ?? '', 1500);
            $key = locked(function () use ($name, $what, $how) {
                $cats = categories();
                $base = slug($name) ?: 'kategoria';
                $key = $base;
                $keys = array_column($cats, 'key');
                for ($i = 2; in_array($key, $keys, true) || $key === 'inne'; $i++) $key = "$base-$i";
                $cats[] = ['key' => $key, 'name' => $name, 'custom' => true, 'aliases' => [], 'what' => $what, 'how' => $how];
                jwrite('categories', $cats);
                return $key;
            });
            out(['ok' => true, 'key' => $key]);
        }

        case 'cat_delete': {
            $key = text($body['key'] ?? '', 40);
            $res = locked(function () use ($key) {
                $cats = categories();
                foreach ($cats as $i => $c) {
                    if ($c['key'] === $key) {
                        if (!$c['custom']) return 'builtin';
                        array_splice($cats, $i, 1);
                        jwrite('categories', $cats);
                        return 'ok';
                    }
                }
                return 'none';
            });
            if ($res === 'builtin') fail('Kategorii wbudowanych nie można usunąć.');
            if ($res === 'none') fail('Nie ma takiej kategorii.', 404);
            out(['ok' => true]);
        }

        case 'sources': {
            out(['sources' => sources_list()]);
        }

        case 'src_add': {
            $url = text($body['url'] ?? '', 500);
            if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_http_url($url) || !$host || !str_contains($host, '.') || !filter_var($url, FILTER_VALIDATE_URL)) {
                fail('To nie wygląda na poprawny adres strony.');
            }
            $cat = text($body['category'] ?? '', 40);
            $note = text($body['note'] ?? '', 200);
            $res = locked(function () use ($url, $cat, $note) {
                $list = sources_list();
                if (count($list) >= 100) return ['err' => 'Limit 100 stron. Usuń którąś, żeby dodać nową.'];
                foreach ($list as $s) { if (norm_url($s['url']) === norm_url($url)) return ['err' => 'Ta strona już jest na liście.']; }
                $valid = array_column(categories(), 'key');
                if ($cat !== '' && !in_array($cat, $valid, true)) $cat = '';
                $item = ['id' => bin2hex(random_bytes(4)), 'url' => $url, 'category' => $cat, 'note' => $note, 'active' => true, 'added' => date('c')];
                array_unshift($list, $item);
                jwrite('sources', $list);
                return ['item' => $item];
            });
            if (isset($res['err'])) fail($res['err'], 409);
            out(['ok' => true] + $res);
        }

        case 'src_toggle': {
            $id = text($body['id'] ?? '', 20);
            $active = (bool)($body['active'] ?? false);
            $ok = locked(function () use ($id, $active) {
                $list = sources_list();
                foreach ($list as &$s) { if ($s['id'] === $id) { $s['active'] = $active; jwrite('sources', $list); return true; } }
                return false;
            });
            if (!$ok) fail('Nie ma takiej strony.', 404);
            out(['ok' => true]);
        }

        case 'src_delete': {
            $id = text($body['id'] ?? '', 20);
            locked(function () use ($id) {
                $list = array_values(array_filter(sources_list(), fn($s) => $s['id'] !== $id));
                jwrite('sources', $list);
            });
            out(['ok' => true]);
        }

        default:
            fail('Nieznana akcja.', 404);
    }
} catch (Throwable $e) {
    error_log('okazje api: ' . $e->getMessage());
    fail('Błąd serwera. Spróbuj ponownie.', 500);
}
