/**
 * Adresse du calendrier scolaire (P2-05) — l'écran Réglages refuse une
 * adresse interne, le dit, et garde l'adresse précédente.
 *
 * bin/verify-ics-import.php couvre l'import lui-même (réseau interne,
 * redirections, volume, calendrier aberrant) ; cette spec couvre l'écran
 * touché, contrôle d'accessibilité axe compris.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' }).trim();
}

test('réglages : une adresse de calendrier interne est refusée et l’ancienne conservée', async ({ page }) => {
  const previous = wp('eval', "echo get_option('psc_school_calendar_ics_url', '');");
  try {
    wp('option', 'update', 'psc_school_calendar_ics_url', '');
    await page.goto(`${APP_BASE}/wp-login.php`);
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);
    await page.locator('#psc-ics-url').fill('http://169.254.169.254/latest/meta-data');
    await page.getByRole('button', { name: 'Enregistrer' }).last().click();

    await expect(page.getByText(/sauf l’adresse du calendrier scolaire/)).toBeVisible();
    await expect(page.locator('#psc-ics-url')).toHaveValue('');
    expect(wp('eval', "echo get_option('psc_school_calendar_ics_url', '');")).toBe('');

    const results = await new AxeBuilder({ page })
      .include('#wpbody-content .wrap')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.map((v) => `${v.id} : ${v.nodes.length} élément(s)`)).toEqual([]);
  } finally {
    wp('option', 'update', 'psc_school_calendar_ics_url', previous);
  }
});
