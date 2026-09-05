#!/usr/bin/env bash
#
# scripts/dast.sh — scan dynamique passif de l'application en fonctionnement.
#
# Usage : ./scripts/dast.sh [<arguments Playwright supplémentaires>...]
#         npm run dast      (commande canonique — voir README.md)
#
# Une commande, une campagne complète : provisionner une instance jetable, la
# servir en HTTPS par le vrai point d'entrée de l'application, la piloter avec
# la campagne Playwright existante à travers un proxy OWASP ZAP, produire un
# rapport, décider du verdict, et tout démonter — au succès, à l'échec et sur
# Ctrl-C.
#
# LA CAMPAGNE EST LA SURFACE D'ATTAQUE, PAS L'ARAIGNÉE DE ZAP
# ---------------------------------------------------------------------------
# La campagne traverse déjà l'assistant d'installation, l'administration
# derrière sa session, un parcours de réservation complet, les paiements
# factices, l'espace client, le mode séjour et les états des lieux. Une
# araignée pointée sur SecondStay verrait la page d'accueil et s'arrêterait.
# Rejouer la campagne à travers un proxy est l'image la plus fidèle de la
# surface réelle qui existe.
#
# Conséquence assumée : **une campagne en échec fait échouer le scan**, même
# sans le moindre constat de sécurité. Un scan ne vaut que le trafic qu'on lui
# a donné.
#
# CE QUI EST RÉUTILISÉ, ET POURQUOI IL N'Y A PAS DE SECONDE PROVISION
# ---------------------------------------------------------------------------
# `tests/e2e/global-setup.js` sait déjà faire une instance jetable : remise à
# zéro de la base de test, serveur avec les fournisseurs factices, vérification
# que les boîtes de test répondent. Ce script s'appuie sur **la même**
# préparation plutôt que sur une copie parallèle qui finirait par diverger. Ce
# qui reste ici est ce que le scan ajoute réellement : TLS, ZAP, et le verdict.
#
# CE QUI NE SE SIMPLIFIE PAS : IL FAUT DU HTTPS
# ---------------------------------------------------------------------------
# Deux protections de SecondStay sont conditionnées à l'arrivée en HTTPS :
# l'en-tête HSTS et le drapeau `Secure` du cookie de session. Un scan en clair
# rapporterait « pas de HSTS » et « cookie sans Secure » : deux constats FAUX,
# à propos de code CORRECT. La correction tentante est un filtre d'alertes qui
# fait taire les deux règles, et c'est précisément ainsi qu'un rapport cesse
# d'être lu. On répare donc le harnais (`scripts/dast-tls-proxy.php` +
# `scripts/dast-https-prepend.php`), les deux règles restent armées, et le
# câblage est PROUVÉ VIVANT avant le début du scan.
#
# IL NE PEUT PAS ENTRER EN COLLISION AVEC `npm run e2e`
# ---------------------------------------------------------------------------
# Son propre répertoire temporaire, ses propres ports — tous choisis à
# l'exécution — et son propre répertoire de rapport. Pour la base, voir
# `DAST_DB_NAME` ci-dessous.
#
# Configuration, toute optionnelle :
#   DAST_PORT / DAST_BACKEND_PORT / DAST_ZAP_PORT
#       Ports fixes pour la façade HTTPS, le `php -S` derrière le terminateur
#       TLS, et l'écouteur proxy/API de ZAP. Défaut : des ports libres choisis
#       à l'exécution.
#   DAST_DB_NAME
#       Base de test dédiée au scan. Défaut : `SECONDSTAY_TEST_DB_NAME`, ce qui
#       suffit en intégration continue où le travail a son propre service
#       MySQL. En local, la définir sur une **seconde** base est ce qui permet
#       de jouer `npm run dast` et `npm run e2e` en même temps.
#   DAST_ZAP_IMAGE       Défaut « ghcr.io/zaproxy/zaproxy:stable ».
#   DAST_REPORT_DIR      Où vont le rapport et le résumé. Défaut « dast-report/ ».
#   DAST_THRESHOLD       Risque le plus faible qui fait échouer. Défaut « Medium ».
#   DAST_SERVER_TIMEOUT / DAST_ZAP_TIMEOUT / DAST_PLAN_TIMEOUT
#       Secondes d'attente pour l'instance (60), pour l'API de ZAP (180) et
#       pour la fin du plan (3600).
#   DAST_TIMEOUT_FACTOR
#       Multiplie chaque délai Playwright. Défaut 4 : les mêmes scénarios font
#       le même travail, mais chaque requête traverse désormais une poignée de
#       main TLS et un proxy. Mettre les plafonds à l'échelle est ce qui
#       empêche la latence du harnais d'être rapportée comme des échecs de
#       l'application. `npm run e2e` ne le définit jamais.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

