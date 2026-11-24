#!/usr/bin/env bash
# Test lokalny site/deploy.php bez internetu: dwa serwery `php -S` (strona i "repo").
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="$ROOT/site"
T="$(mktemp -d)"
APP="$T/app"; REPO="$T/repo"
PIDS=()
ERRS=()
cleanup() { for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done; rm -rf "$T"; }
trap cleanup EXIT
export NO_PROXY=127.0.0.1 no_proxy=127.0.0.1
unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy

fail() { ERRS+=("$1"); }
check() { local d="$1"; shift; "$@" >/dev/null 2>&1 || fail "$d"; }
free_port() { python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1])'; }
sha() { sha256sum "$1" | cut -d' ' -f1; }

P1=$(free_port); P2=$(free_port)
mkdir -p "$APP" "$REPO" "$T/data"
cp -a "$SITE/." "$APP/"; rm -f "$APP/MANIFEST.json"
cp -a "$SITE/." "$REPO/"; rm -f "$REPO/MANIFEST.json"

# config testowy (hasło haszowane przez PHP, bez cytowania w shellu)
php -r '
$c = ["user"=>"michal","pass_hash"=>password_hash("testtesttest", PASSWORD_DEFAULT),"feed_token"=>"tok",
      "data_dir"=>$argv[1],"allow_private_hosts"=>true,"deploy_base"=>"http://127.0.0.1:".$argv[2]."/"];
file_put_contents($argv[3], "<?php\nreturn ".var_export($c,true).";\n");' "$T/data" "$P2" "$APP/config.php"
cp "$APP/config.php" "$T/config.orig"

# "repo": zmieniony jeden plik (assets/app.css) + manifest z tools/manifest.py
echo '/* zmiana testowa */' >> "$REPO/assets/app.css"
python3 "$ROOT/tools/manifest.py" "$REPO" >/dev/null 2>&1 || fail "manifest.py zawiodl"
cp "$APP/assets/app.css" "$T/css.orig"

# "repo" serwuje pliki jak raw.githubusercontent.com: surowa tresc, bez wykonywania PHP
cat > "$T/router.php" <<RT
<?php
\$f = '$REPO' . rawurldecode((string)parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (is_file(\$f)) { header('Content-Type: text/plain; charset=utf-8'); readfile(\$f); } else { http_response_code(404); }
RT
( cd "$REPO" && exec php -S 127.0.0.1:$P2 "$T/router.php" >"$T/repo.log" 2>&1 ) & PIDS+=($!)
( cd "$APP"  && exec php -S 127.0.0.1:$P1 -t "$APP"  >"$T/app.log"  2>&1 ) & PIDS+=($!)
for i in $(seq 1 50); do
  curl -s -o /dev/null "http://127.0.0.1:$P1/index.php" && curl -s -o /dev/null "http://127.0.0.1:$P2/MANIFEST.json" && break
  sleep 0.1
done
U="http://127.0.0.1:$P1"
JAR="$T/jar"

# 1. bez logowania: przekierowanie
R=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$U/deploy.php")
case "$R" in 302*"/") ;; *) fail "GET bez logowania: oczekiwano 302 na ./, jest: $R";; esac
R=$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'csrf=x' "$U/deploy.php")
[ "$R" = 302 ] || fail "POST bez logowania: oczekiwano 302, jest: $R"

# 2. logowanie
CS=$(curl -s -c "$JAR" -b "$JAR" "$U/index.php" | sed -n 's/.*<meta name="csrf" content="\([0-9a-f]*\)".*/\1/p' | head -1)
[ -n "$CS" ] || fail "brak tokenu csrf na stronie logowania"
curl -s -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "act=login" --data-urlencode "user=michal" \
  --data-urlencode "pass=testtesttest" --data-urlencode "csrf=$CS" "$U/index.php"
page() { curl -s -c "$JAR" -b "$JAR" "$U/deploy.php"; }
H=$(page)
echo "$H" | grep -q 'name="csrf"' || fail "po logowaniu deploy.php nie pokazuje formularza"
CS=$(echo "$H" | sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p' | head -1)

# 3. GET: dokladnie 1 plik "inny"
N_INNY=$(echo "$H" | grep -c 'data-st="inny"')
N_ZG=$(echo "$H" | grep -c 'data-st="zgodny"')
[ "$N_INNY" = 1 ] || fail "GET: oczekiwano 1 pliku 'inny', jest $N_INNY"
[ "$N_ZG" -ge 8 ] || fail "GET: oczekiwano reszty plikow jako 'zgodny', jest $N_ZG"
echo "$H" | grep -q 'Wdróż zmienione (1)' || fail "GET: brak przycisku 'Wdróż zmienione (1)'"
echo "$H" | grep -q 'assets/app.css' || fail "GET: brak assets/app.css na liscie"

# 4. POST bez CSRF i ze zlym CSRF: odrzucony, nic nie zapisane
R=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$U/deploy.php")
[ "$R" = 403 ] || fail "POST bez CSRF: oczekiwano 403, jest $R"
R=$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -d 'csrf=zly' "$U/deploy.php")
[ "$R" = 403 ] || fail "POST ze zlym CSRF: oczekiwano 403, jest $R"
cmp -s "$APP/assets/app.css" "$T/css.orig" || fail "POST bez CSRF zmienil app.css"
[ ! -d "$T/data/backup" ] || fail "POST bez CSRF utworzyl kopie zapasowa"

