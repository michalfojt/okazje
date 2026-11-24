<?php
declare(strict_types=1);
if (!defined('OKZ')) { http_response_code(403); exit; }

// Dane v3: Moje produkty, sklepy z gazetkami, gazetki, oferty produktów i trafienia w gazetkach.
// Zakłada, że lib.php jest już załadowany. Zapisy zawsze pod locked(), a locked() nie jest
// zagnieżdżalne (kolejny flock z tego samego procesu zawisłby), więc categories() i shops()
// (które przy pierwszym wywołaniu mogą zapisać domyślne dane) wywołujemy przed blokadą.

const PRODUCT_OFFER_DAYS = 30;
const PRODUCT_LIMIT = 200;
const SHOP_LIMIT = 40;
const PRODUCT_SITES_MAX = 15;
const LEAFLET_ITEMS_MAX = 400;
const EXTRAS_FILES_KEEP = 300;

/* ---------- pomocnicze ---------- */

/** Tekst z niezaufanego źródła: tylko skalary tekstowe i liczby, bez znaków sterujących, przycięty. Pusty -> null. */
function v3_str(mixed $v, int $max, bool $multiline = false): ?string {
    if (is_int($v) || is_float($v)) $v = (string)$v;
    if (!is_string($v)) return null;
    $v = $multiline
        ? (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v)
        : (string)preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $v);
    $v = trim(mb_substr(trim($v), 0, $max));
    return $v === '' ? null : $v;
}

function v3_bool(mixed $v, bool $default): bool {
    if (is_bool($v)) return $v;
    if (is_int($v)) return $v !== 0;
    if (is_string($v)) return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    return $default;
}