PLAYWRIGHT_ARGS=("$@")

SUPPORT="${REPO_ROOT}/scripts/dast-support.php"
PLAN_FILE="${REPO_ROOT}/tests/dast/zap-passive.yaml"
SITEMAP_EXPECTATIONS="${REPO_ROOT}/tests/dast/expected-paths.txt"

DAST_ZAP_IMAGE="${DAST_ZAP_IMAGE:-ghcr.io/zaproxy/zaproxy:stable}"
DAST_REPORT_DIR="${DAST_REPORT_DIR:-${REPO_ROOT}/dast-report}"
DAST_THRESHOLD="${DAST_THRESHOLD:-Medium}"
DAST_SERVER_TIMEOUT="${DAST_SERVER_TIMEOUT:-60}"
DAST_ZAP_TIMEOUT="${DAST_ZAP_TIMEOUT:-180}"
DAST_PLAN_TIMEOUT="${DAST_PLAN_TIMEOUT:-3600}"
DAST_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR:-4}"

# Configuration locale de la base de test, comme `check.sh` la lit.
if [ -f "${REPO_ROOT}/scripts/test-env.local.sh" ]; then
    # shellcheck disable=SC1091
    . "${REPO_ROOT}/scripts/test-env.local.sh"
fi

# ---------------------------------------------------------------
# 1. Prérequis. Échouer fermé avec la commande exacte à lancer — ne jamais rien
# installer à la place de l'appelant.
# ---------------------------------------------------------------
command -v php >/dev/null 2>&1 || { echo "ERREUR : php est requis." >&2; exit 1; }
command -v npx >/dev/null 2>&1 || { echo "ERREUR : node/npx est requis." >&2; exit 1; }
[[ -f "${REPO_ROOT}/vendor/autoload.php" ]] || {
    echo "ERREUR : vendor/autoload.php est absent — lancez d'abord « composer install »." >&2; exit 1; }
[[ -d "${REPO_ROOT}/node_modules/@playwright/test" ]] || {
    echo "ERREUR : dépendances absentes — lancez d'abord « npm ci »." >&2; exit 1; }
[[ -f "${PLAN_FILE}" ]] || { echo "ERREUR : aucun plan ZAP dans ${PLAN_FILE}." >&2; exit 1; }

php -r 'exit(extension_loaded("openssl") && extension_loaded("pcntl") ? 0 : 1);' || {
    echo "ERREUR : le scan a besoin des extensions PHP « openssl » et « pcntl »." >&2
    echo "         openssl génère le certificat de la campagne ; pcntl fait tourner le terminateur TLS." >&2
    exit 1
}

if [ -z "${SECONDSTAY_TEST_DB_NAME:-}" ]; then
    echo "ERREUR : SECONDSTAY_TEST_DB_NAME n'est pas défini." >&2
    echo "         Voir TESTING.md §5 : la base de production ne doit jamais être utilisée." >&2
    exit 1
fi

command -v docker >/dev/null 2>&1 || {
    echo "ERREUR : Docker est requis — OWASP ZAP tourne en conteneur." >&2
    exit 1
}
docker info >/dev/null 2>&1 || {
    echo "ERREUR : le démon Docker est injoignable. Démarrez Docker, puis relancez." >&2
    exit 1
}
docker image inspect "${DAST_ZAP_IMAGE}" >/dev/null 2>&1 || {
    echo "ERREUR : l'image ZAP n'est pas présente localement. Récupérez-la une fois avec :" >&2
    echo "             docker pull ${DAST_ZAP_IMAGE}" >&2
    echo "         (environ 1,2 Go ; rien ici ne la télécharge à votre place.)" >&2
    exit 1
}

# ---------------------------------------------------------------
# 2. L'état sur lequel agit le démontage. Chaque variable reste vide tant que
# la ressource correspondante n'existe pas : le nettoyage est donc sûr à tout
# moment.
# ---------------------------------------------------------------
INSTANCE_DIR=""
PLAYWRIGHT_PID=""
ZAP_CONTAINER=""

