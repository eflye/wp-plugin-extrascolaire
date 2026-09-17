import { defineConfig, devices } from '@playwright/test';

/**
 * Configuration Playwright dédiée aux captures d'écran de la documentation
 * administrateur (cf. docs-plan/PLAN.md, étape 04). Volontairement séparée
 * de playwright.config.ts : un troisième `project` dans ce fichier
 * réexécuterait le `globalSetup` du parcours parent-connu (bin/seed-journey.php),
 * sans rapport avec les captures et coûteux pour rien.
 */

export default defineConfig({
  testDir: './scripts/screenshots',
  globalSetup: require.resolve('./scripts/screenshots/global-setup'),
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],

  use: {
    ...devices['Desktop Chrome'],
    viewport: { width: 1280, height: 800 },
    deviceScaleFactor: 1,
    headless: true,
    actionTimeout: 10_000,
    navigationTimeout: 15_000,
  },
});
