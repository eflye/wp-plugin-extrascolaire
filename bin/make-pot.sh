#!/usr/bin/env bash
#
# Catalogue de traduction — languages/periscolaire-registration.pot.
#
#   bin/make-pot.sh            régénère le catalogue (à committer avec les chaînes modifiées)
#   bin/make-pot.sh --check    échoue si le catalogue versionné n'est plus à jour (CI)
#
# `wp i18n make-pot` tourne dans le conteneur WordPress (WP-CLI y est
# déposé par bin/test-stack.sh ou par la CI), sans charger WordPress :
# seules les sources PHP de l'extension sont lues. Le fichier est écrit
# dans le /tmp du conteneur puis recopié : le dépôt monté en bind
# appartient à l'hôte, le conteneur n'y écrit pas. Les chaînes des
# scripts passent par wp_localize_script() côté PHP, donc tout est
# couvert. La date de génération (POT-Creation-Date) change à chaque
# exécution, Project-Id-Version suit la version de l'extension, et les
# références « #: fichier:ligne » bougent dès qu'une ligne est déplacée :
# la comparaison ignore ces trois éléments, et ne réagit donc qu'aux
# chaînes elles-mêmes (ajout, retrait, modification, commentaire
# « translators: »). Le catalogue versionné garde ses références, mises
# à jour à chaque régénération.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENGINE="${PSC_CONTAINER_ENGINE:-podman}"
CONTAINER="${PSC_WP_CONTAINER:-plugin-extrascolaire-wordpress-1}"
PLUGIN=/var/www/html/wp-content/plugins/periscolaire-registration
POT=languages/periscolaire-registration.pot
EXCLUDE='node_modules,vendor,tests,bin,docs,documentation,theme,scripts,playwright,site,mu-plugins,helpers,journeys,.design-sync,.ds-sync,ds-bundle,.cache,.playwright,.claude,includes/fpdf,claude-export,build'

generate() {
    "$ENGINE" exec -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache "$CONTAINER" \
        php /usr/local/bin/wp-cli.phar i18n make-pot "$PLUGIN" /tmp/psc-make-pot.pot \
        --domain=periscolaire-registration \
        --exclude="$EXCLUDE" \
        --headers='{"Report-Msgid-Bugs-To":"https://github.com/eflye/wp-plugin-extrascolaire/issues","Language-Team":"Mairie de Montgeroult"}' \
        --allow-root
    "$ENGINE" exec "$CONTAINER" cat /tmp/psc-make-pot.pot > "$1"
}

strip_date() { grep -vE '^"(POT-Creation-Date|Project-Id-Version):|^#: ' "$1"; }

cd "$ROOT"
mkdir -p languages

if [ "${1:-}" = "--check" ]; then
    TMP="$(mktemp)"
    trap 'rm -f "$TMP"' EXIT
    generate "$TMP" >/dev/null
    if diff -u <(strip_date "$POT") <(strip_date "$TMP") >/dev/null; then
        echo "Catalogue de traduction à jour ($(grep -c '^msgid' "$POT") entrées)."
    else
        echo "Le catalogue $POT n'est plus à jour : lancez bin/make-pot.sh et committez le résultat." >&2
        diff -u <(strip_date "$POT") <(strip_date "$TMP") | head -40 >&2 || true
        exit 1
    fi
else
    generate "$POT"
    echo "$POT régénéré ($(grep -c '^msgid' "$POT") entrées)."
fi
