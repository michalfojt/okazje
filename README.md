# okazje

Źródło strony https://michalfojt.pl/okazje/.

- `okazje/index.html` – bieżąca strona z okazjami. Nadpisuje ją zadanie zaplanowane „Okazje dla Michała” (6:00, 14:00, 20:00 Europe/Warsaw).
- `hosting/pull.sh` – kopia skryptu z hostingu seohost (`~/okazje/pull.sh`). Cron w DirectAdmin co 15 min pobiera `okazje/index.html` z tego repo i podmienia stronę, jeśli plik jest poprawny i się zmienił.

Repo jest publiczne, bo strona też jest publiczna. Nie wrzucaj tu haseł ani tokenów.
