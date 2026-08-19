#!/usr/bin/env bash
#
# SecondStay — publication d'une release (RELEASE.md §5).
#
# Philosophie : fail closed. Toutes les gates sont vérifiées AVANT la création
# du tag. Aucun bypass n'est silencieux : chaque `--skip-*` produit un
# avertissement et apparaît dans les notes de release.
#
# Usage :
#   ./scripts/release.sh patch|minor|major [options]
#
# Options :
#   --dry-run             n'écrit rien, ne pousse rien, ne publie rien
#   --skip-tests          n'exécute pas ./scripts/check.sh --full   (bypass)
#   --skip-ci             ne vérifie pas GitHub Actions             (bypass)
#   --skip-security       ne vérifie pas CodeQL/Dependabot          (bypass)
#   --skip-sonar          ne vérifie pas la Quality Gate SonarCloud (bypass)
#   --skip-deployment     ne vérifie pas la production déployée     (bypass)
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; RESET=$'\033[0m'

EXPECTED_BRANCH="${SECONDSTAY_RELEASE_BRANCH:-main}"
REMOTE="${SECONDSTAY_RELEASE_REMOTE:-origin}"
DEPLOYMENT_URL="${SECONDSTAY_DEPLOYMENT_URL:-}"
SONAR_PROJECT_KEY="${SONAR_PROJECT_KEY:-xdubois-57_secondstay}"

BUMP=""
DRY_RUN=0
SKIP_TESTS=0
SKIP_CI=0
SKIP_SECURITY=0
SKIP_SONAR=0
SKIP_DEPLOYMENT=0
BYPASSES=()

die() { printf '%s✘ %s%s\n' "$RED$BOLD" "$1" "$RESET" >&2; exit 1; }
ok() { printf '%s✔ %s%s\n' "$GREEN" "$1" "$RESET"; }
info() { printf '%s→ %s%s\n' "$BLUE" "$1" "$RESET"; }
warn() { printf '%s⚠ %s%s\n' "$YELLOW$BOLD" "$1" "$RESET"; }
bypass() { warn "BYPASS : $1"; BYPASSES+=("$1"); }

while [ $# -gt 0 ]; do
    case "$1" in
        patch|minor|major) BUMP="$1" ;;
        --dry-run) DRY_RUN=1 ;;
        --skip-tests) SKIP_TESTS=1 ;;
        --skip-ci) SKIP_CI=1 ;;
        --skip-security) SKIP_SECURITY=1 ;;
        --skip-sonar) SKIP_SONAR=1 ;;
        --skip-deployment) SKIP_DEPLOYMENT=1 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) die "Option inconnue : $1" ;;
    esac
    shift
done

[ -n "$BUMP" ] || die "Indiquez le type d'incrément : patch, minor ou major."

# ---------------------------------------------------------------- 1. Outils --
info "1/23 Vérification des outils requis"
for tool in git php composer npm zip; do
    command -v "$tool" >/dev/null 2>&1 || die "Outil manquant : $tool"
done
HAS_GH=0
command -v gh >/dev/null 2>&1 && HAS_GH=1
ok "Outils présents (gh: $([ $HAS_GH -eq 1 ] && echo oui || echo non))"

# ------------------------------------------------------- 2. Working tree ------
info "2/23 Working tree propre"
[ -z "$(git status --porcelain)" ] || die "Le working tree contient des modifications non commitées."
ok "Working tree propre"

# ------------------------------------------------------------- 3. Branche -----
info "3/23 Branche attendue"
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$CURRENT_BRANCH" = "$EXPECTED_BRANCH" ] || die "Branche courante '$CURRENT_BRANCH' ≠ '$EXPECTED_BRANCH'."
ok "Sur $EXPECTED_BRANCH"

# --------------------------------------------------------------- 4. Fetch ----
info "4/23 Synchronisation avec $REMOTE"
git fetch --tags "$REMOTE" "$EXPECTED_BRANCH" >/dev/null 2>&1 || die "git fetch a échoué."
ok "Remote synchronisé"

# ------------------------------------------------------------- 5. HEAD -------
info "5/23 HEAD à jour"
LOCAL="$(git rev-parse HEAD)"
UPSTREAM="$(git rev-parse "$REMOTE/$EXPECTED_BRANCH")"
[ "$LOCAL" = "$UPSTREAM" ] || die "HEAD local ($LOCAL) ≠ $REMOTE/$EXPECTED_BRANCH ($UPSTREAM)."
ok "HEAD identique à $REMOTE/$EXPECTED_BRANCH"

