#!/usr/bin/env bash
#
# SecondStay — publication d'une release (RELEASE.md §5).
#
# Philosophie : fail closed. Toutes les gates sont vérifiées AVANT la création
# du tag. Aucun bypass n'est silencieux : chaque `--skip-*` produit un
# avertissement et apparaît dans les notes de release.
#
# QUI CRÉE LA RELEASE
# ---------------------------------------------------------------------------
# **Ce script ne crée plus la Release.** `.github/workflows/release.yml` le
# fait, au brouillon, une fois toutes les gates jouées et le pack de preuves
# constitué. Ce script pose le tag, attend ce verdict, attache le ZIP
# installable au brouillon, y met les notes, puis publie.
#
# Les deux ne peuvent pas créer la même Release : le workflow, arrivant en
# dernier, repasserait une Release publiée en brouillon. Un seul chemin de
# publication.
#
# LES NOTES DE RELEASE NE SONT PAS OPTIONNELLES
# ---------------------------------------------------------------------------
# `RELEASE_NOTES_FILE` doit pointer sur un fichier Markdown **rédigé**. Sans
# lui, ce script refuse de publier : le brouillon reste, et GitHub garderait
# sinon une liste de commits qui dit ce qui a été touché et jamais ce que cela
# signifie.
#
# Cinq sections obligatoires, dans cet ordre, écrites pour la personne qui lit
# la page Releases et non pour celle qui a écrit le diff :
#
#   1. « Ce qui change », dans la langue d'un utilisateur du produit. Si rien
#      n'est visible, le dire exactement — c'est une information, pas une
#      excuse ;
#   2. « Corrections », chacune formulée comme le symptôme qui a disparu, pas
#      comme le correctif ;
#   3. « Compatibilité » : ce qu'une installation existante doit faire, ou
#      explicitement « rien ». Le silence est un oubli, pas une réponse ;
#   4. « Tests » : un tableau à trois colonnes — la gate, ce qu'elle vérifie
#      réellement en une ligne, le résultat. La colonne du milieu n'est pas du
#      remplissage : une ligne « Vitest — 111 » n'apprend rien à quelqu'un qui
#      audite le projet ;
#   5. « Vérifier la release » : la commande `gh attestation verify` et le
#      contenu du pack.
#
# Ce qu'un lecteur ne doit pas manquer — changement de licence, rupture de
# compatibilité, correctif de sécurité — va tout en haut, avec un marqueur.
# Supposer que la lecture s'arrête au premier écran.
#
# **Ne pas écrire de liste de dépendances à la main** :
# `scripts/dependency-inventory.php` en ajoute une, générée depuis les fichiers
# de verrouillage.
#
# Les affirmations d'une note portent à conséquence : elles sont lues par des
# gens qui décident de mettre à jour. Ne jamais annoncer un nombre de tests
# qu'on n'a pas vu, ni un comportement corrigé qu'aucun test ne couvre.
#
# Usage :
#   ./scripts/release.sh patch|minor|major [options]
#
#   RELEASE_NOTES_FILE=notes.md ./scripts/release.sh minor
#
# Options :
#   --dry-run             n'écrit rien, ne pousse rien, ne publie rien
#   --skip-tests          n'exécute pas ./scripts/check.sh --full   (bypass)
#   --skip-ci             ne vérifie pas GitHub Actions             (bypass)
#   --skip-security       ne vérifie pas CodeQL/Dependabot          (bypass)
#   --skip-sonar          ne vérifie pas la Quality Gate SonarCloud (bypass)
#   --skip-deployment     ne vérifie pas la production déployée     (bypass)
#   --skip-freshness      ne vérifie pas la fraîcheur des deps      (bypass)
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
SKIP_FRESHNESS=0
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
        --skip-freshness) SKIP_FRESHNESS=1 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) die "Option inconnue : $1" ;;
    esac
    shift
done

[ -n "$BUMP" ] || die "Indiquez le type d'incrément : patch, minor ou major."

# ---------------------------------------------------------------- 1. Outils --
info "1/24 Vérification des outils requis"
for tool in git php composer npm zip; do
    command -v "$tool" >/dev/null 2>&1 || die "Outil manquant : $tool"
done
HAS_GH=0
command -v gh >/dev/null 2>&1 && HAS_GH=1
ok "Outils présents (gh: $([ $HAS_GH -eq 1 ] && echo oui || echo non))"

