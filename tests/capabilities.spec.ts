/**
 * Habilitations du backoffice (P1-02) — ce que voit chaque profil.
 *
 * La sonde tests/integration/capabilities-matrix.php appelle tous les
 * endpoints admin sous des comptes restreints ; cette spec couvre ce que
 * la sonde ne voit pas : les écrans servis par WordPress (menu et refus
 * d'accès à une URL) et l'affichage des allergies, réservé à
 * psc_view_health.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const PASSWORD = 'Habilitations-E2E-2026';
const EMAIL = 'habilitations.e2e@example.test';
const ALLERGY = 'Kiwi — habilitations E2E';
const USERS = {
  editeur: { role: 'editor', caps: [] as string[] },
  facturation: { role: 'subscriber', caps: ['psc_manage_billing'] },
  familles: { role: 'subscriber', caps: ['psc_manage_families'] },
};

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function login(name: string): string {
  return `psc-e2e-${name}`;
}

function cleanup(): void {
  const logins = Object.keys(USERS).map((name) => `'${login(name)}'`).join(',');
  wpEval(`global $wpdb; require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(array(${logins}) as $l){ $u=get_user_by('login',$l); if($u) wp_delete_user((int)$u->ID); }
    $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}'));
    if($pid){ foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){ $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid)); $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid)); } $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid)); }
    echo 'ok';`);
}

async function loginAs(page: Page, user: string, password: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

function childRow(page: Page) {
  return page.locator('table.widefat tbody tr', { hasText: 'Noa' }).filter({ hasText: 'HabilitationsE2E' });
}

async function status(page: Page, slug: string): Promise<number> {
  const response = await page.goto(`${APP_BASE}/wp-admin/admin.php?page=${slug}`);
  return response?.status() ?? 0;
}

test.describe('Habilitations du backoffice', () => {
  test.beforeAll(() => {
    cleanup();
    for (const [name, { role, caps }] of Object.entries(USERS)) {
      const grants = caps.map((cap) => `$u->add_cap('${cap}');`).join('');
      wpEval(`$id=wp_insert_user(array('user_login'=>'${login(name)}','user_pass'=>'${PASSWORD}','user_email'=>'${login(name)}@example.test','role'=>'${role}'));
        $u=get_user_by('id',$id); ${grants} echo 'ok';`);
    }
    wpEval(`global $wpdb;
      $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}','nom'=>'HabilitationsE2E','prenom'=>'Famille','active'=>1,'created_at'=>current_time('mysql')));
      $wpdb->insert($wpdb->prefix.'psc_children',array('parent_id'=>(int)$wpdb->insert_id,'nom'=>'HabilitationsE2E','prenom'=>'Noa','food_allergies'=>'${ALLERGY}','created_at'=>current_time('mysql')));
      Psc_School_Years::enroll((int)$wpdb->insert_id,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
      echo 'ok';`);
  });

  test.afterAll(() => cleanup());

  test('un éditeur de contenus n’accède à aucun écran périscolaire', async ({ page }) => {
    await loginAs(page, login('editeur'), PASSWORD);
    await page.goto(`${APP_BASE}/wp-admin/`);
    await expect(page.locator('#adminmenu a[href*="page=psc_"]')).toHaveCount(0);
    for (const slug of ['psc_dashboard', 'psc_parents', 'psc_children', 'psc_factures', 'psc_impersonate', 'psc_settings']) {
      expect(await status(page, slug), slug).toBe(403);
    }
  });

  test('la facturation seule ne voit que la facturation', async ({ page }) => {
    await loginAs(page, login('facturation'), PASSWORD);
    await page.goto(`${APP_BASE}/wp-admin/`);
    await expect(page.locator('#adminmenu a[href*="page=psc_factures"]')).toHaveCount(1);
    await expect(page.locator('#adminmenu a[href*="page=psc_parents"]')).toHaveCount(0);
    expect(await status(page, 'psc_factures')).toBe(200);
    expect(await status(page, 'psc_comptes_familles')).toBe(200);
    for (const slug of ['psc_dashboard', 'psc_parents', 'psc_children', 'psc_requests', 'psc_settings']) {
      expect(await status(page, slug), slug).toBe(403);
    }
  });

  test('les allergies restent masquées sans l’habilitation données de santé', async ({ page }) => {
    await loginAs(page, login('familles'), PASSWORD);
    expect(await status(page, 'psc_children')).toBe(200);
    const row = childRow(page);
    await expect(row).toContainText('Accès restreint');
    await expect(page.locator('body')).not.toContainText(ALLERGY);
    expect(await status(page, 'psc_factures')).toBe(403);
  });

  test('l’administrateur voit les allergies', async ({ page }) => {
    await loginAs(page, 'admin', 'admin');
    expect(await status(page, 'psc_children')).toBe(200);
    await expect(childRow(page)).toContainText(ALLERGY);
  });

  test('aucun endpoint admin n’accepte un profil hors de son domaine', () => {
    const out = execFileSync(
      ENGINE,
      ['exec', CONTAINER, 'php', WP, '--allow-root', '--path=/var/www/html', 'eval-file',
        '/var/www/html/wp-content/plugins/periscolaire-registration/tests/integration/capabilities-matrix.php'],
      { encoding: 'utf8' }
    ).trim().split('\n').pop() ?? '{}';
    const result = JSON.parse(out) as { ok: boolean; endpoints: number; failures: string[] };
    expect(result.failures).toEqual([]);
    expect(result.ok).toBe(true);
    expect(result.endpoints).toBeGreaterThan(20);
  });
});
