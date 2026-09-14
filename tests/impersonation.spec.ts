import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const EMAIL = 'impersonation.e2e@example.test';

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
    echo 'ok';`);
}

function seedFamily(): number {
  return Number(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array(
      'email'=>'${EMAIL}','nom'=>'Consultation E2E','prenom'=>'Famille','active'=>1,
      'onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array(
      'parent_id'=>$pid,'nom'=>'Consultation','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    $cid=(int)$wpdb->insert_id;
    Psc_School_Years::enroll($cid,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    echo $pid;`));
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test.describe('Consultation d’un espace famille', () => {
  test.beforeEach(() => cleanup());
  test.afterEach(() => cleanup());

  test('identifie le portail, désactive les écritures et conserve les lectures AJAX', async ({ page }) => {
    const familyId = seedFamily();
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents`);
    await page.getByTestId(`impersonate-open-${familyId}`).click();
    await expect(page.getByRole('heading', { name: 'Consulter un espace famille' })).toBeVisible();
    await page.getByTestId('impersonate-motif-reclamation').check();
    await page.getByTestId('impersonate-submit').click();

    const portal = page.getByTestId('portal-root');
    await expect(portal).toHaveAttribute('data-psc-readonly', '1');
    await expect(page.getByTestId('impersonation-banner')).toContainText('Consultation E2E');
    await expect(page.getByTestId('impersonation-stop')).toBeVisible();
    await expect(page).toHaveTitle(/^\[Consultation\]/);

    await page.getByTestId('portal-nav-profil').click();
    await expect(page.getByTestId('profil-submit')).toBeDisabled();
    const familyHistory = page.getByTestId('profil-impersonation-history');
    await expect(familyHistory).toBeVisible();
    await expect(familyHistory).toContainText('La mairie');
    await expect(familyHistory).toContainText('Réponse à une demande de la famille');

    wpEval("update_option('psc_impersonation_visible_famille',0); echo 'ok';");
    await page.reload();
    await page.getByTestId('portal-nav-profil').click();
    await expect(page.getByTestId('profil-impersonation-history')).toHaveCount(0);
    wpEval("update_option('psc_impersonation_visible_famille',1); echo 'ok';");

    await page.getByTestId('portal-nav-cantine2').click();
    const planningCell = page.locator('.psc-pat-btn').first();
    await expect(planningCell).toBeDisabled();
    await expect(planningCell).toHaveAttribute('aria-disabled', 'true');

    let writeRequests = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST' && (request.postData() ?? '').includes('action=psc_toggle_pattern')) {
        writeRequests++;
      }
    });
    const blocked = await page.evaluate(async () => {
      const psc = (window as any).PSC;
      return (window as any).PscAjax.envelope(psc.ajax_url, {
        action: 'psc_toggle_pattern', nonce: psc.nonce, parent_nonce: psc.parent_nonce
      });
    });
    expect(blocked.success).toBe(false);
    expect(blocked.data.code).toBe('impersonation_readonly');
    expect(writeRequests).toBe(0);
    await expect(page.locator('#psc-readonly-live')).toHaveText('Mode consultation : modification impossible');

    const loaded = await page.evaluate(async () => {
      const psc = (window as any).PSC;
      return (window as any).PscAjax.envelope(psc.ajax_url, {
        action: 'psc_menu_week', nonce: psc.nonce, parent_nonce: psc.parent_nonce, semaine: ''
      });
    });
    expect(loaded.success).toBe(true);
    expect(page.url()).toContain(new URL(readFormPageUrl()).pathname);
  });
});
