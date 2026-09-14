import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';
import { readFormPageUrl } from '../playwright/seed-result';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const EMAIL = 'impersonation.e2e@example.test';
const LIMITED_USER = 'impersonation-limited-e2e';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function cleanup(): void {
  wpEval(`global $wpdb;
    update_option('psc_impersonation_visible_famille',1);
    foreach($wpdb->get_col("SELECT id FROM {$wpdb->prefix}psc_messages WHERE titre LIKE 'ConsultationE2E%'") as $mid){Psc_Messages::delete((int)$mid);}
    $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}'));
    if($pid){
      $wpdb->delete($wpdb->prefix.'psc_impersonations',array('family_id'=>$pid));
      foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){
        $wpdb->delete($wpdb->prefix.'psc_exception',array('child_id'=>(int)$cid));
        $wpdb->delete($wpdb->prefix.'psc_pattern',array('child_id'=>(int)$cid));
        $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid));
        $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid));
      }
      $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid));
    }
    $user=get_user_by('login','${LIMITED_USER}');
    if($user){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user((int)$user->ID);}
    echo 'ok';`);
}

function seedFamily(): { familyId: number; childId: number } {
  return JSON.parse(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array(
      'email'=>'${EMAIL}','nom'=>'Consultation E2E','prenom'=>'Famille','active'=>1,
      'adresse'=>'1 rue avant test','onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array(
      'parent_id'=>$pid,'nom'=>'Consultation','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    $cid=(int)$wpdb->insert_id;
    Psc_School_Years::enroll($cid,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    echo wp_json_encode(array('familyId'=>$pid,'childId'=>$cid));`));
}

function createUnreadMessage(familyId: number): number {
  return Number(wpEval(`$id=Psc_Messages::save(array(
      'titre'=>'ConsultationE2E Message non lu','corps'=>'<p>Information de test</p>',
      'categorie'=>'information','statut'=>'brouillon','cible_type'=>'familles',
      'cible_valeur'=>array('family_ids'=>array(${familyId})),
      'canaux'=>array('portail'=>true,'email'=>false,'push'=>false),'auteur_id'=>1));
    Psc_Messages::send($id); echo (int)$id;`));
}

function latestConsultation(familyId: number): { id: number; ended_reason: string | null } {
  return JSON.parse(wpEval(`global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare("SELECT id,ended_reason FROM {$wpdb->prefix}psc_impersonations WHERE family_id=%d ORDER BY id DESC LIMIT 1",${familyId}),ARRAY_A);
    echo wp_json_encode($row);`));
}

function planningState(childId: number): string {
  return wpEval(`global $wpdb;
    echo wp_json_encode(array(
      'patterns'=>$wpdb->get_results($wpdb->prepare("SELECT weekday,service_code FROM {$wpdb->prefix}psc_pattern WHERE child_id=%d ORDER BY id",${childId}),ARRAY_A),
      'exceptions'=>$wpdb->get_results($wpdb->prepare("SELECT jour_date,service_code,\`value\` FROM {$wpdb->prefix}psc_exception WHERE child_id=%d ORDER BY id",${childId}),ARRAY_A)
    ));`);
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

async function loginAsWordPressUser(page: Page, login: string, password: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(login);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

async function loginAsFamily(page: Page): Promise<void> {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(EMAIL);
  await page.getByTestId('login-submit-button').click();
  const mail = await findLatestMessage(EMAIL, 'Votre lien d\'accès aux inscriptions périscolaires');
  const link = mail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(link, 'lien de connexion famille introuvable').toBeTruthy();
  await page.goto(link![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

async function openConfirmation(page: Page, familyId: number): Promise<void> {
  await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents`);
  await page.getByTestId(`impersonate-open-${familyId}`).click();
  await expect(page.getByRole('heading', { name: 'Consulter un espace famille' })).toBeVisible();
}

async function startConsultation(page: Page, familyId: number, motif: 'reclamation' | 'verification' = 'reclamation'): Promise<void> {
  await openConfirmation(page, familyId);
  await page.getByTestId(`impersonate-motif-${motif}`).check();
  await page.getByTestId('impersonate-submit').click();
  await expect(page.getByTestId('portal-root')).toHaveAttribute('data-psc-readonly', '1');
}

async function tabTo(page: Page, testId: string): Promise<void> {
  for (let i = 0; i < 30; i++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate((id) => document.activeElement?.getAttribute('data-testid') === id, testId)) return;
  }
  throw new Error(`L’élément ${testId} n’est pas atteignable au clavier.`);
}

async function copiedImpersonationContext(browser: Browser, source: BrowserContext): Promise<BrowserContext> {
  const cookie = (await source.cookies()).find((item) => item.name === 'psc_impersonation');
  expect(cookie, 'cookie de consultation introuvable').toBeTruthy();
  const context = await browser.newContext();
  await context.addCookies([cookie!]);
  return context;
}

