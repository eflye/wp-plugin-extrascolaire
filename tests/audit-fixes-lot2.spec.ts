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

test.describe('Lot 2 — pièce jointe d’une famille visible côté mairie (PRA-04)', () => {
  const CONV_EMAIL = 'lot2-e2e-echange@example.test';
  const AGENTS = {
    messages: ['psc_manage_messages'],
    familles: ['psc_manage_families'],
  };
  const agent = (name: string) => `psc-lot2-e2e-pj-${name}`;
  let conversationId = 0;
  let messageId = 0;

  const cleanupConv = () => wpEval(`global $wpdb; require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(array('${agent('messages')}','${agent('familles')}') as $l){ $u=get_user_by('login',$l); if($u) wp_delete_user((int)$u->ID); }
    $f=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${CONV_EMAIL}'));
    if($f){ Psc_Conversations::delete_for_family($f); $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$f)); } echo 'ok';`);

  test.beforeAll(() => {
    cleanupConv();
    for (const [name, caps] of Object.entries(AGENTS)) {
      wpEval(`$id=wp_insert_user(array('user_login'=>'${agent(name)}','user_pass'=>'${PASSWORD}','user_email'=>'${agent(name)}@example.test','role'=>'subscriber'));
        $u=get_user_by('id',$id); ${caps.map((c) => `$u->add_cap('${c}');`).join('')} echo 'ok';`);
    }
    const ids = wpEval(`global $wpdb; $now=current_time('mysql');
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${CONV_EMAIL}','nom'=>'Lot2Echange','active'=>1,'created_at'=>$now)); $f=(int)$wpdb->insert_id;
      $wpdb->insert($wpdb->prefix.'psc_conversations',array('family_id'=>$f,'sujet'=>'Échange Lot2 E2E','initiee_par'=>'famille','dernier_message_at'=>$now,'dernier_auteur'=>'famille','created_at'=>$now,'updated_at'=>$now)); $c=(int)$wpdb->insert_id;
      $rel='conversations/lot2-e2e/'.wp_generate_password(12,false).'.pdf'; wp_mkdir_p(dirname(psc_private_path($rel))); file_put_contents(psc_private_path($rel),'%PDF-1.4 lot2 e2e');
      $wpdb->insert($wpdb->prefix.'psc_conversation_messages',array('conversation_id'=>$c,'auteur_type'=>'famille','corps'=>'Voici le certificat','piece_jointe_path'=>$rel,'piece_jointe_nom'=>'certificat.pdf','piece_jointe_taille'=>17,'created_at'=>$now));
      echo $c.' '.$wpdb->insert_id;`);
    [conversationId, messageId] = ids.split(' ').map(Number);
  });

  test.afterAll(() => cleanupConv());

  test('la secrétaire « Messages » voit et télécharge la pièce jointe', async ({ page }) => {
    await loginAs(page, agent('messages'));
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversation&id=${conversationId}`);
    const link = page.getByTestId(`conversation-attachment-${messageId}`);
    await expect(link).toContainText('certificat.pdf');
    const href = await link.getAttribute('href');
    const response = await page.request.get(href ?? '');
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain('%PDF-1.4 lot2 e2e');
    const axe = await new AxeBuilder({ page }).include('.psc-conversation-thread').analyze();
    expect(axe.violations.map((v) => v.id)).toEqual([]);
  });

  test('sans l’habilitation « Messages », ou sans nonce, le téléchargement est refusé', async ({ page }) => {
    await loginAs(page, agent('messages'));
    const noNonce = await page.request.get(`${APP_BASE}/wp-admin/admin-post.php?action=psc_download_conversation_attachment&message_id=${messageId}`);
    expect(await noNonce.text()).not.toContain('%PDF-1.4 lot2 e2e');

    await page.context().clearCookies();
    await loginAs(page, agent('familles'));
    const forbidden = await page.request.get(`${APP_BASE}/wp-admin/admin-post.php?action=psc_download_conversation_attachment&message_id=${messageId}`);
    expect(forbidden.status()).toBe(403);
  });
});

test.describe('Lot 2 — écrans Messages sans code brut ni texte de l’URL (PRA-05)', () => {
  const user = 'psc-lot2-e2e-messages';
  test.beforeAll(() => {
    wpEval(`require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','${user}'); if($u) wp_delete_user((int)$u->ID);
      $id=wp_insert_user(array('user_login'=>'${user}','user_pass'=>'${PASSWORD}','user_email'=>'${user}@example.test','role'=>'subscriber'));
      get_user_by('id',$id)->add_cap('psc_manage_messages'); echo 'ok';`);
  });
  test.afterAll(() => {
    wpEval(`require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','${user}'); if($u) wp_delete_user((int)$u->ID); echo 'ok';`);
  });

  test('un texte forgé dans psc_msg n’est jamais affiché ; un code connu l’est en clair', async ({ page }) => {
    await loginAs(page, user);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_message_edit&psc_msg=${encodeURIComponent('Votre compte est suspendu')}`);
    await expect(page.locator('.notice')).not.toContainText('suspendu');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_message_edit&psc_msg=test_sent`);
    await expect(page.locator('.notice')).toContainText('Message de test envoyé');
    await expect(page.locator('body')).not.toContainText('test_sent');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_messages&psc_msg=deleted`);
    await expect(page.locator('.notice')).toContainText('Message supprimé');
  });
});
