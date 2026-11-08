#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXPECTED_BRANCH="develop"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dondebailar}"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$APP_DIR"

if ! command -v git >/dev/null 2>&1; then
    echo "FOUT: Git is niet geïnstalleerd." >&2
    exit 1
fi
if ! command -v php >/dev/null 2>&1; then
    echo "FOUT: PHP CLI is niet geïnstalleerd of niet beschikbaar in PATH." >&2
    exit 1
fi
if [[ ! -f config/config.local.php ]]; then
    echo "FOUT: config/config.local.php ontbreekt. Maak eerst de lokale configuratie." >&2
    exit 1
fi

CURRENT_BRANCH="$(git branch --show-current)"
if [[ "$CURRENT_BRANCH" != "$EXPECTED_BRANCH" ]]; then
    echo "FOUT: verwacht branch '$EXPECTED_BRANCH', huidige branch is '$CURRENT_BRANCH'." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
    echo "FOUT: de repository bevat lokale wijzigingen. Los die eerst op; het script overschrijft ze niet." >&2
    git status --short
    exit 1
fi

mkdir -p "$BACKUP_DIR"
PREVIOUS_COMMIT="$(git rev-parse HEAD)"
BUNDLE="$BACKUP_DIR/dondebailar-test-$STAMP.bundle"

echo "Maak Git-back-up: $BUNDLE"
git bundle create "$BUNDLE" --all

echo "Haal updates op van origin/$EXPECTED_BRANCH ..."
git fetch origin "$EXPECTED_BRANCH"
git merge --ff-only "origin/$EXPECTED_BRANCH"

echo "Controleer PHP-syntax ..."
if ! git ls-files -z -- '*.php' | xargs -0 -r -n 1 php -l; then
    echo "FOUT: PHP-syntaxcontrole mislukt. Herstel code naar $PREVIOUS_COMMIT." >&2
    git reset --hard "$PREVIOUS_COMMIT"
    exit 1
fi

echo
echo "Testupdate is bijgewerkt."
echo "Vorige commit: $PREVIOUS_COMMIT"
echo "Nieuwe commit:  $(git rev-parse HEAD)"
echo "Git-back-up:    $BUNDLE"
echo
echo "Let op: dit script voert geen database-migraties uit en test nog niet of de website functioneel werkt."
