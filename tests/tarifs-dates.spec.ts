/**
 * P1-16 — tarifs et statut « cantine sans repas » datés.
 *
 *  - Réglages : un nouveau tarif à partir d'une date à venir n'change pas
 *    le tarif du jour, figure « à venir » et se supprime ; un tarif entré
 *    en vigueur est refusé à la suppression ; un montant invalide est
 *    refusé côté serveur.
 *  - Fiche enfant : le statut posé à une date future n'est pas actif
 *    aujourd'hui et s'annonce ; posé à la date du jour, il l'est.
 *  - Droits : sans la capacité de configuration, pas de tarif ; sans la
 *    gestion des familles, pas de statut.
 * Contrôle axe sur les deux écrans.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const EMAIL = 'tarifs-dates@example.invalid';
const USER = 'tarifs-familles';
const PASSWORD = 'tarifs-familles-test-2026';
const FUTURE = '2099-03-01';

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

async function axe(page: Page) {
  const r = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  expect(r.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);
}

function cleanup() {
  php(`global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM '.psc_table('tarifs').' WHERE debut >= %s OR debut = %s', '${FUTURE}', '2001-01-01')); Psc_Tarifs::flush_cache();
    $pid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('parents').' WHERE email=%s', '${EMAIL}'));
    if ($pid) { foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('children').' WHERE parent_id=%d', $pid)) as $c) { Psc_Planning::delete_for_child((int) $c); $wpdb->delete(psc_table('child_school_years'), array('child_id'=>(int) $c)); } $wpdb->delete(psc_table('parents'), array('id'=>$pid)); }
    if ($u = get_user_by('login', '${USER}')) { require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
    echo 'ok';`);
}

test.describe.serial('Tarifs et statut sans repas datés', () => {
  let childId = 0;
  test.beforeAll(() => {
    cleanup();
    childId = Number(php(`global $wpdb; $pid = Psc_Parents::create('${EMAIL}', 'Tarifs');
      $wpdb->insert(psc_table('children'), array('parent_id'=>$pid, 'nom'=>'Datee', 'prenom'=>'Lise', 'created_at'=>current_time('mysql')));
      $cid = (int) $wpdb->insert_id; Psc_School_Years::enroll($cid, Psc_School_Years::active_id(), 'CE1');
      $id = wp_insert_user(array('user_login'=>'${USER}', 'user_pass'=>'${PASSWORD}', 'user_email'=>'${USER}@example.test', 'role'=>'subscriber'));
      $u = new WP_User($id); $u->add_cap('read'); $u->add_cap('psc_manage_families'); echo $cid;`));
  });
  test.afterAll(() => cleanup());

  test('Réglages : un tarif à venir ne change pas le tarif du jour, puis se supprime', async ({ page }) => {
    await login(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);
    const current = (await page.getByTestId('tarif-current-CANT').innerText()).trim();

    await page.getByTestId('tarif-code').selectOption('CANT');
    await page.getByTestId('tarif-prix').fill('9,99');
    await page.getByTestId('tarif-debut').fill(FUTURE);
    await page.getByTestId('tarif-submit').click();
    await expect(page.getByText('Tarif enregistré.')).toBeVisible();
    await expect(page.getByTestId('tarif-current-CANT')).toHaveText(current);
    const upcoming = page.getByTestId('tarif-history-CANT').filter({ hasText: '01/03/2099' });
    await expect(upcoming).toContainText(/9[,.]99 €/); // séparateur selon la langue du site
    await expect(upcoming).toContainText('(à venir)');
    await axe(page);

    await upcoming.getByRole('button', { name: /Supprimer le tarif à venir du 01\/03\/2099/ }).click();
    await expect(page.getByText('Tarif à venir supprimé.')).toBeVisible();
    await expect(page.getByTestId('tarif-history-CANT').filter({ hasText: '01/03/2099' })).toHaveCount(0);
  });

  test('Réglages : montant invalide et suppression d’un tarif en vigueur refusés', async ({ page }) => {
    await login(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);
    const nonce = await page.locator('[data-testid="tarif-form"] input[name="_wpnonce"]').inputValue();
    const bad = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_save_tarif', _wpnonce: nonce, _wp_http_referer: '/wp-admin/admin.php?page=psc_settings', code: 'CANT', prix: 'abc', debut: FUTURE },
    });
    expect(bad.url()).toContain('psc_msg=tarif_invalid');

    // Un tarif à venir fournit le formulaire (et son jeton) de suppression ;
    // le jeton sert ensuite à viser un tarif déjà en vigueur.
    const pastId = php(`Psc_Tarifs::set('GS', 480, '${FUTURE}'); Psc_Tarifs::set('GM', 190, '2001-01-01'); global $wpdb; echo (int) $wpdb->get_var("SELECT id FROM ".psc_table('tarifs')." WHERE code='GM' AND debut='2001-01-01'");`);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);
    const delNonce = await page.getByTestId('tarif-history-GS').filter({ hasText: '01/03/2099' }).locator('input[name="_wpnonce"]').inputValue();
    const refused = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_delete_tarif', _wpnonce: delNonce, _wp_http_referer: '/wp-admin/admin.php?page=psc_settings', id: pastId },
    });
    expect(refused.url()).toContain('psc_msg=tarif_refused');
    expect(php(`global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM ".psc_table('tarifs')." WHERE id=${pastId}");`)).toBe('1');
  });

  test('Fiche enfant : statut sans repas à une date future, puis du jour', async ({ page }) => {
    await login(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_children&etat=tous`);
    await expect(page.getByTestId(`child-csr-${childId}`)).toHaveText('Repas cantine');

    await page.getByTestId(`child-csr-from-${childId}`).fill(FUTURE);
    await page.getByTestId(`child-csr-toggle-${childId}`).click();
    await expect(page.getByText('Cantine sans repas à partir de la date choisie')).toBeVisible();
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_children&etat=tous`);
    await expect(page.getByTestId(`child-csr-${childId}`)).toHaveText('Repas cantine');
    await expect(page.getByTestId(`child-csr-note-${childId}`)).toHaveText('sans repas à partir du 01/03/2099');
    await axe(page);

    const today = php(`echo psc_today();`);
    await page.getByTestId(`child-csr-from-${childId}`).fill(today);
    await page.getByTestId(`child-csr-toggle-${childId}`).click();
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_children&etat=tous`);
    await expect(page.getByTestId(`child-csr-${childId}`)).toHaveText('Sans repas');
    await expect(page.getByTestId(`child-csr-note-${childId}`)).toContainText('depuis le');
    expect(php(`echo Psc_Sans_Repas::on(${childId}, '${today}') ? 1 : 0;`)).toBe('1');
  });

  test('Droits : sans capacité de configuration, aucun tarif enregistré', async ({ page }) => {
    await login(page, USER, PASSWORD);
    const nonce = php(`$u = get_user_by('login', '${USER}'); wp_set_current_user($u->ID); echo wp_create_nonce('psc_save_tarif');`);
    const res = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_save_tarif', _wpnonce: nonce, code: 'CANT', prix: '1,00', debut: FUTURE },
      maxRedirects: 0,
    });
    expect(res.status()).toBe(403);
    expect(php(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".psc_table('tarifs')." WHERE code='CANT' AND debut=%s", '${FUTURE}'));`)).toBe('0');
  });
});
