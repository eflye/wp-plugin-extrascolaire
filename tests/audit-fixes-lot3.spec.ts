/**
 * Corrections d'audit — lot 3 (envois, calendrier, facturation,
 * performance, administration). Les assertions de logique pure vivent
 * dans bin/verify-audit-lot3.php ; ici, ce qui demande une vraie requête
 * HTTP ou un écran.
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const APP_BASE = 'http://localhost:8080';
const AGENT_LOGIN = 'psc-e2e-lot3-presence';
const AGENT_PASSWORD = 'Lot3-Presence-E2E-2026';

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

test.describe('Audit — lot 3', () => {
  test('PRA-09 : le portail part avec no-store/private, émis avant le rendu', async ({ request }) => {
    expect(wpCliEval(
      "echo has_action('template_redirect', array('Psc_Frontend', 'portal_private_headers')) !== false ? 'ok' : 'absent';"
    )).toBe('ok');
    const resp = await request.get(readFormPageUrl());
    const cc = resp.headers()['cache-control'] ?? '';
    expect(cc).toContain('no-store');
    expect(cc).toContain('private');
  });

  test.describe('PRA-02/L3 : réglages fournisseur selon l\'habilitation', () => {
    const cleanupAgent = () => wpCliEval(
      `require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','${AGENT_LOGIN}'); if($u) wp_delete_user((int)$u->ID); echo 'ok';`
    );
    test.beforeAll(() => {
      cleanupAgent();
      wpCliEval(`$id=wp_insert_user(array('user_login'=>'${AGENT_LOGIN}','user_pass'=>'${AGENT_PASSWORD}','user_email'=>'${AGENT_LOGIN}@example.test','role'=>'subscriber'));
        get_user_by('id',$id)->add_cap('psc_manage_presence'); echo 'ok';`);
    });
    test.afterAll(() => { cleanupAgent(); });

    async function loginAs(page: Page): Promise<void> {
      await page.goto(`${APP_BASE}/wp-login.php`);
      await page.locator('#user_login').fill(AGENT_LOGIN);
      await page.locator('#user_pass').fill(AGENT_PASSWORD);
      await page.locator('#wp-submit').click();
      await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
    }

    test('un agent « présences » voit l\'adresse sans formulaire, et l\'enregistrement lui reste refusé', async ({ page }) => {
      await loginAs(page);
      const resp = await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_supplier_orders&tab=reglages`);
      expect(resp?.status()).toBe(200);
      await expect(page.getByTestId('supplier-email-readonly')).toBeVisible();
      await expect(page.locator('input[name="action"][value="psc_save_supplier_settings"]')).toHaveCount(0);
      await expect(page.locator('#psc-supplier-mail')).toHaveCount(0);
      const axe = await new AxeBuilder({ page }).include('.psc-box').analyze();
      expect(axe.violations).toEqual([]);

      // Contrôle serveur inchangé : un POST forgé reste refusé.
      const post = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
        form: { action: 'psc_save_supplier_settings', supplier_email: 'pirate@example.test', _wpnonce: 'x' },
        maxRedirects: 0,
      });
      expect(post.status()).toBeGreaterThanOrEqual(400);
    });
  });
});
