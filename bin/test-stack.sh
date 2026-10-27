#!/usr/bin/env bash
#
# Stack de test locale — une seconde instance WordPress + MySQL + Mailpit,
# jetable, montée depuis docker-compose.yml sous un autre nom de projet
# compose et d'autres ports. Les scénarios Playwright et les scripts
# bin/verify-*.php s'y exécutent sans jamais toucher la stack de travail
# (:8080, projet plugin-extrascolaire).
#
#   bin/test-stack.sh up                 démarre, installe WordPress, active l'extension
#   bin/test-stack.sh playwright [args]  lance Playwright sur cette stack (npm run test:local)
#   bin/test-stack.sh verify <nom>       joue bin/verify-<nom>.php dessus (ex. migrations)
#   bin/test-stack.sh wp <args>          WP-CLI sous www-data, comme en CI
#   bin/test-stack.sh env                affiche les variables à exporter pour la viser
#   bin/test-stack.sh status             conteneurs et site
#   bin/test-stack.sh down               détruit conteneurs ET volumes
#
# Les étapes d'installation reproduisent .github/workflows/e2e.yml : même
# ordre, même utilisateur (www-data), même page d'accueil. `up` est
# idempotent — relancé sur une stack déjà installée, il ne fait que
# vérifier qu'elle répond.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="${PSC_CONTAINER_ENGINE:-podman}"

export COMPOSE_PROJECT_NAME="${PSC_TEST_PROJECT:-psc-test}"
export PSC_WP_PORT="${PSC_TEST_WP_PORT:-8081}"
export PSC_MAILPIT_PORT="${PSC_TEST_MAILPIT_PORT:-8026}"
export MAILPIT_ENABLED=true

APP_BASE="http://localhost:${PSC_WP_PORT}"
MAILPIT_API="http://localhost:${PSC_MAILPIT_PORT}/api/v1"
WP_CLI_URL='https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar'
CONTAINER_WP_CLI=/usr/local/bin/wp-cli.phar
CONTAINER_WP_PATH=/var/www/html
CONTAINER_PLUGIN_PATH="$CONTAINER_WP_PATH/wp-content/plugins/periscolaire-registration"

compose() { "$ENGINE" compose --profile mailpit "$@"; }

wp_container() {
    local id
    id="$(compose ps -q wordpress 2>/dev/null || true)"
    [ -n "$id" ] || { echo "La stack de test n'est pas démarrée : bin/test-stack.sh up" >&2; return 1; }
    "$ENGINE" inspect --format '{{.Name}}' "$id" | sed 's|^/||'
}

# WP-CLI sous www-data : l'activation de l'extension crée le dossier privé
# des documents, qui doit appartenir au serveur web (cf. e2e.yml).
wp() {
    "$ENGINE" exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
        "$(wp_container)" php "$CONTAINER_WP_CLI" --path="$CONTAINER_WP_PATH" "$@"
}

print_env() {
    cat <<EOF
export PSC_CONTAINER_ENGINE=$ENGINE
export PSC_WP_CONTAINER=$(wp_container)
export PSC_APP_BASE=$APP_BASE
export PSC_MAILPIT_API=$MAILPIT_API
EOF
}

wait_for() {
    local label="$1" url="$2" i
    for i in $(seq 1 90); do
        if curl -fsS "$url" -o /tmp/psc-test-probe.html 2>/dev/null \
           && ! grep -q 'Error establishing a database connection' /tmp/psc-test-probe.html; then
            echo "$label prêt après ${i}s."
            return 0
        fi
        sleep 1
    done
    echo "$label n'a pas répondu en 90 s." >&2
    compose logs --tail=50 wordpress db mailpit >&2 || true
    return 1
}

