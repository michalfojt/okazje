# okazje - kod strony

Strona z okazjami pod https://michalfojt.pl/okazje/. Działa na hostingu seohost w PHP 8.1+, bez bazy danych. Dane są w plikach JSON w katalogu `data/`. Ten katalog jest lustrem `public_html/okazje/` na hostingu.

Partie wyników nie są pisane przez ludzi. Zadanie cykliczne „Okazje dla Michała" zapisuje je w `okazje/inbox/` w repo, a strona importuje je cronem.

## Struktura plików

Kod w tym katalogu:

- `lib.php` - wspólne funkcje: odczyt i zapis JSON (`jread()`, `jwrite()`), blokada zapisu (`locked()`), normalizacja adresów, import partii v2, konfiguracja (`cfg()`).
- `lib3.php` - funkcje v3: produkty, sklepy z gazetkami, gazetki, oferty produktów, trafienia, import dodatkowych pól partii. Plik w trakcie wdrażania, jeszcze go nie ma w repo.
- `api.php` - API dla zakładek (`api.php?a=...`). Akcje v3 są w `api3.php`, dołączanym z gałęzi `default:`.
- `api3.php` - akcje v3 (`products`, `prod_save`, `shops`, `leaflet`, `leaflet_search` i pozostałe z tabeli w specyfikacji). Plik w trakcie wdrażania.
- `ingest.php` - import partii z `inbox/` (lokalnie i z GitHub). Uruchamiany z crona.
- `feed.php` - odczyt list dla zadania wyszukującego (JSON, chroniony tokenem).
- `deploy.php` - wdrażanie zmienionych plików z GitHub. Plik w trakcie wdrażania.
- `index.php` - jedna strona aplikacji, zakładki i szkielet HTML.
- `assets/app.js`, `assets/app.css` - frontend. Pliki `assets/app3.js` i `assets/app3.css` dochodzą w v3.
- `passwd.php` - zmiana hasła (tylko z linii poleceń).
- `.htaccess` - reguły Apache: blokada plików pomocniczych, nagłówki.

Katalogi, których nie ma w repo, istnieją tylko na serwerze:

- `data/` - pliki danych JSON (oferty, produkty, gazetki, trafienia, sklepy). W `data/backup/` są kopie zapasowe z wdrożeń.
- `img/` - zdjęcia ofert, okładki gazetek i zdjęcia produktów.
- `inbox/` - partie wyników od zadania cyklicznego.
- `config.php` - konfiguracja i hasło. Nigdy nie trafia do repo.

## Zakładki

- Oferty - najnowsze oferty z partii, z filtrem kategorii.
- Wybrane - oferty oznaczone gwiazdką.
- Moje produkty (v3) - lista produktów do śledzenia, oferty każdego produktu i trafienia w gazetkach.
- Gazetki (v3) - sklepy z gazetkami, aktualne gazetki i wyszukiwarka po produktach w gazetkach.
- Kategorie - lista kategorii i ich opisy dla zadania wyszukującego.

## Format partii

Plik partii nazywa się `RRRR-MM-DD-GGMM.json` i leży w `inbox/`. Pola `run`, `offers` i `images` są bez zmian. Pola v3 (`searched_products`, `product_offers`, `leaflets`, `leaflet_hits`) są opcjonalne.

Pełny opis pól i reguły importu są w `docs/v3-spec.md`. Przykład z danymi zmyślonymi jest w `docs/przyklad-partii-v3.json`.

## Cron

Import działa co 15 minut:

```
*/15 * * * * php .../public_html/okazje/ingest.php >> ~/okazje-ingest.log 2>&1
```

Przełączniki `ingest.php`: `--local` (tylko `inbox/`), `--prune` (tylko czyszczenie), `--images` (tylko szukanie brakujących zdjęć). Log jest w `~/okazje-ingest.log`.

## feed.php

Adres: `feed.php?token=...`. Token jest w `config.php` jako `feed_token`. Bez poprawnego tokenu odpowiedź to 403. Odpowiedź zawiera klucze:

- `categories` - kategorie z opisami dla wyszukiwania.
- `extra_sources` - aktywne strony do przeszukiwania.
- `missing_images` - oferty bez zdjęcia.
- v3: `products`, `leaflet_shops`, `leaflets_known`, `known_hits`. Szczegóły w specyfikacji, sekcja 3.

## Wdrażanie (v3)

1. Zaloguj się do panelu strony.
2. Otwórz https://michalfojt.pl/okazje/deploy.php.
3. Sprawdź listę plików. Statusy: zgodny, inny, brak na serwerze. Porównanie idzie po sha256 z `site/MANIFEST.json` w repo.
4. Kliknij „Wdróż zmienione".

Przed nadpisaniem każdy zmieniony plik trafia do `data/backup/<RRRRmmdd-HHMMSS>/`. Deploy nie rusza `config.php`, katalogów `data/`, `img/`, `inbox/` ani plików spoza manifestu.

## Zmiana hasła

Na hostingu, w katalogu `okazje`:

```
php passwd.php 'nowe-haslo'
```

Skrypt wypisuje hash. Wklej go do `config.php` jako `pass_hash`. Hasło musi mieć co najmniej 10 znaków. Skrypt nie działa z przeglądarki.

## Bezpieczeństwo

- `config.php` nigdy nie idzie do repo. Zawiera hasło, hash i token.
- Repo jest publiczne, bo strona też jest publiczna. Nie wrzucaj tu haseł, tokenów, adresów domowych ani danych logowania. Przykłady w `docs/` mają dane zmyślone.
- Dane z partii są niezaufane. Kod przycina długości, sprawdza adresy `http(s)` i nie przepuszcza HTML. Teksty w UI idą przez `textContent`.
- `.htaccess` blokuje `config`, `lib`, `lib3`, `api3`, `ingest` i `passwd` oraz pliki `.json`, `.md`, `.sh`, `.log` i `.tmp`.
- CSP strony to `default-src 'self'`. Zewnętrzne zasoby nie są ładowane.
