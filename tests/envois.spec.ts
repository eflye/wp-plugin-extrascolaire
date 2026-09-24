/**
 * Envois suivis (P2-04) — ce que voit la mairie après un échec d'envoi.
 *
 * bin/verify-envois.php couvre les garanties (bilan exact, pas de doublon,
 * reprise) ; cette spec couvre les écrans : l'échec est affiché, la
 * relance part depuis le navigateur (vers Mailpit), l'écran le constate,
 * et chaque écran touché passe le contrôle d'accessibilité axe.
 *
 * Les échecs sont préparés en WP-CLI, où le serveur d'envoi est coupé le
 * temps de l'appel (filtre pre_wp_mail) ; la relance, elle, passe par le
 * vrai transport de l'environnement de test.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const WEEK = '2090-01-02';
const MOIS = '2090-01';
const EMAIL = 'envois-e2e@example.test';
const SUPPLIER = 'fournisseur-envois-e2e@example.test';
const COUPURE = "add_filter('pre_wp_mail', '__return_false', 99);";

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function cleanup(): void {
  wpEval(`global $wpdb; $e=psc_table('envois');
    foreach($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menus').' WHERE semaine_debut=%s','${WEEK}')) as $id){ $wpdb->delete($e,array('objet_type'=>'menu','objet_id'=>(int)$id)); $wpdb->delete(psc_table('menus'),array('id'=>(int)$id)); }
    foreach($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('supplier_orders').' WHERE supplier_email=%s','${SUPPLIER}')) as $id){ $wpdb->delete($e,array('objet_type'=>'commande_fournisseur','objet_id'=>(int)$id)); $wpdb->delete(psc_table('supplier_orders'),array('id'=>(int)$id)); }
    $pid=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('parents').' WHERE email=%s','${EMAIL}'));
    if($pid){ foreach($wpdb->get_results($wpdb->prepare('SELECT id,pdf_path FROM '.psc_table('invoices').' WHERE parent_id=%d',$pid)) as $i){ if($i->pdf_path) @unlink(psc_private_path($i->pdf_path)); $wpdb->delete($e,array('objet_type'=>'facture','objet_id'=>(int)$i->id)); $wpdb->delete(psc_table('invoices'),array('id'=>(int)$i->id)); }
      $wpdb->delete(psc_table('children'),array('parent_id'=>$pid)); $wpdb->delete(psc_table('parents'),array('id'=>$pid)); }
    echo 'ok';`);
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

/** Contrôle axe limité au contenu du plugin (le cadre de WordPress n'est pas de son ressort). */
async function expectAccessible(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .include('#wpbody-content .wrap')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  expect(results.violations.map((v) => `${v.id} : ${v.nodes.length} élément(s)`)).toEqual([]);
}

test.describe('Envois suivis : échec visible, relance, accessibilité', () => {
  test.beforeAll(() => cleanup());
  test.afterAll(() => cleanup());

  test('menu : échec affiché puis relancé', async ({ page }) => {
    const menuId = Number(wpEval(`global $wpdb; $wpdb->insert(psc_table('menus'),array('semaine_debut'=>'${WEEK}','lundi'=>'Soupe','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')));
      $id=(int)$wpdb->insert_id; ${COUPURE} Psc_Menus::send(Psc_Menus::get($id),'e2e-menu'); echo $id;`));
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_menus`);
    await expect(page.getByTestId(`menu-bilan-${menuId}`)).toContainText('échec');
    await expectAccessible(page);

    await page.getByTestId(`menu-relancer-${menuId}`).click();
    await expect(page.getByText('Menu envoyé aux familles.')).toBeVisible();
    await expect(page.getByTestId(`menu-bilan-${menuId}`)).toHaveCount(0);
    await expect(page.getByTestId(`menu-envoi-${menuId}`)).toContainText('✔');
    await expectAccessible(page);
  });

  test('facture : échec du dernier envoi affiché, puis envoi réussi', async ({ page }) => {
    const invoiceId = Number(wpEval(`global $wpdb;
      $wpdb->insert(psc_table('parents'),array('email'=>'${EMAIL}','nom'=>'EnvoisE2E','active'=>1,'created_at'=>current_time('mysql'))); $pid=(int)$wpdb->insert_id;
      require_once PSC_PATH.'includes/fpdf/fpdf.php'; $pdf=new FPDF(); $pdf->AddPage(); $rel='factures/envois-e2e.pdf';
      wp_mkdir_p(dirname(psc_private_path($rel))); file_put_contents(psc_private_path($rel),$pdf->Output('S'));
      $wpdb->insert(psc_table('invoices'),array('parent_id'=>$pid,'mois'=>'${MOIS}','total'=>10,'pdf_path'=>$rel,'created_at'=>current_time('mysql'))); $id=(int)$wpdb->insert_id;
      ${COUPURE} Psc_Invoices::send($id,'e2e-facture'); echo $id;`));
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_factures&mois=${MOIS}`);
    await expect(page.getByTestId(`facture-echec-${invoiceId}`)).toBeVisible();
    await expectAccessible(page);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /Envoyer toutes les factures non envoyées/ }).click();
    await expect(page.getByText(/Toutes les factures ont été envoyées \(1\)/)).toBeVisible();
    await expect(page.getByTestId(`facture-echec-${invoiceId}`)).toHaveCount(0);
  });

  test('commande fournisseur : enregistrée malgré l’échec, puis relancée', async ({ page }) => {
    const orderId = Number(wpEval(`$old=get_option('psc_supplier_email',''); update_option('psc_supplier_email','${SUPPLIER}');
      $y=Psc_School_Year::active(); ${COUPURE} Psc_Supplier_Orders::send(psc_week_start($y->date_start),'e2e-fournisseur');
      update_option('psc_supplier_email',$old); global $wpdb;
      echo (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('supplier_orders').' WHERE supplier_email=%s','${SUPPLIER}'));`));
    expect(orderId).toBeGreaterThan(0);
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_supplier_orders`);
    await expect(page.getByTestId(`supplier-history-statut-${orderId}`)).toContainText('Échec');
    await expectAccessible(page);

    await page.getByTestId(`supplier-history-relancer-${orderId}`).click();
    await expect(page.getByText('Commande envoyée au fournisseur.')).toBeVisible();
    await expect(page.getByTestId(`supplier-history-statut-${orderId}`)).toHaveCount(0);
  });
});