/** Data jako RRRR-MM-DD. Przyjmuje też DD.MM.RRRR (i RRRR-MM-DD z godziną). Nieparsowalna -> null. */
function norm_date(mixed $v): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T\s])/', $v, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } else {
        return null;
    }
    if ($y < 2000 || $y > 2100 || !checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Adres strony jak w src_add: dopisuje https://, wymaga poprawnego URL z kropką w hoście. */
function norm_site_url(mixed $v): ?string {
    if (!is_string($v)) return null;
    $u = trim($v);
    if ($u === '' || strlen($u) > 500) return null;
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    $host = parse_url($u, PHP_URL_HOST);
    if (!is_http_url($u) || !is_string($host) || !str_contains($host, '.') || !filter_var($u, FILTER_VALIDATE_URL)) return null;
    return $u;
}

/** @return array{0:array,1:?string} [adresy bez duplikatów, komunikat błędu] */
function parse_sites(mixed $v): array {
    if (is_string($v)) $items = preg_split('/[\r\n]+/', $v) ?: [];
    elseif (is_array($v)) $items = $v;
    else $items = [];
    $out = [];
    $seen = [];
    foreach ($items as $it) {
        if (!is_string($it)) continue;
        $it = trim($it);
        if ($it === '') continue;
        $u = norm_site_url($it);
        if ($u === null) return [[], 'Nieprawidłowy adres strony: ' . mb_substr($it, 0, 80)];
        $k = norm_url($u);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $u;
    }
    if (count($out) > PRODUCT_SITES_MAX) return [[], 'Maksymalnie ' . PRODUCT_SITES_MAX . ' stron priorytetowych.'];
    return [$out, null];
}

function v3_new_id(string $prefix, array $existing): string {
    do { $id = $prefix . bin2hex(random_bytes(4)); } while (in_array($id, $existing, true));
    return $id;
}

/** Usuwa plik zdjęcia z img/, ale tylko o nazwie zgodnej z wzorcem. */
function v3_unlink_image(?string $path, string $kind): void {
    if (!$path || !preg_match('#^img/' . $kind . '-[a-f0-9]{12}\.(jpg|png|webp|gif)$#', $path)) return;
    $f = __DIR__ . '/' . $path;
    if (is_file($f)) @unlink($f);
}

/* ---------- produkty ---------- */

function products(): array {
    $p = jread('products', []);
    return is_array($p) ? array_values($p) : [];
}

/**
 * Walidacja produktu z formularza.
 * @return array{0:?array,1:?string} [produkt (id puste dla nowego), komunikat błędu]
 */
function norm_product(array $in, ?array $old = null, ?array $catKeys = null): array {
    $name = v3_str($in['name'] ?? null, 160);
    if ($name === null) return [null, 'Podaj nazwę produktu.'];
    $ean = (string)preg_replace('/[\s\-]+/u', '', v3_str($in['ean'] ?? null, 40) ?? '');
    if ($ean !== '' && (!ctype_digit($ean) || !in_array(strlen($ean), [8, 12, 13, 14], true))) {
        return [null, 'EAN musi mieć 8, 12, 13 lub 14 cyfr.'];
    }
    $catKeys ??= array_column(categories(), 'key');
    $cat = v3_str($in['category'] ?? null, 40) ?? '';
    if (!in_array($cat, $catKeys, true)) $cat = '';
    if (array_key_exists('sites', $in)) {
        [$sites, $err] = parse_sites($in['sites']);
        if ($err !== null) return [null, $err];
    } else {
        $sites = is_array($old['sites'] ?? null) ? $old['sites'] : [];
    }
    $now = date('c');
    return [[
        'id'            => (string)($old['id'] ?? ''),
        'name'          => $name,
        'ean'           => $ean,
        'desc'          => v3_str($in['desc'] ?? null, 1500, true) ?? '',
        'category'      => $cat,
        'sites'         => $sites,
        'active'        => v3_bool($in['active'] ?? null, (bool)($old['active'] ?? true)),
        'added'         => (string)($old['added'] ?? $now),
        'updated'       => $now,
        'last_searched' => $old['last_searched'] ?? null,
    ], null];
}

/** @return array{ok?:true,product?:array,err?:string,code?:int} */
function product_save(array $in): array {
    $catKeys = array_column(categories(), 'key');
    $id = v3_str($in['id'] ?? null, 20) ?? '';
    return locked(function () use ($in, $id, $catKeys) {
        $list = products();
        if ($id !== '') {
            foreach ($list as $i => $p) {
                if (($p['id'] ?? '') !== $id) continue;
                [$n, $err] = norm_product($in, $p, $catKeys);
                if ($n === null) return ['err' => (string)$err, 'code' => 400];
                $list[$i] = $n;
                jwrite('products', $list);
                return ['ok' => true, 'product' => $n];
            }
            return ['err' => 'Nie ma takiego produktu.', 'code' => 404];
        }
        if (count($list) >= PRODUCT_LIMIT) return ['err' => 'Limit ' . PRODUCT_LIMIT . ' produktów. Usuń któryś, żeby dodać nowy.', 'code' => 409];
        [$n, $err] = norm_product($in, null, $catKeys);
        if ($n === null) return ['err' => (string)$err, 'code' => 400];
        $n['id'] = v3_new_id('p', array_column($list, 'id'));
        $list[] = $n;
        jwrite('products', $list);
        return ['ok' => true, 'product' => $n];
    });
}

function product_set_active(string $id, bool $active): bool {
    return (bool)locked(function () use ($id, $active) {
        $list = products();
        foreach ($list as $i => $p) {
            if (($p['id'] ?? '') !== $id) continue;
            $list[$i]['active'] = $active;
            $list[$i]['updated'] = date('c');
            jwrite('products', $list);
            return true;
        }
        return false;
    });
}

/** Usuwa produkt razem z jego ofertami, trafieniami i zdjęciami ofert. @return bool czy produkt istniał */
function product_delete(string $id): bool {
    return (bool)locked(function () use ($id) {
        $list = products();
        $left = array_values(array_filter($list, fn($p) => ($p['id'] ?? '') !== $id));
        $existed = count($left) !== count($list);
        if ($existed) jwrite('products', $left);

        $po = prodoffers_db();
        $gone = [];
        foreach ($po['offers'] as $k => $o) {
            if (($o['product_id'] ?? '') !== $id) continue;
            $gone[] = $o['image'] ?? null;
            unset($po['offers'][$k]);
        }
        if ($gone) {
            jwrite('prodoffers', $po);
            v3_drop_unused_offer_images($gone, $po['offers']);
        }

        $hd = hits_db();
        $n = count($hd['hits']);
        $hd['hits'] = array_filter($hd['hits'], fn($h) => ($h['product_id'] ?? '') !== $id);
        if (count($hd['hits']) !== $n) jwrite('hits', $hd);
        return $existed;
    });
}

/** Kasuje zdjęcia, których nie używa już żadna z pozostałych ofert (ta sama oferta może należeć do kilku produktów). */
function v3_drop_unused_offer_images(array $paths, array $remaining): void {
    $used = [];
    foreach ($remaining as $o) { if (!empty($o['image'])) $used[(string)$o['image']] = true; }
    foreach ($paths as $p) {
        if (is_string($p) && !isset($used[$p])) v3_unlink_image($p, 'po');
    }
}

/* ---------- sklepy z gazetkami ---------- */

function default_shops(): array {
    $now = date('c');
    return [
        ['id' => 's' . substr(sha1('default:biedronka'), 0, 8), 'name' => 'Biedronka', 'url' => 'https://www.biedronka.pl/pl/gazetki', 'note' => 'sklep: Poznań, ul. Dąbrowskiego 29', 'active' => true, 'added' => $now],
        ['id' => 's' . substr(sha1('default:lidl'), 0, 8), 'name' => 'Lidl', 'url' => 'https://www.lidl.pl/c/nasze-gazetki/s10008614', 'note' => 'sklep najbliższy: Poznań, Jeżyce', 'active' => true, 'added' => $now],
    ];
}

function shops(): array {
    $s = jread('shops', null);
    if (!is_array($s)) {
        $s = default_shops();
        locked(function () use ($s) { if (jread('shops', null) === null) jwrite('shops', $s); });
    }
    return array_values($s);
}

/** @return array{0:?array,1:?string} */
function norm_shop(array $in, ?array $old = null): array {
    $name = v3_str($in['name'] ?? null, 60);
    if ($name === null) return [null, 'Podaj nazwę sklepu.'];
    $urlRaw = v3_str($in['url'] ?? null, 500);
    if ($urlRaw === null) return [null, 'Podaj adres strony z gazetkami.'];
    $url = norm_site_url($urlRaw);
    if ($url === null) return [null, 'To nie wygląda na poprawny adres strony.'];
    return [[
        'id'     => (string)($old['id'] ?? ''),
        'name'   => $name,
        'url'    => $url,
        'note'   => v3_str($in['note'] ?? null, 300, true) ?? '',
        'active' => v3_bool($in['active'] ?? null, (bool)($old['active'] ?? true)),
        'added'  => (string)($old['added'] ?? date('c')),
    ], null];
}

/** @return array{ok?:true,shop?:array,err?:string,code?:int} */
function shop_save(array $in): array {
    shops(); // utworzy domyślną listę przed blokadą
    $id = v3_str($in['id'] ?? null, 20) ?? '';
    return locked(function () use ($in, $id) {
        $list = jread('shops', []);
        $list = is_array($list) ? array_values($list) : [];
        if ($id !== '') {
            foreach ($list as $i => $s) {
                if (($s['id'] ?? '') !== $id) continue;
                [$n, $err] = norm_shop($in, $s);
                if ($n === null) return ['err' => (string)$err, 'code' => 400];
                $list[$i] = $n;
                jwrite('shops', $list);
                return ['ok' => true, 'shop' => $n];
            }
            return ['err' => 'Nie ma takiego sklepu.', 'code' => 404];
        }
        if (count($list) >= SHOP_LIMIT) return ['err' => 'Limit ' . SHOP_LIMIT . ' sklepów. Usuń któryś, żeby dodać nowy.', 'code' => 409];
        [$n, $err] = norm_shop($in, null);
        if ($n === null) return ['err' => (string)$err, 'code' => 400];
        $n['id'] = v3_new_id('s', array_column($list, 'id'));
        $list[] = $n;
        jwrite('shops', $list);
        return ['ok' => true, 'shop' => $n];
    });
}

function shop_set_active(string $id, bool $active): bool {
    shops();
    return (bool)locked(function () use ($id, $active) {
        $list = array_values((array)jread('shops', []));
        foreach ($list as $i => $s) {
            if (($s['id'] ?? '') !== $id) continue;
            $list[$i]['active'] = $active;
            jwrite('shops', $list);
            return true;
        }
        return false;
    });
}

/** Usuwa sklep. Jego gazetki zostają (do wygaśnięcia), ale tracą powiązanie z listą sklepów. */
function shop_delete(string $id): bool {
    shops();
    return (bool)locked(function () use ($id) {
        $list = array_values((array)jread('shops', []));
        $left = array_values(array_filter($list, fn($s) => ($s['id'] ?? '') !== $id));
        if (count($left) === count($list)) return false;
        jwrite('shops', $left);
        $db = leaflets_db();
        $changed = false;
        foreach ($db['leaflets'] as $k => $l) {
            if (($l['shop_id'] ?? null) === $id) { $db['leaflets'][$k]['shop_id'] = null; $changed = true; }
        }
        if ($changed) jwrite('leaflets', $db);
        return true;
    });
}

/* ---------- pliki danych gazetek, ofert produktów i trafień ---------- */

function leaflets_db(): array {
    $d = jread('leaflets', []);
    if (!is_array($d)) $d = [];
    $d['leaflets'] = is_array($d['leaflets'] ?? null) ? $d['leaflets'] : [];
    $d['files'] = is_array($d['files'] ?? null) ? array_values($d['files']) : [];
    return $d;
}

function prodoffers_db(): array {
    $d = jread('prodoffers', []);
    if (!is_array($d)) $d = [];
    $d['offers'] = is_array($d['offers'] ?? null) ? $d['offers'] : [];
    $d['files'] = is_array($d['files'] ?? null) ? array_values($d['files']) : [];
    return $d;
}

function hits_db(): array {
    $d = jread('hits', []);
    if (!is_array($d)) $d = [];
    $d['hits'] = is_array($d['hits'] ?? null) ? $d['hits'] : [];
    $d['files'] = is_array($d['files'] ?? null) ? array_values($d['files']) : [];
    return $d;
}

function extras_files(): array {
    $l = jread('extras_files', []);
    return is_array($l) ? array_values(array_map('strval', $l)) : [];
}

/** Zapisuje nazwę pliku jako obsłużoną przez ingest_extras (np. partia bez nowych pól, żeby nie pobierać jej co 15 min). */
function extras_mark_file(string $file): void {
    locked(function () use ($file) {
        $l = extras_files();
        if (in_array($file, $l, true)) return;
        $l[] = $file;
        jwrite('extras_files', array_slice($l, -EXTRAS_FILES_KEEP));
    });
}

/** Zapisuje plik na liście img_files bazy ofert (partia bez ofert, ale obsłużona w całości przez ingest_extras). */
function offers_mark_file(string $file): void {
    locked(function () use ($file) {
        $db = jread('offers', ['offers' => [], 'batches' => []]);
        if (in_array($file, $db['img_files'] ?? [], true)) return;
        foreach ($db['batches'] ?? [] as $b) { if (($b['file'] ?? '') === $file) return; }
        $db['img_files'] = array_slice(array_merge($db['img_files'] ?? [], [$file]), -300);
        jwrite('offers', $db);
    });
}

/* ---------- normalizacja danych z partii (niezaufanych) ---------- */

function leaflet_id(string $url, ?string $validFrom): string {
    return substr(sha1(norm_url($url) . '|' . ($validFrom ?? '')), 0, 12);
}

function norm_leaflet_item(mixed $it): ?array {
    if (!is_array($it)) return null;
    $name = v3_str($it['name'] ?? null, 200);
    if ($name === null) return null;
    $page = $it['page'] ?? null;
    $page = (is_int($page) || (is_string($page) && ctype_digit($page))) && (int)$page > 0 && (int)$page < 100000 ? (int)$page : null;
    return [
        'name'       => $name,
        'price'      => v3_str($it['price'] ?? null, 40),
        'old_price'  => v3_str($it['old_price'] ?? null, 40),
        'valid_from' => norm_date($it['valid_from'] ?? null),
        'valid_to'   => norm_date($it['valid_to'] ?? null),
        'page'       => $page,
        'notes'      => v3_str($it['notes'] ?? null, 200),
    ];
}

/** Gazetka z partii (bez okładki i znaczników czasu). Zła lub brakująca url -> null. */
function norm_leaflet(array $l, array $shops): ?array {
    $url = v3_str($l['url'] ?? null, 1000);
    if ($url === null || !is_http_url($url)) return null;
    $vf = norm_date($l['valid_from'] ?? null);
    $vt = norm_date($l['valid_to'] ?? null);

    $shopId = v3_str($l['shop_id'] ?? null, 20);
    $shopName = v3_str($l['shop'] ?? null, 80);
    $byId = null; $byName = null;
    foreach ($shops as $s) {
        if ($shopId !== null && ($s['id'] ?? '') === $shopId) $byId = $s;
        if ($shopName !== null && slug((string)$s['name']) === slug($shopName) && $byName === null) $byName = $s;
    }
    $found = $byId ?? $byName;

    $items = [];
    if (is_array($l['items'] ?? null)) {
        foreach ($l['items'] as $it) {
            $n = norm_leaflet_item($it);
            if ($n) $items[] = $n;
            if (count($items) >= LEAFLET_ITEMS_MAX) break;
        }
    }
    $cover = v3_str($l['cover_url'] ?? null, 1000);
    $shopLabel = $found['name'] ?? $shopName;
    return [
        'id'         => leaflet_id($url, $vf),
        'shop_id'    => $found['id'] ?? null,
        'shop'       => $shopLabel !== null ? mb_substr((string)$shopLabel, 0, 80) : null,
        'title'      => v3_str($l['title'] ?? null, 200) ?? ($shopLabel !== null ? 'Gazetka ' . $shopLabel : 'Gazetka'),
        'url'        => $url,
        'valid_from' => $vf,
        'valid_to'   => $vt,
        'cover_src'  => ($cover !== null && is_http_url($cover)) ? $cover : null,
        'items'      => $items,
    ];
}

function hit_id(string $productId, ?string $leafletUrl, string $itemName, ?string $validFrom): string {
    return substr(sha1($productId . '|' . norm_url((string)$leafletUrl) . '|' . slug($itemName) . '|' . ($validFrom ?? '')), 0, 12);
}

/** @param array<string,true> $pids znane id produktów */
function norm_hit(array $h, array $pids): ?array {
    $pid = v3_str($h['product_id'] ?? null, 20);
    $item = v3_str($h['item_name'] ?? null, 200);
    if ($pid === null || $item === null || !isset($pids[$pid])) return null;
    $lurl = v3_str($h['leaflet_url'] ?? null, 1000);
    $lurl = ($lurl !== null && is_http_url($lurl)) ? $lurl : null;
    $vf = norm_date($h['valid_from'] ?? null);
    $page = $h['page'] ?? null;
    $page = (is_int($page) || (is_string($page) && ctype_digit($page))) && (int)$page > 0 && (int)$page < 100000 ? (int)$page : null;
    return [
        'id'            => hit_id($pid, $lurl, $item, $vf),
        'product_id'    => $pid,
        'leaflet_id'    => null,
        'shop'          => v3_str($h['shop'] ?? null, 80),
        'leaflet_url'   => $lurl,
        'leaflet_title' => v3_str($h['leaflet_title'] ?? null, 200),
        'item_name'     => $item,
        'price'         => v3_str($h['price'] ?? null, 40),
        'old_price'     => v3_str($h['old_price'] ?? null, 40),
        'valid_from'    => $vf,
        'valid_to'      => norm_date($h['valid_to'] ?? null),
        'page'          => $page,
        'notes'         => v3_str($h['notes'] ?? null, 300),
        'alerted'       => v3_bool($h['alerted'] ?? null, false),
    ];
}

/** Id gazetki z leaflets_db pasującej do adresu (preferuje tę, której okres obejmuje $validFrom, inaczej najnowszą). */
function find_leaflet_id(array $leaflets, ?string $url, ?string $validFrom): ?string {
    if ($url === null) return null;
    $nu = norm_url($url);
    $best = null;
    foreach ($leaflets as $l) {
        if (norm_url((string)($l['url'] ?? '')) !== $nu) continue;
        $inRange = $validFrom !== null && ($l['valid_from'] ?? null) !== null && $validFrom >= $l['valid_from']
            && (($l['valid_to'] ?? null) === null || $validFrom <= $l['valid_to']);
        $key = ($inRange ? '1' : '0') . ($l['valid_from'] ?? '') . '|' . ($l['last_seen'] ?? '');
        if ($best === null || $key > $best[0]) $best = [$key, (string)$l['id']];
    }
    return $best[1] ?? null;
}

/* ---------- import partii ---------- */

/**
 * Importuje nowe pola partii: searched_products, product_offers, leaflets, leaflet_hits.
 * @return array{status:string,products?:int,leaflets?:int,items?:int,hits?:int,searched?:int,rejected?:int}
 */
function ingest_extras(array $batch, string $file): array {
    $has = false;
    foreach (['searched_products', 'product_offers', 'leaflets', 'leaflet_hits'] as $k) {
        if (is_array($batch[$k] ?? null)) $has = true;
    }
    if (!$has) return ['status' => 'none'];

    $runTs = is_string($batch['run'] ?? null) ? strtotime($batch['run']) : false;
    if ($runTs === false) $runTs = time();
    if ($runTs < time() - retention_days() * 86400) return ['status' => 'too_old'];
    if (in_array($file, extras_files(), true)) return ['status' => 'duplicate'];
    $iso = date('c', $runTs);

    $shops = shops();
    $pids = [];
    foreach (products() as $p) { $pids[(string)$p['id']] = true; }
    $rejected = 0;

    // oferty produktów (klucz: product_id:oid); zdjęcia pobieramy przed blokadą, bo to najwolniejszy krok
    $offers = [];
    $rawOffers = is_array($batch['product_offers'] ?? null) ? array_slice($batch['product_offers'], 0, 300) : [];
    foreach ($rawOffers as $o) {
        $pid = is_array($o) ? v3_str($o['product_id'] ?? null, 20) : null;
        $n = (is_array($o) && $pid !== null && isset($pids[$pid])) ? norm_offer($o, []) : null;
        if ($n === null) { $rejected++; continue; }
        unset($n['category']);
        $n['product_id'] = $pid;
        $offers[$pid . ':' . $n['id']] ??= $n;
    }
    $imgs = [];
    foreach ($offers as $k => $n) {
        $oidv = (string)$n['id'];
        if (!array_key_exists($oidv, $imgs)) $imgs[$oidv] = fetch_image($n['image_src'], 'po-' . $oidv);
        $offers[$k]['image'] = $imgs[$oidv];
    }

    // gazetki
    $leaflets = [];
    $rawLeaflets = is_array($batch['leaflets'] ?? null) ? array_slice($batch['leaflets'], 0, 40) : [];
    foreach ($rawLeaflets as $l) {
        $n = is_array($l) ? norm_leaflet($l, $shops) : null;
        if ($n === null) { $rejected++; continue; }
        $leaflets[$n['id']] ??= $n;
    }
    foreach ($leaflets as $lid => $n) {
        $cover = null;
        foreach (glob(__DIR__ . '/img/lf-' . $lid . '.*') ?: [] as $f) { $cover = 'img/' . basename($f); break; }
        if ($cover === null && $n['cover_src'] !== null) {
            $im = image_from_url($n['cover_src'], 120);
            if ($im) $cover = save_image($im, 'lf-' . $lid);
        }
        $leaflets[$lid]['cover'] = $cover;
    }

    // trafienia
    $hits = [];
    $rawHits = is_array($batch['leaflet_hits'] ?? null) ? array_slice($batch['leaflet_hits'], 0, 300) : [];
    foreach ($rawHits as $h) {
        $n = is_array($h) ? norm_hit($h, $pids) : null;
        if ($n === null) { $rejected++; continue; }
        $hits[$n['id']] ??= $n;
    }

    $searched = [];
    foreach (is_array($batch['searched_products'] ?? null) ? array_slice($batch['searched_products'], 0, 200) : [] as $s) {
        if (is_string($s) && preg_match('/^p[a-f0-9]{8}$/', $s)) $searched[$s] = true;
    }

    return locked(function () use ($file, $iso, $offers, $leaflets, $hits, $searched, $rejected) {
        if (in_array($file, extras_files(), true)) return ['status' => 'duplicate'];

        // produkty mogły zniknąć w międzyczasie
        $list = products();
        $live = [];
        foreach ($list as $p) { $live[(string)$p['id']] = true; }

        $marked = 0;
        if ($searched) {
            foreach ($list as $i => $p) {
                if (!isset($searched[$p['id']])) continue;
                if (($p['last_searched'] ?? '') < $iso) $list[$i]['last_searched'] = $iso;
                $marked++;
            }
            jwrite('products', $list);
        }

        $nOffers = 0;
        if ($offers) {
            $po = prodoffers_db();
            foreach ($offers as $k => $o) {
                if (!isset($live[$o['product_id']])) { $rejected++; continue; }
                $old = $po['offers'][$k] ?? null;
                $img = $o['image'] ?? null;
                unset($o['image'], $o['image_src']);
                $o['image'] = $img ?: ($old['image'] ?? null);
                $o['first_seen'] = $old['first_seen'] ?? $iso;
                $o['last_seen'] = max((string)($old['last_seen'] ?? ''), $iso);
                $po['offers'][$k] = $o;
                $nOffers++;
            }
            jwrite('prodoffers', $po);
        }

        $nLeaflets = 0; $nItems = 0;
        $ld = leaflets_db();
        if ($leaflets) {
            foreach ($leaflets as $lid => $n) {
                $lid = (string)$lid;
                $old = $ld['leaflets'][$lid] ?? null;
                $items = $n['items'] ?: ($old['items'] ?? []); // pusta lista nie kasuje wcześniej wczytanych pozycji
                $ld['leaflets'][$lid] = [
                    'id'         => $lid,
                    'shop_id'    => $n['shop_id'] ?? ($old['shop_id'] ?? null),
                    'shop'       => $n['shop'] ?? ($old['shop'] ?? null),
                    'title'      => $n['title'],
                    'url'        => $n['url'],
                    'valid_from' => $n['valid_from'],
                    'valid_to'   => $n['valid_to'],
                    'cover'      => $n['cover'] ?? ($old['cover'] ?? null),
                    'items'      => $items,
                    'first_seen' => $old['first_seen'] ?? $iso,
                    'last_seen'  => max((string)($old['last_seen'] ?? ''), $iso),
                ];
                $nLeaflets++;
                $nItems += count($items);
            }
            jwrite('leaflets', $ld);
        }

        $nHits = 0;
        if ($hits) {
            $hd = hits_db();
            foreach ($hits as $hid => $h) {
                $hid = (string)$hid;
                if (!isset($live[$h['product_id']])) { $rejected++; continue; }
                $old = $hd['hits'][$hid] ?? null;
                $h['leaflet_id'] = find_leaflet_id($ld['leaflets'], $h['leaflet_url'], $h['valid_from']) ?? ($old['leaflet_id'] ?? null);
                $h['alerted'] = (bool)($old['alerted'] ?? false) || $h['alerted'];
                $h['found'] = $old['found'] ?? $iso;
                $hd['hits'][$hid] = $h;
                $nHits++;
            }
            jwrite('hits', $hd);
        }

        $files = extras_files();
        $files[] = $file;
        jwrite('extras_files', array_slice($files, -EXTRAS_FILES_KEEP));
        return ['status' => 'ok', 'products' => $nOffers, 'leaflets' => $nLeaflets, 'items' => $nItems, 'hits' => $nHits, 'searched' => $marked, 'rejected' => $rejected];
    });
}

/**
 * Czyszczenie: gazetki 7 dni po valid_to (bez valid_to: 21 dni po last_seen), oferty produktów
 * PRODUCT_OFFER_DAYS po last_seen, trafienia 14 dni po valid_to (bez valid_to: 30 dni po found).
 * Usuwa też oferty i trafienia nieistniejących już produktów oraz pliki zdjęć.
 * @return array{leaflets:int,offers:int,hits:int}
 */
function prune_extras(): array {
    return locked(function () {
        $res = ['leaflets' => 0, 'offers' => 0, 'hits' => 0];
        $now = time();

        $ld = leaflets_db();
        $dLeaf = date('Y-m-d', $now - 7 * 86400);
        $isoLeaf = date('c', $now - 21 * 86400);
        foreach ($ld['leaflets'] as $k => $l) {
            $vt = $l['valid_to'] ?? null;
            $old = $vt !== null ? $vt < $dLeaf : (string)($l['last_seen'] ?? '') < $isoLeaf;
            if (!$old) continue;
            v3_unlink_image($l['cover'] ?? null, 'lf');
            unset($ld['leaflets'][$k]);
            $res['leaflets']++;
        }
        if ($res['leaflets']) jwrite('leaflets', $ld);

        $live = [];
        foreach (products() as $p) { $live[(string)$p['id']] = true; }

        $po = prodoffers_db();
        $isoOffer = date('c', $now - PRODUCT_OFFER_DAYS * 86400);
        $gone = [];
        foreach ($po['offers'] as $k => $o) {
            if (isset($live[$o['product_id'] ?? '']) && (string)($o['last_seen'] ?? '') >= $isoOffer) continue;
            $gone[] = $o['image'] ?? null;
            unset($po['offers'][$k]);
            $res['offers']++;
        }
        if ($res['offers']) {
            jwrite('prodoffers', $po);
            v3_drop_unused_offer_images($gone, $po['offers']);
        }

        $hd = hits_db();
        $dHit = date('Y-m-d', $now - 14 * 86400);
        $isoHit = date('c', $now - 30 * 86400);
        $changed = false;
        foreach ($hd['hits'] as $k => $h) {
            $vt = $h['valid_to'] ?? null;
            $old = !isset($live[$h['product_id'] ?? '']) || ($vt !== null ? $vt < $dHit : (string)($h['found'] ?? '') < $isoHit);
            if ($old) { unset($hd['hits'][$k]); $res['hits']++; $changed = true; continue; }
            if (!empty($h['leaflet_id']) && !isset($ld['leaflets'][$h['leaflet_id']])) { $hd['hits'][$k]['leaflet_id'] = null; $changed = true; }
        }
        if ($changed) jwrite('hits', $hd);
        return $res;
    });
}

/* ---------- widoki dla API ---------- */

function v3_current(?string $validTo, string $today): bool { return $validTo === null || $validTo >= $today; }

/** Aktualne trafienia (valid_to >= dziś albo brak valid_to), najbliższe valid_from najpierw. */
function current_hits(?array $hits = null): array {
    $today = date('Y-m-d');
    $rows = array_values(array_filter($hits ?? hits_db()['hits'], fn($h) => v3_current($h['valid_to'] ?? null, $today)));
    usort($rows, fn($a, $b) => strcmp((string)($a['valid_from'] ?? '9999'), (string)($b['valid_from'] ?? '9999')));
    return $rows;
}

function products_view(): array {
    $cut = date('c', time() - PRODUCT_OFFER_DAYS * 86400);
    $byP = [];
    foreach (prodoffers_db()['offers'] as $o) {
        if ((string)($o['last_seen'] ?? '') < $cut) continue;
        unset($o['image_src']);
        $byP[(string)($o['product_id'] ?? '')][] = $o;
    }
    $hP = [];
    foreach (current_hits() as $h) { $hP[(string)($h['product_id'] ?? '')][] = $h; }
    $rows = [];
    foreach (products() as $p) {
        $id = (string)$p['id'];
        $offers = $byP[$id] ?? [];
        usort($offers, function ($a, $b) {
            $pa = $a['price_num'] ?? null; $pb = $b['price_num'] ?? null;
            if ($pa !== $pb) {
                if ($pa === null) return 1;
                if ($pb === null) return -1;
                return $pa <=> $pb;
            }
            return strcmp((string)($b['last_seen'] ?? ''), (string)($a['last_seen'] ?? ''));
        });
        $best = null;
        foreach ($offers as $o) {
            if (($o['price_num'] ?? null) === null) continue;
            $best = ['price' => $o['price'] ?? null, 'price_num' => $o['price_num'], 'shop' => $o['shop'] ?? null, 'url' => $o['url'] ?? null];
            break;
        }
        $p['offers'] = array_slice($offers, 0, 12);
        $p['best'] = $best;
        $p['hits'] = $hP[$id] ?? [];
        $p['offers_count'] = count($offers);
        $rows[] = $p;
    }
    return $rows;
}

function leaflet_card(array $l, string $today, array $hitCounts): array {
    $items = is_array($l['items'] ?? null) ? $l['items'] : [];
    unset($l['items']);
    $l['items_count'] = count($items);
    $l['hits_count'] = $hitCounts[(string)$l['id']] ?? 0;
    $l['current'] = v3_current($l['valid_to'] ?? null, $today);
    return $l;
}

/** @return array{shops:array,leaflets:array} */
function shops_view(): array {
    $today = date('Y-m-d');
    $hc = [];
    foreach (current_hits() as $h) { if (!empty($h['leaflet_id'])) $hc[(string)$h['leaflet_id']] = ($hc[(string)$h['leaflet_id']] ?? 0) + 1; }
    $rows = [];
    foreach (leaflets_db()['leaflets'] as $l) { $rows[] = leaflet_card($l, $today, $hc); }
    usort($rows, function ($a, $b) {
        if ($a['current'] !== $b['current']) return $a['current'] ? -1 : 1;
        if ($a['current']) return strcmp((string)$a['shop'], (string)$b['shop']) ?: strcmp((string)($b['valid_from'] ?? ''), (string)($a['valid_from'] ?? ''));
        return strcmp((string)($b['valid_to'] ?? ''), (string)($a['valid_to'] ?? ''));
    });
    return ['shops' => shops(), 'leaflets' => $rows];
}

/** @return ?array{leaflet:array,hits:array} */
function leaflet_view(string $id): ?array {
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) return null;
    $l = leaflets_db()['leaflets'][$id] ?? null;
    if (!$l) return null;
    $names = [];
    foreach (products() as $p) { $names[(string)$p['id']] = (string)$p['name']; }
    $hits = [];
    foreach (hits_db()['hits'] as $h) {
        if (($h['leaflet_id'] ?? null) !== $id) continue;
        $h['product_name'] = $names[(string)($h['product_id'] ?? '')] ?? null;
        $hits[] = $h;
    }
    usort($hits, fn($a, $b) => strcmp((string)($a['valid_from'] ?? '9999'), (string)($b['valid_from'] ?? '9999')));
    return ['leaflet' => $l, 'hits' => $hits];
}

