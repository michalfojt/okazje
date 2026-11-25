# okazje - kod strony

Strona z okazjami pod https://michalfojt.pl/okazje/. Działa na hostingu seohost w PHP 8.1+, bez bazy danych. Dane są w plikach JSON w katalogu `data/`. Ten katalog jest lustrem `public_html/okazje/` na hostingu.

Partie wyników nie są pisane przez ludzi. Zadanie cykliczne „Okazje dla Michała" zapisuje je w `okazje/inbox/` w repo, a strona importuje je cronem.

## Struktura plików

Kod w tym katalogu:

- `lib.php` - wspólne funkcje: odczyt i zapis JSON (`jread()`, `jwrite()`), blokada zapisu (`locked()`), normalizacja adresów, import partii v2, konfiguracja (`cfg()`).
- `lib5.php` - archiwum pozycji gazetek i ocena atrakcyjności.
- `lib6.php` - import cyfrowych paragonów do Moich produktów.
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

## Atrakcyjność 1-10 i archiwum (lib5.php)

- Każda pozycja gazetki trafia na stałe do `data/arch-RRRR-MM.json` (miesiąc początku promocji). Zapis przy imporcie, w cronie (`arch_sync()`) i przed usunięciem starej gazetki.
- Punkty za realny rabat po spełnieniu warunku: poniżej 10% = 2, od 10 = 3, 20 = 4, 25 = 5, 33 = 6, 40 = 7, 45 = 8, 55 = 9, 65 = 10. „Do -X%” liczone jako 60% X. Karta i zakup kilku sztuk nie obniżają oceny.
- Historia: promocje z 365 dni zakończone przed początkiem bieżącej, jedna na sklep i datę. Porównanie ceny za kg, l, szt lub opakowanie z medianą (marka i kategoria: głębokość rabatu). Waga 30% przy 1 obserwacji, 50% przy 2, 70% od 3; inna gramatura: połowa wagi.
- Turbo okazja: ta sama gramatura, co najmniej 3 obserwacje z 28 dni, cena nie wyższa od minimum i co najmniej 15% poniżej mediany. Pozorna okazja: rabat od 25%, ale cena nie niższa od mediany (min. 2 obserwacje), ocena najwyżej 5.
- Brak rabatu i historii: 3 z oznaczeniem niepewności.
- „To inny produkt” zapisuje rozłączoną parę w `data/arch_split.json` (API `hist_split`).
- Pozycje gazetek w partii mogą mieć opcjonalne pola `brand`, `size`, `unit_price`.

## Import paragonów (lib6.php, ocr.php)

- „Wgraj paragony” w Moich produktach przyjmuje pliki JSON z aplikacji Biedronki (do 30 naraz). Przeglądarka wysyła tylko pozycje sprzedaży i stopkę fiskalną. Plik paragonu nie jest zapisywany.
- Przyjmuje też zdjęcia paragonów z aplikacji Lidla (PNG, JPG, WebP). Tekst odczytuje w przeglądarce Tesseract (OCR); na serwer trafia tylko tekst od początku paragonu do jego numeru, obrazek nie jest wysyłany.
- Pliki OCR podaje `ocr.php` (tylko po zalogowaniu): przy pierwszym użyciu hosting pobiera je z cdn.jsdelivr.net (zapas: unpkg.com) w wersjach 7.0.0 i 1.0.0, sprawdza sha256 i trzyma w `data/ocr/`. Zmiana wersji wymaga nowych sum w `ocr.php`.
- Zakupy jednorazowe (narzędzia, elektronarzędzia, akumulatory, sprzęt ogrodowy) są pomijane w obu sklepach i wypisane w wyniku importu. Lista słów: `RECEIPT_ONEOFF` w `lib6.php`; w Lidlu także pozycje z numerem artykułu w nazwie (6 cyfr i więcej).
- Odczyt ze zdjęcia może mylić znaki, zwłaszcza przy małych obrazkach. Różnica jednej litery nie tworzy duplikatu; przy zdjęciu węższym niż 600 px łączone są też nazwy różniące się tylko liczbami.
- Produkt ma `source: receipt` i pole `receipt` (klucze nazw z paragonu, liczba zakupów, ostatnia data i cena). Ten sam paragon wgrany drugi raz niczego nie zmienia. Torby, kaucje i storna są pomijane.
- Usunięty produkt trafia na listę `data/receipt_ignored.json` i nie wraca przy kolejnym imporcie, chyba że użytkownik kliknie „Przywróć usunięte”.
- Dopasowanie do gazetek: pusta marka oznacza „bez marki”, a fraza ze słów kluczowych porównywana jest z nazwą pozycji (jedno słowo musi ją zaczynać).
- Rozwijanie skrótów, rozpoznawanie marek i słowa kluczowe to listy w `lib6.php`.
- Nie wrzucaj prawdziwych paragonów do repo (test `receipt_test.php` ma dane zmyślone).

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

## Konta dodatkowe

`users.php` (tylko właściciel, czyli konto z `config.php`): dodanie, zmiana hasła i usunięcie konta. Hashe są w `data/users.json`. Konto dodatkowe nie ma zakładek Oferty i Wybrane (także w API) ani dostępu do `users.php`, `deploy.php` i `snapshot.php`. Moje produkty, Gazetki i Kategorie są wspólne.

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
