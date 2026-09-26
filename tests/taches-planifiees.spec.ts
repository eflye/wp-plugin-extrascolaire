/**
 * P1-11 — alerte de panne : une tâche planifiée du plugin en retard de plus
 * de six heures (WP-Cron à l'arrêt) est signalée sur les tableaux de bord,
 * avec la liste des tâches. Tâches à l'heure : aucun avis. Un compte sans
 * la capacité de configuration ne voit pas l'avis.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const USER = 'cron-facturation';
const PASSWORD = 'cron-facturation-test-2026';

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

/** Replanifie toutes les tâches récurrentes : $offset secondes depuis maintenant. */
function schedule(offset: number, only?: string) {
  php(`foreach (array_keys(psc_recurring_cron_hooks()) as $h) { if (${only ? `$h !== '${only}'` : 'false'}) continue; $s = wp_get_schedule($h) ?: 'daily'; wp_clear_scheduled_hook($h); wp_schedule_event(time() + (${offset}), $s, $h); } echo 'ok';`);
}

async function login(page: Page, user: string, pass: string) {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test.describe.serial('Tâches planifiées en retard', () => {
  test.beforeAll(() => {
    php(`if ($u = get_user_by('login', '${USER}')) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); } $id = wp_insert_user(array('user_login' => '${USER}', 'user_pass' => '${PASSWORD}', 'user_email' => '${USER}@example.test', 'role' => 'subscriber')); (new WP_User($id))->add_cap('psc_manage_billing'); echo $id;`);
  });
  test.afterAll(() => {
    schedule(3600);
    php(`if ($u = get_user_by('login', '${USER}')) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); } echo 'ok';`);
  });

  test('une tâche en retard est signalée, puis l’avis disparaît', async ({ page }) => {
    schedule(3600);
    schedule(-2 * 86400, 'psc_cleanup_requests');
    await login(page, 'admin', 'admin');

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_dashboard`);
    const notice = page.getByTestId('notice-cron-late');
    await expect(notice).toContainText('les tâches planifiées ne s’exécutent plus');
    await expect(notice.getByRole('listitem')).toHaveText(['Nettoyage des demandes d’inscription']);
    await expect(notice).toContainText('2 jours');

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    await page.goto(`${APP_BASE}/wp-admin/index.php`);
    await expect(page.getByTestId('notice-cron-late')).toBeVisible();

    // Cinq heures de retard : dans le délai de grâce.
    schedule(-5 * 3600, 'psc_cleanup_requests');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_dashboard`);
    await expect(page.getByTestId('notice-cron-late')).toHaveCount(0);
  });

  test('sans la capacité de configuration, pas d’avis', async ({ page }) => {
    schedule(-2 * 86400, 'psc_cleanup_requests');
    await login(page, USER, PASSWORD);
    await page.goto(`${APP_BASE}/wp-admin/index.php`);
    await expect(page.locator('#wpbody-content')).toBeVisible();
    await expect(page.getByTestId('notice-cron-late')).toHaveCount(0);
  });
});
