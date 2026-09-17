/**
 * globalSetup Playwright — prépare l'état de départ des captures d'écran de
 * la documentation administrateur en exécutant bin/seed-docs-screenshots.php
 * via WP-CLI dans le conteneur WordPress.
 *
 * Même mécanique que playwright/global-setup.ts (podman/docker exec de
 * WP-CLI, cf. son doc-block pour le détail), simplifiée : un seul profil,
 * pas de sélection de projet.
 */

import { createWriteStream, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import * as https from 'node:https';
import * as path from 'node:path';

const WP_CLI_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_WP_PATH = '/var/www/html';
const CONTAINER_PLUGIN_PATH = `${CONTAINER_WP_PATH}/wp-content/plugins/periscolaire-registration`;

const ROOT = path.resolve(__dirname, '..', '..');
const CACHE_DIR = path.join(ROOT, '.cache');
const HOST_WP_CLI = path.join(CACHE_DIR, 'wp-cli.phar');
const OUT_DIR = path.join(ROOT, '.playwright');
export const SEED_RESULT_FILE = path.join(OUT_DIR, 'seed.screenshots.json');

export interface DocsScreenshotsSeedResult {
  parent_email: string;
  parent_id: number;
  noe_id: number;
  alma_id: number;
  sophie_id: number;
  request_email: string;
  year_id: number;
  year_key: string;
  next_year_id: number;
  next_week: string;
  invoice_mois_envoye: string;
  invoice_mois_a_envoyer: string;
  form_page_url: string;
}

function sh(cmd: string, args: string[]): string {
  return execFileSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
}

function containerHasWpCli(): boolean {
  try {
    execFileSync(ENGINE, ['exec', CONTAINER, 'test', '-f', CONTAINER_WP_CLI], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

function downloadWpCli(): Promise<void> {
  mkdirSync(CACHE_DIR, { recursive: true });
  return new Promise((resolve, reject) => {
    const fetch = (url: string) => {
      https
        .get(url, (res) => {
          const status = res.statusCode ?? 0;
          if (status >= 300 && status < 400 && res.headers.location) {
            fetch(res.headers.location);
            return;
          }
          if (status !== 200) {
            reject(new Error(`Téléchargement de wp-cli.phar échoué : HTTP ${status}`));
            return;
          }
          const file = createWriteStream(HOST_WP_CLI);
          res.pipe(file);
          file.on('finish', () => file.close(() => resolve()));
          file.on('error', reject);
        })
        .on('error', reject);
    };
    fetch(WP_CLI_URL);
  });
}

async function ensureWpCli(): Promise<void> {
  if (containerHasWpCli()) return;
  if (!existsSync(HOST_WP_CLI)) {
    console.log('[global-setup:screenshots] wp-cli.phar absent du conteneur : téléchargement...');
    await downloadWpCli();
  }
  sh(ENGINE, ['cp', HOST_WP_CLI, `${CONTAINER}:${CONTAINER_WP_CLI}`]);
  sh(ENGINE, ['exec', CONTAINER, 'chmod', '+x', CONTAINER_WP_CLI]);
}

// Cf. playwright/global-setup.ts : exécuté en www-data pour que les
// fichiers déposés par le seed appartiennent au même utilisateur que le
// serveur web, jamais root.
const WEB_USER = 'www-data';

function runSeed(): DocsScreenshotsSeedResult {
  const output = sh(ENGINE, [
    'exec',
    '-u',
    WEB_USER,
    CONTAINER,
    'php',
    CONTAINER_WP_CLI,
    `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-docs-screenshots.php`,
    'seed-docs-screenshots',
    `--path=${CONTAINER_WP_PATH}`,
    '--allow-root',
  ]);

  const jsonLine = output
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .reverse()
    .find((line) => line.startsWith('{') && line.endsWith('}'));

  if (!jsonLine) {
    throw new Error(`seed-docs-screenshots : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
  }

  return JSON.parse(jsonLine) as DocsScreenshotsSeedResult;
}

export default async function globalSetup(): Promise<void> {
  mkdirSync(OUT_DIR, { recursive: true });

  await ensureWpCli();

  const result = runSeed();
  writeFileSync(SEED_RESULT_FILE, JSON.stringify(result, null, 2));
  console.log(
    `[global-setup:screenshots] prêt — parent=${result.parent_email} noe_id=${result.noe_id} ` +
      `alma_id=${result.alma_id} next_week=${result.next_week} -> ${SEED_RESULT_FILE}`
  );
}
