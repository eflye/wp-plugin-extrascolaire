/**
 * Messages (diffusions) et échanges familles ↔ mairie — sécurité des
 * écrans : le jeton de diffusion disparaît de l'URL et reste requis ; la
 * pièce jointe d'un échange est visible et téléchargeable côté mairie
 * avec l'habilitation « Messages » seulement (et un nonce) ; les écrans
 * Messages n'affichent ni code brut ni texte venu de l'URL ; la
 * consultation d'une famille par la mairie ne marque aucun échange lu.
 *
 * bin/verify-rgpd.php couvre la logique (pièces jointes, lectures
 * légères) ; ici, les écrans et les contrôles d'habilitation servis par
 * WordPress. Les diffusions elles-mêmes sont couvertes par
 * tests/messages.spec.ts, les échanges par tests/conversations.spec.ts.
 *
 * Origine : audit du 14/10/2026, lot 2 (ex-tests/audit-fixes-lot2.spec.ts,
 * scindé par domaine). Données créées et supprimées par la spec (préfixe
 * « messages-e2e »).
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

const WP = '/usr/local/bin/wp-cli.phar';
const PASSWORD = 'Messages-E2E-2026';

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

test.describe('Lien de diffusion sans jeton dans l’URL (RGPD-09)', () => {
  const MSG_EMAIL = 'messages-e2e-diffusion@example.test';
  const TOKEN = 'msge2emsge2emsge2emsge2emsge2e12';
  let messageId = 0;

  test.beforeAll(() => {
    messageId = Number(wpEval(`global $wpdb; $wpdb->delete($wpdb->prefix.'psc_parents',array('email'=>'${MSG_EMAIL}'));
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${MSG_EMAIL}','nom'=>'E2EDiffusion','created_at'=>current_time('mysql'))); $f=(int)$wpdb->insert_id;
      $wpdb->insert($wpdb->prefix.'psc_messages',array('titre'=>'Diffusion messages E2E','corps'=>'Corps E2E','statut'=>'envoye','date_envoi'=>current_time('mysql'),'auteur_id'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'))); $m=(int)$wpdb->insert_id;
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
    await expect(page.locator('body')).toContainText('Diffusion messages E2E');
  });

  test('un autre navigateur, sans le lien, ne voit pas le message', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${APP_BASE}/?psc_msg=${messageId}`);
    await expect(page.locator('body')).not.toContainText('Diffusion messages E2E');
    await page.goto(`${APP_BASE}/?psc_msg=${messageId}&t=mauvais-jeton`);
    await expect(page.locator('body')).not.toContainText('Diffusion messages E2E');
    await context.close();
  });
});

test.describe('Pièce jointe d’une famille visible côté mairie (PRA-04)', () => {
  const CONV_EMAIL = 'messages-e2e-echange@example.test';
  const AGENTS = {
    messages: ['psc_manage_messages'],
    familles: ['psc_manage_families'],
  };
  const agent = (name: string) => `psc-messages-e2e-pj-${name}`;
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
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${CONV_EMAIL}','nom'=>'E2EEchange','active'=>1,'created_at'=>$now)); $f=(int)$wpdb->insert_id;
      $wpdb->insert($wpdb->prefix.'psc_conversations',array('family_id'=>$f,'sujet'=>'Échange messages E2E','initiee_par'=>'famille','dernier_message_at'=>$now,'dernier_auteur'=>'famille','created_at'=>$now,'updated_at'=>$now)); $c=(int)$wpdb->insert_id;
      $rel='conversations/messages-e2e/'.wp_generate_password(12,false).'.pdf'; wp_mkdir_p(dirname(psc_private_path($rel))); file_put_contents(psc_private_path($rel),'%PDF-1.4 messages e2e');
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
    expect(await response.text()).toContain('%PDF-1.4 messages e2e');
    const axe = await new AxeBuilder({ page }).include('.psc-conversation-thread').analyze();
    expect(axe.violations.map((v) => v.id)).toEqual([]);
  });

  test('sans l’habilitation « Messages », ou sans nonce, le téléchargement est refusé', async ({ page }) => {
    await loginAs(page, agent('messages'));
    const noNonce = await page.request.get(`${APP_BASE}/wp-admin/admin-post.php?action=psc_download_conversation_attachment&message_id=${messageId}`);
    expect(await noNonce.text()).not.toContain('%PDF-1.4 messages e2e');

    await page.context().clearCookies();
    await loginAs(page, agent('familles'));
    const forbidden = await page.request.get(`${APP_BASE}/wp-admin/admin-post.php?action=psc_download_conversation_attachment&message_id=${messageId}`);
    expect(forbidden.status()).toBe(403);
  });
});

test.describe('Écrans Messages sans code brut ni texte de l’URL (PRA-05)', () => {
  const user = 'psc-messages-e2e-ecrans';
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
    await expect(page.getByRole('heading', { level: 1, name: 'Nouveau message' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('suspendu');
    // Seule la notice du plugin est visée : l'environnement en affiche d'autres.
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_message_edit&psc_msg=test_sent`);
    await expect(page.locator('.wrap .notice', { hasText: 'Message de test envoyé' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('test_sent');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_messages&psc_msg=deleted`);
    await expect(page.locator('.wrap .notice', { hasText: 'Message supprimé' })).toBeVisible();
  });
});

test.describe('Consultation mairie en lecture seule sur les échanges (SEC-09)', () => {
  const CONS_EMAIL = 'messages-e2e-consultation@example.test';
  let familyId = 0;
  let conversationId = 0;

  const cleanupCons = () => wpEval(`global $wpdb; $f=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${CONS_EMAIL}'));
    if($f){ Psc_Conversations::delete_for_family($f); $wpdb->delete($wpdb->prefix.'psc_impersonations',array('family_id'=>$f)); $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$f)); } echo 'ok';`);

  test.beforeAll(() => {
    cleanupCons();
    const ids = wpEval(`global $wpdb; $now=current_time('mysql');
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${CONS_EMAIL}','nom'=>'E2EConsultation','prenom'=>'Famille','active'=>1,'onboarding_seen_at'=>$now,'created_at'=>$now)); $f=(int)$wpdb->insert_id;
      $c=Psc_Conversations::create_by_mairie($f,'Échange E2E consultation','Réponse de la mairie',1);
      echo $f.' '.(int)$c;`);
    [familyId, conversationId] = ids.split(' ').map(Number);
  });

  test.afterAll(() => cleanupCons());

  test('ouvrir un échange en consultation ne le marque pas lu pour la famille', async ({ page }) => {
    const unreadBefore = wpEval(`echo Psc_Conversations::unread_count_for_family(${familyId});`);
    expect(Number(unreadBefore)).toBeGreaterThan(0);
    await page.goto(`${APP_BASE}/wp-login.php`);
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents`);
    await page.getByTestId(`impersonate-open-${familyId}`).click();
    await page.getByTestId('impersonate-motif-verification').check();
    await page.getByTestId('impersonate-submit').click();
    await expect(page.getByTestId('portal-root')).toHaveAttribute('data-psc-readonly', '1');
    const base = readFormPageUrl();
    await page.goto(`${base}${base.includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges&conversation_id=${conversationId}`);
    await expect(page.locator('body')).toContainText('Réponse de la mairie');
    expect(wpEval(`echo Psc_Conversations::unread_count_for_family(${familyId});`)).toBe(unreadBefore);
  });
});