ensure_wp_cli() {
    local name="$1"
    if "$ENGINE" exec "$name" test -f "$CONTAINER_WP_CLI" 2>/dev/null; then return 0; fi
    mkdir -p "$ROOT/.cache"
    if [ ! -f "$ROOT/.cache/wp-cli.phar" ]; then
        echo "Téléchargement de wp-cli.phar (empreinte vérifiée)…"
        curl -fsSL -o "$ROOT/.cache/wp-cli.phar" "$WP_CLI_URL"
        curl -fsSL -o "$ROOT/.cache/wp-cli.phar.sha512" "$WP_CLI_URL.sha512"
        (cd "$ROOT/.cache" && echo "$(cat wp-cli.phar.sha512)  wp-cli.phar" | shasum -a 512 -c -)
    fi
    "$ENGINE" cp "$ROOT/.cache/wp-cli.phar" "$name:$CONTAINER_WP_CLI"
    "$ENGINE" exec "$name" chmod +x "$CONTAINER_WP_CLI"
}

cmd_up() {
    cd "$ROOT"
    compose up -d db wordpress mailpit

    local i
    for i in $(seq 1 90); do
        if compose exec -T db mysqladmin ping --silent >/dev/null 2>&1; then
            echo "MySQL prêt après ${i}s."
            break
        fi
        [ "$i" -eq 90 ] && { echo "MySQL n'a pas démarré en 90 s." >&2; compose logs --tail=50 db >&2; exit 1; }
        sleep 1
    done
    wait_for WordPress "$APP_BASE/"
    wait_for Mailpit "$MAILPIT_API/messages"

    local name
    name="$(wp_container)"
    ensure_wp_cli "$name"

    if wp core is-installed >/dev/null 2>&1; then
        echo "Site déjà installé sur ${APP_BASE}."
    else
        echo "Installation de WordPress sur ${APP_BASE}…"
        wp core install \
            --url="$APP_BASE" \
            --title='Périscolaire — stack de test' \
            --admin_user='admin' \
            --admin_password='admin' \
            --admin_email='admin@example.invalid' \
            --skip-email
        # Limitations anti-abus désactivées, comme en CI et sur un poste de
        # développement : plusieurs liens de connexion dans une même suite.
        wp config set WP_ENVIRONMENT_TYPE local
        wp theme activate Archive
        wp plugin activate periscolaire-registration
        local page_id
        page_id="$(wp post create \
            --post_type=page \
            --post_status=publish \
            --post_title='Espace familles' \
            --post_content='[periscolaire_form]' \
            --porcelain)"
        wp option update show_on_front page
        wp option update page_on_front "$page_id"
    fi

    echo
    echo "Stack de test prête : $APP_BASE (admin / admin), Mailpit sur ${MAILPIT_API%/api/v1}"
    echo "Pour la viser depuis un shell :"
    print_env
}

cmd_playwright() {
    cd "$ROOT"
    eval "$(print_env)"
    export PSC_CONTAINER_ENGINE PSC_WP_CONTAINER PSC_APP_BASE PSC_MAILPIT_API
    exec npx playwright test "$@"
}

cmd_verify() {
    local name="${1:?nom du script, ex. migrations (bin/verify-migrations.php)}"
    shift
    wp --require="$CONTAINER_PLUGIN_PATH/bin/verify-$name.php" "verify-$name" "$@"
}

cmd_status() {
    cd "$ROOT"
    compose ps
    if curl -fsS "$APP_BASE/" -o /dev/null 2>/dev/null; then
        echo "Site : $APP_BASE répond."
    else
        echo "Site : $APP_BASE ne répond pas."
    fi
}

cmd_down() {
    cd "$ROOT"
    compose down -v
    echo "Stack de test détruite (conteneurs et volumes du projet $COMPOSE_PROJECT_NAME)."
}

case "${1:-}" in
    up)          cmd_up ;;
    down)        cmd_down ;;
    status)      cmd_status ;;
    env)         print_env ;;
    wp)          shift; wp "$@" ;;
    verify)      shift; cmd_verify "$@" ;;
    playwright)  shift; cmd_playwright "$@" ;;
    *)
        sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        exit 2
        ;;
esac
