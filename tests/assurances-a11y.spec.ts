/**
 * Écran Assurances de la mairie : titre de niveau 1, tableau avec légende
 * et en-têtes de colonnes, aucune violation axe. Le parcours de dépôt et
 * de revue est couvert par tests/assurance-review.spec.ts.
 *
 * Origine : audit du 14/10/2026, lot 2 (ex-tests/audit-fixes-lot2.spec.ts,
 * scindé par domaine). Compte créé et supprimé par la spec.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

const WP = '/usr/local/bin/wp-cli.phar';
const PASSWORD = 'Assurances-E2E-2026';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

async function loginAs(page: Page, user: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(PASSWORD);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

test.describe('Écran Assurances traduisible et accessible (PRA-06)', () => {
  const user = 'psc-assurances-e2e-a11y';
  test.beforeAll(() => {
    wpEval(`require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','${user}'); if($u) wp_delete_user((int)$u->ID);
      $id=wp_insert_user(array('user_login'=>'${user}','user_pass'=>'${PASSWORD}','user_email'=>'${user}@example.test','role'=>'subscriber'));
      get_user_by('id',$id)->add_cap('psc_manage_families'); echo 'ok';`);
  });
  test.afterAll(() => {
    wpEval(`require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','${user}'); if($u) wp_delete_user((int)$u->ID); echo 'ok';`);
  });

  test('le tableau a une légende et des en-têtes de colonnes', async ({ page }) => {
    await loginAs(page, user);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_assurances`);
    await expect(page.getByRole('heading', { level: 1, name: 'Assurances scolaires' })).toBeVisible();
    await expect(page.locator('table.widefat caption')).toHaveCount(1);
    await expect(page.locator('table.widefat thead th[scope="col"]')).toHaveCount(4);
    const axe = await new AxeBuilder({ page }).include('.wrap').analyze();
    expect(axe.violations.map((v) => v.id)).toEqual([]);
  });
});
