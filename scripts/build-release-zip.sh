#!/usr/bin/env bash
# Construit (et vérifie) l'artefact ZIP de production.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MODE="${1:---build}"

case "$MODE" in
    --verify-only) php scripts/release-artifact.php verify ;;
    --build) php scripts/release-artifact.php build "${2:-}" ;;
    *) echo "usage: $0 [--build [zip]|--verify-only]" >&2; exit 2 ;;
esac
