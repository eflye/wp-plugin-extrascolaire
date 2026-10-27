/**
 * Cibles de l'environnement testé — source unique pour les scénarios
 * Playwright, les helpers et les globalSetup.
 *
 * Les valeurs par défaut sont celles de la CI (.github/workflows/e2e.yml)
 * et de la stack de travail (docker-compose.yml) : WordPress sur :8080,
 * Mailpit sur :8025, conteneur `plugin-extrascolaire-wordpress-1`.
 *
 * La stack de test locale (bin/test-stack.sh, docker-compose.test.yml)
 * surcharge ces quatre variables pour viser :8081 / :8026 et le conteneur
 * `psc-test-wordpress-1`, afin que les tests ne touchent jamais la base
 * de travail.
 */

/** Racine du site WordPress testé, sans barre oblique finale. */
export const APP_BASE = (process.env.PSC_APP_BASE ?? 'http://localhost:8080').replace(/\/+$/, '');

/** Racine de l'API Mailpit, sans barre oblique finale. */
export const MAILPIT_API = (process.env.PSC_MAILPIT_API ?? 'http://localhost:8025/api/v1').replace(/\/+$/, '');

/** Moteur de conteneurs (`podman` sur les postes de développement, `docker` en CI). */
export const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';

/** Nom du conteneur WordPress dans lequel WP-CLI est exécuté. */
export const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

/** Conteneur de la stack de travail : y seeder efface les données locales. */
export const WORK_CONTAINER = 'plugin-extrascolaire-wordpress-1';
