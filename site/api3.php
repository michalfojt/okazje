<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }
require_once __DIR__ . '/lib3.php';

// Akcje v3 (Moje produkty, Gazetki). Dołączany z api.php w gałęzi default:; korzysta z out(), fail(), text() z api.php.
// Nieznana akcja: funkcja po prostu wraca, a api.php zwraca 404.

function api3(string $a, string $method, array $body): void {
    $post = function () use ($method): void {
        if ($method !== 'POST') fail('Metoda niedozwolona.', 405);
    };
    $res = function (array $r, array $extra = []): never {
        if (isset($r['err'])) fail((string)$r['err'], (int)($r['code'] ?? 400));
        out(['ok' => true] + $extra);
    };

    switch ($a) {

        case 'products': {
            out(['products' => products_view()]);
        }

        case 'prod_save': {
            $post();
            $r = product_save($body);
            if (!empty($r['ok'])) rematch_hits();
            $res($r, ['product' => $r['product'] ?? null]);
        }

        case 'prod_toggle': {
            $post();
            if (!product_set_active(text($body['id'] ?? '', 20), (bool)($body['active'] ?? false))) fail('Nie ma takiego produktu.', 404);
            out(['ok' => true]);
        }

        case 'prod_delete': {
            $post();
            product_delete(text($body['id'] ?? '', 20));
            out(['ok' => true]);
        }

        case 'shops': {
            out(shops_view());
        }

        case 'shop_save': {
            $post();
            $r = shop_save($body);
            $res($r, ['shop' => $r['shop'] ?? null]);
        }

        case 'shop_toggle': {
            $post();
            if (!shop_set_active(text($body['id'] ?? '', 20), (bool)($body['active'] ?? false))) fail('Nie ma takiego sklepu.', 404);
            out(['ok' => true]);
        }

        case 'shop_delete': {
            $post();
            shop_delete(text($body['id'] ?? '', 20));
            out(['ok' => true]);
        }

        case 'leaflet': {
            $id = text($_GET['id'] ?? '', 20);
            if (!preg_match('/^[a-f0-9]{12}$/', $id)) fail('Nieprawidłowe id gazetki.');
            $v = leaflet_view($id);
            if ($v === null) fail('Tej gazetki nie ma już na liście.', 404);
            out($v);
        }

        case 'leaflet_search': {
            out(['results' => leaflet_search(text($_GET['q'] ?? '', 80))]);
        }

        default:
            return;
    }
}
