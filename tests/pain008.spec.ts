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
    await page.waitForURL('**/wp-admin/**');
    const unauthorized = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2099-12-05' } });
    expect(unauthorized.status()).toBe(403);
    await page.goto(base + '/wp-admin/admin.php?page=psc_settings');
    await expect(page.locator('#psc-org-iban')).toHaveValue('FR7630006000011234567890189');
    await page.locator('#psc-org-iban').fill('FR7630006000011234567890188');
    expect(await page.locator('#psc-org-iban').evaluate((el: HTMLInputElement) => el.checkValidity())).toBe(false);
    await page.goto(base + '/wp-admin/admin.php?page=psc_factures&mois=2099-11');
    await expect(page.getByRole('button', { name: 'export fichier pain.008', exact: true })).toBeVisible();
    await page.locator('#psc-collection-date').fill('2099-12-05');
    await page.screenshot({ path: testInfo.outputPath('factures-pain008.png'), fullPage: true });
    const form = page.locator('form').filter({ has: page.locator('input[value="psc_download_pain008"]') });
    const nonce = await form.locator('input[name="_wpnonce"]').inputValue();
    const invalidDate = await page.request.post(base + '/wp-admin/admin-post.php', { form: { action: 'psc_download_pain008', mois: '2099-11', collection_date: '2026-02-30', _wpnonce: nonce } });
    expect(invalidDate.status()).toBe(400);
    const responsePromise = page.waitForResponse(r => r.url().endsWith('/admin-post.php'));
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'export fichier pain.008', exact: true }).click();
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
});