stop_pid() {
    local pid="$1" name="$2"
    [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null || return 0

    echo "DAST : arrêt de ${name} (pid ${pid})."
    kill "${pid}" 2>/dev/null || true
    local waited=0
    while kill -0 "${pid}" 2>/dev/null && [[ "${waited}" -lt 50 ]]; do
        waited=$((waited + 1))
        sleep 0.1
    done
    kill -9 "${pid}" 2>/dev/null || true

    return 0
}

cleanup() {
    # Ne jamais laisser l'échec d'une étape de nettoyage remplacer le code de
    # sortie de la campagne, ni faire sauter les étapes suivantes.
    local exit_code=$?
    set +e

    # Playwright d'abord : c'est le seul enfant qui pilote encore la pile, et
    # arrêter le serveur sous lui transformerait une campagne annulée en un mur
    # d'erreurs de connexion.
    stop_pid "${PLAYWRIGHT_PID}" "Playwright"

    # La préparation globale a démarré le serveur et le terminateur TLS, et le
    # démontage global les arrête. Filet de sécurité pour le cas où Playwright
    # n'aurait pas eu l'occasion d'y arriver — une campagne interrompue au
    # Ctrl-C, par exemple.
    #
    # Le terminateur TLS FORQUE un fils par connexion : tuer le parent
    # laisserait ces fils tenir leurs sockets. Le motif est le répertoire
    # temporaire de la campagne, qui apparaît dans leur ligne de commande
    # (--cert=<dir>/server.pem) et qui est un nom `mktemp` qu'aucun autre
    # processus de cette machine ne porte. La ligne de commande de ce script,
    # elle, ne le contient pas : le nettoyage ne peut pas se tuer lui-même.
    if [[ -n "${BACKEND_PORT:-}" ]]; then
        SECONDSTAY_HOST=127.0.0.1 SECONDSTAY_PORT="${BACKEND_PORT}" \
            "${REPO_ROOT}/scripts/dev-server.sh" stop >/dev/null 2>&1
    fi
    if [[ -n "${INSTANCE_DIR}" ]]; then
        pkill -f "${INSTANCE_DIR}" >/dev/null 2>&1
    fi

    if [[ -n "${ZAP_CONTAINER}" ]]; then
        echo "DAST : suppression du conteneur ZAP."
        docker rm -f "${ZAP_CONTAINER}" >/dev/null 2>&1
    fi

    if [[ -n "${INSTANCE_DIR}" && -d "${INSTANCE_DIR}" ]]; then
        rm -rf "${INSTANCE_DIR}"
    fi

    exit "${exit_code}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------
# 3. Ports, certificat et répertoire de la campagne.
# ---------------------------------------------------------------
INSTANCE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/secondstay-dast.XXXXXX")"
CERT_FILE="${INSTANCE_DIR}/server.pem"

PORT="${DAST_PORT:-$(php "${SUPPORT}" free-port)}"
BACKEND_PORT="${DAST_BACKEND_PORT:-$(php "${SUPPORT}" free-port)}"
ZAP_PORT="${DAST_ZAP_PORT:-$(php "${SUPPORT}" free-port)}"

# `localhost` et non `127.0.0.1` : une adresse IP n'est pas une « relying
# party » WebAuthn valide, et les parcours de clés d'accès de la campagne
# seraient refusés par le navigateur.
DAST_TLS_HOST="localhost"
BASE_URL="https://${DAST_TLS_HOST}:${PORT}"

echo "DAST : génération d'un certificat auto-signé pour cette campagne."
php "${SUPPORT}" generate-cert "${CERT_FILE}" "${DAST_TLS_HOST}"

# ---------------------------------------------------------------
# 4. ZAP, en démon, pour que le plan configure le scanner passif puis se bloque
# pendant que le navigateur pousse du trafic à travers lui.
# ---------------------------------------------------------------
ZAP_WORK_DIR="${INSTANCE_DIR}/zap"
mkdir -p "${ZAP_WORK_DIR}/reports"
cp "${PLAN_FILE}" "${ZAP_WORK_DIR}/plan.yaml"

# Le conteneur tourne sous l'utilisateur `zap`, pas sous celui qui a lancé ce
# script. Seul le répertoire des rapports doit être accessible en écriture par
# cet autre uid ; le plan est lu, et le répertoire seulement traversé.
chmod 0755 "${ZAP_WORK_DIR}"
chmod 0777 "${ZAP_WORK_DIR}/reports"
chmod 0644 "${ZAP_WORK_DIR}/plan.yaml"

ZAP_API_KEY="$(php -r 'echo bin2hex(random_bytes(16));')"
ZAP_CONTAINER="secondstay-dast-zap-$$"
RELEASE_FILE_HOST="${ZAP_WORK_DIR}/browser-finished"

# Joindre un serveur de l'hôte depuis un conteneur dépend de la plateforme, et
# se tromper produit une CARTE DE SITE VIDE plutôt qu'une erreur — un scan qui
# « passe » sans avoir rien vu. Sous Linux le conteneur partage l'espace réseau
# de l'hôte, `localhost` désigne donc la même boucle des deux côtés. Ailleurs
# (Docker Desktop) le port est publié et l'hôte joignable par un nom, qu'il
# faut donner au navigateur : chaque requête qu'il émet est résolue par ZAP et
# non par lui.
if [[ "$(uname -s)" == "Linux" ]]; then
    ZAP_NETWORK_ARGS=(--network=host)
    ZAP_TARGET="${BASE_URL}"
    ZAP_LISTEN_HOST="127.0.0.1"
else
    ZAP_NETWORK_ARGS=(--publish "127.0.0.1:${ZAP_PORT}:${ZAP_PORT}" --add-host "host.docker.internal:host-gateway")
    ZAP_TARGET="https://host.docker.internal:${PORT}"
    ZAP_LISTEN_HOST="0.0.0.0"
    echo "DAST : hors Linux — ZAP joindra l'instance par ${ZAP_TARGET}."
fi

# Le navigateur reçoit **la même** origine que ZAP. Changer la cible du seul
# scanner ne suffisait pas : le navigateur demandait au proxy conteneurisé de
# joindre `localhost:PORT`, c'est-à-dire la boucle du conteneur et non le
# terminateur de l'hôte — et le contexte se retrouvait en prime sur un nom
# d'hôte différent de celui du scan. Le certificat de campagne couvre
# `host.docker.internal` pour que cette origine reste valide côté navigateur.
#
# Ce chemin n'est pas exercé par l'intégration continue, qui tourne sous Linux.
# `assert-sitemap` reste le garde-fou : une carte vide fait échouer la campagne
# plutôt que de rendre un scan qui n'a rien vu.
BROWSER_BASE_URL="${ZAP_TARGET}"
ZAP_PROXY="http://127.0.0.1:${ZAP_PORT}"

echo "DAST : démarrage d'OWASP ZAP (${DAST_ZAP_IMAGE}) sur ${ZAP_PROXY}."
docker run --detach --rm \
    --name "${ZAP_CONTAINER}" \
    "${ZAP_NETWORK_ARGS[@]}" \
    --volume "${ZAP_WORK_DIR}:/dast" \
    --env "DAST_TARGET=${ZAP_TARGET}" \
    --env "DAST_REPORT_DIR=/dast/reports" \
    --env "DAST_RELEASE_GATE_FILE=/dast/browser-finished" \
    "${DAST_ZAP_IMAGE}" \
    zap.sh -daemon -silent \
        -host "${ZAP_LISTEN_HOST}" -port "${ZAP_PORT}" \
        -config api.key="${ZAP_API_KEY}" \
        -config api.addrs.addr.name=.* \
        -config api.addrs.addr.regex=true \
    > /dev/null || {
        echo "ERREUR : démarrage du conteneur ZAP impossible." >&2
        exit 1
    }

if ! php "${SUPPORT}" wait-url "${ZAP_PROXY}/JSON/core/view/version/?apikey=${ZAP_API_KEY}" "${DAST_ZAP_TIMEOUT}"; then
    echo "ERREUR : ZAP n'a pas répondu à son API en ${DAST_ZAP_TIMEOUT} s." >&2
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi
echo "DAST : ZAP est prêt."

# Le plan démarre maintenant et se bloque sur son propre travail `delay`
# jusqu'à ce que le navigateur ait fini. Le démarrer AVANT le trafic est tout
# l'intérêt : la configuration du scanner passif doit être en place avant que
# la première réponse ne soit analysée.
PLAN_ID="$(php "${SUPPORT}" zap-plan-start "${ZAP_PROXY}" "${ZAP_API_KEY}" /dast/plan.yaml)"
echo "DAST : plan ZAP ${PLAN_ID} démarré (en attente du navigateur)."

# Ne pas envoyer de trafic avant que les travaux de configuration aient
# réellement tourné. Interrogé, jamais attendu à l'aveugle : l'apparition du
# travail « delay » dans le journal est la preuve que celui d'avant est fini.
if ! php "${SUPPORT}" zap-plan-await-delay "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" 120; then
    echo "ERREUR : ZAP n'a jamais atteint le travail « delay » — le scanner passif n'est pas configuré." >&2
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 5. Le navigateur, à travers ZAP.
#
# La préparation globale de Playwright démarre l'instance, pose le terminateur
# TLS devant elle et **prouve** que le câblage HTTPS est vivant avant que le
# premier scénario ne tourne — voir tests/e2e/global-setup.js.
#
# Seul `desktop-chromium` est rejoué (plus sa dépendance `install`). WebKit
# derrière un proxy et un certificat auto-signé apporte de la fragilité sans
# surface supplémentaire : le même serveur répond aux deux.
# ---------------------------------------------------------------
echo "DAST : campagne Playwright à travers ZAP..."
set +e
SECONDSTAY_E2E_TLS=1 \
SECONDSTAY_TEST_DB_NAME="${DAST_DB_NAME:-${SECONDSTAY_TEST_DB_NAME}}" \
SECONDSTAY_HOST="${DAST_TLS_HOST}" \
SECONDSTAY_PORT="${PORT}" \
SECONDSTAY_BACKEND_PORT="${BACKEND_PORT}" \
SECONDSTAY_BASE_URL="${BROWSER_BASE_URL}" \
SECONDSTAY_TLS_CERT="${CERT_FILE}" \
SECONDSTAY_E2E_PROXY="${ZAP_PROXY}" \
SECONDSTAY_TIMEOUT_FACTOR="${DAST_TIMEOUT_FACTOR}" \
    npx playwright test --project=desktop-chromium \
        ${PLAYWRIGHT_ARGS[@]+"${PLAYWRIGHT_ARGS[@]}"} &
PLAYWRIGHT_PID=$!
wait "${PLAYWRIGHT_PID}"
PLAYWRIGHT_EXIT=$?
PLAYWRIGHT_PID=""
set -e

# ---------------------------------------------------------------
# 6. Libérer le plan, quel que soit le verdict du navigateur : un scénario en
# échec a tout de même produit du trafic qui mérite d'être analysé, et le code
# de sortie du navigateur est rapporté séparément plus bas plutôt que d'avaler
# les constats.
# ---------------------------------------------------------------
if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST : la campagne est sortie en ${PLAYWRIGHT_EXIT} — analyse du trafic qu'elle a produit." >&2
fi

touch "${RELEASE_FILE_HOST}"
echo "DAST : attente de la fin du scan passif et de l'écriture du rapport."
if ! php "${SUPPORT}" zap-plan-wait "${ZAP_PROXY}" "${ZAP_API_KEY}" "${PLAN_ID}" "${DAST_PLAN_TIMEOUT}"; then
    docker logs "${ZAP_CONTAINER}" 2>&1 | tail -40 >&2 || true
    exit 1
fi

# ---------------------------------------------------------------
# 7. Verdict.
# ---------------------------------------------------------------
mkdir -p "${DAST_REPORT_DIR}"
cp "${ZAP_WORK_DIR}"/reports/* "${DAST_REPORT_DIR}/" 2>/dev/null || {
    echo "ERREUR : ZAP n'a produit aucun rapport dans ${ZAP_WORK_DIR}/reports." >&2
    exit 1
}
echo "DAST : rapport écrit dans ${DAST_REPORT_DIR}/"

# Prouver que ZAP a réellement vu le site avant de croire quoi que ce soit de
# ce qu'il en dit.
php "${SUPPORT}" assert-sitemap "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${SITEMAP_EXPECTATIONS}"

set +e
php "${SUPPORT}" gate-alerts \
    "${ZAP_PROXY}" "${ZAP_API_KEY}" "${ZAP_TARGET}" "${DAST_THRESHOLD}" \
    "${DAST_REPORT_DIR}/dast-severity-summary.json"
GATE_EXIT=$?
set -e

if [[ "${GATE_EXIT}" -ne 0 ]]; then
    echo "DAST EN ÉCHEC. Rapport : ${DAST_REPORT_DIR}/dast-passive.html" >&2
    exit "${GATE_EXIT}"
fi

if [[ "${PLAYWRIGHT_EXIT}" -ne 0 ]]; then
    echo "DAST : aucun constat de sécurité, mais la campagne a échoué (sortie ${PLAYWRIGHT_EXIT})." >&2
    echo "       Un scan ne vaut que le trafic qu'on lui a donné : c'est une campagne en échec." >&2
    exit "${PLAYWRIGHT_EXIT}"
fi

echo "DAST OK."
