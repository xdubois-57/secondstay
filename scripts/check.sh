#!/usr/bin/env bash
#
# SecondStay — commande canonique de validation locale (TESTING.md §2).
#
#   ./scripts/check.sh            gates rapides + DB + JS + E2E (défaut = --full)
#   ./scripts/check.sh --fast     syntaxe + PHPStan + PHPUnit unitaire + i18n
#   ./scripts/check.sh --php      syntaxe + PHPStan + PHPUnit
#   ./scripts/check.sh --db       tests d'intégration base de données
#   ./scripts/check.sh --js       Vitest + tsc
#   ./scripts/check.sh --e2e      Playwright
#   ./scripts/check.sh --security composer audit + contrôles de fuite
#   ./scripts/check.sh --full     tout
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MODE="${1:---full}"

# Configuration locale de la base de test (jamais versionnée).
# Voir TESTING.md §5 : la base de production ne doit jamais être utilisée.
if [ -f "$ROOT/scripts/test-env.local.sh" ]; then
    # shellcheck disable=SC1091
    . "$ROOT/scripts/test-env.local.sh"
fi

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; RESET=$'\033[0m'

FAILURES=()
STEPS=0

section() {
    printf '\n%s==> %s%s\n' "${BLUE}${BOLD}" "$1" "$RESET"
}

run_step() {
    local name="$1"; shift
    STEPS=$((STEPS + 1))
    section "$name"
    if "$@"; then
        printf '%s✔ %s%s\n' "$GREEN" "$name" "$RESET"
        return 0
    fi
    printf '%s✘ %s%s\n' "$RED" "$name" "$RESET"
    FAILURES+=("$name")
    return 1
}

require_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        printf '%sDépendances Composer absentes. Lancez: composer install%s\n' "$RED" "$RESET"
        exit 1
    fi
}

php_syntax() {
    local failed=0
    while IFS= read -r -d '' file; do
        if ! php -l "$file" > /dev/null; then
            php -l "$file"
            failed=1
        fi
    done < <(find bootstrap src public config scripts tests/php migrations translations -name '*.php' -print0 2>/dev/null)
    return $failed
}

phpstan() {
    ./vendor/bin/phpstan analyse --no-progress
}

phpunit_unit() {
    XDEBUG_MODE=off ./vendor/bin/phpunit --testsuite unit --do-not-cache-result
}

phpunit_coverage() {
    if php -m | grep -qiE '^(xdebug|pcov)$'; then
        XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite unit --do-not-cache-result \
            --coverage-clover build/coverage/clover.xml \
            --coverage-html build/coverage/html
    else
        printf 'Aucun driver de couverture (xdebug/pcov) : exécution sans couverture.\n'
        phpunit_unit
    fi
}

phpunit_db() {
    if [ -z "$(find tests/php/Database -name '*Test.php' -print -quit 2>/dev/null)" ]; then
        printf 'Aucun test d’intégration base de données à ce stade du projet.\n'
        return 0
    fi
    if [ -z "${SECONDSTAY_TEST_DB_NAME:-}" ]; then
        printf '%sSECONDSTAY_TEST_DB_NAME non défini : configurez une base de test dédiée.%s\n' "$YELLOW" "$RESET"
        printf 'Voir TESTING.md §5. La base de production ne doit jamais être utilisée.\n'
        return 1
    fi
    # `SECONDSTAY_TEST_DB_REQUIRED` transforme « pas de base configurée » en
    # échec plutôt qu'en test ignoré : une exécution automatisée qui n'a touché
    # aucune base n'a pas fait son travail. Voir l'en-tête de
    # `tests/php/Support/DatabaseTestCase.php` pour ce que ce garde-fou couvre
    # exactement — un trou latent, pas actuel.
    SECONDSTAY_TEST_DB_REQUIRED=1 XDEBUG_MODE=off \
        ./vendor/bin/phpunit --testsuite database --do-not-cache-result
}

vitest() {
    npm run --silent test
}