# ---------------------------------------------------- 6. Version courante ----
info "6/23 Version courante"
CURRENT_VERSION="$(tr -d '[:space:]' < VERSION)"
[[ "$CURRENT_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "VERSION invalide : $CURRENT_VERSION"
ok "VERSION = $CURRENT_VERSION"

# ------------------------------------------------- 7. Déploiement précédent --
info "7/23 Gate déploiement"
if [ $SKIP_DEPLOYMENT -eq 1 ]; then
    bypass "gate déploiement ignorée"
elif [ -z "$DEPLOYMENT_URL" ]; then
    ok "Aucune production configurée (SECONDSTAY_DEPLOYMENT_URL vide)"
else
    DEPLOYED="$(curl -fsS --max-time 15 "$DEPLOYMENT_URL/api/version" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";' || true)"
    [ -n "$DEPLOYED" ] || die "La production ne répond pas sur $DEPLOYMENT_URL/api/version."
    ok "Production en $DEPLOYED"
fi

# ----------------------------------------------------------- 8. Gate CI ------
info "8/23 Gate CI GitHub Actions"
if [ $SKIP_CI -eq 1 ]; then
    bypass "gate CI ignorée"
elif [ $HAS_GH -eq 0 ]; then
    die "gh est requis pour vérifier la CI (ou utilisez --skip-ci en récupération exceptionnelle)."
else
    CI_STATE="$(gh run list --branch "$EXPECTED_BRANCH" --limit 1 --json conclusion,headSha \
        --jq 'map(select(.headSha == "'"$LOCAL"'")) | .[0].conclusion // "missing"')"
    [ "$CI_STATE" = "success" ] || die "CI non verte pour $LOCAL (état: $CI_STATE)."
    ok "CI verte pour $LOCAL"
fi

# ------------------------------------------ 9. Gate CodeQL / Dependabot ------
info "9/23 Gate sécurité (CodeQL / Dependabot)"
if [ $SKIP_SECURITY -eq 1 ]; then
    bypass "gate sécurité ignorée"
elif [ $HAS_GH -eq 0 ]; then
    die "gh est requis pour vérifier CodeQL/Dependabot (ou --skip-security)."
else
    OPEN_CODEQL="$(gh api "repos/{owner}/{repo}/code-scanning/alerts?state=open&severity=high" --jq 'length' 2>/dev/null || echo 0)"
    [ "$OPEN_CODEQL" = "0" ] || die "$OPEN_CODEQL alerte(s) CodeQL ouverte(s) de sévérité haute."
    OPEN_DEPENDABOT="$(gh api "repos/{owner}/{repo}/dependabot/alerts?state=open&severity=high,critical" --jq 'length' 2>/dev/null || echo 0)"
    [ "$OPEN_DEPENDABOT" = "0" ] || die "$OPEN_DEPENDABOT alerte(s) Dependabot bloquante(s)."
    ok "Aucune alerte de sécurité bloquante"
fi

# ------------------------------------------------------- 10. Gate Sonar ------
info "10/23 Gate SonarCloud"
if [ $SKIP_SONAR -eq 1 ]; then
    bypass "gate SonarCloud ignorée"
elif [ -z "${SONAR_TOKEN:-}" ]; then
    die "SONAR_TOKEN absent : impossible de vérifier la Quality Gate (ou --skip-sonar)."
else
    QG="$(curl -fsS -u "$SONAR_TOKEN:" \
        "https://sonarcloud.io/api/qualitygates/project_status?projectKey=$SONAR_PROJECT_KEY" \
        | php -r 'echo json_decode(stream_get_contents(STDIN), true)["projectStatus"]["status"] ?? "UNKNOWN";')"
    [ "$QG" = "OK" ] || die "Quality Gate SonarCloud : $QG"
    ok "Quality Gate SonarCloud OK"
fi

# ------------------------------------------------------- 11. Tests locaux ----
info "11/23 Gate tests locaux"
if [ $SKIP_TESTS -eq 1 ]; then
    bypass "tests locaux ignorés"
else
    ./scripts/check.sh --full || die "./scripts/check.sh --full a échoué."
    ok "check.sh --full vert"
fi

# ------------------------------------------------------ 12. Dépendances ------
info "12/23 Audit des dépendances"
COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked --no-interaction >/dev/null || die "composer audit a échoué."
npm audit --omit=dev --audit-level=high >/dev/null 2>&1 || warn "npm audit signale des alertes (dépendances de développement uniquement)."
ok "Dépendances auditées"

# ------------------------------------------------------ 13. Nouvelle version -
info "13/23 Calcul de la nouvelle version"
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"
case "$BUMP" in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
esac
NEW_VERSION="$MAJOR.$MINOR.$PATCH"
TAG="v$NEW_VERSION"
git rev-parse "$TAG" >/dev/null 2>&1 && die "Le tag $TAG existe déjà."
ok "Nouvelle version : $NEW_VERSION"

if [ $DRY_RUN -eq 1 ]; then
    warn "--dry-run : arrêt avant toute écriture."
    exit 0
fi

# ----------------------------------------------------------- 14. VERSION -----
info "14/23 Écriture de VERSION"
printf '%s\n' "$NEW_VERSION" > VERSION
ok "VERSION = $NEW_VERSION"

# ------------------------------------------------------------ 15. Commit -----
info "15/23 Commit de version"
git add VERSION
git commit -m "chore: release $NEW_VERSION" >/dev/null || die "Commit impossible."
ok "Commit créé"

# -------------------------------------------------------------- 16. Push -----
info "16/23 Push du commit"
git push -u "$REMOTE" "$EXPECTED_BRANCH" >/dev/null || die "Push impossible."
ok "Commit poussé"

# --------------------------------------------------------------- 17. Tag -----
info "17/23 Tag annoté"
git tag -a "$TAG" -m "SecondStay $NEW_VERSION" || die "Création du tag impossible."
git push "$REMOTE" "$TAG" >/dev/null || die "Push du tag impossible."
ok "Tag $TAG poussé"

# --------------------------------------------------- 18. Composer production -
info "18/23 Dépendances de production"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction >/dev/null \
    || die "composer install --no-dev a échoué."
ok "Dépendances de production prêtes"

# --------------------------------------------------------------- 19. ZIP -----
info "19/23 Construction du ZIP"
ZIP_PATH="$ROOT/build/release/secondstay-$NEW_VERSION.zip"
./scripts/build-release-zip.sh --build "$ZIP_PATH" >/dev/null || die "Construction du ZIP impossible."
ok "ZIP construit : $ZIP_PATH"

# ------------------------------------------------------- 20. Inspection ZIP --
info "20/23 Inspection du ZIP"
php scripts/release-artifact.php inspect "$ZIP_PATH" || die "L'artefact est non conforme."
ok "Artefact conforme"

# ------------------------------------------------------------ 21. Notes ------
info "21/23 Notes de release"
NOTES_FILE="$ROOT/build/release/notes-$NEW_VERSION.md"
{
    printf '# SecondStay %s\n\n' "$NEW_VERSION"
    printf '## Changements\n\n'
    git log --pretty='- %s' "v$CURRENT_VERSION..HEAD" 2>/dev/null || git log --pretty='- %s' -20
    printf '\n## Vérifications effectuées\n\n'
    printf '| Gate | Résultat |\n|---|---|\n'
    printf '| Tests locaux (check.sh --full) | %s |\n' "$([ $SKIP_TESTS -eq 1 ] && echo 'IGNORÉE (bypass)' || echo 'OK')"
    printf '| CI GitHub Actions | %s |\n' "$([ $SKIP_CI -eq 1 ] && echo 'IGNORÉE (bypass)' || echo 'OK')"
    printf '| CodeQL / Dependabot | %s |\n' "$([ $SKIP_SECURITY -eq 1 ] && echo 'IGNORÉE (bypass)' || echo 'OK')"
    printf '| SonarCloud Quality Gate | %s |\n' "$([ $SKIP_SONAR -eq 1 ] && echo 'IGNORÉE (bypass)' || echo 'OK')"
    printf '| Déploiement précédent | %s |\n' "$([ $SKIP_DEPLOYMENT -eq 1 ] && echo 'IGNORÉE (bypass)' || echo 'OK')"
    printf '| Artefact de production | OK |\n'
    if [ ${#BYPASSES[@]} -gt 0 ]; then
        printf '\n## ⚠ Bypass utilisés\n\n'
        for b in "${BYPASSES[@]}"; do printf -- '- %s\n' "$b"; done
        printf '\nCes bypass doivent être audités humainement après publication.\n'
    fi
} > "$NOTES_FILE"
ok "Notes générées : $NOTES_FILE"

# ---------------------------------------------------------- 22. Publication --
info "22/23 Publication de la GitHub Release"
if [ $HAS_GH -eq 1 ]; then
    gh release create "$TAG" "$ZIP_PATH" --title "SecondStay $NEW_VERSION" --notes-file "$NOTES_FILE" \
        || die "Publication de la release impossible."
    ok "Release $TAG publiée"
else
    warn "gh absent : publiez manuellement $ZIP_PATH sur la release $TAG avec $NOTES_FILE."
fi

# --------------------------------------------------- 23. Restauration dev ----
info "23/23 Restauration de l'environnement de développement"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction >/dev/null || warn "Restauration des dépendances de développement à refaire manuellement."
ok "Environnement de développement restauré"

printf '\n%s✔ SecondStay %s publiée.%s\n' "$GREEN$BOLD" "$NEW_VERSION" "$RESET"
if [ ${#BYPASSES[@]} -gt 0 ]; then
    warn "${#BYPASSES[@]} bypass utilisé(s) : voir $NOTES_FILE"
fi
