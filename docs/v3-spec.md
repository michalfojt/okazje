# okazje v3 - specyfikacja (Moje produkty, Gazetki, alerty)

Dokument dla wykonawców. Kod strony: `site/` (lustro `public_html/okazje/` na hostingu seohost, PHP 8.1+, bez bazy, dane JSON w `data/`). Zasady istniejącego kodu obowiązują dalej: `declare(strict_types=1)`, `define('OKZ',1)` + `require lib.php`, zapisy tylko pod `locked()`, `jread()/jwrite()`, odpowiedzi API przez `out()/fail()`, wszystkie teksty UI po polsku, bez zewnętrznych zasobów (CSP `default-src 'self'`).

Kto co robi:
- **Strona (hosting)** przechowuje produkty, sklepy z gazetkami, gazetki, oferty produktów i trafienia; pokazuje je w zakładkach; importuje wyniki z partii JSON (cron `ingest.php`); udostępnia listy zadaniu przez `feed.php`.
- **Zadanie cykliczne (Claude)** szuka ofert produktów, czyta gazetki, dopasowuje produkty do gazetek, wysyła maile z alertami i zapisuje wyniki w partii JSON w repo (`okazje/inbox/RRRR-MM-DD-GGMM.json`).

## 1. Pliki danych (`data/`)

### products.json - lista
```json
[{"id":"p3f9a1c2e","name":"Sonoff ZBDongle-E","ean":"6920075740219","desc":"koordynator Zigbee 3.0 USB, wersja E (EFR32MG21), nie mylić z ZBDongle-P","category":"zigbee","sites":["https://botland.com.pl","https://allegro.pl"],"active":true,"added":"2026-11-24T12:00:00+01:00","updated":"...","last_searched":null}]
```
- `id`: `"p"` + 8 hex (`bin2hex(random_bytes(4))`).
- `name` wymagane, max 160 znaków. `ean` opcjonalne: same cyfry, 8, 12, 13 lub 14 znaków (spacje i myślniki usuwać); inaczej błąd walidacji. `desc` max 1500. `category`: klucz istniejącej kategorii albo `""`. `sites`: max 15 adresów; każdy normalizowany jak w `src_add` (dopisz `https://`, walidacja `is_http_url` + host z kropką); duplikaty (po `norm_url`) usuwane. `active` bool. Limit 200 produktów.
- `last_searched`: ISO, ustawiane przy imporcie partii z `searched_products`.

### shops.json - sklepy z gazetkami - lista
```json
[{"id":"s1a2b3c4d","name":"Biedronka","url":"https://www.biedronka.pl/pl/gazetki","note":"sklep: Poznań, ul. Dąbrowskiego 29","active":true,"added":"..."}]
```
- `name` wymagane max 60, `url` wymagany (walidacja jak wyżej), `note` max 300. Limit 40.
- Gdy `shops.json` nie istnieje, `shops()` zwraca i zapisuje domyślne: Biedronka (`https://www.biedronka.pl/pl/gazetki`, note `sklep: Poznań, ul. Dąbrowskiego 29`) i Lidl (`https://www.lidl.pl/c/nasze-gazetki/s10008614`, note `sklep najbliższy: Poznań, Jeżyce`). Wzorzec jak `categories()`.

