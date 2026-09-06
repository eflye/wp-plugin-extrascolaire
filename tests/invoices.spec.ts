/**
 * Facturation libre (v5.5.0) — la mairie génère quand elle veut, efface
 * et régénère, pour n'importe quel mois (passé, présent, FUTUR), et
 * récupère l'export des prélèvements SEPA du mois (fichier .ods).
 *
 * Vérifications en base via WP-CLI : le rendu peut mentir, pas la table
 * psc_invoices ni les fichiers du répertoire privé.
 *
 * Terrain : une famille en prélèvement (IBAN chiffré, mandat), un enfant
 * au forfait le jeudi — les jeudis d'octobre 2026 (mois FUTUR, horloge
 * figée au 7 septembre) produisent la facture sans aucune saisie.
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, unlinkSync } from 'node:fs';
import { inflateSync } from 'node:zlib';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

const EMAIL = 'factures.e2e@example.test';
const IBAN = 'FR7630006000011234567890189';
const MOIS = '2026-10';

function wpCli(args: string[]): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, ...args, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

function wpCliEval(php: string): string {
  return wpCli(['eval', php]);
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
}

function facturesUrl(): string {
  return `${APP_BASE}/wp-admin/admin.php?page=psc_factures&mois=${MOIS}`;
}

/** Terrain : famille prélèvement + enfant forfait le jeudi. Idempotent. */
function seedFamily(): number {
  return Number(wpCliEval(
    `global $wpdb;
     $email = '${EMAIL}';
     $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", $email));
     if (!$pid) {
       $wpdb->insert($wpdb->prefix.'psc_parents', array(
         'email' => $email, 'nom' => 'FacturesE2E', 'prenom' => 'Famille', 'active' => 1,
         'payment_mode' => 'prelevement',
         'sepa_iban' => psc_encrypt('${IBAN}'), 'sepa_bic' => 'AGRIFRPP882',
         'sepa_titulaire' => 'Famille FacturesE2E', 'sepa_mandate_ref' => 'E2E-0001',
         'created_at' => current_time('mysql'),
       ), array('%s','%s','%s','%d','%s','%s','%s','%s','%s','%s'));
       $pid = (int) $wpdb->insert_id;
     }
     $cid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id = %d AND prenom = 'Jules'", $pid));
     if (!$cid) {
       $wpdb->insert($wpdb->prefix.'psc_children', array('parent_id' => $pid, 'nom' => 'FacturesE2E', 'prenom' => 'Jules', 'statut' => 'actif', 'created_at' => current_time('mysql')), array('%d','%s','%s','%s','%s'));
       $cid = (int) $wpdb->insert_id;
       Psc_School_Years::enroll($cid, Psc_School_Years::active_id(), 'CE2', 'inscrit', current_time('mysql'));
       Psc_Planning::toggle_pattern($cid, '2026-2027', 4, 'FORF', true);
       Psc_Planning::flush_cache();
     }
     echo (string) $cid;`
  ).trim().split('\n').pop() ?? '0');
}

function cleanupFamily(): void {
  wpCliEval(
    `global $wpdb;
     $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${EMAIL}'));
     if ($pid) {
       foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id = %d", $pid)) as $cid) {
         Psc_Planning::delete_for_child((int) $cid);
         $wpdb->delete($wpdb->prefix.'psc_pattern', array('child_id' => (int) $cid), array('%d'));
         $wpdb->delete($wpdb->prefix.'psc_children', array('id' => (int) $cid), array('%d'));
       }
       $wpdb->delete($wpdb->prefix.'psc_parents', array('id' => $pid), array('%d'));
     }
     $wpdb->delete($wpdb->prefix.'psc_invoices', array('mois' => '${MOIS}'), array('%s'));`
  );
}

function invoiceCount(): string {
  return wpCliEval(
    `global $wpdb;
     $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${EMAIL}'));
     echo (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_invoices WHERE parent_id = %d AND mois = %s", $pid, '${MOIS}'));`
  ).trim().split('\n').pop() ?? '0';
}