# 5. POST z CSRF: podmiana + kopia zapasowa
O=$(curl -s -c "$JAR" -b "$JAR" -d "csrf=$CS" "$U/deploy.php")
cmp -s "$APP/assets/app.css" "$REPO/assets/app.css" || fail "POST z CSRF nie podmienil app.css"
BK=$(find "$T/data/backup" -path '*/assets/app.css' 2>/dev/null | head -1)
if [ -z "$BK" ]; then fail "brak kopii zapasowej assets/app.css"; else cmp -s "$BK" "$T/css.orig" || fail "kopia zapasowa nie jest poprzednia wersja"; fi
echo "$O" | grep -q 'data-st="ok"' || fail "wynik: brak wpisu ok"
echo "$O" | grep -q '>Wróć<' || fail "wynik: brak odnosnika Wroc"
[ -z "$(find "$APP" -name '*.tmp' 2>/dev/null)" ] || fail "zostaly pliki .tmp"
H=$(page); echo "$H" | grep -c 'data-st="inny"' | grep -qx 0 || fail "po wdrozeniu nadal sa pliki 'inny'"

# 6. scenariusz niezgodny sha256 + sciezki spoza listy + nowy plik + deploy.php na koncu
cp "$APP/config.php" "$T/config.before"
sha_of_app_js=$(sha "$APP/assets/app.js")
echo '/* zly sha */' >> "$REPO/assets/app.js"                       # tresc w repo inna niz sha w manifescie
echo '<?php // zmiana passwd' >> "$REPO/passwd.php"                  # poprawny, zmieniony
echo '/* nowy */' > "$REPO/assets/nowy_plik.js"                       # brak na serwerze
printf '<?php return ["user"=>"evil"];\n' > "$REPO/config.php"
mkdir -p "$REPO/data"; echo '{"x":1}' > "$REPO/data/x.json"
printf '\n<?php // v2\n' >> "$REPO/deploy.php"                        # samopodmiana, ostatnia
python3 - "$REPO" <<'PY'
import hashlib, json, sys
root = sys.argv[1]
h = lambda p: hashlib.sha256(open(f"{root}/{p}", "rb").read()).hexdigest()
m = json.load(open(f"{root}/MANIFEST.json"))
f = m["files"]
for p in ["passwd.php", "assets/nowy_plik.js", "deploy.php", "config.php", "data/x.json"]:
    f[p] = h(p)
f["../config.php"] = h("config.php")
f["assets/app.js"] = "0" * 63 + "1"       # niezgodny sha256
f["..\\evil.php"] = h("config.php")
json.dump(m, open(f"{root}/MANIFEST.json", "w"))
PY
H=$(page)
echo "$H" | grep -c 'data-st="odrzucony"' | grep -qx 4 || fail "GET: oczekiwano 4 odrzuconych sciezek (config.php, ../config.php, data/x.json, ..\\evil.php)"
O=$(curl -s -c "$JAR" -b "$JAR" -d "csrf=$CS" "$U/deploy.php")
cmp -s "$APP/config.php" "$T/config.before" || fail "config.php zostal nadpisany"
[ ! -e "$T/config.php" ] || fail "../config.php zapisany poza katalogiem strony"
[ ! -e "$APP/data/x.json" ] && [ ! -e "$T/data/x.json" ] || fail "data/x.json zapisany"
[ "$(sha "$APP/assets/app.js")" = "$sha_of_app_js" ] || fail "plik z niezgodnym sha256 zostal zapisany"
echo "$O" | grep -c 'data-st="odrzucony"' | grep -qx 4 || fail "wynik: oczekiwano 4 odrzuconych"
echo "$O" | grep -c 'data-st="blad"' | grep -qx 1 || fail "wynik: oczekiwano 1 bledu (sha)"
cmp -s "$APP/passwd.php" "$REPO/passwd.php" || fail "passwd.php nie zostal wdrozony"
cmp -s "$APP/assets/nowy_plik.js" "$REPO/assets/nowy_plik.js" || fail "nowy plik nie zostal utworzony"
cmp -s "$APP/deploy.php" "$REPO/deploy.php" || fail "deploy.php nie zostal podmieniony"
echo "$O" | grep -q '>Wróć<' || fail "po samopodmianie brak odnosnika Wroc (wynik zepsuty)"
# deploy.php ma byc ostatnim wpisem wsrod zapisanych
OKS=$(echo "$O" | grep -c 'data-st="ok"')
[ "$OKS" = 3 ] || fail "wynik: oczekiwano 3 zapisanych (passwd, nowy plik, deploy), jest $OKS"
echo "$O" | grep -A3 'data-st="ok"' | grep -o 'class="mono">[^<]*' | tail -1 | grep -q 'deploy.php' || fail "deploy.php nie byl zapisany jako ostatni"
H=$(page); echo "$H" | grep -q 'Wdrażanie z GitHub' || fail "nowy deploy.php nie dziala po samopodmianie"
php -l "$APP/deploy.php" >/dev/null 2>&1 || fail "nowy deploy.php ma blad skladni"

# 7. manifest bez pola files i z blednym sha: odrzucony w calosci
echo '{"generated":"x","commit":null}' > "$REPO/MANIFEST.json"
echo "$(page)" | grep -q 'brak pola files' || fail "manifest bez files nie zostal odrzucony"
echo '{"files":{"lib.php":"xyz"}}' > "$REPO/MANIFEST.json"
echo "$(page)" | grep -q 'niepoprawną sumę sha256' || fail "manifest z blednym sha256 nie zostal odrzucony"

if [ ${#ERRS[@]} -eq 0 ]; then echo OK; exit 0; fi
printf 'BLAD: %s\n' "${ERRS[@]}"; exit 1
