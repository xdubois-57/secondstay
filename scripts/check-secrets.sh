#!/usr/bin/env bash
#
# Vérifie qu'aucun secret ni donnée runtime n'est versionné (SECURITY.md §11).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

STATUS=0
report() {
    printf '  ✘ %s\n' "$1"
    STATUS=1
}

# Les fichiers non encore commités comptent : sinon un secret ne serait
# détecté qu'une fois déjà versionné, c'est-à-dire trop tard. `--others
# --exclude-standard` ajoute exactement ce que `git add .` prendrait.
TRACKED="$(git ls-files --cached --others --exclude-standard 2>/dev/null || true)"
if [ -z "$TRACKED" ]; then
    printf 'Dépôt git introuvable : contrôle des secrets ignoré.\n'
    exit 0
fi

# 1. Fichiers interdits.
while IFS= read -r file; do
    case "$file" in
        config/local.php|.env|.env.*|*.pem|*.key|*.p12|*.pfx)
            report "Fichier sensible versionné : $file" ;;
        storage/*)
            if [ "$file" != "storage/.gitkeep" ]; then
                report "Donnée runtime versionnée : $file"
            fi ;;
        vendor/*|node_modules/*)
            report "Dépendance versionnée : $file" ;;
    esac
done <<< "$TRACKED"

# 2. Motifs de secrets dans les fichiers suivis.
PATTERNS=(
    'BEGIN [A-Z ]*PRIVATE KEY'
    'live_[A-Za-z0-9]{20,}'
    'test_[A-Za-z0-9]{25,}'
    'AKIA[0-9A-Z]{16}'
    'xox[baprs]-[A-Za-z0-9-]{10,}'
    'gh[pousr]_[A-Za-z0-9]{30,}'
)
for pattern in "${PATTERNS[@]}"; do
    matches="$(git grep --untracked -nIE "$pattern" -- . ':(exclude)scripts/check-secrets.sh' 2>/dev/null || true)"
    if [ -n "$matches" ]; then
        report "Motif de secret détecté ($pattern) :"
        printf '%s\n' "$matches" | sed 's/^/      /'
    fi
done

# 3. Les valeurs de secret des defaults doivent rester vides.
if ! php -r '
$config = require "config/app.php";
$bad = [];
foreach ([["security","encryption_key"],["database","password"],["database","user"],["database","name"]] as $path) {
    $value = $config[$path[0]][$path[1]] ?? "";
    if ($value !== "") { $bad[] = implode(".", $path); }
}
if ($bad !== []) { fwrite(STDERR, "Valeurs non vides dans config/app.php : " . implode(", ", $bad) . PHP_EOL); exit(1); }
'; then
    report "config/app.php contient des valeurs spécifiques à une installation"
fi

if [ $STATUS -eq 0 ]; then
    printf '  ✔ Aucun secret ni donnée runtime versionné\n'
fi
exit $STATUS
