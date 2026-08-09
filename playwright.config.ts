import { defineConfig, devices } from '@playwright/test';
import * as path from 'node:path';

/**
 * Deux profils, cf. journeys/parent-connu.md :
 *   - test : exécution rapide et déterministe, sans vidéo.
 *   - demo : capture vidéo pour montage (habillage helpers/demo-overlay.ts
 *            + helpers/mouse-helper.ts), rythmée par slowMo.
 *
 * "test" est le profil par défaut au sens de journeys/parent-connu.md,
 * mais Playwright n'a pas de notion native de "projet par défaut" : sans
 * --project explicite, `playwright test` exécute TOUS les projets listés
 * ci-dessous. Utiliser `npm run test:e2e` / `npm run demo:e2e` (package.json)
 * ou passer --project=test|demo explicitement.
 *
 * globalSetup (commun aux deux projets) exécute bin/seed-journey.php via
 * WP-CLI pour le(s) profil(s) réellement sélectionnés et écrit le résultat
 * dans .playwright/seed.<profile>.json — cf. playwright/global-setup.ts et
 * playwright/seed-result.ts pour la lecture côté specs.
 */

const OUT_DIR = path.resolve(__dirname, '.playwright');

export default defineConfig({
  testDir: './tests',
  globalSetup: require.resolve('./playwright/global-setup'),
  outputDir: path.join(OUT_DIR, 'test-results'),
  fullyParallel: true,
  reporter: [['list']],

  projects: [
    {
      name: 'test',
      timeout: 30_000,
      expect: { timeout: 5_000 },
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
        deviceScaleFactor: 1,
        headless: true,
        video: 'off',
        actionTimeout: 10_000,
        navigationTimeout: 15_000,
      },
    },
    {
      name: 'demo',
      // tests/_diag-hang.spec.ts est un outil de diagnostic et
      // tests/supplier-order.spec.ts un parcours backoffice pur (aucune
      // famille impliquée, pas d'habillage vidéo) : sans cette exclusion,
      // le profil demo les exécute aussi et produit des .webm parasites en
      // plus de parent-connu.spec.ts.
      testIgnore: /(_diag-hang|supplier-order)\.spec\.ts$/,
      // slowMo ralentit aussi les attentes internes à chaque action
      // (waitForResponse, waitForSelector...), pas seulement le rendu :
      // des timeouts calibrés pour le profil "test" échoueraient ici.
      // Marges généreuses plutôt qu'un simple facteur proportionnel au
      // slowMo, pour absorber aussi le coût de l'encodage vidéo à
      // deviceScaleFactor 2.
      timeout: 90_000,
      expect: { timeout: 15_000 },
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
        deviceScaleFactor: 2,
        headless: true, // le rendu vidéo ne dépend pas du mode fenêtré
        video: {
          mode: 'on',
          // Strictement égale au viewport : si elle diffère, Playwright
          // redimensionne la vidéo et le rendu perd en netteté.
          size: { width: 1280, height: 720 },
        },
        launchOptions: { slowMo: 250 },
        actionTimeout: 30_000,
        navigationTimeout: 45_000,
      },
    },
  ],
});
