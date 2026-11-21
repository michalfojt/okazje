#!/bin/sh
# okazje-pull: pobiera najnowszą stronę z GitHub i podmienia michalfojt.pl/okazje/index.html.
# Uruchamiany z crona DirectAdmin: */15 * * * * /bin/sh $HOME/okazje/pull.sh
# Konfiguracja (w katalogu $HOME/okazje, poza public_html):
#   url.txt   - jedna linia: adres pliku index.html w repozytorium (raw lub API GitHub)
#   token.txt - opcjonalnie, token GitHub tylko do odczytu dla prywatnego repo (chmod 600)
# Brak url.txt = skrypt nic nie robi.

DIR="$HOME/okazje"
DEST="$HOME/domains/michalfojt.pl/public_html/okazje"
LOG="$DIR/pull.log"
TMP="$DIR/index.new.html"

[ -s "$DIR/url.txt" ] || exit 0
URL=$(head -n 1 "$DIR/url.txt" | tr -d '\r\n ')
[ -n "$URL" ] || exit 0

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"; }

if [ -s "$DIR/token.txt" ]; then
  TOKEN=$(head -n 1 "$DIR/token.txt" | tr -d '\r\n ')
  curl -fsSL --max-time 60 \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/vnd.github.raw" \
    -o "$TMP" "$URL" 2>>"$LOG"
else
  curl -fsSL --max-time 60 -o "$TMP" "$URL" 2>>"$LOG"
fi
RC=$?
if [ $RC -ne 0 ]; then
  log "blad pobierania (curl $RC)"
  rm -f "$TMP"
  exit 1
fi

SIZE=$(wc -c < "$TMP" | tr -d ' ')
if [ "$SIZE" -lt 2000 ] || ! head -c 300 "$TMP" | grep -qi '<!doctype html'; then
  log "odrzucono plik: $SIZE B, brak <!doctype html> albo za maly"
  rm -f "$TMP"
  exit 1
fi

if [ -f "$DEST/index.html" ] && cmp -s "$TMP" "$DEST/index.html"; then
  rm -f "$TMP"
  exit 0
fi

mkdir -p "$DEST"
[ -f "$DEST/index.html" ] && cp -p "$DEST/index.html" "$DIR/index.prev.html"
chmod 644 "$TMP"
mv -f "$TMP" "$DEST/index.html"
log "zaktualizowano: $SIZE B"

# log nie rosnie bez konca
if [ "$(wc -l < "$LOG")" -gt 500 ]; then
  tail -n 200 "$LOG" > "$LOG.tmp" && mv -f "$LOG.tmp" "$LOG"
fi
exit 0