### leaflets.json - gazetki
```json
{"leaflets":{"<lid>":{"id":"<lid>","shop_id":"s1a2b3c4d","shop":"Biedronka","title":"Gazetka od 24.11","url":"https://...","valid_from":"2026-11-24","valid_to":"2026-11-29","cover":"img/lf-<lid>.jpg","items":[{"name":"Masło Extra 200 g","price":"5,99 zł","old_price":"8,49 zł","valid_from":"2026-11-24","valid_to":"2026-11-26","page":3,"notes":"przy zakupie 2 szt."}],"first_seen":"...","last_seen":"..."}},"files":["2026-11-24-0600.json"]}
```
- `lid` = `substr(sha1(norm_url(url) . '|' . valid_from), 0, 12)`.
- Ponowny import tej samej gazetki nadpisuje `items`, `title`, daty, `last_seen`; `first_seen` zostaje.
- `shop_id`: z partii; gdy brak, dopasowanie po nazwie sklepu (`slug(name)`), inaczej `null`.
- `items`: max 400 na gazetkę; `name` wymagane (max 200), `price`/`old_price` max 40, daty `RRRR-MM-DD` albo null, `page` int albo null, `notes` max 200.
- Okładka: `cover_url` z partii pobierana jak zdjęcie oferty (`image_from_url($src, 120)` + zapis pod nazwą `lf-<lid>`; `save_image()` przyjmuje dowolne id, więc wystarczy `save_image($im, 'lf-' . $lid)`). Nie pobieraj ponownie, gdy plik już jest.
- Czyszczenie: gazetka znika 7 dni po `valid_to`; bez `valid_to` - 21 dni po `last_seen`. Razem z nią plik okładki.

### prodoffers.json - oferty produktów
```json
{"offers":{"<oid>":{"id":"<oid>","product_id":"p3f9a1c2e","title":"...","url":"...","price":"...","price_num":89.9,"original_price":null,"original_num":null,"shop":"Botland","promo_end":null,"notes":"...","confidence":"high","image":"img/<oid>.jpg","first_seen":"...","last_seen":"..."}},"files":[]}
```
- `oid` = `oid(url)` z lib.php, ale klucz w tym pliku to `product_id . ':' . oid` (ta sama oferta może pasować do dwóch produktów). Pole `id` = `oid` (12 hex).
- Normalizacja jak `norm_offer()` (bez kategorii). `product_id` musi istnieć w `products.json`, inaczej oferta odrzucona.
- Zdjęcie: `fetch_image(image_url, 'po-' . oid)`.
- Czyszczenie: 30 dni po `last_seen` (stała `PRODUCT_OFFER_DAYS = 30`). Usunięcie produktu usuwa jego oferty, trafienia i ich zdjęcia.

### hits.json - trafienia produktów w gazetkach
```json
{"hits":{"<hid>":{"id":"<hid>","product_id":"p3f9a1c2e","leaflet_id":"<lid>|null","shop":"Lidl","leaflet_url":"...","leaflet_title":"...","item_name":"...","price":"...","old_price":"...","valid_from":"2026-11-27","valid_to":"2026-11-29","page":12,"notes":"...","alerted":true,"found":"..."}},"files":[]}
```
- `hid` = `substr(sha1(product_id . '|' . norm_url(leaflet_url) . '|' . slug(item_name) . '|' . (valid_from ?? '')), 0, 12)`.
- `leaflet_id`: wyliczany z `leaflet_url` + `valid_from` gazetki, jeśli taka gazetka jest w leaflets.json (szukaj po `norm_url(url)` równym `norm_url(leaflet_url)`), inaczej null.
- Ponowny import tego samego trafienia aktualizuje pola, zachowuje `found` i ustawia `alerted = stare || nowe`.
- Czyszczenie: 14 dni po `valid_to`; bez `valid_to` - 30 dni po `found`.

## 2. Partia JSON - nowe pola

Plik partii jak dotąd (`RRRR-MM-DD-GGMM.json`, pola `run`, `offers`, `images` bez zmian). Nowe, wszystkie opcjonalne:
```json
{
  "run": "2026-11-24T14:00:00+01:00",
  "searched_products": ["p3f9a1c2e"],
  "product_offers": [{"product_id":"p3f9a1c2e","title":"","url":"","price":"","original_price":"","shop":"","promo_end":"","image_url":"","notes":"","confidence":"high|medium|low"}],
  "leaflets": [{"shop_id":"s1a2b3c4d","shop":"Biedronka","title":"","url":"","valid_from":"2026-11-24","valid_to":"2026-11-29","cover_url":"","items":[{"name":"","price":"","old_price":"","valid_from":"","valid_to":"","page":3,"notes":""}]}],
  "leaflet_hits": [{"product_id":"p3f9a1c2e","shop":"Lidl","leaflet_url":"","leaflet_title":"","item_name":"","price":"","old_price":"","valid_from":"","valid_to":"","page":12,"notes":"","alerted":true}]
}
```
Import (`ingest_extras(array $batch, string $file): array`): przetwarza 4 nowe pola niezależnie od `ingest_batch()`. Deduplikacja po nazwie pliku w osobnej liście `data/extras_files.json` (ostatnie 300). Partia bez żadnego z nowych pól zwraca `['status' => 'none']` i nie trafia na listę. Partie starsze niż `retention_days()` (`run`) - `too_old`. Zwraca `['status'=>'ok','products'=>n,'leaflets'=>n,'items'=>n,'hits'=>n]`.

