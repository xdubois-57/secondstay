#!/usr/bin/env bash
# Démarre / arrête le serveur PHP intégré utilisé en développement et par Playwright.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# `localhost` : une adresse IP n'est pas un domaine WebAuthn valide, les clés
# d'accès ne fonctionneraient pas en développement.
HOST="${SECONDSTAY_HOST:-localhost}"
PORT="${SECONDSTAY_PORT:-8123}"
PIDFILE="${ROOT}/storage/temp/dev-server.pid"
LOGFILE="${ROOT}/storage/logs/dev-server.log"

mkdir -p "$(dirname "$PIDFILE")" "$(dirname "$LOGFILE")"

start() {
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        echo "dev-server already running (pid $(cat "$PIDFILE"))"
        return 0
    fi
    # Plusieurs workers : indispensable pour les scénarios concurrents
    # (anti-double-réservation, webhooks) testés en E2E.
    PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}" \
        php -S "${HOST}:${PORT}" -t "$ROOT" "${ROOT}/scripts/router.php" >"$LOGFILE" 2>&1 &
    echo $! > "$PIDFILE"
    for _ in $(seq 1 50); do
        if curl -fsS -o /dev/null "http://${HOST}:${PORT}/api/health"; then
            echo "dev-server started on http://${HOST}:${PORT} (pid $(cat "$PIDFILE"))"
            return 0
        fi
        sleep 0.2
    done
    echo "dev-server failed to start; see $LOGFILE" >&2
    return 1
}

stop() {
    if [ -f "$PIDFILE" ]; then
        PID="$(cat "$PIDFILE")"
        if kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null || true
            sleep 0.3
            kill -9 "$PID" 2>/dev/null || true
        fi
        rm -f "$PIDFILE"
    fi

    # Un serveur lancé hors de ce script (session précédente, arrêt brutal)
    # garderait le port et servirait un environnement obsolète. Le motif est
    # ancré en début de ligne de commande : il ne peut pas correspondre au
    # shell qui exécute ce script.
    for ORPHAN in $(pgrep -f "^php -S ${HOST}:${PORT}" 2>/dev/null || true); do
        kill "$ORPHAN" 2>/dev/null || true
        sleep 0.3
        kill -9 "$ORPHAN" 2>/dev/null || true
    done

    echo "dev-server stopped"
}

case "${1:-start}" in
    start) start ;;
    stop) stop ;;
    restart) stop; start ;;
    *) echo "usage: $0 {start|stop|restart}" >&2; exit 2 ;;
esac
