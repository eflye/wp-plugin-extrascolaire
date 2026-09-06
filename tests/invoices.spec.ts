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
  await page.waitForURL('**/wp-admin/**');
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
