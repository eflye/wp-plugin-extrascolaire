/**
 * Corrections du lot 2 de l'audit (données, RGPD, journal, échanges,
 * messages) — ce que bin/verify-audit-lot2.php ne voit pas : les écrans
 * et les contrôles d'habilitation servis par WordPress.
 *
 * Données créées et supprimées par la spec (préfixe « lot2-e2e »).
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const PASSWORD = 'Lot2-E2E-Audit-2026';
const EMAIL = 'lot2-e2e@example.test';
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
  return `psc-lot2-e2e-${name}`;
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

test.describe('Lot 2 — IBAN et habilitation Facturation (RGPD-07)', () => {
  test.beforeAll(() => {
    cleanup();
    for (const [name, { role, caps }] of Object.entries(USERS)) {
      const grants = caps.map((cap) => `$u->add_cap('${cap}');`).join('');
      wpEval(`$id=wp_insert_user(array('user_login'=>'${login(name)}','user_pass'=>'${PASSWORD}','user_email'=>'${login(name)}@example.test','role'=>'${role}'));
        $u=get_user_by('id',$id); ${grants} echo 'ok';`);
    }
    wpEval(`global $wpdb; $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}','nom'=>'Lot2E2E','prenom'=>'Famille','active'=>1,
      'payment_mode'=>'prelevement','sepa_iban'=>psc_encrypt('${IBAN}'),'sepa_bic'=>'AGRIFRPP882','sepa_titulaire'=>'Titulaire Lot2','created_at'=>current_time('mysql'))); echo 'ok';`);
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

test.describe('Lot 2 — lien de diffusion sans jeton dans l’URL (RGPD-09)', () => {
  const MSG_EMAIL = 'lot2-e2e-diffusion@example.test';
  const TOKEN = 'lot2e2elot2e2elot2e2elot2e2e1234';
  let messageId = 0;

  test.beforeAll(() => {
    messageId = Number(wpEval(`global $wpdb; $wpdb->delete($wpdb->prefix.'psc_parents',array('email'=>'${MSG_EMAIL}'));
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${MSG_EMAIL}','nom'=>'Lot2Diffusion','created_at'=>current_time('mysql'))); $f=(int)$wpdb->insert_id;
      $wpdb->insert($wpdb->prefix.'psc_messages',array('titre'=>'Diffusion Lot2 E2E','corps'=>'Corps Lot2','statut'=>'envoye','date_envoi'=>current_time('mysql'),'auteur_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'))); $m=(int)$wpdb->insert_id;
      $wpdb->insert($wpdb->prefix.'psc_message_destinataires',array('message_id'=>$m,'family_id'=>$f,'token'=>'${TOKEN}')); echo $m;`));
  });

  test.afterAll(() => {
    wpEval(`global $wpdb; $wpdb->delete($wpdb->prefix.'psc_message_destinataires',array('message_id'=>${messageId}));
      $wpdb->delete($wpdb->prefix.'psc_messages',array('id'=>${messageId})); $wpdb->delete($wpdb->prefix.'psc_parents',array('email'=>'${MSG_EMAIL}')); echo 'ok';`);
  });

  test('le jeton disparaît de l’URL, le message reste lisible', async ({ page }) => {
    await page.goto(`${APP_BASE}/?psc_msg=${messageId}&t=${TOKEN}`);
    expect(page.url()).not.toContain(TOKEN);
    expect(new URL(page.url()).searchParams.get('psc_msg')).toBe(String(messageId));
    await expect(page.locator('body')).toContainText('Diffusion Lot2 E2E');
  });

  test('un autre navigateur, sans le lien, ne voit pas le message', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${APP_BASE}/?psc_msg=${messageId}`);
    await expect(page.locator('body')).not.toContainText('Diffusion Lot2 E2E');
    await page.goto(`${APP_BASE}/?psc_msg=${messageId}&t=mauvais-jeton`);
    await expect(page.locator('body')).not.toContainText('Diffusion Lot2 E2E');
    await context.close();
  });
});
