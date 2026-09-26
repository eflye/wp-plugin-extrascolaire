/**
 * P1-17 — alertes d'administration d'une montée de version arrêtée et de
 * documents restés à un ancien emplacement. Les pannes elles-mêmes sont
 * provoquées par bin/verify-migration-resume.php ; ici, l'alerte doit
 * nommer l'étape et les dossiers sans rien de personnel, être réservée à
 * la configuration, et l'écran rester conforme (axe).
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

async function loginAsAdmin(page: Page) {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test('alertes P1-17 : montée de version arrêtée et documents non déplacés', async ({ page }) => {
  try {
    php(`update_option('psc_migration_failed', array('etape' => '4.17.0', 'depuis' => '4.15.0', 'requete' => 'ALTER wp_psc_supplier_orders', 'erreurs' => 1, 'ts' => time()), false);
         update_option('psc_storage_move_failed', array('e2e' => array('depuis' => '/srv/ancien-e2e', 'vers' => '/srv/nouveau-e2e', 'restants' => 2, 'ts' => time())), false); echo 'ok';`);
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);

    const migration = page.getByTestId('notice-migration-failed');
    await expect(migration).toBeVisible();
    await expect(migration).toContainText('4.17.0');
    await expect(migration).toContainText('ALTER wp_psc_supplier_orders');

    const storage = page.getByTestId('notice-storage-move-failed');
    await expect(storage).toBeVisible();
    await expect(storage).toContainText('2 fichiers sont restés dans /srv/ancien-e2e au lieu de /srv/nouveau-e2e');
    await expect(storage).toContainText('Aucun fichier n’a été supprimé');

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    // Réservées à la configuration : un compte sans psc_manage_config ne les voit pas.
    php(`$u = get_user_by('login', 'admin'); $u->add_cap('psc_manage_config', false); echo 'ok';`);
    await page.goto(`${APP_BASE}/wp-admin/index.php`);
    await expect(page.getByTestId('notice-migration-failed')).toHaveCount(0);
    await expect(page.getByTestId('notice-storage-move-failed')).toHaveCount(0);
  } finally {
    php(`$u = get_user_by('login', 'admin'); $u->remove_cap('psc_manage_config'); delete_option('psc_migration_failed'); delete_option('psc_storage_move_failed'); echo 'ok';`);
  }
});

test('alerte : base trop ancienne pour cette version, sans nouvelle tentative promise', async ({ page }) => {
  try {
    php(`update_option('psc_migration_failed', array('etape' => 'version', 'depuis' => '4.14.0', 'requete' => 'schéma 4.14.0 trop ancien : mettez d’abord à jour vers la version 5.32.1 de l’extension (schéma 4.15.0 ou plus), puis vers celle-ci', 'erreurs' => 0, 'ts' => time()), false); echo 'ok';`);
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);

    const notice = page.getByTestId('notice-migration-failed');
    await expect(notice).toContainText('la base de données est trop ancienne pour cette version');
    await expect(notice).toContainText('5.32.1');
    await expect(notice).not.toContainText('nouvelle tentative');

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);
  } finally {
    php(`delete_option('psc_migration_failed'); echo 'ok';`);
  }
});