# ------------------------------------------------------- 2. Working tree ------
info "2/24 Working tree propre"
[ -z "$(git status --porcelain)" ] || die "Le working tree contient des modifications non commitées."
ok "Working tree propre"

# ------------------------------------------------------------- 3. Branche -----
info "3/24 Branche attendue"
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$CURRENT_BRANCH" = "$EXPECTED_BRANCH" ] || die "Branche courante '$CURRENT_BRANCH' ≠ '$EXPECTED_BRANCH'."
ok "Sur $EXPECTED_BRANCH"

# --------------------------------------------------------------- 4. Fetch ----
info "4/24 Synchronisation avec $REMOTE"
git fetch --tags "$REMOTE" "$EXPECTED_BRANCH" >/dev/null 2>&1 || die "git fetch a échoué."
ok "Remote synchronisé"

# ------------------------------------------------------------- 5. HEAD -------
info "5/24 HEAD à jour"
LOCAL="$(git rev-parse HEAD)"
UPSTREAM="$(git rev-parse "$REMOTE/$EXPECTED_BRANCH")"
[ "$LOCAL" = "$UPSTREAM" ] || die "HEAD local ($LOCAL) ≠ $REMOTE/$EXPECTED_BRANCH ($UPSTREAM)."
ok "HEAD identique à $REMOTE/$EXPECTED_BRANCH"

