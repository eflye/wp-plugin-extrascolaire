/**
 * Coordonnées bancaires d'une famille (IBAN) et habilitation Facturation :
 * l'IBAN est masqué et non modifiable pour l'habilitation « Familles »
 * seule, même par POST forgé ; avec « Facturation », un champ vide le
 * conserve et un nouvel IBAN est enregistré. Écran passé au contrôle axe.
 *
 * bin/verify-rgpd.php (RGPD-07) couvre la logique de suppression ; ici,
 * l'écran d'édition d'une famille et les contrôles d'habilitation servis
 * par WordPress.
 *
 * Origine : audit du 14/10/2026, lot 2 (spec scindée par domaine). Données
 * créées et supprimées par la spec (préfixe « iban-e2e »).
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

const WP = '/usr/local/bin/wp-cli.phar';
const PASSWORD = 'Iban-E2E-2026';
const EMAIL = 'iban-e2e@example.test';
const IBAN = 'FR7630006000011234567890189';
const USERS = {
  familles: { role: 'subscriber', caps: ['psc_manage_families'] },
  complet: { role: 'subscriber', caps: ['psc_manage_families', 'psc_manage_billing'] },
};

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function login(name: string): string {
  return `psc-iban-e2e-${name}`;
}

function familyId(): number {
  return Number(wpEval(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}'));`));
}

function storedIban(): string {
  return wpEval(`global $wpdb; $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}')); echo psc_read_iban($r);`);
}

function cleanup(): void {
  const logins = Object.keys(USERS).map((name) => `'${login(name)}'`).join(',');
  wpEval(`global $wpdb; require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(array(${logins}) as $l){ $u=get_user_by('login',$l); if($u) wp_delete_user((int)$u->ID); }
    $wpdb->delete($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}'));
    echo 'ok';`);
}

async function loginAs(page: Page, user: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(PASSWORD);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

test.describe('IBAN et habilitation Facturation (RGPD-07)', () => {
  test.beforeAll(() => {
    cleanup();
    for (const [name, { role, caps }] of Object.entries(USERS)) {
      const grants = caps.map((cap) => `$u->add_cap('${cap}');`).join('');
      wpEval(`$id=wp_insert_user(array('user_login'=>'${login(name)}','user_pass'=>'${PASSWORD}','user_email'=>'${login(name)}@example.test','role'=>'${role}'));
        $u=get_user_by('id',$id); ${grants} echo 'ok';`);
    }
    wpEval(`global $wpdb; $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}','nom'=>'IbanE2E','prenom'=>'Famille','active'=>1,
      'payment_mode'=>'prelevement','sepa_iban'=>psc_encrypt('${IBAN}'),'sepa_bic'=>'AGRIFRPP882','sepa_titulaire'=>'Titulaire Iban','created_at'=>current_time('mysql'))); echo 'ok';`);
  });

  test.afterAll(() => cleanup());

  test('« Familles » seule : IBAN masqué, données bancaires non modifiables, même par POST forgé', async ({ page }) => {
    await loginAs(page, login('familles'));
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyId()}`);
    await expect(page.locator('#psc-edit-sepa-iban')).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText(IBAN);
    await expect(page.locator('.psc-readonly-sepa')).toContainText('FR76');
    const axe = await new AxeBuilder({ page }).include('form').analyze();
    expect(axe.violations.map((v) => v.id)).toEqual([]);

    // Contrôle serveur : un champ sepa_iban ajouté au formulaire est ignoré.
    await page.evaluate(() => {
      const form = document.querySelector('input[name="action"][value="psc_edit_parent"]')?.closest('form');
      const input = document.createElement('input');
      input.type = 'hidden'; input.name = 'sepa_iban'; input.value = 'DE89370400440532013000';
      form?.appendChild(input);
    });
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForURL(/psc_msg=updated/);
    expect(storedIban()).toBe(IBAN);
  });

  test('« Familles » + « Facturation » : champ vide = IBAN conservé, nouvel IBAN enregistré', async ({ page }) => {
    await loginAs(page, login('complet'));
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyId()}`);
    const field = page.locator('#psc-edit-sepa-iban');
    await expect(field).toHaveValue('');
    await expect(page.locator('body')).not.toContainText(IBAN);
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForURL(/psc_msg=updated/);
    expect(storedIban()).toBe(IBAN);

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyId()}`);
    await page.locator('#psc-edit-sepa-iban').fill('FR14 2004 1010 0505 0001 3M02 606');
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForURL(/psc_msg=updated/);
    expect(storedIban()).toBe('FR1420041010050500013M02606');
  });
});