/** Szukanie pozycji w aktualnych gazetkach (bez rozróżniania wielkości liter i polskich znaków). @return array<int,array> */
function leaflet_search(string $q, int $limit = 100): array {
    $needle = slug($q);
    if (mb_strlen(trim($q)) < 2 || $needle === '') return [];
    $today = date('Y-m-d');
    $out = [];
    foreach (leaflets_db()['leaflets'] as $l) {
        if (!v3_current($l['valid_to'] ?? null, $today)) continue;
        foreach ($l['items'] ?? [] as $it) {
            if (!str_contains(slug((string)($it['name'] ?? '')), $needle)) continue;
            $out[] = ['leaflet_id' => (string)$l['id'], 'shop' => $l['shop'] ?? null, 'title' => $l['title'] ?? null,
                      'valid_from' => $l['valid_from'] ?? null, 'valid_to' => $l['valid_to'] ?? null, 'item' => $it];
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}

/* ---------- widoki dla feed.php ---------- */

/** Aktywne produkty; najpierw nigdy nie szukane, potem najdawniej szukane. */
function feed_products(): array {
    $rows = array_values(array_filter(products(), fn($p) => !empty($p['active'])));
    usort($rows, function ($a, $b) {
        $la = $a['last_searched'] ?? null; $lb = $b['last_searched'] ?? null;
        if ($la === $lb) return 0;
        if ($la === null) return -1;
        if ($lb === null) return 1;
        return strcmp((string)$la, (string)$lb);
    });
    return array_map(fn($p) => [
        'id' => $p['id'], 'name' => $p['name'], 'ean' => $p['ean'] ?? '', 'desc' => $p['desc'] ?? '',
        'category' => $p['category'] ?? '', 'sites' => $p['sites'] ?? [], 'last_searched' => $p['last_searched'] ?? null,
    ], $rows);
}

function feed_leaflet_shops(): array {
    return array_values(array_map(
        fn($s) => ['id' => $s['id'], 'name' => $s['name'], 'url' => $s['url'], 'note' => $s['note'] ?? ''],
        array_filter(shops(), fn($s) => !empty($s['active']))
    ));
}

function feed_leaflets_known(): array {
    $today = date('Y-m-d');
    $out = [];
    foreach (leaflets_db()['leaflets'] as $l) {
        if (!v3_current($l['valid_to'] ?? null, $today)) continue;
        $out[] = ['shop_id' => $l['shop_id'] ?? null, 'shop' => $l['shop'] ?? null, 'url' => $l['url'], 'title' => $l['title'] ?? null,
                  'valid_from' => $l['valid_from'] ?? null, 'valid_to' => $l['valid_to'] ?? null, 'items_count' => count($l['items'] ?? [])];
    }
    return $out;
}

function feed_known_hits(): array {
    return array_map(fn($h) => [
        'product_id' => $h['product_id'], 'shop' => $h['shop'] ?? null, 'leaflet_url' => $h['leaflet_url'] ?? null,
        'item_name' => $h['item_name'], 'valid_from' => $h['valid_from'] ?? null, 'valid_to' => $h['valid_to'] ?? null,
        'alerted' => !empty($h['alerted']),
    ], current_hits());
}
