#!/usr/bin/env bash
# Test CLI v3: kopiuje site/ do katalogu tymczasowego, tworzy config.php i uruchamia v3_test.php.
# Nie wymaga internetu. Użycie: bash site/tests/run.sh
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(dirname "$HERE")"
TMP="$(mktemp -d)"
SITE="$TMP/site"
DATA="$TMP/data"
cleanup() {
    pkill -f -- "-t $SITE" 2>/dev/null || true
    rm -rf "$TMP"
}
trap cleanup EXIT

mkdir -p "$SITE" "$DATA"
cp -a "$SRC/." "$SITE/"
rm -rf "$SITE/tests" "$SITE/data" "$SITE/config.php" "$SITE/img" "$SITE/inbox"
mkdir -p "$SITE/inbox"

php -r '
echo "<?php\nreturn ", var_export([
    "user" => "michal",
    "pass_hash" => password_hash("testtesttest", PASSWORD_DEFAULT),
    "feed_token" => "tok",
    "data_dir" => $argv[1],
    "retention_days" => 14,
    "github_inbox" => null,
    "allow_private_hosts" => false,
], true), ";\n";
' "$DATA" > "$SITE/config.php"

rc=0
for f in lib lib3 lib4 lib5 lib6 api api3 ingest feed index snapshot; do
    out="$(php -l "$SITE/$f.php" 2>&1)" || { echo "BŁĄD składni $f.php: $out"; rc=1; }
done
[ "$rc" -eq 0 ] || exit 1

php -d error_reporting=-1 "$HERE/snap_test.php" "$SITE" "$DATA" || exit 1
php -d error_reporting=-1 "$HERE/match_test.php" "$SITE" "$DATA" || exit 1
rm -f "$DATA"/*.json
php -d error_reporting=-1 "$HERE/deal_test.php" "$SITE" "$DATA" || exit 1
rm -f "$DATA"/*.json
rm -f "$DATA"/*.json
php -d error_reporting=-1 "$HERE/score_test.php" "$SITE" "$DATA" || exit 1
rm -f "$DATA"/*.json
php -d error_reporting=-1 "$HERE/receipt_test.php" "$SITE" "$DATA" || exit 1
rm -f "$DATA"/*.json
php -d error_reporting=-1 "$HERE/v3_test.php" "$SITE" "$DATA" "$TMP"
exit $?
