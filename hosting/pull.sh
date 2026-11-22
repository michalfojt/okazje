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

RAW="$DIR/index.raw.html"
if [ -f "$RAW" ] && [ -f "$DEST/index.html" ] && cmp -s "$TMP" "$RAW"; then
  rm -f "$TMP"
  exit 0
fi
cp -f "$TMP" "$RAW"

# Zdjecia: kazde http(s) <img src> pobieramy na serwer do img/ i podmieniamy adres na lokalny.
# Gdy pobranie sie nie uda, zostaje oryginalny adres (strona ma fallback "brak zdjecia").
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
IMG="$DEST/img"
mkdir -p "$IMG"
OK=0; BAD=0
grep -o '<img[^>]*src="[a-z]*://[^"]*"' "$TMP" | sed 's/.*src="//; s/"$//' | sort -u > "$DIR/imgs.list"
while IFS= read -r u; do
  [ -n "$u" ] || continue
  h=$(printf '%s' "$u" | md5sum | cut -c1-16)
  ex=$(ls "$IMG" 2>/dev/null | grep "^$h\." | head -n 1)
  if [ -z "$ex" ]; then
    curl -fsSL --max-time 30 -A "$UA" -o "$IMG/$h.dl" "$u" 2>/dev/null
    sz=0; magic=""
    if [ -f "$IMG/$h.dl" ]; then
      sz=$(wc -c < "$IMG/$h.dl" | tr -d ' ')
      magic=$(head -c 4 "$IMG/$h.dl" | od -An -tx1 | tr -d ' \n')
    fi
    case "$magic" in
      ffd8ff*) e=jpg ;; 89504e47) e=png ;; 47494638) e=gif ;; 52494646) e=webp ;; *) e="" ;;
    esac
    if [ -n "$e" ] && [ "${sz:-0}" -ge 1500 ]; then
      mv -f "$IMG/$h.dl" "$IMG/$h.$e"; chmod 644 "$IMG/$h.$e"; ex="$h.$e"
    else
      rm -f "$IMG/$h.dl"; ex=""
    fi
  fi
  if [ -n "$ex" ]; then
    esc=$(printf '%s' "$u" | sed 's/[][\\.*^$|&\/]/\\&/g')
    sed "s|$esc|img/$ex|g" "$TMP" > "$TMP.2" && mv -f "$TMP.2" "$TMP"
    touch "$IMG/$ex"; OK=$((OK+1))
  else
    BAD=$((BAD+1))
  fi
done < "$DIR/imgs.list"
find "$IMG" -type f -mtime +30 -delete 2>/dev/null
log "zdjecia: $OK lokalnie, $BAD bez pobrania"
SIZE=$(wc -c < "$TMP" | tr -d ' ')

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
