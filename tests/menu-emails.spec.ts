/**
 * Menus de la cantine par e-mail : la famille choisit de les recevoir ou non.
 *
 *  - « Mon profil » : case cochée par défaut, décocher retire la famille
 *    des destinataires, recocher l'y remet ; axe sans violation ;
 *  - droits : sans session ni nonce de la famille, aucune écriture ; un
 *    lien de désinscription signé pour une autre famille est refusé ;
 *  - e-mail du menu : lien visible « Ne plus recevoir les menus par
 *    e-mail » et en-têtes List-Unsubscribe / List-Unsubscribe-Post ;
 *  - lien : GET n'écrit rien (page de confirmation, axe), le bouton
 *    désinscrit ; POST « en un clic » des messageries (RFC 8058) aussi ;
 *  - admin : la fiche famille montre et modifie le choix, la page Menus
 *    affiche le nombre de familles désinscrites.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';
import { APP_BASE, MAILPIT_API, ENGINE, CONTAINER } from '../helpers/env';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const WEEK = '2091-01-01';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

interface Seed { parent_id: number }

function seedFamily(): number {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  return (JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as Seed).parent_id;
}

function loginUrl(parentId: number): string {
  return php(`global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${parentId})); echo add_query_arg(array('psc_pid'=>${parentId},'psc_token'=>$t),Psc_Mailer::form_page_url());`);
}

function menuEmails(parentId: number): number {
  return Number(php(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT menu_emails FROM '.psc_table('parents').' WHERE id=%d', ${parentId}));`));
}

function setMenuEmails(parentId: number, value: 0 | 1): void {
  php(`global $wpdb; $wpdb->update(psc_table('parents'), array('menu_emails'=>${value}), array('id'=>${parentId})); echo 'ok';`);
}

function isRecipient(parentId: number): boolean {
  return php(`echo in_array(${parentId}, array_map('intval', wp_list_pluck(Psc_Menus::recipients(), 'id')), true) ? 'oui' : 'non';`) === 'oui';
}

function unsubscribeUrl(parentId: number): string {
  return php(`echo Psc_Menus::unsubscribe_url(Psc_Parents::get_by_id(${parentId}));`);
}

async function axeViolations(page: Page, include?: string): Promise<string[]> {
  let builder = new AxeBuilder({ page }).withTags(TAGS).exclude('#wpadminbar');
  if (include) builder = builder.include(include);
  const results = await builder.analyze();
  return results.violations.map((v) => `${v.id} : ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test.describe('Menus de la cantine par e-mail', () => {
  let familyA = 0;
  let familyB = 0;

  test.beforeAll(() => {
    familyA = seedFamily();
    familyB = Number(php(`global $wpdb; $wpdb->insert(psc_table('parents'), array('email'=>'menu-emails-b@example.test','nom'=>'MenuEmailsB','active'=>1,'created_at'=>current_time('mysql'))); echo (int) $wpdb->insert_id;`));
  });

  test.afterAll(() => {
    php(`global $wpdb; $wpdb->delete(psc_table('parents'), array('id'=>${familyB}));
      foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menus').' WHERE semaine_debut=%s', '${WEEK}')) as $id) $wpdb->delete(psc_table('menus'), array('id'=>(int) $id));
      echo 'ok';`);
    setMenuEmails(familyA, 1);
  });

  test('« Mon profil » : décocher puis recocher la réception des menus', async ({ page }) => {
    setMenuEmails(familyA, 1);
    await page.goto(loginUrl(familyA));
    await expect(page.getByTestId('portal-root')).toBeVisible();
    await page.goto(`${APP_BASE}/?psc_tab=profil`);

    const box = page.getByTestId('profil-menu-emails');
    await expect(box).toBeChecked();
    await expect(box).toHaveAccessibleName(/Recevoir le menu de la cantine par e-mail/);
    expect(await axeViolations(page), 'Mon profil').toEqual([]);

    // Au clavier : Espace décoche, le bouton enregistre.
    await box.focus();
    await page.keyboard.press('Space');
    await expect(box).not.toBeChecked();
    await page.getByTestId('profil-email-prefs-submit').click();
    await expect(page.getByTestId('notice-email_prefs_updated')).toBeVisible();
    await expect(page.getByTestId('profil-menu-emails')).not.toBeChecked();
    expect(menuEmails(familyA)).toBe(0);
    expect(isRecipient(familyA)).toBe(false);

    await page.getByTestId('profil-menu-emails').check();
    await page.getByTestId('profil-email-prefs-submit').click();
    await expect(page.getByTestId('notice-email_prefs_updated')).toBeVisible();
    expect(menuEmails(familyA)).toBe(1);
    expect(isRecipient(familyA)).toBe(true);
  });

  test('droits : aucune écriture sans la session de la famille', async ({ page, browser }) => {
    setMenuEmails(familyA, 1);
    await page.goto(loginUrl(familyA));
    await page.goto(`${APP_BASE}/?psc_tab=profil`);
    const form = page.getByTestId('profil-email-prefs-form');
    const fields = await form.evaluate((f) => Object.fromEntries(new FormData(f as HTMLFormElement)));

    // Même formulaire (nonces de A) rejoué depuis un autre navigateur, sans session.
    const stranger = await browser.newContext();
    const { menu_emails: _ignored, ...withoutBox } = fields as Record<string, string>;
    const response = await stranger.request.post(`${APP_BASE}/wp-admin/admin-post.php`, { form: withoutBox, maxRedirects: 0 });
    // Refus : nonce WordPress rejeté (403) ou famille non reconnue (retour « auth »).
    if (response.status() !== 403) {
      expect(response.status()).toBe(302);
      expect(response.headers()['location']).toContain('psc_msg=auth');
    }
    expect(menuEmails(familyA)).toBe(1);

    // Lien de désinscription : jeton de B présenté pour A → refusé.
    const tokenB = new URL(unsubscribeUrl(familyB)).searchParams.get('t')!;
    const forged = await stranger.request.post(`${APP_BASE}/?psc_menu_unsub=${familyA}&t=${tokenB}`, { form: { 'List-Unsubscribe': 'One-Click' } });
    expect(forged.status()).toBe(404);
    expect(menuEmails(familyA)).toBe(1);
    await stranger.close();
  });

  test('e-mail du menu : lien et en-têtes de désinscription', async ({ request }) => {
    setMenuEmails(familyA, 1);
    const email = php(`echo Psc_Parents::get_by_id(${familyA})->email;`);
    php(`global $wpdb; $wpdb->insert(psc_table('menus'), array('semaine_debut'=>'${WEEK}','lundi'=>'Soupe de potiron','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
      Psc_Mailer::send_weekly_menu(Psc_Parents::get_by_id(${familyA}), Psc_Menus::get((int) $wpdb->insert_id)); echo 'ok';`);
    const message = await findLatestMessage(email, '01/01/2091');
    const expected = unsubscribeUrl(familyA);
    expect(message.HTML).toContain('Ne plus recevoir les menus par e-mail');
    expect(message.HTML.replace(/&#038;|&amp;/g, '&')).toContain(expected);

    const search = await (await request.get(`${MAILPIT_API}/search?${new URLSearchParams({ query: `to:${email} subject:"01/01/2091"`, limit: '1' })}`)).json() as { messages: { ID: string }[] };
    const headers = await (await request.get(`${MAILPIT_API}/message/${search.messages[0].ID}/headers`)).json() as Record<string, string[]>;
    expect(headers['List-Unsubscribe']?.[0]).toBe(`<${expected}>`);
    expect(headers['List-Unsubscribe-Post']?.[0]).toBe('List-Unsubscribe=One-Click');
  });

  test('lien de désinscription : confirmation, puis désinscription en un clic', async ({ page, request }) => {
    setMenuEmails(familyA, 1);
    const url = unsubscribeUrl(familyA);

    // GET : rien n'est écrit, la page demande confirmation.
    await page.goto(url);
    await expect(page.getByRole('heading', { name: 'Menus de la cantine par e-mail' })).toBeVisible();
    expect(menuEmails(familyA)).toBe(1);
    expect(await axeViolations(page), 'page de désinscription').toEqual([]);

    await page.getByTestId('menu-unsub-confirm').click();
    await expect(page.getByTestId('menu-unsub-done')).toBeVisible();
    expect(menuEmails(familyA)).toBe(0);
    expect(isRecipient(familyA)).toBe(false);

    // Lien rouvert : déjà désinscrit, rien à confirmer.
    await page.goto(url);
    await expect(page.getByTestId('menu-unsub-already')).toBeVisible();

    // Jeton altéré : refusé.
    const altered = await request.get(url.replace(/t=([0-9a-f])/, (_m, c) => `t=${c === '0' ? '1' : '0'}`));
    expect(altered.status()).toBe(404);

    // Désinscription « en un clic » des messageries (RFC 8058).
    setMenuEmails(familyA, 1);
    const oneClick = await request.post(url, { form: { 'List-Unsubscribe': 'One-Click' } });
    expect(oneClick.status()).toBe(200);
    expect(menuEmails(familyA)).toBe(0);
    setMenuEmails(familyA, 1);
  });

  test('admin : fiche famille et compteur de la page Menus', async ({ page }) => {
    setMenuEmails(familyA, 0);
    await loginAsAdmin(page);

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_menus`);
    await expect(page.getByTestId('menu-recipients-summary')).toContainText(/choisi de ne pas recevoir le menu par e-mail/);
    expect(await axeViolations(page, '#wpbody-content .wrap'), 'page Menus').toEqual([]);

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyA}`);
    const box = page.getByTestId('edit-parent-menu-emails');
    await expect(box).not.toBeChecked();
    expect(await axeViolations(page, '#wpbody-content .wrap'), 'fiche famille').toEqual([]);
    await box.check();
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await expect(page).toHaveURL(/psc_msg=updated/);
    expect(menuEmails(familyA)).toBe(1);
  });
});