# L'autre moitié de l'analyse statique : les défauts du JavaScript navigateur
# qu'aucune des trois campagnes ne voit, parce qu'ils vivent dans du code
# qu'elles n'exécutent pas. TypeScript est un vérificateur, jamais une étape de
# construction : rien n'est compilé (voir `tsconfig.json`).
typecheck() {
    npm run --silent typecheck
}

playwright() {
    # `SECONDSTAY_E2E_PROJECT` restreint la campagne à un seul navigateur.
    # L'intégration continue s'en sert pour jouer les deux projets en
    # parallèle sur deux exécuteurs : chacun installe le sien, ce qui les
    # isole mieux qu'une installation partagée. Sans la variable, les deux
    # sont joués à la suite, comme en local.
    #
    # `${project[@]+…}` et non `"${project[@]}"` : bash 3.2, celui que macOS
    # livre encore, traite l'expansion d'un tableau vide comme une variable
    # non définie et `set -u` interrompt alors toute la commande de
    # validation.
    local project=()
    if [ -n "${SECONDSTAY_E2E_PROJECT:-}" ]; then
        project=(--project="$SECONDSTAY_E2E_PROJECT")
    fi

    # Transports factices : les parcours de compte et de notification sont
    # vérifiables sans serveur SMTP, sans service de push et sans réseau.
    SECONDSTAY_MAIL_TRANSPORT=fake SECONDSTAY_PUSH_PROVIDER=fake SECONDSTAY_LLM_PROVIDER=fake \
        npx playwright test ${project[@]+"${project[@]}"}
}

composer_audit() {
    COMPOSER_ALLOW_SUPERUSER=1 composer audit --no-interaction --locked
}

secret_scan() {
    "$ROOT/scripts/check-secrets.sh"
}

i18n_check() {
    XDEBUG_MODE=off ./vendor/bin/phpunit --testsuite unit --filter 'TranslationCatalogue' --do-not-cache-result
}

artifact_check() {
    "$ROOT/scripts/build-release-zip.sh" --verify-only
}

require_dependencies

case "$MODE" in
    --fast)
        run_step "Syntaxe PHP" php_syntax
        run_step "PHPStan" phpstan
        run_step "PHPUnit (unitaires)" phpunit_unit
        run_step "i18n FR/EN/NL/DE" i18n_check
        ;;
    --php)
        run_step "Syntaxe PHP" php_syntax
        run_step "PHPStan" phpstan
        run_step "PHPUnit (unitaires)" phpunit_unit
        ;;
    --db)
        run_step "PHPUnit (base de données)" phpunit_db
        ;;
    --js)
        run_step "Vitest" vitest
        run_step "tsc (vérificateur JavaScript)" typecheck
        ;;
    --e2e)
        run_step "Playwright" playwright
        ;;
    --security)
        run_step "Composer audit" composer_audit
        run_step "Absence de secrets versionnés" secret_scan
        ;;
    --full|"")
        run_step "Syntaxe PHP" php_syntax
        run_step "PHPStan" phpstan
        run_step "PHPUnit (unitaires + couverture)" phpunit_coverage
        run_step "i18n FR/EN/NL/DE" i18n_check
        run_step "PHPUnit (base de données)" phpunit_db
        run_step "Vitest" vitest
        run_step "tsc (vérificateur JavaScript)" typecheck
        run_step "Playwright" playwright
        run_step "Composer audit" composer_audit
        run_step "Absence de secrets versionnés" secret_scan
        run_step "Artefact de release" artifact_check
        ;;
    *)
        printf 'Option inconnue: %s\n' "$MODE" >&2
        exit 2
        ;;
esac

printf '\n%s────────────────────────────────────────%s\n' "$BOLD" "$RESET"
if [ ${#FAILURES[@]} -eq 0 ]; then
    printf '%s✔ %d/%d contrôles réussis — SecondStay est vert.%s\n' "$GREEN$BOLD" "$STEPS" "$STEPS" "$RESET"
    exit 0
fi

printf '%s✘ %d/%d contrôles en échec :%s\n' "$RED$BOLD" "${#FAILURES[@]}" "$STEPS" "$RESET"
for failure in "${FAILURES[@]}"; do
    printf '   - %s\n' "$failure"
done
exit 1