/** Décompresse les flux du PDF et rend le texte/contenu brut (ISO-8859-1). */
function pdfContent(path: string): string {
  const buf = readFileSync(path);
  const parts: Buffer[] = [];
  const re = /stream\r?\n/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(buf)) !== null) {
    const start = m.index + m[0].length;
    const end = buf.indexOf('endstream', start);
    if (end === -1) continue;
    try {
      parts.push(inflateSync(buf.subarray(start, end)));
    } catch {
      // flux non compressé (dictionnaires, xref…) : ignoré
    }
  }
  return Buffer.concat(parts).toString('latin1');
}

test.describe('Facturation libre + export prélèvements', () => {
  test.beforeEach(async () => {
    cleanupFamily();
    seedFamily();
  });

  test.afterEach(async () => {
    cleanupFamily();
  });

  test('génération d\'un mois futur, régénération sans perte d\'envoi, suppression', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    const cid = seedFamily();
    // Le terrain doit être en place AVANT toute action : rythme forfait
    // du jeudi posé pour l'enfant semé.
    expect(
      wpCliEval(
        `global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}psc_pattern WHERE child_id = %d AND service_code = 'FORF'", ${cid}));`
      ).trim().split('\n').pop()
    ).toBe('1');

    await loginAsAdmin(page);
    await page.goto(facturesUrl());

    // 1. Génération du mois FUTUR — notice + ligne facture.
    await page.locator('button:has-text("Générer / Regénérer les factures de")').click();
    await expect(page.locator('.notice-updated:has-text("Factures générées avec succès")')).toBeVisible();
    expect(invoiceCount()).toBe('1');
    await expect(page.locator('td:has-text("FacturesE2E")')).toBeVisible();

    // 2. Décorrélation : envoi simulé en base, puis régénération — le
    // statut d'envoi est conservé (l'UI montre toujours ✔ Envoyée le).
    wpCliEval(
      `global $wpdb;
       $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${EMAIL}'));
       $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}psc_invoices SET sent_at = %s WHERE parent_id = %d AND mois = %s", '2026-09-06 10:00:00', $pid, '${MOIS}'));`
    );
    await page.goto(facturesUrl());
    await page.locator('button:has-text("Générer / Regénérer les factures de")').click();
    await expect(page.locator('.notice-updated:has-text("Factures générées avec succès")')).toBeVisible();
    await expect(page.locator('span:has-text("✔")').first()).toBeVisible();
    expect(
      wpCliEval(
        `global $wpdb;
         $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${EMAIL}'));
         echo (string) ($wpdb->get_var($wpdb->prepare("SELECT sent_at IS NOT NULL FROM {$wpdb->prefix}psc_invoices WHERE parent_id = %d AND mois = %s", $pid, '${MOIS}')) ?? '0');`
      ).trim().split('\n').pop()
    ).toBe('1');

    // 3. Suppression du mois : notice + plus rien en base.
    await page.locator('button:has-text("Supprimer les factures du mois")').click();
    await expect(page.locator('.notice-updated:has-text("Factures du mois supprimées")')).toBeVisible();
    expect(invoiceCount()).toBe('0');
    await expect(page.locator('td:has-text("FacturesE2E")')).toHaveCount(0);
  });

  test('PDF : seules les prestations dues figurent, logos bornés', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await loginAsAdmin(page);

    // 1. Sans logo : la famille n'a QUE le forfait — les autres types de
    // prestation n'apparaissent pas du tout (ni bloc, ni zéros).
    await page.goto(facturesUrl());
    await page.locator('button:has-text("Générer / Regénérer les factures de")').click();
    await expect(page.locator('.notice-updated:has-text("Factures générées avec succès")')).toBeVisible();

    const row = page.locator('tr:has-text("FacturesE2E")');
    const href = await row.locator('a:has-text("Télécharger")').getAttribute('href');
    expect(href, 'lien de téléchargement du PDF introuvable').toBeTruthy();
    const pdf = await page.request.get(href!.startsWith('http') ? href! : `${APP_BASE}${href}`);
    expect(pdf.status()).toBe(200);
    const pdfPath = `${MOIS}-facture.pdf`;
    const body = Buffer.from(await pdf.body());
    const { writeFileSync } = await import('node:fs');
    writeFileSync(pdfPath, body);

    const content = pdfContent(pdfPath);
    expect(content).toContain('Forfait');   // la prestation due
    expect(content).toContain('TOTAL');
    expect(content).not.toContain('Garderie'); // GM/GS absents du mois
    expect(content).not.toContain('Cantine');  // CANT absente du mois
    unlinkSync(pdfPath);

    // 2. Avec un logo PORTRAIT (300×1200 px) : la matrice de placement
    // du PDF doit rester dans la boîte 35×25 mm — jamais sous le bloc
    // d'adresse de la famille.
    wpCliEval(
      `$im = imagecreatetruecolor(300, 1200);
       $w = imagecolorallocate($im, 255, 255, 255);
       imagefill($im, 0, 0, $w);
       imagepng($im, '/tmp/logo-tall.png');`
    );
    const logoId = wpCli(['media', 'import', '/tmp/logo-tall.png', '--porcelain']).trim().split('\n').pop() ?? '';
    wpCli(['option', 'update', 'psc_billing_logo_left_id', logoId]);
    try {
      await page.goto(facturesUrl());
      await page.locator('button:has-text("Générer / Regénérer les factures de")').click();
      await expect(page.locator('.notice-updated:has-text("Factures générées avec succès")')).toBeVisible();

      const href2 = await page.locator('tr:has-text("FacturesE2E") a:has-text("Télécharger")').getAttribute('href');
      const pdf2 = await page.request.get(href2!.startsWith('http') ? href2! : `${APP_BASE}${href2}`);
      writeFileSync(pdfPath, Buffer.from(await pdf2.body()));
      const content2 = pdfContent(pdfPath);

      const matrices = [...content2.matchAll(/q ([\d.]+) 0 0 ([\d.]+) [-\d.]+ [-\d.]+ cm \/I\d+ Do Q/g)];
      expect(matrices.length, 'au moins une image dans le PDF').toBeGreaterThan(0);
      // Le flux PDF de FPDF est en points (1 mm = 72/25.4 pt) : la boîte
      // 35 × 25 mm devient ~99,2 × 70,9 pt.
      const pt = (mm: number) => (mm * 72) / 25.4;
      for (const [, w, h] of matrices) {
        expect(parseFloat(w), 'largeur du logo ≤ 35 mm').toBeLessThanOrEqual(pt(35) + 0.01);
        expect(parseFloat(h), 'hauteur du logo ≤ 25 mm').toBeLessThanOrEqual(pt(25) + 0.01);
      }
      unlinkSync(pdfPath);
    } finally {
      wpCli(['option', 'delete', 'psc_billing_logo_left_id']);
      wpCli(['post', 'delete', logoId, '--force']);
    }
  });

  test('export prélèvements : .ods OpenDocument avec IBAN, mandat et montant', async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await loginAsAdmin(page);

    // Sans factures générées : notice d'abord.
    await page.goto(facturesUrl());
    await page.locator('button:has-text("Export prélèvements (SEPA, .ods)")').click();
    await expect(page.locator('.notice-warning:has-text("Générez d\'abord les factures")')).toBeVisible();

    // Génération puis export : le fichier téléchargé est un ZIP ODF.
    await page.locator('button:has-text("Générer / Regénérer les factures de")').click();
    await expect(page.locator('.notice-updated:has-text("Factures générées avec succès")')).toBeVisible();

    const downloadPromise = page.waitForEvent('download');
    await page.locator('button:has-text("Export prélèvements (SEPA, .ods)")').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe(`prelevements-${MOIS}.ods`);

    const path = await download.path();
    const mimetype = execFileSync('unzip', ['-p', path!, 'mimetype'], { encoding: 'utf8' });
    expect(mimetype).toBe('application/vnd.oasis.opendocument.spreadsheet');

    const content = execFileSync('unzip', ['-p', path!, 'content.xml'], { encoding: 'utf8' });
    expect(content).toContain(IBAN);          // IBAN déchiffré, en clair pour la banque
    expect(content).toContain('E2E-0001');    // référence de mandat
    expect(content).toContain('FC-202610-');  // référence de facture
    expect(content).toContain('office:value="'); // montant typé numériquement
    expect(content).toContain('58.50');       // 5 jeudis d'octobre × 11,70 €

    // Le fichier est temporaire : téléchargé, jamais stocké côté serveur
    // (aucune trace attendue dans le répertoire privé des factures).
    const listing = wpCliEval(
      `echo (string) count(glob(psc_private_path('periscolaire/factures/${MOIS}/prelevements-*')) ?: array());`
    ).trim().split('\n').pop();
    expect(listing).toBe('0');

    unlinkSync(path!);
  });
});
