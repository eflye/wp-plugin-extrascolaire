/**
 * Environnement de développement (P2-13) — le dépôt entier est monté comme
 * dossier du plugin : seuls les fichiers statiques chargés par le
 * navigateur doivent répondre, jamais les fichiers de travail (.git,
 * TODO, sources PHP, configuration). Cf. .htaccess à la racine du dépôt.
 */
import { test, expect } from '@playwright/test';

const PLUGIN = 'http://localhost:8080/wp-content/plugins/periscolaire-registration';

test('les fichiers de travail du dépôt ne sont pas servis en HTTP', async ({ request }) => {
  for (const path of ['.git/HEAD', '.github/workflows/e2e.yml', 'TODO.md', 'AGENTS.md', 'composer.json', 'package.json', 'includes/helpers/files.php', 'bin/seed-journey.php', 'docker-compose.yml']) {
    const res = await request.get(`${PLUGIN}/${path}`);
    expect([403, 404], path).toContain(res.status());
  }
  for (const path of ['assets/css/portal.css', 'assets/js/planning-2.js']) {
    const res = await request.get(`${PLUGIN}/${path}`);
    expect(res.status(), path).toBe(200);
  }
});
