# okazje

Źródło strony https://michalfojt.pl/okazje/.

- `site/` - kod strony (PHP, JS, CSS). Opis kodu, zakładek i wdrażania jest w `site/README.md`.
- `okazje/inbox/` - partie wyników w formacie JSON (`RRRR-MM-DD-GGMM.json`). Pisze je zadanie cykliczne „Okazje dla Michała", a strona importuje je cronem.
- `docs/` - specyfikacja v3 (`docs/v3-spec.md`) i przykładowa partia (`docs/przyklad-partii-v3.json`).
- `tools/` - narzędzia pomocnicze, m.in. generator `site/MANIFEST.json` używanego przez `deploy.php`.

Pliki `okazje/index.html` i `hosting/pull.sh` są pozostałościami starej, statycznej wersji strony, nie są już używane, a strona działa teraz na PHP z katalogu `site/`.

Repo jest publiczne, bo strona też jest publiczna. Nie wrzucaj tu haseł ani tokenów.
