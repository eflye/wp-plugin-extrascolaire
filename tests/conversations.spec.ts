/**
 * Échanges familles ↔ mairie — conversations privées, distinctes des
 * diffusions descendantes (cf. tests/messages.spec.ts). Deux familles
 * seedées (A et B) pour vérifier l'isolation entre foyers.
 *
 * Les notifications e-mail sont planifiées par wp_schedule_single_event à
 * T+60s (regroupement, cf. Psc_Conversations::schedule_notify) : plutôt que
 * d'attendre une vraie exécution de cron, chaque test déclenche l'événement
 * directement via wpEval (do_action), comme suggéré pour le scénario de
 * regroupement — déterministe et rapide.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const EMAIL_A = 'conversations-a.e2e@example.test';
const EMAIL_A_SECOND = 'conversations-a-second.e2e@example.test';
const EMAIL_B = 'conversations-b.e2e@example.test';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function notify(conversationId: number, side: 'famille' | 'mairie'): void {
  wpEval(`do_action('psc_conversation_notify', ${conversationId}, '${side}'); echo 'ok';`);
}

function cleanup(): void {
  wpEval(`global $wpdb;
    foreach($wpdb->get_col("SELECT id FROM {$wpdb->prefix}psc_messages WHERE titre LIKE 'ConversationsE2E%'") as $mid){Psc_Messages::delete((int)$mid);}
    foreach(array('${EMAIL_A}','${EMAIL_B}') as $email){
      $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",$email));
      if($pid){
        Psc_Conversations::delete_for_family($pid);
        foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){
          $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid));
          $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid));
        }
        $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid));
      }
    }
    echo 'ok';`);
}

function seedFamily(email: string, secondParentEmail = ''): number {
  return Number(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array(
      'email'=>'${email}','nom'=>'ConversationsE2E','prenom'=>'Famille','active'=>1,
      'second_parent_email'=>${secondParentEmail ? `'${secondParentEmail}'` : 'null'},
      'onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array('parent_id'=>$pid,'nom'=>'ConversationsE2E','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    Psc_School_Years::enroll((int)$wpdb->insert_id,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    echo $pid;`));
}

function createBroadcast(familyIds: number[], allowReplies: boolean): number {
  return Number(wpEval(`$id=Psc_Messages::save(array(
      'titre'=>'ConversationsE2E Diffusion','corps'=>'<p>Information de test</p>',
      'categorie'=>'information','statut'=>'brouillon','cible_type'=>'familles',
      'cible_valeur'=>array('family_ids'=>array(${familyIds.join(',')})),
      'canaux'=>array('portail'=>true,'email'=>false,'push'=>false),
      'reponses_autorisees'=>${allowReplies ? 'true' : 'false'},'auteur_id'=>1));
    Psc_Messages::send($id); echo (int)$id;`));
}

function conversationCountForFamily(familyId: number): number {
  return Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_conversations WHERE family_id=%d",${familyId}));`));
}

async function countMessages(toEmail: string, subjectContains: string): Promise<number> {
  const query = `to:${toEmail} subject:"${subjectContains}"`;
  const res = await fetch(`http://localhost:8025/api/v1/search?${new URLSearchParams({ query, limit: '20' })}`);
  const data = (await res.json()) as { messages: unknown[] };
  return data.messages.length;
}

function familleDernierLu(conversationId: number): string {
  return wpEval(`global $wpdb; echo (string)$wpdb->get_var($wpdb->prepare("SELECT famille_dernier_lu_id FROM {$wpdb->prefix}psc_conversations WHERE id=%d",${conversationId}));`);
}

async function loginAsFamily(page: Page, email: string): Promise<void> {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(email);
  await page.getByTestId('login-submit-button').click();
  const mail = await findLatestMessage(email, 'Votre lien d\'accès aux inscriptions périscolaires');
  const link = mail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(link, 'lien de connexion famille introuvable').toBeTruthy();
  await page.goto(link![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

async function openEchanges(page: Page): Promise<void> {
  const base = readFormPageUrl();
  await page.goto(`${base}${base.includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges`);
}

async function startConsultation(page: Page, familyId: number): Promise<void> {
  await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents`);
  await page.getByTestId(`impersonate-open-${familyId}`).click();
  await expect(page.getByRole('heading', { name: 'Consulter un espace famille' })).toBeVisible();
  await page.getByTestId('impersonate-motif-reclamation').check();
  await page.getByTestId('impersonate-submit').click();
  await expect(page.getByTestId('portal-root')).toHaveAttribute('data-psc-readonly', '1');
}

test.describe('Échanges familles ↔ mairie', () => {
  test.beforeEach(() => cleanup());
  test.afterEach(() => cleanup());

  test('la famille écrit à la mairie, l’admin voit le non-lu et répond, l’e-mail ne contient aucun contenu', async ({ page }) => {
    seedFamily(EMAIL_A, EMAIL_A_SECOND);
    await loginAsFamily(page, EMAIL_A);
    await openEchanges(page);
    await page.getByTestId('conversation-new').click();
    await page.locator('#psc-conversation-sujet').fill('Une question sur le portail');
    await page.locator('#psc-conversation-corps').fill('Bonjour, une question pour vous.');
    await page.getByRole('button', { name: 'Envoyer' }).click();
    await expect(page.getByTestId('notice-conversation_sent')).toBeVisible();
    const conversationId = Number(new URL(page.url()).searchParams.get('conversation_id'));
    expect(conversationId).toBeGreaterThan(0);

    notify(conversationId, 'mairie');
    const mairieMail = await findLatestMessage('admin@example.invalid', 'Nouveau message d’une famille');
    expect(mairieMail.Subject).not.toContain('Une question sur le portail');
    expect(mairieMail.Text).not.toContain('Bonjour, une question pour vous.');

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversations`);
    const conversationsMenuLink = page.locator('#adminmenu').getByRole('link', { name: /Échanges familles/ });
    await expect(conversationsMenuLink.locator('.pending-count')).toHaveText('1');

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversation&id=${conversationId}`);
    await expect(page.getByRole('heading', { name: 'Une question sur le portail' })).toBeVisible();

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversations`);
    await expect(conversationsMenuLink.locator('.pending-count')).toHaveCount(0);

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversation&id=${conversationId}`);
    await page.locator('#psc-conversation-reply-body').fill('Bonjour, merci pour votre message.');
    await page.getByRole('button', { name: 'Répondre', exact: true }).click();

    notify(conversationId, 'famille');
    const familyMail = await findLatestMessage(EMAIL_A, 'Nouveau message de la mairie');
    expect(familyMail.Text).not.toContain('Bonjour, merci pour votre message.');
    const secondMail = await findLatestMessage(EMAIL_A_SECOND, 'Nouveau message de la mairie');
    expect(secondMail).toBeTruthy();

    // La session famille (cookie propre au plugin) survit à la connexion admin
    // ci-dessus, faite dans le même contexte navigateur — pas de reconnexion.
    await page.goto(readFormPageUrl());
    await expect(page.getByTestId('portal-root')).toBeVisible();
    await expect(page.getByTestId('portal-nav-messages').locator('.psc-message-nav-badge')).toHaveText('1');
    await openEchanges(page);
    // Refonte front : le non-lu n'est plus signalé par une phrase dédiée
    // mais par le style de la ligne (fond, liseré, sujet en gras) — on
    // vérifie ici que l'aperçu du dernier message (la réponse de la
    // mairie) est bien remonté dans la liste.
    await expect(page.getByText('Bonjour, merci pour votre message.', { exact: false })).toBeVisible();
  });

  test('isole les foyers : accès direct et réponse forgée refusés', async ({ page, browser }) => {
    seedFamily(EMAIL_A);
    seedFamily(EMAIL_B);
    await loginAsFamily(page, EMAIL_A);
    await openEchanges(page);
    await page.getByTestId('conversation-new').click();
    await page.locator('#psc-conversation-sujet').fill('Sujet privé A');
    await page.locator('#psc-conversation-corps').fill('Contenu privé de la famille A.');
    await page.getByRole('button', { name: 'Envoyer' }).click();
    const conversationIdA = Number(new URL(page.url()).searchParams.get('conversation_id'));
    expect(conversationIdA).toBeGreaterThan(0);

    const context = await browser.newContext();
    const pageB = await context.newPage();
    await loginAsFamily(pageB, EMAIL_B);

    const direct = await pageB.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges&conversation_id=${conversationIdA}`);
    expect(direct?.status()).toBe(403);

    // B ouvre son propre échange pour obtenir des nonces valides POUR ELLE,
    // puis les rejoue avec l'identifiant de la conversation de A.
    await pageB.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges`);
    await pageB.getByTestId('conversation-new').click();
    await pageB.locator('#psc-conversation-sujet').fill('Sujet de B');
    await pageB.locator('#psc-conversation-corps').fill('Contenu de B.');
    await pageB.getByRole('button', { name: 'Envoyer' }).click();
    const replyForm = pageB.locator('#psc-conversation-reply-form');
    const nonces = await replyForm.evaluate((el: HTMLFormElement) => ({
      wp: (el.elements.namedItem('_wpnonce') as HTMLInputElement).value,
      family: (el.elements.namedItem('psc_nonce') as HTMLInputElement).value,
    }));

    const forged = await pageB.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: {
        action: 'psc_parent_conversation_reply', conversation_id: String(conversationIdA),
        corps: 'Intrusion', _wpnonce: nonces.wp, psc_nonce: nonces.family,
      },
      maxRedirects: 0,
    });
    expect(forged.status()).toBe(403);

    const intrusion = JSON.parse(wpEval(`global $wpdb; echo wp_json_encode($wpdb->get_col($wpdb->prepare("SELECT corps FROM {$wpdb->prefix}psc_conversation_messages WHERE conversation_id=%d",${conversationIdA})));`));
    expect(intrusion.some((m: string) => m.includes('Intrusion'))).toBe(false);
    await context.close();
  });

  test('réponse à une diffusion : refusée sans autorisation, isolée par foyer une fois autorisée', async ({ page, browser }) => {
    const familyIdA = seedFamily(EMAIL_A);
    const familyIdB = seedFamily(EMAIL_B);
    const lockedId = createBroadcast([familyIdA, familyIdB], false);

    await loginAsFamily(page, EMAIL_A);
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${lockedId}`);
    await expect(page.getByTestId('message-reply')).toHaveCount(0);

    const forged = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_parent_conversation_create', message_id: String(lockedId), sujet: '', corps: 'Réponse interdite' },
      maxRedirects: 0,
    });
    expect(forged.status()).toBe(403); // check_admin_referer() meurt sur un nonce absent, aucune écriture.
    expect(conversationCountForFamily(familyIdA)).toBe(0);

    const openId = createBroadcast([familyIdA, familyIdB], true);
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${openId}`);
    await page.getByTestId('message-reply').click();
    await page.locator('#psc-conversation-corps').fill('Réponse de la famille A.');
    await page.getByRole('button', { name: 'Envoyer' }).click();
    await expect(page.getByTestId('notice-conversation_sent')).toBeVisible();

    // Seconde réponse de A à la même diffusion : va dans la même conversation.
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${openId}`);
    await page.getByTestId('message-reply').click();
    await page.locator('#psc-conversation-reply-body').fill('Un complément.');
    await page.getByRole('button', { name: 'Envoyer la réponse' }).click();
    expect(conversationCountForFamily(familyIdA)).toBe(1);

    const context = await browser.newContext();
    const pageB = await context.newPage();
    await loginAsFamily(pageB, EMAIL_B);
    await pageB.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${openId}`);
    await pageB.getByTestId('message-reply').click();
    await pageB.locator('#psc-conversation-corps').fill('Réponse de la famille B.');
    await pageB.getByRole('button', { name: 'Envoyer' }).click();
    expect(conversationCountForFamily(familyIdB)).toBe(1);
    expect(conversationCountForFamily(familyIdA)).toBe(1);
    await context.close();
  });

  test('clôture, réouverture par réponse, XSS affiché en texte, validation et anti-doublon', async ({ page }) => {
    seedFamily(EMAIL_A);
    await loginAsFamily(page, EMAIL_A);
    await openEchanges(page);
    await page.getByTestId('conversation-new').click();
    await page.locator('#psc-conversation-sujet').fill('Sujet a cloturer');
    await page.locator('#psc-conversation-corps').fill('<script>alert(1)</script>');
    await page.getByRole('button', { name: 'Envoyer' }).click();
    const conversationId = Number(new URL(page.url()).searchParams.get('conversation_id'));

    const dialogs: string[] = [];
    page.on('dialog', (d) => { dialogs.push(d.message()); void d.dismiss(); });
    await page.reload();
    expect(dialogs.length).toBe(0);
    // Texte brut préservé tel quel (jamais wp_strip_all_tags, qui effacerait
    // le bloc <script> en entier) — la sécurité vient de l'échappement à
    // l'affichage : aucun <script> exécutable ne doit exister dans le DOM.
    await expect(page.locator('script:has-text("alert(1)")')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('<script>alert(1)</script>');

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_conversation&id=${conversationId}`);
    await expect(page.locator('script:has-text("alert(1)")')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('<script>alert(1)</script>');
    await page.locator('#psc-conversation-reply-body').fill('Je clos cet échange.');
    await page.getByRole('button', { name: 'Répondre et clore' }).click();

    // Refonte front : une conversation close masque entièrement la zone de
    // réponse (« Cet échange est clos. » + lien vers une nouvelle demande) —
    // le formulaire n'existe plus dans le DOM pour ce cas. La capacité
    // serveur de réouverture par réponse (Psc_Conversations::reply(), hors
    // périmètre de cette refonte front) reste néanmoins active : on la
    // vérifie ci-dessous par requêtes forgées, avec des jetons valides
    // récupérés sur une seconde conversation ouverte (même doctrine que le
    // test d'isolation entre foyers ci-dessus — un jeton d'action n'est pas
    // lié à un identifiant de conversation précis).
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges&conversation_id=${conversationId}`);
    await expect(page.getByText('Cet échange est clos.', { exact: false })).toBeVisible();
    await expect(page.locator('#psc-conversation-reply-body')).toHaveCount(0);

    const familyIdOfConversation = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT family_id FROM {$wpdb->prefix}psc_conversations WHERE id=%d",${conversationId}));`));
    const scratchId = Number(wpEval(`echo Psc_Conversations::create_by_family(${familyIdOfConversation}, 'Jeton', 'Message pour récupérer un jeton valide.');`));
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges&conversation_id=${scratchId}`);
    const form = page.locator('#psc-conversation-reply-form');
    const nonceValues = await form.evaluate((el: HTMLFormElement) => ({
      wp: (el.elements.namedItem('_wpnonce') as HTMLInputElement).value,
      family: (el.elements.namedItem('psc_nonce') as HTMLInputElement).value,
    }));

    // Corps vide refusé.
    const emptyResp = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_parent_conversation_reply', conversation_id: String(conversationId), corps: '', _wpnonce: nonceValues.wp, psc_nonce: nonceValues.family },
      maxRedirects: 0,
    });
    expect(new URL(emptyResp.headers()['location']).searchParams.get('psc_msg')).toBe('psc_conversation_body');

    // Corps trop long refusé.
    const longResp = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_parent_conversation_reply', conversation_id: String(conversationId), corps: 'x'.repeat(2001), _wpnonce: nonceValues.wp, psc_nonce: nonceValues.family },
      maxRedirects: 0,
    });
    expect(new URL(longResp.headers()['location']).searchParams.get('psc_msg')).toBe('psc_conversation_body');

    // Réponse valide : réouvre la conversation close.
    const dupBody = { action: 'psc_parent_conversation_reply', conversation_id: String(conversationId), corps: 'Une nouvelle question.', _wpnonce: nonceValues.wp, psc_nonce: nonceValues.family };
    await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, { form: dupBody, maxRedirects: 0 });
    const statut = wpEval(`global $wpdb; echo (string)$wpdb->get_var($wpdb->prepare("SELECT statut FROM {$wpdb->prefix}psc_conversations WHERE id=%d",${conversationId}));`);
    expect(statut).toBe('ouverte');

    // Double soumission identique en moins de 30 secondes : un seul message créé.
    const before = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_conversation_messages WHERE conversation_id=%d",${conversationId}));`));
    const dupBody2 = { action: 'psc_parent_conversation_reply', conversation_id: String(conversationId), corps: 'Message en double.', _wpnonce: nonceValues.wp, psc_nonce: nonceValues.family };
    // Séquentiel, pas Promise.all : reproduit un double-clic (deux requêtes
    // qui se suivent), pas une vraie collision concurrente au niveau SQL —
    // la garde anti-doublon est un SELECT puis INSERT, pas un verrou.
    await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, { form: dupBody2, maxRedirects: 0 });
    await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, { form: dupBody2, maxRedirects: 0 });
    const after = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_conversation_messages WHERE conversation_id=%d",${conversationId}));`));
    expect(after - before).toBe(1);
  });

  test('consultation : lecture d’une conversation non lue sans avancer le pointeur, écriture bloquée', async ({ page }) => {
    const familyIdA = seedFamily(EMAIL_A);
    const conversationId = Number(wpEval(`echo Psc_Conversations::create_by_family(${familyIdA}, 'Consultation', 'Message initial.');`));
    wpEval(`Psc_Conversations::reply(${conversationId}, 'mairie', 'Réponse mairie non lue.', 1); echo 'ok';`);

    await loginAsAdmin(page);
    await startConsultation(page, familyIdA);
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&psc_vue=echanges&conversation_id=${conversationId}`);
    await expect(page.getByText('Réponse mairie non lue.')).toBeVisible();

    const pointerAfter = familleDernierLu(conversationId);
    const lastMessageId = wpEval(`global $wpdb; echo (string)$wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$wpdb->prefix}psc_conversation_messages WHERE conversation_id=%d",${conversationId}));`);
    expect(pointerAfter).not.toBe(lastMessageId);

    const response = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_parent_conversation_reply', conversation_id: String(conversationId), corps: 'Écriture pendant consultation' },
      maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toContain('psc_msg=impersonation_readonly');
  });

  test('regroupe deux réponses mairie rapprochées en un seul e-mail famille', async ({ page }) => {
    const familyIdA = seedFamily(EMAIL_A);
    const conversationId = Number(wpEval(`echo Psc_Conversations::create_by_family(${familyIdA}, 'Groupage', 'Bonjour.');`));
    const before = await countMessages(EMAIL_A, 'Nouveau message de la mairie');

    wpEval(`Psc_Conversations::reply(${conversationId}, 'mairie', 'Première réponse.', 1); echo 'ok';`);
    notify(conversationId, 'famille');
    const firstMail = await findLatestMessage(EMAIL_A, 'Nouveau message de la mairie');
    expect(firstMail).toBeTruthy();

    wpEval(`Psc_Conversations::reply(${conversationId}, 'mairie', 'Seconde réponse rapprochée.', 1); echo 'ok';`);
    notify(conversationId, 'famille'); // moins de 15 minutes après famille_notifie_le : ne doit rien renvoyer de plus.

    const after = await countMessages(EMAIL_A, 'Nouveau message de la mairie');
    expect(after - before).toBe(1);
  });

  test('supprime intégralement les conversations et messages d’une famille supprimée', async ({ page }) => {
    const familyIdA = seedFamily(EMAIL_A);
    const conversationId = Number(wpEval(`echo Psc_Conversations::create_by_family(${familyIdA}, 'À supprimer', 'Contenu.');`));
    expect(conversationId).toBeGreaterThan(0);

    await loginAsAdmin(page);
    page.on('dialog', (d) => void d.accept());
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyIdA}`);
    const deleteForm = page.locator(`form:has(input[name="action"][value="psc_delete_family"]):has(input[name="id"][value="${familyIdA}"])`);
    await deleteForm.getByRole('button', { name: 'Supprimer' }).click();
    await page.waitForURL('**/admin.php?page=psc_parents&psc_msg=family_deleted');

    const remainingConversations = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_conversations WHERE family_id=%d",${familyIdA}));`));
    const remainingMessages = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_conversation_messages WHERE conversation_id=%d",${conversationId}));`));
    expect(remainingConversations).toBe(0);
    expect(remainingMessages).toBe(0);
  });

  test('parcours complet au clavier : créer, lire, répondre', async ({ page }) => {
    seedFamily(EMAIL_A);
    await loginAsFamily(page, EMAIL_A);
    await openEchanges(page);

    await page.getByTestId('conversation-new').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#psc-conversation-sujet')).toBeVisible();
    await page.locator('#psc-conversation-sujet').fill('Question clavier');
    await page.locator('#psc-conversation-corps').fill('Écrit entièrement au clavier.');
    await page.getByRole('button', { name: 'Envoyer' }).focus();
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('notice-conversation_sent')).toBeVisible();
    await expect(page.getByTestId('notice-conversation_sent')).toBeFocused();
    await expect(page.getByText('Vous', { exact: true })).toBeVisible();

    const conversationId = Number(new URL(page.url()).searchParams.get('conversation_id'));
    wpEval(`Psc_Conversations::reply(${conversationId}, 'mairie', 'Réponse de la mairie.', 1); echo 'ok';`);
    await page.reload();
    await expect(page.getByText('Mairie', { exact: true })).toBeVisible();

    await page.locator('#psc-conversation-reply-body').focus();
    await page.keyboard.type('Merci pour votre réponse.');
    await page.getByRole('button', { name: 'Envoyer la réponse' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('notice-conversation_sent')).toBeVisible();
    await expect(page.getByText('Merci pour votre réponse.')).toBeVisible();
  });
});
