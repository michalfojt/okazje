#!/usr/bin/env python3
"""Generuje site/MANIFEST.json (sha256 plików wdrażanych przez site/deploy.php).

Użycie: tools/manifest.py [katalog_site]   (domyślnie: <repo>/site)
"""
import datetime
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

# Biała lista jak w deploy.php (spec, sekcja 7). config.php nigdy.
ALLOWED = re.compile(r"(?:[a-z0-9_]+\.php|assets/[a-z0-9_]+\.(?:js|css)|\.htaccess)")
NEVER = {"config.php"}
# Pomijane po cichu: nie są częścią wdrożenia.
SILENT_NAMES = {"MANIFEST.json", "README.md", ".gitattributes"}
SILENT_DIRS = {"tests"}
MAX_FILES = 60
MAX_BYTES = 2 * 1024 * 1024


def main() -> int:
    root = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / "site"
    if not root.is_dir():
        print(f"Brak katalogu: {root}", file=sys.stderr)
        return 2

    files = {}
    skipped = []
    for p in sorted(x for x in root.rglob("*") if x.is_file()):
        rel = p.relative_to(root).as_posix()
        if rel in SILENT_NAMES or rel.split("/")[0] in SILENT_DIRS:
            continue
        if rel in NEVER or not ALLOWED.fullmatch(rel):
            skipped.append(rel)
            continue
        if p.stat().st_size > MAX_BYTES:
            skipped.append(rel + " (powyżej 2 MB)")
            continue
        files[rel] = hashlib.sha256(p.read_bytes()).hexdigest()

    if len(files) > MAX_FILES:
        print(f"Za dużo plików: {len(files)} (limit {MAX_FILES})", file=sys.stderr)
        return 1

    try:
        commit = subprocess.run(
            ["git", "rev-parse", "HEAD"], cwd=root, capture_output=True, text=True, check=True
        ).stdout.strip() or None
    except (OSError, subprocess.CalledProcessError):
        commit = None

    manifest = {
        "generated": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
        "commit": commit,
        "files": files,
    }
    (root / "MANIFEST.json").write_text(
        json.dumps(manifest, sort_keys=True, indent=1, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n"
    )
    for s in skipped:
        print(f"pominięto (poza białą listą): {s}", file=sys.stderr)
    print(f"MANIFEST.json: {len(files)} plików, commit {commit or 'null'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
