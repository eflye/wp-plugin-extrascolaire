import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const engine = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const container = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const base = 'http://localhost:8080';
const wp = (args: string[]) => execFileSync(engine, ['exec', container, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', ...args], { encoding: 'utf8' });
const fixture = (mode: string) => wp(['eval-file', '/var/www/html/wp-content/plugins/periscolaire-registration/tests/integration/pain008-fixture.php', mode]);

test.describe('Export bancaire pain.008', () => {
  test.beforeAll(() => fixture('setup'));
  test.afterAll(() => fixture('cleanup'));

  test('refuse un téléchargement anonyme', async ({ request }) => {
    const response = await request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2099-12-05' } });
    expect(response.status()).toBeGreaterThanOrEqual(400);
  });

  test('bouton, réglages, téléchargement privé et erreurs bloquantes', async ({ page }, testInfo) => {
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.goto(base + '/wp-admin/admin.php?page=psc_factures');
    const unauthorized = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2099-12-05' } });
    expect(unauthorized.status()).toBe(403);
    await page.goto(base + '/wp-admin/admin.php?page=psc_settings');
    await expect(page.locator('#psc-org-iban')).toHaveValue('FR7630006000011234567890189');
    await page.locator('#psc-org-iban').fill('FR7630006000011234567890188');
    expect(await page.locator('#psc-org-iban').evaluate((el: HTMLInputElement) => el.checkValidity())).toBe(false);
    await page.goto(base + '/wp-admin/admin.php?page=psc_factures&mois=2099-11');
    await expect(page.getByRole('button', { name: 'Export fichier pain.008', exact: true })).toBeVisible();
    await page.locator('#psc-collection-date').fill('2099-12-05');
    await page.screenshot({ path: testInfo.outputPath('factures-pain008.png'), fullPage: true });
    const form = page.locator('form').filter({ has: page.locator('input[value="psc_download_pain008"]') });
    const nonce = await form.locator('input[name="_wpnonce"]').inputValue();
    const invalidDate = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2026-02-30', _wpnonce: nonce } });
    expect(invalidDate.status()).toBe(400);
    const responsePromise = page.waitForResponse(r => r.url().endsWith('/admin-post.php'));
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Export fichier pain.008', exact: true }).click();
    const [response, download] = await Promise.all([responsePromise, downloadPromise]);
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(response.headers()['content-type']).toContain('application/xml');
    expect(download.suggestedFilename()).toBe('prelevements-2099-11-2099-12-05-pain008.xml');
    const xml = readFileSync((await download.path())!, 'utf8');
    expect(xml).toContain('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');
    expect(xml).toContain('<CtrlSum>12.34</CtrlSum>');
    expect(xml).toContain('<DtOfSgntr>2026-01-01</DtOfSgntr>');
    expect(xml).toContain('<MndtId>PSC-PAIN008-TEST</MndtId>');
    expect(xml).toContain('<SeqTp>RCUR</SeqTp>');
    const state = wp(['eval', '$b=get_option("psc_test_pain008_backup");global $wpdb;echo $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".psc_table("invoices")." WHERE parent_id=%d AND sent_at IS NOT NULL",$b["parent_id"]));']);
    expect(state.trim()).toBe('0');
    wp(['eval', '$b=get_option("psc_test_pain008_backup");global $wpdb;$wpdb->update(psc_table("parents"),array("sepa_reglement_accepted_at"=>null),array("id"=>$b["parent_id"]));']);
    const rejected = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2099-12-05', _wpnonce: nonce } });
    expect(rejected.status()).toBe(400);
    expect(await rejected.text()).toContain('date d’acceptation');
  });
  test('exports mensuels et pointage indépendant des paiements', async ({ page }) => {
    wp(['eval', '$b=get_option("psc_test_pain008_backup");global $wpdb;$wpdb->update(psc_table("parents"),array("payment_mode"=>"autre"),array("id"=>$b["parent_id"]));$wpdb->insert(psc_table("invoices"),array("parent_id"=>$b["parent_id"],"mois"=>"2099-10","total"=>"25.00","created_at"=>current_time("mysql")));']);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.goto(base + '/wp-admin/admin.php?page=psc_factures&mois=2099-11');
    await expect(page.getByRole('heading', { name: 'Facturation', exact: true })).toBeVisible();
    const block = page.getByRole('region', { name: 'Exports par mois' });
    await expect(block.getByRole('button', { name: 'Export prélèvements (SEPA, .csv)', exact: true })).toBeVisible();
    await expect(block.getByRole('button', { name: 'Export prélèvements (SEPA, .ods)', exact: true })).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await block.getByRole('button', { name: 'Export général (.csv)', exact: true }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('facturation-2099-11.csv');
    const csv = readFileSync((await download.path())!, 'utf8');
    expect(csv).toContain('Année;Mois;Nom;"Moyen de paiement";Montant');
    expect(csv).toContain('2099;11;TestExport;"chèque ou espèces";12,34');
    const forbidden = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_payment_received', invoice_id: '1', received: '1' } });
    expect(forbidden.status()).toBe(403);
    await page.getByRole('button', { name: 'Marquer reçu', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Marquer non reçu', exact: true })).toBeVisible();
    await page.locator('#psc-export-month').selectOption('2099-10');
    await expect(page.getByRole('button', { name: 'Marquer reçu', exact: true })).toBeVisible();
    await page.locator('#psc-export-month').selectOption('2099-11');
    await page.getByRole('button', { name: 'Marquer non reçu', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Marquer reçu', exact: true })).toBeVisible();
  });

  test('comptes familles : soldes, paiements et exclusion des mois futurs', async ({ page }) => {
    wp(['eval', '$b=get_option("psc_test_pain008_backup");global $wpdb;foreach(array("2026-08"=>"10.25","2026-09"=>"20.50") as $m=>$v){$wpdb->insert(psc_table("invoices"),array("parent_id"=>$b["parent_id"],"mois"=>$m,"total"=>$v,"created_at"=>current_time("mysql"),"payment_received_at"=>$m==="2026-08"?current_time("mysql"):null));}$a=Psc_Invoices::family_accounts("2026-09")[$b["parent_id"]];if($a["paid"]!==1025||$a["due"]!==2050||count($a["invoices"])!==2)throw new Exception("Soldes incorrects");']);
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('admin');
    await page.locator('#user_pass').fill('admin');
    await page.locator('#wp-submit').click();
    await page.goto(base + '/wp-admin/admin.php?page=psc_comptes_familles');
    await expect(page.getByRole('heading', { name: 'État des comptes familles' })).toBeVisible();
    const row = page.getByRole('row').filter({ hasText: 'TestExport' });
    await expect(row).toContainText('1 payée(s), 1 non payée(s)');
    await expect(row.locator('td').last()).toHaveText('20,50 €');
    await row.locator('summary').click();
    await expect(row).toContainText('Non payée');
    await expect(row).not.toContainText('2099');
    wp(['eval', '$b=get_option("psc_test_pain008_backup");global $wpdb;$wpdb->update(psc_table("parents"),array("payment_mode"=>"prelevement"),array("id"=>$b["parent_id"]));$a=Psc_Invoices::family_accounts("2026-09")[$b["parent_id"]];if($a["due"]!==0||$a["paid"]!==3075)throw new Exception("Prélèvement non soldé");']);
    await page.reload();
    await expect(row).toContainText('2 payée(s), 0 non payée(s)');
    await expect(row.locator('td').last()).toHaveText('0,00 €');
  });

});
