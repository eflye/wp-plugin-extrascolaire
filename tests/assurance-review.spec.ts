import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
test.use({ channel: 'chromium' });
const engine = process.env.PSC_CONTAINER_ENGINE || 'podman';
const container = process.env.PSC_WP_CONTAINER || 'plugin-extrascolaire-wordpress-1';
const plugin = '/var/www/html/wp-content/plugins/periscolaire-registration';
function wp(...args: string[]) {
  return execFileSync(engine, ['exec', container, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', ...args], { encoding: 'utf8' }).trim();
}


test('assurances : dépôt mobile, revue, refus, remplacement et acceptation automatique', async ({ page, browser }, testInfo) => {
  test.setTimeout(120000);
  const pdf = { name: 'assurance.pdf', mimeType: 'application/pdf', buffer: Buffer.from(wp('eval', "require_once PSC_PATH . 'includes/fpdf/fpdf.php'; $pdf = new FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial','',16); $pdf->Cell(0,20,'Assurance scolaire - document de test'); echo base64_encode($pdf->Output('S'));"), 'base64') };
  const seeded = wp(`--require=${plugin}/bin/seed-planning-2.php`, 'seed-planning-2');
  const data = JSON.parse(seeded.split('\n').findLast(line => line.startsWith('{'))!);
  const oldMode = wp('eval', "echo get_option('psc_assurance_review_mode', 'auto');");
  wp('option', 'update', 'psc_assurance_review_mode', 'manual');
  const login = wp('eval', `global $wpdb; $token = bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'), array('token_hash'=>psc_hash_token($token),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)), array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$token),Psc_Mailer::form_page_url());`);
  const admin = await browser.newContext();
  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(login);
    await page.goto('http://localhost:8080/?psc_tab=cantine2');
    // L'assurance manquante de Chloé ne bloque plus Alice ni Bob.
    await expect(page.getByTestId('exception-grid')).toBeVisible();
    await page.getByTestId(`child-tab-${data.chloe_id}`).click();
    await expect(page.getByTestId('portal-section-cantine2').getByTestId('assurance-gate')).toBeVisible();
    await expect(page.getByTestId('exception-grid')).toBeHidden();
    const load = async (childId: number) => page.evaluate(async childId => {
        const cfg = (window as any).PSC;
        const r = await fetch(cfg.ajax_url, { method: 'POST', body: new URLSearchParams({ action: 'psc_load_month', child_id: String(childId), month: '2026-09', nonce: cfg.nonce, parent_nonce: cfg.parent_nonce }) });
        return { status: r.status, json: await r.json() };
      }, childId);
    expect((await load(data.alice_id)).status).toBe(200);
    const blocked = await load(data.chloe_id);
    expect(blocked.status).toBe(403);
    expect(blocked.json.data.code).toBe('assurance_missing');
    const upload = async () => {
      await page.getByTestId('portal-section-cantine2').locator('input[name="assurance_file"]').setInputFiles(pdf);
      await page.getByTestId('portal-section-cantine2').locator('.psc-assurance-card button[type="submit"]').click();
      await expect(page).toHaveURL(/psc_tab=cantine2/);
    };
    await upload();
    await expect(page.getByTestId('portal-section-cantine2').getByTestId('assurance-gate')).toContainText('En attente de validation');
    await page.screenshot({ path: testInfo.outputPath('assurance-mobile.png'), fullPage: true });
    const ap = await admin.newPage();
    await ap.goto('http://localhost:8080/wp-login.php');
    await ap.locator('#user_login').fill('admin');
    await ap.locator('#user_pass').fill('admin');
    await ap.locator('#wp-submit').click();
    const reviewUrl = `http://localhost:8080/wp-admin/admin.php?page=psc_assurances&child_id=${data.chloe_id}`;
    await ap.goto(reviewUrl);
    await expect(ap.locator('iframe')).toBeVisible();
    const docUrl = await ap.locator('iframe').getAttribute('src');
    const doc = await admin.request.get(docUrl!);
    expect(doc.status()).toBe(200);
    expect(doc.headers()['content-type']).toContain('application/pdf');
    expect((await doc.body()).toString()).toContain('%PDF');
    // Une famille ne peut pas emprunter l'URL de téléchargement réservée à la mairie.
    expect([400, 403]).toContain((await page.request.get(docUrl!)).status());
    await ap.screenshot({ path: testInfo.outputPath('assurance-mairie.png'), fullPage: true });
    await ap.locator('#review-note').fill('Le document doit couvrir cette année scolaire.');
    await ap.getByRole('button', { name: 'Refuser — demander un remplacement' }).click();
    await page.reload();
    await expect(page.getByTestId('portal-section-cantine2').getByTestId('assurance-gate')).toContainText('Le document doit couvrir cette année scolaire.');
    // Remplacer la pièce invalide la revue et la visionneuse déjà ouvertes.
    await ap.goto(reviewUrl);
    await upload();
    await ap.getByRole('button', { name: 'Valider l’assurance' }).click();
    await expect(ap.locator('.notice-error').filter({ hasText: 'Le document a changé' })).toContainText('Le document a changé');
    await expect(page.getByTestId('portal-section-cantine2').getByTestId('assurance-gate')).toContainText('En attente de validation');
    await ap.getByRole('button', { name: 'Valider l’assurance' }).click();
    await expect(ap.locator('.notice-success')).toContainText('Décision enregistrée');
    await page.reload();
    await expect(page.getByTestId('exception-grid')).toBeVisible();
    // Nouvelle pièce en mode manuel : elle repasse en attente.
    wp('eval', `Psc_Assurances::upsert_row(${data.chloe_id}, 'test/assurance.pdf', 'assurance.pdf');`);
    await page.goto(`http://localhost:8080/?psc_tab=cantine2&psc_child=${data.chloe_id}`);
    await expect(page.getByTestId('portal-section-cantine2').getByTestId('assurance-gate')).toBeVisible();
    wp('option', 'update', 'psc_assurance_review_mode', 'auto');
    await page.reload();
    await expect(page.getByTestId('exception-grid')).toBeVisible();
    wp('eval', `global $wpdb; $wpdb->update(psc_table('child_school_years'),array('assurance_file_path'=>null),array('child_id'=>${data.chloe_id}));`);
    await page.reload();
    await upload();
    await expect(page.getByTestId('exception-grid')).toBeVisible();
  } finally {
    wp('option', 'update', 'psc_assurance_review_mode', oldMode);
    await admin.close();
  }
});