# ---------------------------------------------------- 6. Version courante ----
info "6/24 Version courante"
CURRENT_VERSION="$(tr -d '[:space:]' < VERSION)"
[[ "$CURRENT_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "VERSION invalide : $CURRENT_VERSION"
ok "VERSION = $CURRENT_VERSION"

# ------------------------------------------------- 7. Déploiement précédent --
info "7/24 Gate déploiement"
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
info "8/24 Gate CI GitHub Actions"
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
info "9/24 Gate sécurité (CodeQL / Dependabot)"
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
# **Rien au-dessus de INFO.** Plus strict que la Quality Gate de SonarCloud,
# qui ne juge que le code neuf et reste donc verte pendant que des constats
# hérités s'accumulent.
#
# Un constat n'est acceptable que s'il est informationnel sur **les deux**
# échelles de sévérité que SonarCloud rapporte : la classique
# (INFO/MINOR/MAJOR/CRITICAL/BLOCKER) et celle du Clean Code
# (INFO/LOW/MEDIUM/HIGH/BLOCKER, par impact). Un MINOR classique porte un
# impact LOW : se fier à une seule échelle laisserait passer ce que l'autre
# appelle un défaut.
#
# Un constat marqué *won't fix* ou *faux positif* est résolu, donc non rendu
# par l'API : décliner un constat est une décision avec un nom en face, et
# cette gate l'honore plutôt que de la refaire.
SONAR_WAIT_ATTEMPTS="${SONAR_WAIT_ATTEMPTS:-20}"
SONAR_WAIT_SECONDS="${SONAR_WAIT_SECONDS:-30}"

sonar_get() {
    if [ -n "${SONAR_TOKEN:-}" ]; then
        curl -fsS --max-time 30 -u "$SONAR_TOKEN:" "https://sonarcloud.io/api/$1"
    else
        curl -fsS --max-time 30 "https://sonarcloud.io/api/$1"
    fi
}

sonar_wait_budget() {
    local total=$((SONAR_WAIT_ATTEMPTS * SONAR_WAIT_SECONDS))
    if [ "$total" -lt 60 ]; then printf '%s seconde(s)' "$total"
    else printf '%s minute(s)' "$((total / 60))"; fi
}

info "10/24 Gate SonarCloud"
if [ $SKIP_SONAR -eq 1 ]; then
    bypass "gate SonarCloud ignorée"
else
    # L'analyse doit porter SUR LE COMMIT publié. En lire une plus ancienne
    # serait pire que ne rien vérifier : elle décrit du code qui n'est pas
    # celui qui part, et elle le décrit comme un succès.
    #
    # Attendue plutôt que refusée d'emblée : publier quelques minutes après une
    # fusion est le cas normal et SonarCloud calcule encore. Échouer
    # immédiatement ferait de cette gate quelque chose qu'on apprend à relancer
    # deux fois, ce qui est à mi-chemin d'apprendre à la sauter.
    #
    # L'attente est bornée. Si l'analyse n'arrive jamais, la gate refuse :
    # « pas encore analysé » et « analysé et propre » sont deux réponses
    # différentes, et une seule est un succès.
    ANALYSED_SHA=""
    for attempt in $(seq 1 "$SONAR_WAIT_ATTEMPTS"); do
        ANALYSED_SHA="$(sonar_get "project_analyses/search?project=$SONAR_PROJECT_KEY&ps=1" \
            | php -r '$d = json_decode(stream_get_contents(STDIN), true);
                      echo $d["analyses"][0]["revision"] ?? "";' 2>/dev/null || true)"
        [ "$ANALYSED_SHA" = "$LOCAL" ] && break
        if [ "$attempt" -eq "$SONAR_WAIT_ATTEMPTS" ]; then
            printf 'À publier : %s\nAnalysé   : %s\n' "$LOCAL" "${ANALYSED_SHA:-(aucune analyse lisible)}" >&2
            die "SonarCloud n'a pas analysé ce commit (attendu $(sonar_wait_budget))."
        fi
        [ "$attempt" -eq 1 ] && info "  attente de l'analyse de ${LOCAL:0:7} (jusqu'à $(sonar_wait_budget))..."
        sleep "$SONAR_WAIT_SECONDS"
    done

    SONAR_ISSUES="$(sonar_get "issues/search?componentKeys=$SONAR_PROJECT_KEY&statuses=OPEN,CONFIRMED,REOPENED&ps=500")" \
        || die "Constats SonarCloud illisibles."

    # 500 est le maximum d'une page. Au-delà, cette réponse ne décrit qu'une
    # partie des constats — et une gate qui compte sur un sous-ensemble en
    # annonçant « rien au-dessus de INFO » est pire que pas de gate. Elle
    # refuse donc de conclure plutôt que de conclure faux.
    SONAR_TOTAL="$(printf '%s' "$SONAR_ISSUES" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d) || !isset($d["issues"]) || !is_array($d["issues"])) { echo "-1"; exit; }
        $total = $d["paging"]["total"] ?? count($d["issues"]);
        echo (int) $total - count($d["issues"]);
    ')"
    [ "$SONAR_TOTAL" -ge 0 ] 2>/dev/null || die "Constats SonarCloud illisibles."
    [ "$SONAR_TOTAL" -eq 0 ] \
        || die "SonarCloud rend plus de constats qu'une page n'en porte ($SONAR_TOTAL de plus) : verdict impossible sur un sous-ensemble."

    SONAR_BLOCKING="$(printf '%s' "$SONAR_ISSUES" | php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d) || !isset($d["issues"])) { echo "-1"; exit; }
        $blocking = 0;
        foreach ($d["issues"] as $i) {
            $informational = ($i["severity"] ?? "") === "INFO";
            foreach (($i["impacts"] ?? []) as $impact) {
                if (($impact["severity"] ?? "") !== "INFO") { $informational = false; }
            }
            if (!$informational) { $blocking++; }
        }
        echo $blocking;
    ')"

    [ "$SONAR_BLOCKING" -ge 0 ] 2>/dev/null || die "Constats SonarCloud illisibles."

    if [ "$SONAR_BLOCKING" -gt 0 ]; then
        printf '%s' "$SONAR_ISSUES" | php -r '
            $d = json_decode(stream_get_contents(STDIN), true);
            foreach (($d["issues"] ?? []) as $i) {
                $informational = ($i["severity"] ?? "") === "INFO";
                foreach (($i["impacts"] ?? []) as $impact) {
                    if (($impact["severity"] ?? "") !== "INFO") { $informational = false; }
                }
                if ($informational) { continue; }
                $impacts = [];
                foreach (($i["impacts"] ?? []) as $impact) {
                    $impacts[] = ($impact["softwareQuality"] ?? "?") . " " . ($impact["severity"] ?? "?");
                }
                printf("  %-9s %-24s %s:%s\n    %s\n",
                    $i["severity"] ?? "?",
                    $impacts ? implode(", ", $impacts) : ($i["type"] ?? "?"),
                    explode(":", $i["component"] ?? "?", 2)[1] ?? "?",
                    $i["line"] ?? "-", $i["message"] ?? "");
            }' >&2
        warn "INFO est acceptable ; LOW et au-dessus ne le sont pas."
        warn "Un constat qui ne vaut vraiment pas d'être corrigé se marque « won't fix »"
        warn "dans SonarCloud : c'est une décision avec un nom en face, et la gate l'honore."
        die "$SONAR_BLOCKING constat(s) SonarCloud au niveau LOW ou au-dessus."
    fi

    # Les hotspots vivent derrière leur propre endpoint. Une gate qui ne
    # regarde que les constats a l'air complète et manque la catégorie qu'un
    # relecteur sécurité ouvre en premier.
    SONAR_HOTSPOTS="$(sonar_get "hotspots/search?projectKey=$SONAR_PROJECT_KEY&status=TO_REVIEW&ps=100" \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo count($d["hotspots"] ?? []);')"
    [ "$SONAR_HOTSPOTS" = "0" ] || die "$SONAR_HOTSPOTS security hotspot(s) à examiner dans SonarCloud."

    ok "SonarCloud : rien au-dessus de INFO, 0 hotspot à examiner, analysé sur ${LOCAL:0:7}"
fi

# ------------------------------------------------------- 11. Tests locaux ----
# ------------------------------------------------- 11. Fraîcheur des deps ----
# Parmi les gates rapides, et avant les tests : découvrir une dépendance en
# retard après vingt minutes de `check.sh --full` coûterait ces vingt minutes
# pour rien.
info "11/24 Gate fraîcheur des dépendances"
if [ "$SKIP_FRESHNESS" = "1" ]; then
    bypass "fraîcheur des dépendances non vérifiée"
elif COMPOSER_ALLOW_SUPERUSER=1 php scripts/dependency-freshness.php; then
    ok "Aucune montée gratuite en attente"
else
    warn "Des montées autorisées par les contraintes actuelles sont en attente."
    warn "Rattrapez-les (composer update / npm update / copie du vendorisé),"
    warn "ou relancez avec --skip-freshness en sachant ce que vous ne vérifiez pas."
    die "Gate fraîcheur des dépendances en échec."
fi

info "12/24 Gate tests locaux"
if [ $SKIP_TESTS -eq 1 ]; then
    bypass "tests locaux ignorés"
else
    ./scripts/check.sh --full || die "./scripts/check.sh --full a échoué."
    ok "check.sh --full vert"
fi

# ------------------------------------------------------ 12. Dépendances ------
info "13/24 Audit des dépendances"
COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked --no-interaction >/dev/null || die "composer audit a échoué."
npm audit --omit=dev --audit-level=high >/dev/null 2>&1 || warn "npm audit signale des alertes (dépendances de développement uniquement)."
ok "Dépendances auditées"

# ------------------------------------------------------ 13. Nouvelle version -
info "14/24 Calcul de la nouvelle version"
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
info "15/24 Écriture de VERSION"
printf '%s\n' "$NEW_VERSION" > VERSION
ok "VERSION = $NEW_VERSION"

# ------------------------------------------------------------ 15. Commit -----
info "16/24 Commit de version"
git add VERSION
git commit -m "chore: release $NEW_VERSION" >/dev/null || die "Commit impossible."
ok "Commit créé"

# -------------------------------------------------------------- 16. Push -----
info "17/24 Push du commit"
git push -u "$REMOTE" "$EXPECTED_BRANCH" >/dev/null || die "Push impossible."
ok "Commit poussé"

# --------------------------------------------------------------- 17. Tag -----
info "18/24 Tag annoté"
git tag -a "$TAG" -m "SecondStay $NEW_VERSION" || die "Création du tag impossible."
git push "$REMOTE" "$TAG" >/dev/null || die "Push du tag impossible."
ok "Tag $TAG poussé"

# --------------------------------------------------- 18. Composer production -
info "19/24 Dépendances de production"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction >/dev/null \
    || die "composer install --no-dev a échoué."
ok "Dépendances de production prêtes"

# --------------------------------------------------------------- 19. ZIP -----
info "20/24 Construction du ZIP"
ZIP_PATH="$ROOT/build/release/secondstay-$NEW_VERSION.zip"
./scripts/build-release-zip.sh --build "$ZIP_PATH" >/dev/null || die "Construction du ZIP impossible."
ok "ZIP construit : $ZIP_PATH"

# ------------------------------------------------------- 20. Inspection ZIP --
info "21/24 Inspection du ZIP"
php scripts/release-artifact.php inspect "$ZIP_PATH" || die "L'artefact est non conforme."
ok "Artefact conforme"

# ------------------------------------------------------------ 21. Notes ------
# Le fichier est validé ICI, avant l'attente du workflow : découvrir qu'il
# manque une section après dix minutes de gates serait dix minutes perdues, et
# le brouillon resterait de toute façon non publié.
info "22/24 Notes de release"
NOTES_SOURCE="${RELEASE_NOTES_FILE:-}"
if [ -z "$NOTES_SOURCE" ]; then
    warn "RELEASE_NOTES_FILE n'est pas défini."
    warn "Sans lui, la Release garderait une liste de commits : ce qui a été touché,"
    warn "jamais ce que cela signifie. Voir l'en-tête de ce script pour le contrat."
    die "Notes de release absentes."
fi
[ -r "$NOTES_SOURCE" ] || die "RELEASE_NOTES_FILE est défini mais « $NOTES_SOURCE » est illisible."
[ -s "$NOTES_SOURCE" ] || die "« $NOTES_SOURCE » est vide."

# Les cinq sections, dans l'ordre. Un contrôle textuel ne juge pas le contenu,
# mais il attrape l'oubli — et « Compatibilité » est précisément la section
# qu'on oublie, celle dont le silence se lit comme « rien à faire ».
php -r '
    $required = ["Ce qui change", "Corrections", "Compatibilité", "Tests", "Vérifier la release"];
    $text = (string) file_get_contents($argv[1]);
    $position = 0;
    $missing = [];
    $outOfOrder = [];
    // Chaque section est cherchée **après** la précédente. Chercher depuis le
    // début rendrait la première occurrence dans tout le fichier, or une
    // section en cite volontiers une autre : le paragraphe « Ce qui change »
    // qui mentionne « Compatibilité » suffisait à faire rejeter des notes
    // pourtant dans le bon ordre.
    foreach ($required as $section) {
        $at = mb_stripos($text, $section, $position);
        if ($at === false) {
            if (mb_stripos($text, $section) === false) { $missing[] = $section; }
            else { $outOfOrder[] = $section; }
            continue;
        }
        $position = $at + mb_strlen($section);
    }
    if ($missing !== []) {
        fwrite(STDERR, "Sections absentes des notes : " . implode(", ", $missing) . "\n");
        exit(1);
    }
    if ($outOfOrder !== []) {
        fwrite(STDERR, "Sections dans le désordre : " . implode(", ", $outOfOrder) . "\n");
        exit(1);
    }
' "$NOTES_SOURCE" || die "Les notes de release ne respectent pas le contrat (voir l'en-tête de ce script)."
ok "Notes conformes : $NOTES_SOURCE"

# ---------------------------------------------------------- 22. Publication --
# Le tag poussé a démarré `.github/workflows/release.yml`. Il joue toutes les
# gates et, seulement si elles sont vertes, crée la Release **au brouillon**
# avec le pack de preuves. Attendre ici est ce qui fait de la chaîne une seule
# commande : l'alternative est quelqu'un qui doit penser à revenir dix minutes
# plus tard finir la release à la main, ce qui est la façon dont une version
# part avec une gate rouge que personne n'a regardée.
info "23/24 Attente des gates, puis publication"
if [ $HAS_GH -eq 0 ]; then
    warn "gh absent : le workflow a créé un brouillon pour $TAG."
    warn "Attachez-y $ZIP_PATH, mettez les notes de $NOTES_SOURCE, puis publiez à la main."
else
    # La run est trouvée par tag et non par commit : une poussée de tag met le
    # nom du tag dans `head_branch`, et le commit de release peut aussi porter
    # une run de CI sur main.
    RUN_ID=""
    for _ in $(seq 1 30); do
        RUN_ID="$(gh run list --workflow=release.yml --branch "$TAG" --limit 1 \
            --json databaseId -q '.[0].databaseId' 2>/dev/null || true)"
        [ -n "$RUN_ID" ] && [ "$RUN_ID" != "null" ] && break
        sleep 10
    done
    { [ -n "$RUN_ID" ] && [ "$RUN_ID" != "null" ]; } \
        || die "Aucune exécution de release.yml pour $TAG après cinq minutes. Le tag est poussé, rien n'est publié."

    # Suivie pour la liste des travaux, mais **pas** crue pour le verdict :
    # `gh run watch` refuse une run déjà terminée, et un échec rapide peut
    # finir avant même que la boucle ci-dessus ne la trouve. La conclusion est
    # relue séparément.
    if [ "$(gh run view "$RUN_ID" --json status -q .status)" != "completed" ]; then
        gh run watch "$RUN_ID" --interval 15 || true
    fi

    CONCLUSION="$(gh run view "$RUN_ID" --json conclusion -q .conclusion)"
    if [ "$CONCLUSION" != "success" ]; then
        warn "Rien n'a été publié : le workflow ne crée aucun brouillon quand une gate est rouge."
        warn "Le tag $TAG existe et ne pointe sur rien de publié. Corrigez, puis :"
        warn "  git push --delete $REMOTE $TAG && git tag -d $TAG"
        die "release.yml a conclu « $CONCLUSION » : une gate est rouge."
    fi

    # Le brouillon du workflow ne porte que `evidence.zip`. Le ZIP construit
    # plus haut est l'autre moitié — celle qu'on installe — et les deux
    # appartiennent à la même Release. `--clobber` pour qu'une reprise remplace
    # l'asset au lieu d'échouer sur un nom déjà présent.
    gh release upload "$TAG" "$ZIP_PATH" --clobber || die "Impossible d'attacher $ZIP_PATH au brouillon."
    ok "Artefact attaché au brouillon"

    # L'installeur autonome est le troisième asset. Il est publié tel quel,
    # non zippé : il se dépose par FTP à la racine d'un hébergement vide, et
    # demander à quelqu'un de dézipper un fichier unique avant de le
    # téléverser n'ajoute qu'une occasion de se tromper. C'est aussi lui qui,
    # une fois exécuté, ira chercher le ZIP ci-dessus sur cette même Release :
    # les deux ne se publient jamais séparément.
    gh release upload "$TAG" "$ROOT/bootstrap/bootstrap.php" --clobber \
        || die "Impossible d'attacher bootstrap.php au brouillon."
    ok "Installeur attaché au brouillon"

    # La note humaine va AU-DESSUS de celle du workflow, qui décrit le pack de
    # preuves et mérite d'être gardée. L'inventaire des dépendances est
    # **généré** : lu dans les fichiers de verrouillage, il dit ce qui est
    # réellement parti et ne peut pas dériver comme une liste écrite à la main.
    NOTES_FILE="$ROOT/build/release/notes-$NEW_VERSION.md"
    cat "$NOTES_SOURCE" > "$NOTES_FILE"
    printf '\n\n' >> "$NOTES_FILE"
    php "$ROOT/scripts/dependency-inventory.php" >> "$NOTES_FILE"
    if [ ${#BYPASSES[@]} -gt 0 ]; then
        printf '\n### ⚠ Bypass utilisés\n\n' >> "$NOTES_FILE"
        for b in "${BYPASSES[@]}"; do printf -- '- %s\n' "$b" >> "$NOTES_FILE"; done
        printf '\nCes bypass doivent être audités humainement après publication.\n' >> "$NOTES_FILE"
    fi
    printf '\n---\n\n' >> "$NOTES_FILE"
    gh release view "$TAG" --json body -q .body >> "$NOTES_FILE"
    gh release edit "$TAG" --notes-file "$NOTES_FILE" || die "Impossible de poser les notes."
    ok "Notes posées : $NOTES_FILE"

    # Publier ici plutôt que laisser le brouillon à un humain est délibéré, et
    # ce n'est pas un relâchement : le brouillon existe pour que rien ne soit
    # public avant que les gates aient parlé, et à cette ligne elles ont parlé.
    # Ce qu'on abandonne, c'est une paire d'yeux sur les preuves AVANT que la
    # Release ne soit publique ; le pack reste attaché à la Release publiée, il
    # est donc toujours lu — simplement plus comme une étape bloquante.
    gh release edit "$TAG" --draft=false --latest || die "Publication impossible."
    ok "Release $TAG publiée"
fi

# --------------------------------------------------- 23. Restauration dev ----
info "24/24 Restauration de l'environnement de développement"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction >/dev/null || warn "Restauration des dépendances de développement à refaire manuellement."
ok "Environnement de développement restauré"

printf '\n%s✔ SecondStay %s publiée.%s\n' "$GREEN$BOLD" "$NEW_VERSION" "$RESET"
if [ ${#BYPASSES[@]} -gt 0 ]; then
    warn "${#BYPASSES[@]} bypass utilisé(s) : voir $NOTES_FILE"
fi
