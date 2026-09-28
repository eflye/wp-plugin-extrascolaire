/**
 * Échanges familles ↔ mairie : l'e-mail de notification contient le texte
 * du message et son bouton « Répondre » connecte la famille directement sur
 * la conversation (lien de 72 h, schéma 4.19.0). Lien expiré : la demande
 * d'un nouveau lien ramène à la conversation. Réglage mairie : sans texte.
 * Contrôle axe du portail à l'arrivée et de l'écran Réglages.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const SUBJECT = 'Nouveau message de la mairie';

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

async function axe(page: Page, selector: string) {
  const r = await new AxeBuilder({ page }).include(selector).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  expect(r.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);
}

/** Famille, conversation ouverte par la mairie, notification envoyée. */
function seed(email: string, texte: string, sujet = 'Sortie du jeudi') {
  return JSON.parse(php(`global $wpdb;
    $pid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('parents').' WHERE email=%s', '${email}'));
    if ($pid) { Psc_Conversations::delete_for_family($pid); $wpdb->delete(psc_table('parents'), array('id'=>$pid)); }
    $pid = Psc_Parents::create('${email}', 'Echange');
    $cid = Psc_Conversations::create_by_mairie($pid, '${sujet}', '${texte}', 1);
    Psc_Mailer::send_conversation_notification(Psc_Conversations::get($cid), 'famille');
    echo wp_json_encode(array('parent' => $pid, 'conversation' => $cid));`));
}

function buttonLink(html: string): string {
  const m = html.match(/href="([^"]*psc_lt=[0-9a-f]{64}[^"]*)"/);
  expect(m, 'bouton « Répondre » sans lien de connexion').toBeTruthy();
  return m![1].replace(/&(amp|#038);/g, '&');
}

test.describe.serial('Notifications d’échanges : texte et accès direct', () => {
  const email = `echange-notif.e2e+${Date.now()}@example.test`;
  const other = `echange-notif-b.e2e+${Date.now()}@example.test`;
  test.afterAll(() => {
    for (const e of [email, other]) {
      php(`global $wpdb; $pid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('parents').' WHERE email=%s', '${e}'));
        if ($pid) { Psc_Conversations::delete_for_family($pid); $wpdb->delete(psc_table('parents'), array('id'=>$pid)); }
        echo 'ok';`);
    }
    php(`update_option('psc_conversations_contenu_email', 1); echo 'ok';`);
  });

  test('le texte est dans l’e-mail et « Répondre » ouvre la conversation, connecté', async ({ browser }) => {
    php(`update_option('psc_conversations_contenu_email', 1); echo 'ok';`);
    const data = seed(email, 'La sortie est maintenue, pensez au pique-nique.');
    const mail = await findLatestMessage(email, SUBJECT);
    expect(mail.HTML).toContain('La sortie est maintenue, pensez au pique-nique.');
    expect(mail.HTML).toContain('Sortie du jeudi');

    const context = await browser.newContext(); // aucune session
    const page = await context.newPage();
    await page.goto(buttonLink(mail.HTML));
    await expect(page).toHaveURL(new RegExp(`conversation_id=${data.conversation}`));
    await expect(page).not.toHaveURL(/psc_lt=/);
    await expect(page.getByTestId('account-bar')).toBeVisible();
    await expect(page.locator('.psc-conv-thread-subject')).toHaveText('Sortie du jeudi');
    await expect(page.locator('#psc-conversation-reply-body')).toBeVisible();
    await axe(page, '.psc-conversations');
    await context.close();
  });

  test('lien expiré : le lien demandé ramène à la conversation', async ({ browser }) => {
    const data = seed(email, 'Deuxième message.');
    const mail = await findLatestMessage(email, SUBJECT);
    php(`global $wpdb; $wpdb->query("UPDATE ".psc_table('login_tokens')." SET expires_at = '2000-01-01 00:00:00' WHERE parent_id = ${data.parent}"); echo 'ok';`);

    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(buttonLink(mail.HTML));
    await expect(page.getByTestId('login-cible-conversation')).toBeVisible();
    await page.getByTestId('login-email-input').fill(email);
    await page.getByTestId('login-submit-button').click();
    await expect(page.getByTestId('notice-link_sent')).toBeVisible();

    const login = await findLatestMessage(email, 'lien d');
    const link = login.Text.match(/https?:\/\/\S*psc_token=[0-9a-f]+\S*/)?.[0] ?? '';
    expect(link).toContain(`conversation_id=${data.conversation}`);
    await page.goto(link);
    await expect(page).toHaveURL(new RegExp(`conversation_id=${data.conversation}`));
    await expect(page.locator('.psc-conv-thread-subject')).toHaveText('Sortie du jeudi');
    await context.close();
  });

  test('droits : connectée par son bouton, la famille A n’ouvre pas la conversation de B', async ({ browser }) => {
    const b = seed(other, 'Message réservé à B.', 'Sujet de la famille B');
    seed(email, 'Message pour A.');
    const mail = await findLatestMessage(email, SUBJECT);
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(buttonLink(mail.HTML));
    await expect(page.getByTestId('account-bar')).toBeVisible();
    await page.goto(`${APP_BASE}/?psc_tab=messages&psc_vue=echanges&conversation_id=${b.conversation}`);
    await expect(page.getByText('Sujet de la famille B')).toHaveCount(0);
    await expect(page.getByText('Message réservé à B.')).toHaveCount(0);
    await context.close();
  });

  test('réglage mairie décoché : e-mail sans texte, bouton toujours direct', async ({ page }) => {
    await page.goto(`${APP_BASE}/wp-login.php`);
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);
    const box = page.getByTestId('settings-conversations-contenu');
    await expect(box).toBeChecked();
    await axe(page, '#wpbody-content');
    await box.uncheck();
    await page.locator('form:has(input[name="action"][value="psc_save_settings"]) [type="submit"]').last().click();
    await page.waitForURL(/psc_msg=/);
    expect(php(`echo psc_conversations_contenu_email() ? 1 : 0;`)).toBe('0');

    seed(email, 'Texte confidentiel à ne pas recopier.');
    const mail = await findLatestMessage(email, SUBJECT);
    expect(mail.HTML).not.toContain('Texte confidentiel à ne pas recopier.');
    buttonLink(mail.HTML);
  });
});
