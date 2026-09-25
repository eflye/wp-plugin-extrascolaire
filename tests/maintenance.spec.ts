/**
 * Périscolaire › Maintenance : la mise à jour conduite depuis le backoffice,
 * sans ligne de commande. Parcours des cinq étapes, refus côté serveur,
 * droits, et une clé générée qui n'est jamais enregistrée.
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

test.describe.serial('Maintenance du backoffice', () => {
  test.beforeEach(() => { php(`delete_option('psc_maintenance'); echo 'ok';`); });
  test.afterAll(() => { php(`delete_option('psc_maintenance'); echo 'ok';`); });

  test('parcours des étapes : sauvegarde, clé, réglages, recette', async ({ page }) => {
    await loginAsAdmin(page);

    // Rappel sur le tableau de bord tant qu'il reste des étapes.
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_dashboard`);
    await expect(page.getByTestId('notice-maintenance')).toContainText('Maintenance');

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_maintenance`);
    await expect(page.getByRole('heading', { level: 1, name: 'Maintenance' })).toBeVisible();
    await expect(page.getByTestId('maintenance-status-sauvegarde')).toHaveText('À faire');

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    // 1. Sauvegarde.
    await page.getByTestId('maintenance-sauvegarde-check').check();
    await page.getByTestId('maintenance-sauvegarde-submit').click();
    await expect(page.getByTestId('maintenance-status-sauvegarde')).toHaveText('Fait');
    await expect(page.getByTestId('maintenance-sauvegarde')).toContainText('Sauvegarde confirmée par');

    // 2. Base : à jour sur l'instance de test.
    await expect(page.getByTestId('maintenance-status-base')).toHaveText('Fait');

    // 3. Clé : tirée de la base ici ; la clé générée est affichée une fois,
    //    au format attendu, et n'est enregistrée nulle part.
    await expect(page.getByTestId('maintenance-cle-source')).toContainText('tirée de la base');
    await page.getByTestId('maintenance-generer-cle').click();
    const shown = page.getByTestId('maintenance-cle-generee');
    await expect(shown).toContainText('PSC_ENCRYPTION_KEY: "');
    const key = ((await shown.textContent()) ?? '').match(/PSC_ENCRYPTION_KEY: "([0-9a-f]{64})"/)?.[1];
    expect(key, 'clé de 64 caractères hexadécimaux').toBeTruthy();
    await expect(shown).toContainText(`define( 'PSC_ENCRYPTION_KEY', '${key}' );`);
    expect(php(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%${key}%'));`), 'clé enregistrée dans les options').toBe('0');
    expect(php(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . psc_table('audit_log') . " WHERE CONCAT_WS(' ', resume, details) LIKE %s", '%${key}%'));`), 'clé dans le journal').toBe('0');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_maintenance`);
    await expect(page.getByTestId('maintenance-cle-generee')).toHaveCount(0);

    // 4. Modèles relus.
    await page.getByTestId('maintenance-modeles').click();
    await expect(page.getByTestId('maintenance-reglage-modeles')).toContainText('Fait :');

    // 5. Recette : chaque point garde son auteur et sa date.
    for (const item of ['tableau_de_bord', 'connexion', 'documents', 'planning', 'iban', 'journal']) {
      await page.getByTestId(`maintenance-recette-${item}`).check();
    }
    await page.getByTestId('maintenance-recette-submit').click();
    await expect(page.getByTestId('maintenance-status-recette')).toHaveText('Fait');
    await expect(page.getByTestId('maintenance-recette')).toContainText('— par');
    await page.getByTestId('maintenance-recette-iban').uncheck();
    await page.getByTestId('maintenance-recette-submit').click();
    await expect(page.getByTestId('maintenance-status-recette')).toHaveText('À faire');
  });

  test('refus côté serveur : sauvegarde non cochée, rechiffrement avec la clé en base', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_maintenance`);

    // Formulaire forgé sans la case : refusé, rien d'enregistré.
    const r1 = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_config_maintenance_sauvegarde', _wpnonce: await page.locator('#maintenance-sauvegarde form, [data-testid=maintenance-sauvegarde] form').locator('input[name=_wpnonce]').inputValue() },
      maxRedirects: 0,
    });
    expect(r1.headers()['location']).toContain('psc_msg=sauvegarde_requise');
    expect(php(`echo (int) !empty(Psc_Admin_Maintenance::state()['sauvegarde']);`)).toBe('0');

    // Rechiffrement demandé alors que la clé est dans la base : refusé
    // par le contrôleur lui-même (appelé avec un nonce valide, la
    // redirection interceptée), sauvegarde confirmée ou non.
    const refus = (confirmed: boolean) => php(`
      wp_set_current_user(1);
      ${confirmed ? "update_option('psc_maintenance', array('version' => PSC_VERSION, 'sauvegarde' => array('user' => 1, 'at' => current_time('mysql')), 'modeles' => null, 'recette' => array()));" : ''}
      $_POST = $_REQUEST = array('_wpnonce' => wp_create_nonce('psc_config_maintenance_rechiffrer'));
      add_filter('wp_redirect', function ($l) { echo $l; exit; }, 99);
      Psc_Admin_Maintenance::handle_rechiffrer();`);
    const rechiffrements = () => php(`global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . psc_table('audit_log') . " WHERE action = 'systeme.rechiffrement'");`);
    const avant = rechiffrements();
    expect(refus(true)).toContain('psc_msg=cle_en_base');
    expect(rechiffrements(), 'rechiffrement lancé malgré la clé en base').toBe(avant);
  });

  test('droits : réservée à la configuration', async ({ page }) => {
    try {
      php(`$u = get_user_by('login', 'admin'); $u->add_cap('psc_manage_config', false); echo 'ok';`);
      await loginAsAdmin(page);
      const res = await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_maintenance`);
      expect(res?.status()).toBe(403);
      await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_dashboard`);
      await expect(page.getByTestId('notice-maintenance')).toHaveCount(0);
    } finally {
      php(`$u = get_user_by('login', 'admin'); $u->remove_cap('psc_manage_config'); echo 'ok';`);
    }
  });
});