`ingest.php`: dla każdego pliku (lokalnie i z GitHub) wywołaj `ingest_batch()` i `ingest_extras()`. Plik z GitHub jest pomijany tylko, gdy jest już na obu listach plików (ofert + `img_files` oraz `extras_files`); jeśli partia nie ma nowych pól, `ingest_extras` zwraca `none`, a plik i tak dopisz do `extras_files`, żeby nie pobierać go co 15 min. Po imporcie: `prune_extras()`.

## 3. feed.php - nowe klucze
Dotychczasowe klucze bez zmian. Dochodzą:
- `products`: aktywne produkty `[{id,name,ean,desc,category,sites,last_searched}]`, posortowane: najpierw `last_searched` null, potem najstarsze.
- `leaflet_shops`: aktywne sklepy `[{id,name,url,note}]`.
- `leaflets_known`: gazetki z `valid_to >= dziś` albo bez `valid_to` `[{shop_id,shop,url,title,valid_from,valid_to,items_count}]` - zadanie nie musi czytać ich ponownie.
- `known_hits`: trafienia jeszcze ważne `[{product_id,shop,leaflet_url,item_name,valid_from,valid_to,alerted}]` - zadanie nie wysyła drugi raz alertu o tym samym.

## 4. API (`api.php?a=...`, sesja + CSRF jak dotąd)
Nowe akcje w osobnym pliku `api3.php` (bez własnego bootstrapu; dołączany z `api.php` w gałęzi `default:` - `require __DIR__ . '/api3.php'; api3($a, $method, $body);` i dopiero gdy `api3` nie obsłuży akcji, `fail('Nieznana akcja.', 404)`). `api3()` używa `out()`, `fail()`, `text()` z api.php. Wszystkie POST-y wymagają `$method === 'POST'` (inaczej 405).

| akcja | metoda | wejście | wyjście |
|---|---|---|---|
| `products` | GET | - | `{products:[{...produkt, offers:[...najtańsze najpierw, max 12], best:{price,price_num,shop,url}|null, hits:[...aktualne trafienia, najbliższe valid_from najpierw], offers_count}]}` |
| `prod_save` | POST | `{id?, name, ean, desc, category, sites:[...]|"tekst z adresami w liniach", active}` | `{ok, product}`; bez `id` = nowy |
| `prod_toggle` | POST | `{id, active}` | `{ok}` |
| `prod_delete` | POST | `{id}` | `{ok}` |
| `shops` | GET | - | `{shops:[...], leaflets:[{...gazetka bez items, items_count, current:bool}], }` |
| `shop_save` | POST | `{id?, name, url, note, active}` | `{ok, shop}` |
| `shop_toggle` | POST | `{id, active}` | `{ok}` |
| `shop_delete` | POST | `{id}` | `{ok}` |
| `leaflet` | GET | `id` w query | `{leaflet:{...z items}, hits:[...trafienia tej gazetki]}` |
| `leaflet_search` | GET | `q` w query (min 2 znaki) | `{results:[{leaflet_id, shop, title, valid_from, valid_to, item}]}` max 100, tylko gazetki aktualne |

Oferty produktu w `products`: `unset image_src`, pola jak w kartach ofert. `hits` tylko z `valid_to >= dziś` lub bez `valid_to`.

