/**
 * Messages aux familles — contrats critiques de diffusion et de traçabilité.
 *
 * L'interface est couverte par Playwright, l'état réel par WP-CLI. Ces tests
 * ciblent surtout les garanties qui ne doivent jamais régresser : ciblage
 * figé, envoi idempotent, HTML filtré et première lecture immuable.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const EMAIL = 'messages.e2e@example.test';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function cleanup(): void {
  wpEval(`global $wpdb;
    foreach($wpdb->get_col("SELECT id FROM {$wpdb->prefix}psc_messages WHERE titre LIKE 'MessagesFrontE2E%'") as $mid){Psc_Messages::delete((int)$mid);}
    $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}'));
    if($pid){
      $mids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT message_id FROM {$wpdb->prefix}psc_message_destinataires WHERE family_id=%d",$pid));
      foreach($mids as $mid){Psc_Messages::delete((int)$mid);}
      foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){
        $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid));
        $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid));
      }
      $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid));
    }
    $wpdb->delete($wpdb->prefix.'psc_parents',array('email'=>'messages-other.e2e@example.test'));
    echo 'ok';`);
}

function seedFamily(): number {
  return Number(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}','nom'=>'MessagesE2E','prenom'=>'Famille','active'=>1,'onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array('parent_id'=>$pid,'nom'=>'MessagesE2E','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    Psc_School_Years::enroll((int)$wpdb->insert_id,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    echo $pid;`));
}

function createAndSend(familyId: number, category = 'urgent', title = 'MessagesFrontE2E Alerte', ack = false): { id: number; token: string } {
  return JSON.parse(wpEval(`$id=Psc_Messages::save(array(
      'titre'=>'${title}','corps'=>'<p>Texte sûr</p><script>alert(1)</script>',
      'categorie'=>'${category}','statut'=>'brouillon','cible_type'=>'familles',
      'cible_valeur'=>array('family_ids'=>array(${familyId})),
      'canaux'=>array('portail'=>true,'email'=>true,'push'=>false),'accuse_requis'=>${ack ? 'true' : 'false'},'auteur_id'=>1));
    Psc_Messages::send($id); global $wpdb;
    $token=$wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=%d AND family_id=%d",$id,${familyId}));
    echo wp_json_encode(array('id'=>(int)$id,'token'=>$token));`));
}

async function loginAsFamily(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(EMAIL);
  await page.getByTestId('login-submit-button').click();
  const mail = await findLatestMessage(EMAIL, 'Votre lien d\'accès aux inscriptions périscolaires');
  const link = mail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(link, 'lien de connexion famille introuvable').toBeTruthy();
  await page.goto(link![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

test.describe('Messages aux familles', () => {
  test.beforeEach(() => cleanup());
  test.afterEach(() => cleanup());

  test('fige le ciblage, filtre le HTML et bloque un second envoi', () => {
    const familyId = seedFamily();
    const message = createAndSend(familyId);
    const state = JSON.parse(wpEval(`global $wpdb; $m=Psc_Messages::get(${message.id});
      $again=Psc_Messages::send(${message.id});
      echo wp_json_encode(array(
        'status'=>$m->statut,'body'=>$m->corps,
        'recipients'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}"),
        'duplicate'=>is_wp_error($again)
      ));`));
    expect(state.status).toBe('envoye');
    expect(state.body).toContain('<p>Texte sûr</p>');
    expect(state.body).not.toContain('<script');
    expect(state.recipients).toBe(1);
    expect(state.duplicate).toBe(true);
  });

  test('un lien e-mail valide affiche uniquement le message et inscrit la première lecture', async ({ page }) => {
    const familyId = seedFamily();
    const message = createAndSend(familyId);
    await page.goto(`${APP_BASE}/?psc_msg=${message.id}&t=${message.token}`);
    await expect(page.getByRole('heading', { name: 'MessagesFrontE2E Alerte' })).toBeVisible();
    await expect(page.getByText('Texte sûr')).toBeVisible();
    await expect(page.locator('.psc-portal-sidebar')).toHaveCount(0);

    const first = wpEval(`global $wpdb; echo $wpdb->get_var("SELECT vu_le FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}");`);
    expect(first).not.toBe('');
    await page.reload();
    const second = wpEval(`global $wpdb; echo $wpdb->get_var("SELECT vu_le FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}");`);
    expect(second).toBe(first);
  });

  test('un jeton invalide ne modifie aucune preuve de lecture', async ({ page }) => {
    const familyId = seedFamily();
    const message = createAndSend(familyId, 'information');
    await page.goto(`${APP_BASE}/?psc_msg=${message.id}&t=jeton-invalide`);
    const seen = wpEval(`global $wpdb; echo (string)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id} AND vu_le IS NOT NULL");`);
    expect(seen).toBe('0');
  });

  test('ne relance pas par e-mail un message diffusé uniquement au portail', () => {
    const familyId = seedFamily();
    const count = wpEval(`$id=Psc_Messages::save(array('titre'=>'Portail seul','corps'=>'<p>Info</p>','categorie'=>'information','statut'=>'brouillon','cible_type'=>'familles','cible_valeur'=>array('family_ids'=>array(${familyId})),'canaux'=>array('portail'=>true,'email'=>false,'push'=>false),'auteur_id'=>1)); Psc_Messages::send($id); echo Psc_Messages::resend_unread($id);`);
    expect(count).toBe('0');
  });

  test('respecte l’ordre demandé dans le menu du back-office', () => {
    const slugs = JSON.parse(wpEval(`wp_set_current_user(1); do_action('admin_menu'); global $submenu;
      echo wp_json_encode(array_values(array_map(function($item){return $item[2];},$submenu['psc_dashboard'])));`));
    expect(slugs.indexOf('psc_messages')).toBe(slugs.indexOf('psc_dashboard') + 1);
    expect(slugs.indexOf('psc_school_calendar_v2')).toBe(slugs.indexOf('psc_school_years') - 1);
  });

  test('intègre le digest compact et décrémente le badge à l’ouverture', async ({ page }) => {
    const familyId = seedFamily();
    createAndSend(familyId, 'information', 'MessagesFrontE2E Information très longue qui doit rester sur une seule ligne sans casser la mise en page du tableau de bord');
    createAndSend(familyId, 'cantine', 'MessagesFrontE2E Cantine');
    createAndSend(familyId, 'evenement', 'MessagesFrontE2E Événement');
    await page.setViewportSize({ width: 1440, height: 900 });
    await loginAsFamily(page);

    const digest = page.getByTestId('dashboard-messages');
    await expect(digest).toBeVisible();
    expect((await digest.boundingBox())!.height).toBeLessThanOrEqual(160);
    await expect(digest.locator('.psc-dashboard-message-digest>a')).toHaveCount(3);
    await expect(page.getByTestId('portal-nav-messages').locator('.psc-message-nav-badge')).toHaveText('3');
    expect(await page.locator('.psc-portal-subtitle--messages').evaluate((el) => getComputedStyle(el).marginBottom)).toBe('18px');
    const cardsBox = await page.locator('.psc-portal-cards').boundingBox();
    expect(cardsBox!.y).toBeLessThan(900);

    const first = digest.locator('.psc-dashboard-message-digest>a').first();
    const href = await first.getAttribute('href');
    expect(href).toContain('psc_tab=messages');
    expect(href).toContain('message_id=');
    await first.click();
    await expect(page.getByTestId('portal-section-messages')).toBeVisible();
    await expect(page.getByTestId('portal-nav-messages').locator('.psc-message-nav-badge')).toHaveText('2');
    await expect(page.locator('.psc-message-transparency')).toContainText('la mairie sait que vous avez reçu ce message');

    for (const width of [900, 600]) {
      await page.setViewportSize({ width, height: 900 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
    const listBox = await page.locator('.psc-message-list').boundingBox();
    const articleBox = await page.locator('.psc-message-open').boundingBox();
    expect(articleBox!.y).toBeGreaterThan(listBox!.y);
  });

  test('masque le digest sans message et retire une urgence après lecture', async ({ page }) => {
    const familyId = seedFamily();
    await loginAsFamily(page);
    await expect(page.getByTestId('dashboard-messages')).toHaveCount(0);

    createAndSend(familyId, 'urgent', 'MessagesFrontE2E Fermeture urgente', true);
    await page.goto(readFormPageUrl());
    const block = page.getByTestId('dashboard-messages');
    await expect(block.locator('.psc-dashboard-message-urgent')).toBeVisible();
    expect((await block.boundingBox())!.height).toBeLessThanOrEqual(160);
    await block.locator('.psc-dashboard-message-urgent a').click();
    await expect(page.locator('.psc-message-ack')).toBeVisible();
    await page.locator('.psc-message-ack').click();
    await expect(page.locator('.psc-message-ack-sent')).toContainText('Accusé de lecture envoyé le');
    await page.goto(readFormPageUrl());
    await expect(page.locator('.psc-dashboard-message-urgent')).toHaveCount(0);
    await expect(page.getByTestId('portal-nav-messages').locator('.psc-message-nav-badge')).toHaveCount(0);
  });

  test('refuse en 403 le message d’une autre famille sans marquer sa lecture', async ({ page }) => {
    const familyId = seedFamily();
    const otherId = Number(wpEval(`global $wpdb; $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'messages-other.e2e@example.test','nom'=>'Autre','prenom'=>'Famille','active'=>1,'created_at'=>current_time('mysql'))); echo $wpdb->insert_id;`));
    const foreign = createAndSend(otherId, 'information', 'MessagesFrontE2E Étranger');
    await loginAsFamily(page);
    const response = await page.goto(`${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=messages&message_id=${foreign.id}`);
    expect(response?.status()).toBe(403);
    expect(wpEval(`global $wpdb; echo (string)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${foreign.id} AND vu_le IS NOT NULL");`)).toBe('0');
    wpEval(`global $wpdb; Psc_Messages::delete(${foreign.id}); $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>${otherId})); echo 'ok';`);
  });
});
