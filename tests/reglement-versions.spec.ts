/**
 * P2-14 — version des règlements approuvés. Depuis une demande en attente,
 * la mairie ouvre la version acceptée : texte exact affiché à la famille,
 * empreinte, nombre d'acceptations. Contrôle axe de l'écran ; un compte
 * sans la gestion des familles n'y a pas accès.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const EMAIL = 'reglement-versions@example.invalid';
const USER = 'reglement-facturation';
const PASSWORD = 'reglement-facturation-test-2026';

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

async function login(page: Page, user = 'admin', pass = 'admin') {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

function cleanup() {
  php(`global $wpdb; $wpdb->delete(psc_table('requests'), array('email' => '${EMAIL}'));
    if ($u = get_user_by('login', '${USER}')) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID); } echo 'ok';`);
}

test.describe.serial('Versions des règlements approuvés', () => {
  let requestId = 0;
  let versionId = 0;
  test.beforeAll(() => {
    cleanup();
    const ids = JSON.parse(php(`global $wpdb; $v = Psc_Document_Versions::current_id('reglement_interieur');
      $wpdb->insert(psc_table('requests'), array('email' => '${EMAIL}', 'nom' => 'Versions', 'prenom' => 'Alix', 'children_json' => wp_json_encode(array(array('prenom' => 'Ana', 'nom' => 'Versions', 'classe' => 'CE1'))),
        'status' => 'pending', 'verified' => 1, 'reglement_accepted_at' => current_time('mysql'), 'reglement_version_id' => $v, 'created_at' => current_time('mysql')));
      $id = wp_insert_user(array('user_login' => '${USER}', 'user_pass' => '${PASSWORD}', 'user_email' => '${USER}@example.test', 'role' => 'subscriber'));
      (new WP_User($id))->add_cap('psc_manage_billing');
      echo wp_json_encode(array('version' => (int) $v));`));
    requestId = Number(php(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('requests').' WHERE email=%s', '${EMAIL}'));`));
    versionId = ids.version;
  });
  test.afterAll(() => cleanup());

  test('depuis une demande, la version acceptée et son texte exact', async ({ page }) => {
    expect(versionId).toBeGreaterThan(0);
    await login(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_requests`);
    const link = page.getByTestId(`request-reglement-version-${requestId}`);
    await expect(link).toContainText(`version n° ${versionId}`);
    await link.click();

    await expect(page.getByRole('heading', { level: 1 })).toContainText(`Règlement intérieur — version n° ${versionId}`);
    await expect(page.getByTestId('reglement-version-texte')).toContainText('1 – Préambule');
    await expect(page.getByTestId('reglement-version-meta')).toContainText(/[0-9a-f]{64}/);
    expect(Number(await page.getByTestId('reglement-version-count').innerText())).toBeGreaterThanOrEqual(1);

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);
  });

  test('sans la gestion des familles, l’écran est refusé', async ({ page }) => {
    await login(page, USER, PASSWORD);
    const res = await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_reglement_version&id=${versionId}`);
    expect(res?.status()).toBe(403);
    await expect(page.getByTestId('reglement-version-texte')).toHaveCount(0);
  });
});