test.describe('Consultation d’un espace famille', () => {
  test.beforeEach(() => cleanup());
  test.afterEach(() => cleanup());
  test.afterAll(() => cleanup());

  test('refuse le motif Autre vide puis ouvre le bon espace avec un bandeau accessible', async ({ page }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await openConfirmation(page, familyId);
    await page.getByTestId('impersonate-motif-other').check();
    await page.locator('form').filter({ has: page.getByTestId('impersonate-submit') }).evaluate((form: HTMLFormElement) => HTMLFormElement.prototype.submit.call(form));

    const detail = page.getByTestId('impersonate-motif-detail');
    await expect(detail).toHaveAttribute('aria-invalid', 'true');
    await expect(detail).toHaveAttribute('aria-describedby', /psc-motif-detail-error/);
    await expect(page.locator('#psc-motif-detail-error')).toHaveAttribute('role', 'alert');
    await detail.fill('Contrôle demandé par téléphone');
    await page.getByTestId('impersonate-submit').click();

    const banner = page.getByRole('region', { name: 'Mode consultation mairie' });
    await expect(banner).toContainText('Consultation E2E');
    await expect(page.getByRole('button', { name: 'Quitter la consultation' })).toBeVisible();
    await expect(page).toHaveTitle(/^\[Consultation\]/);
    await page.getByTestId('portal-nav-enfants').click();
    await expect(page.getByTestId('portal-section-enfants')).toContainText('Lou');
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    await tabTo(page, 'impersonation-stop');
    const focus = await page.getByTestId('impersonation-stop').evaluate((element) => {
      const style = getComputedStyle(element);
      return { style: style.outlineStyle, width: style.outlineWidth };
    });
    expect(focus.style).not.toBe('none');
    expect(Number.parseFloat(focus.width)).toBeGreaterThan(0);
  });

  test('bloque le planning avant le réseau et conserve la base inchangée', async ({ page }) => {
    const { familyId, childId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    await page.getByTestId('portal-nav-cantine2').click();
    const before = planningState(childId);
    let writes = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST' && /action=psc_(toggle|apply|reset)/.test(request.postData() ?? '')) writes++;
    });
    const cell = page.locator('.psc-pat-btn').first();
    await expect(cell).toBeDisabled();
    await cell.evaluate((element: HTMLButtonElement) => element.click());
    await page.waitForTimeout(150);
    expect(writes).toBe(0);
    expect(planningState(childId)).toBe(before);
    await page.evaluate(async () => {
      const psc = (window as any).PSC;
      await (window as any).PscAjax.envelope(psc.ajax_url, {
        action: 'psc_toggle_pattern', nonce: psc.nonce, parent_nonce: psc.parent_nonce
      });
    });
    await expect(page.locator('#psc-readonly-live')).toHaveText('Mode consultation : modification impossible');
    const loaded = await page.evaluate(async () => {
      const psc = (window as any).PSC;
      return (window as any).PscAjax.envelope(psc.ajax_url, {
        action: 'psc_menu_week', nonce: psc.nonce, parent_nonce: psc.parent_nonce, semaine: ''
      });
    });
    expect(loaded.success).toBe(true);
  });

  test('refuse un POST profil forgé malgré des nonces valides', async ({ page }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    await page.getByTestId('portal-nav-profil').click();
    const form = page.getByTestId('profil-form');
    const action = await form.getAttribute('action');
    const nonces = await form.evaluate((element: HTMLFormElement) => ({
      wp: (element.elements.namedItem('_wpnonce') as HTMLInputElement).value,
      family: (element.elements.namedItem('psc_nonce') as HTMLInputElement).value,
    }));
    const response = await page.request.post(action!, {
      form: {
        action: 'psc_parent_update_profile', _wpnonce: nonces.wp, psc_nonce: nonces.family,
        profil_prenom: 'Pirate', profil_nom: 'Modification interdite', profil_email: EMAIL,
        profil_adresse: '99 rue modifiée', profil_code_postal: '95000', profil_ville: 'Test',
      },
      maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toContain('psc_msg=impersonation_readonly');
    const profile = JSON.parse(wpEval(`global $wpdb; echo wp_json_encode($wpdb->get_row($wpdb->prepare("SELECT prenom,nom,adresse FROM {$wpdb->prefix}psc_parents WHERE id=%d",${familyId}),ARRAY_A));`));
    expect(profile).toEqual({ prenom: 'Famille', nom: 'Consultation E2E', adresse: '1 rue avant test' });
  });

  test('laisse un message ouvert non lu par la famille', async ({ page }) => {
    const { familyId } = seedFamily();
    const messageId = createUnreadMessage(familyId);
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${messageId}`);
    await expect(page.getByRole('heading', { name: 'ConsultationE2E Message non lu' })).toBeVisible();
    await expect(page.getByText('Non lu par la famille')).toBeVisible();
    expect(wpEval(`global $wpdb; echo (string)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${messageId} AND vu_le IS NOT NULL");`)).toBe('0');
  });

  test('quitte manuellement la consultation et ne restaure pas la famille au rechargement', async ({ page }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    await page.getByTestId('impersonation-stop').click();
    await expect(page).toHaveURL(new RegExp(`wp-admin/admin\\.php\\?page=psc_parents&edit=${familyId}`));
    expect(latestConsultation(familyId).ended_reason).toBe('manuel');
    await page.goto(readFormPageUrl());
    await expect(page.getByTestId('portal-root')).toHaveCount(0);
    await expect(page.getByTestId('login-email-input')).toBeVisible();
  });

  test('expire la consultation dès que la base révoque sa durée', async ({ page }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    const consultation = latestConsultation(familyId);
    wpEval(`global $wpdb; $wpdb->update($wpdb->prefix.'psc_impersonations',array('expires_at'=>gmdate('Y-m-d H:i:s',current_time('timestamp')-60)),array('id'=>${consultation.id})); echo 'ok';`);
    await page.reload();
    await expect(page.getByTestId('portal-root')).toHaveCount(0);
    expect(latestConsultation(familyId).ended_reason).toBe('expiration');
  });

  test('renvoie 403 à un utilisateur WordPress sans la capacité', async ({ page }) => {
    const { familyId } = seedFamily();
    const password = 'Limited-E2E-2026!';
    const userId = Number(wpEval(`$id=wp_insert_user(array('user_login'=>'${LIMITED_USER}','user_pass'=>'${password}','user_email'=>'limited-impersonation@example.test','role'=>'subscriber')); echo (int)$id;`));
    await loginAsWordPressUser(page, LIMITED_USER, password);
    const loggedIn = (await page.context().cookies()).find((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
    expect(loggedIn, 'cookie WordPress de l’utilisateur limité introuvable').toBeTruthy();
    const encodedCookie = Buffer.from(loggedIn!.value).toString('base64');
    const nonce = wpEval(`$_COOKIE[LOGGED_IN_COOKIE]=base64_decode('${encodedCookie}'); wp_set_current_user(${userId}); echo wp_create_nonce('psc_impersonate_start');`);
    const response = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_impersonate_start', family_id: String(familyId), motif_type: 'verification', _wpnonce: nonce },
      maxRedirects: 0,
    });
    expect(response.status()).toBe(403);
    expect(wpEval(`global $wpdb; echo (string)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_impersonations WHERE family_id=${familyId}");`)).toBe('0');
  });

  test('ignore un cookie de consultation copié sans la session WordPress', async ({ page, browser }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    const copied = await copiedImpersonationContext(browser, page.context());
    try {
      const anonymousPage = await copied.newPage();
      await anonymousPage.goto(readFormPageUrl());
      await expect(anonymousPage.getByTestId('portal-root')).toHaveCount(0);
      await expect(anonymousPage.getByTestId('login-email-input')).toBeVisible();
    } finally {
      await copied.close();
    }
  });

  test('clôt la ligne quand l’agent se déconnecte de WordPress', async ({ page }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId);
    const logout = page.locator('#wp-admin-bar-logout a');
    const logoutUrl = await logout.getAttribute('href');
    expect(logoutUrl).toBeTruthy();
    await page.goto(logoutUrl!);
    await page.waitForURL('**/wp-login.php**');
    expect(latestConsultation(familyId).ended_reason).toBe('deconnexion');
  });

  test('montre la trace à la vraie famille selon le réglage de transparence', async ({ page, browser }) => {
    const { familyId } = seedFamily();
    await loginAsAdmin(page);
    await startConsultation(page, familyId, 'verification');
    await page.getByTestId('impersonation-stop').click();
    const familyContext = await browser.newContext();
    try {
      const familyPage = await familyContext.newPage();
      await loginAsFamily(familyPage);
      await familyPage.getByTestId('portal-nav-profil').click();
      const history = familyPage.getByTestId('profil-impersonation-history');
      await expect(history).toContainText('La mairie');
      await expect(history).toContainText('Vérification du dossier');
      wpEval("update_option('psc_impersonation_visible_famille',0); echo 'ok';");
      await familyPage.reload();
      await familyPage.getByTestId('portal-nav-profil').click();
      await expect(familyPage.getByTestId('profil-impersonation-history')).toHaveCount(0);
    } finally {
      await familyContext.close();
    }
  });
});
