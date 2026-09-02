/**
 * globalSetup Playwright — prépare l'état de départ du parcours
 * journeys/parent-connu.md en exécutant bin/seed-journey.php via WP-CLI
 * dans le conteneur WordPress, pour le(s) profil(s) réellement demandés
 * (--project=test|demo).
 *
 * C'est le contrat seed->test : ce fichier ne réimplémente JAMAIS la
 * logique de seed en JS, il se contente d'appeler le script PHP et de
 * parser sa sortie.
 *
 * L'environnement Playwright (host) ne peut pas parler directement à la
 * base WordPress : les fichiers core sont dans un volume Docker/Podman
 * nommé, invisible du host (seul le plugin est bind-mounté). On passe
 * donc par `podman exec` (ou `docker exec`, configurable) pour lancer
 * WP-CLI dans le conteneur. WP-CLI lui-même n'est pas préinstallé dans
 * l'image `wordpress:latest` : on le fournit au conteneur au besoin,
 * une fois, depuis un cache local sur le host (le conteneur n'a pas
 * d'accès réseau sortant dans cet environnement).
 */

import type { FullConfig } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createWriteStream, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import * as https from 'node:https';
import * as path from 'node:path';

const WP_CLI_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_WP_PATH = '/var/www/html';
const CONTAINER_PLUGIN_PATH = `${CONTAINER_WP_PATH}/wp-content/plugins/periscolaire-registration`;

const ROOT = path.resolve(__dirname, '..');
const CACHE_DIR = path.join(ROOT, '.cache');
const HOST_WP_CLI = path.join(CACHE_DIR, 'wp-cli.phar');
const OUT_DIR = path.join(ROOT, '.playwright');

export interface SeedResult {
  profile: 'test' | 'demo';
  parent_email: string;
  parent_id: number;
  /** Clé de l'année scolaire du planning (v5 : plus de trimestre). */
  year_key: string;
  enfants: Array<{ index: number; prenom: string; nom: string; classe: string }>;
  open_day: string | null;
  locked_day: string | null;
  months: string[];
  /** URL de la page portant le formulaire, résolue par l'extension elle-même. */
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
    console.log('[global-setup] wp-cli.phar absent du conteneur : téléchargement...');
    await downloadWpCli();
  }
  sh(ENGINE, ['cp', HOST_WP_CLI, `${CONTAINER}:${CONTAINER_WP_CLI}`]);
  sh(ENGINE, ['exec', CONTAINER, 'chmod', '+x', CONTAINER_WP_CLI]);
}

/**
 * Utilisateur sous lequel tourne le serveur web dans l'image officielle
 * WordPress. Le peuplement écrit des fichiers que l'application relira et
 * complétera ensuite (justificatifs d'assurance) : exécuté en root, il crée
 * un dossier privé appartenant à root, et les dépôts ultérieurs des familles
 * échouent en silence — le fichier n'est pas écrit et la colonne reste vide.
 * Le symptôme est distant de la cause, et invisible sur une installation
 * ancienne dont le dossier existait déjà.
 */
const WEB_USER = 'www-data';

function runSeed(profile: 'test' | 'demo'): SeedResult {
  const output = sh(ENGINE, [
    'exec',
    '-u',
    WEB_USER,
    CONTAINER,
    'php',
    CONTAINER_WP_CLI,
    `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-journey.php`,
    'seed-journey',
    `--profile=${profile}`,
    `--path=${CONTAINER_WP_PATH}`,
    '--allow-root',
  ]);

  // bin/seed-journey.php termine toujours par une unique ligne JSON
  // machine-lisible (cf. sa propre doc en tête de fichier) : on prend la
  // dernière ligne qui ressemble à un objet JSON complet, pas la première
  // ligne qui commence par "{" au cas où un warning WP-CLI en contiendrait.
  const jsonLine = output
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .reverse()
    .find((line) => line.startsWith('{') && line.endsWith('}'));

  if (!jsonLine) {
    throw new Error(
      `seed-journey --profile=${profile} : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`
    );
  }

  return JSON.parse(jsonLine) as SeedResult;
}

/**
 * `FullConfig.projects` liste TOUJOURS tous les projets déclarés dans
 * playwright.config.ts, y compris quand la commande est lancée avec
 * --project=xxx (vérifié en conditions réelles : `--project=test` seul
 * seedait quand même 'test' ET 'demo'). Comme chaque profil désactive tous
 * les autres trimestres pour rester sans ambiguïté (bin/seed-journey.php),
 * seeder les deux à chaque run écrase l'état actif du profil qu'on ne
 * teste pas. On lit donc --project directement dans argv plutôt que dans
 * FullConfig, pour ne seeder QUE le(s) profil(s) réellement sélectionnés.
 */
function selectedProfiles(): Array<'test' | 'demo'> {
  const profiles = new Set<'test' | 'demo'>();
  const argv = process.argv;
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    let value: string | null = null;
    if (arg === '--project' && argv[i + 1]) {
      value = argv[i + 1];
    } else if (arg.startsWith('--project=')) {
      value = arg.slice('--project='.length);
    }
    if (value === 'test' || value === 'demo') {
      profiles.add(value);
    }
  }
  // Aucun --project (ex. `playwright test` nu, qui exécuterait tous les
  // projets) : on retombe sur le profil par défaut documenté, "test", plutôt
  // que de seeder (et donc activer) 'demo' silencieusement à sa place.
  return profiles.size > 0 ? [...profiles] : ['test'];
}

export default async function globalSetup(_config: FullConfig): Promise<void> {
  mkdirSync(OUT_DIR, { recursive: true });

  const profiles = selectedProfiles();

  await ensureWpCli();

  for (const profile of profiles) {
    const result = runSeed(profile);
    const outFile = path.join(OUT_DIR, `seed.${profile}.json`);
    writeFileSync(outFile, JSON.stringify(result, null, 2));
    console.log(
      `[global-setup] profil '${profile}' prêt — open_day=${result.open_day} ` +
        `locked_day=${result.locked_day} parent=${result.parent_email} ` +
        `mois=${result.months.join(',')} -> ${outFile}`
    );
  }
}