## 5. Frontend
- Nowe zakładki w `index.php` między „Wybrane" a „Kategorie": `Moje produkty` (`#/produkty`, `data-tab="produkty"`) i `Gazetki` (`#/gazetki`, `data-tab="gazetki"`). Dodatkowy CSS `assets/app3.css` i skrypt `assets/app3.js` (po `app.js`, z wersjonowaniem `?v=` jak istniejące).
- `app.js` - minimalna zmiana: przed `window.addEventListener('hashchange', route);` dodaj `window.OKZ = { api, el, icon, ICON, toast, S, loadState, catName, card, fmtIso, fmtShort, safeHref, plural, discount, routes, route, view };`. `route()` musi brać trasy z obiektu `routes` w chwili wywołania (już tak jest). `app3.js` rejestruje `OKZ.routes.produkty` i `OKZ.routes.gazetki`, dopisuje potrzebne ikony do `OKZ.ICON`, a jeśli bieżący hash to `#/produkty` lub `#/gazetki`, wywołuje `OKZ.route()` (bo pierwszy `route()` w app.js poszedł przed rejestracją).
- **Moje produkty**: nagłówek z liczbą produktów; przycisk „Dodaj produkt" otwiera formularz (nazwa, EAN, kategoria select z `S.cats` bez wirtualnych, opis dla wyszukiwarki textarea, strony priorytetowe textarea - jeden adres w linii, przełącznik aktywny). Lista produktów jako panele: nazwa, EAN (mono), tag kategorii, przełącznik aktywności, najlepsza cena + sklep (link), liczba ofert, data ostatniego wyszukiwania; sekcja „W gazetkach" z trafieniami (sklep, nazwa z gazetki, cena, stara cena przekreślona, okres `od-do`, link do gazetki), wyróżniona kolorem `--ok`; rozwijana lista ofert (siatka mniejszych kart: zdjęcie, cena, sklep, tytuł link, data `widziana`); opis i strony priorytetowe; przyciski Edytuj / Usuń (usuń z potwierdzeniem drugim kliknięciem jak w kategoriach). Filtr: wyszukiwarka tekstowa + chipy kategorii. Pusty stan z instrukcją.
- **Gazetki**: sekcja „Sklepy" (formularz: nazwa, adres strony z gazetkami, notatka; lista z przełącznikiem, linkiem, notatką, usuń, edytuj); sekcja „Aktualne gazetki" - siatka kart: okładka (albo placeholder), sklep, tytuł, okres ważności (z oznaczeniem „trwa"/„od jutra"/„od DD.MM"), liczba produktów, liczba trafień z Moich produktów; klik rozwija listę produktów gazetki (tabela: nazwa, cena, stara cena, okres, strona; wiersze-trafienia wyróżnione) i link „Otwórz gazetkę"; wyszukiwarka po produktach we wszystkich aktualnych gazetkach (`leaflet_search`, debounce 300 ms). Gazetki zakończone - zwinięta sekcja „Zakończone".
- Styl: te same zmienne i komponenty co w `app.css` (`.panel`, `.btn`, `.chip`, `.tag`, `.switch`, `.empty`, `.skel`, `.section-title`, `.hint`, `.form-err`), responsywnie (<= 860 px jedna kolumna formularzy, <= 520 px karty na całą szerokość). Treści użytkownika zawsze przez `textContent` (funkcja `el`), linki przez `safeHref`.

## 6. Bezpieczeństwo
- `.htaccess`: do zablokowanych dopisz `lib3` i `api3` (`^(config|lib|lib3|api3|ingest|passwd)\.php$`); `api3.php` dodatkowo zaczyna się od `if (!defined('OKZ')) { http_response_code(403); exit; }`.
- Wszystkie dane z partii traktuj jako niezaufane: przycinanie długości, `is_http_url`, bez HTML.
- `deploy.php`: patrz sekcja 7.

## 7. Wdrażanie z GitHub (`deploy.php`)
- Dostęp tylko po zalogowaniu (`auth_user()`), POST z CSRF. GET pokazuje prostą stronę: lista plików z `site/MANIFEST.json` w repo (`https://raw.githubusercontent.com/michalfojt/okazje/main/site/MANIFEST.json`) ze statusem: zgodny / inny / brak na serwerze (porównanie sha256), przycisk „Wdróż zmienione".
- POST: dla każdego pliku z manifestu o innym sha256 - pobierz `https://raw.githubusercontent.com/michalfojt/okazje/main/site/<path>`, sprawdź sha256 z manifestu, skopiuj obecną wersję do `data/backup/<RRRRmmdd-HHMMSS>/<path>`, zapisz nowy plik atomowo (tmp + rename). Ścieżki tylko z białej listy wzorców: `^[a-z0-9_]+\.php$`, `^assets/[a-z0-9_]+\.(js|css)$`, `^\.htaccess$`; nigdy `config.php`, nic w `data/`, `img/`, `inbox/`. Pliki spoza manifestu nie są ruszane ani usuwane.
- Manifest: `{"generated":"ISO","commit":"<sha lub null>","files":{"lib.php":"<sha256>",...}}` generowany przez `tools/manifest.py` z zawartości `site/` (pomija `MANIFEST.json`, `README.md`, `.gitattributes`).

## Dopasowanie po marce i kategorii (dodatek)

Gazetki rzadko podają EAN albo pełną nazwę, częściej piszą "wszystkie produkty Dove, drugi za 1 zł". Dlatego:

- Produkt ma pola `brand` (puste = pierwsze słowo nazwy) i `keywords` (frazy po przecinku, np. "żel pod prysznic").
- Pozycja gazetki ma pola `applies_to` (czego dotyczy promocja, np. "wszystkie produkty Dove"), `deal` (np. "drugi za 1 zł", "2+1", "-30%") i `scope` (`item`, `brand`, `category`; domyślnie `brand`, gdy jest `applies_to`). Cena może być pusta.
- Trafienie ma pole `match`: `item` (ta sama pozycja: EAN albo marka i co najmniej 60% słów nazwy), `brand` (promocja na całą markę produktu), `keyword` (promocja na kategorię: fraza ze słów kluczowych w tekście pozycji). Pozycje zwykłe (`scope: item`) nie łapią się po samej marce ani po słowach kluczowych.
- `rematch_hits()` (lib3.php) przegląda aktualne gazetki i aktywne produkty po każdym imporcie (ingest.php) i po zapisaniu produktu. Nowe trafienia mają `auto: true`, `alerted: false`, a feed oddaje je w `known_hits` z ceną, `deal`, `match` i nazwą produktu, żeby zadanie wysłało alert i oznaczyło je jako `alerted: true`.
- To samo trafienie nie powstaje dwa razy: klucz to produkt, adres gazetki i nazwa pozycji (bez daty).

## Promocje w gazetkach i ocena atrakcyjności

- `deal_score($deal, $price, $old)` (lib3.php) szacuje efektywny rabat: "drugi za 1 zł" i "1+1 gratis" = 50%, "2+1 gratis" = 33%, "3 za 2" = 33%, "-30%" = 30%, "drugi -50%" = 25%, "do -50%" liczone jako maksimum (waga 0,6 przy ocenie). Gdy brak warunku, rabat liczony z ceny i ceny poprzedniej. Brak danych = brak oceny (null). Oceny: >=45% bardzo dobra, >=30% dobra, >=15% średnia, niżej słaba.
- `leaflet_promos()` zwraca płaską listę pozycji ze wszystkich aktualnych gazetek (pola: shop, name, scope, applies_to, deal, price, old_price, discount, mine[]). `mine` to trafienia z Moich produktów (z current_hits). Sortowanie: najpierw "mine", potem malejąco wg rabatu.
- API: `api3.php?a=promos`. Oceny rabatu są też w `hits[].discount` (widok produktów i gazetek).
- Zakładka Gazetki: u góry lista "Promocje w gazetkach" (szukajka, filtr sklepu, "Tylko moje produkty", plakietka ~N%), niżej aktualne gazetki, na dole Sklepy.
- Testy: tests/deal_test.php (w run.sh przed v3_test).
