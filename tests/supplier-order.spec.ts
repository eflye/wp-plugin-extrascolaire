/**
 * Commande fournisseur (cantine) — parcours administrateur.
 *
 * Contrairement à parent-connu.spec.ts, ce parcours ne concerne que le
 * backoffice (aucune famille impliquée) : pas d'habillage vidéo, un seul
 * profil, une seed dédiée (bin/seed-supplier-order.php) plutôt que
 * journeys/parent-connu.md. Exclu du profil "demo" (cf. playwright.config.ts).
 *
 * Couverture :
 *   1. L'adresse e-mail fournisseur se configure depuis Réglages (UI réelle,
 *      pas une écriture directe en base).
 *   2. L'aperçu par classe x jour est calculé correctement à partir des
 *      inscriptions Cantine (CANT) — et ignore les autres prestations (le
 *      seed inclut une inscription Garderie Matin comme piège).
 *   3. L'envoi déclenche un e-mail réellement reçu par le fournisseur
 *      (vérifié via l'API Mailpit, jamais l'UI), avec le bon sujet et le
 *      bon contenu.
 *   4. L'envoi crée une entrée d'historique consultable, avec le contenu
 *      exact de l'e-mail envoyé et l'horodatage.
 *   5. Annulation de la cantine pour une classe entière (sortie
 *      scolaire...) : écran d'avertissement avant toute suppression,
 *      confirmation explicite requise, e-mail à la famille avec le motif
 *      — et seule l'inscription Cantine du piège du seed est supprimée,
 *      jamais la Garderie Matin du même jour.
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ADMIN_BASE = `${APP_BASE}/wp-admin`;

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_WP_PATH = '/var/www/html';
const CONTAINER_PLUGIN_PATH = `${CONTAINER_WP_PATH}/wp-content/plugins/periscolaire-registration`;

const ADMIN_USER = process.env.PSC_WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.PSC_WP_ADMIN_PASS ?? 'admin';

// Domaine .test (RFC 2606) : jamais routable, adresse dédiée au spec pour
// ne pas polluer/dépendre d'une adresse fournisseur réelle déjà configurée.
const SUPPLIER_EMAIL = 'e2e-fournisseur@example.test';

// bin/seed-supplier-order.php : adresse fixe de la famille de test.
const PARENT_EMAIL = 'fournisseur.e2e@example.test';

type Jour = 'lundi' | 'mardi' | 'jeudi' | 'vendredi';

interface SeedResult {
  semaine_debut: string;
  jours: Record<Jour, string>;
  trimestre_id: number;
  parent_id: number;
  child_ids: number[];
  expected: {
    classes: Record<string, string>;
    counts: Record<string, Record<Jour, number>>;
    totaux_jour: Record<Jour, number>;
    totaux_classe: Record<string, number>;
    total: number;
  };
}

/**
 * Exécute bin/seed-supplier-order.php dans le conteneur via WP-CLI et
 * parse sa dernière ligne JSON — même contrat que playwright/global-setup.ts
 * (le seed est LA source de vérité, jamais réimplémenté côté Node).
 */
function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec',
      CONTAINER,
      'php',
      CONTAINER_WP_CLI,
      `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-supplier-order.php`,
      'seed-supplier-order',
      `--path=${CONTAINER_WP_PATH}`,
      '--allow-root',
    ],
    { encoding: 'utf8' }
  );

  const jsonLine = output
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .reverse()
    .find((l) => l.startsWith('{') && l.endsWith('}'));

  if (!jsonLine) {
    throw new Error(`seed-supplier-order : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
  }
  return JSON.parse(jsonLine) as SeedResult;
}

/**
 * Requête WP-CLI eval en lecture seule, pour vérifier un invariant côté
 * base indépendamment de ce que l'UI affiche (ex : la Garderie Matin du
 * piège du seed n'a pas été supprimée par erreur en même temps que la
 * Cantine — un bug corrélé au comptage UI ne serait pas détecté par une
 * simple lecture de la page).
 */
function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, `--path=${CONTAINER_WP_PATH}`, '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(ADMIN_USER);
  await page.locator('#user_pass').fill(ADMIN_PASS);
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

// serial : les deux tests partagent la même identité de seed (adresse
// e-mail fixe du parent) — seed() purge-et-recrée à chaque appel, une
// exécution en parallèle des deux tests provoquerait une course entre la
// purge de l'un et la lecture de l'autre.
test.describe.serial('Commande fournisseur (backoffice)', () => {

test('configuration, calcul par classe, envoi et historique', async ({ page }) => {
  const data = seed();
  const { semaine_debut, expected } = data;

  await loginAsAdmin(page);

  /* ---------------- 1. Réglages : e-mail fournisseur configurable ---------------- */
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_settings`);
  await page.locator('#psc-supplier-mail').fill(SUPPLIER_EMAIL);
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.locator('.notice-success')).toBeVisible();
  await expect(page.locator('#psc-supplier-mail')).toHaveValue(SUPPLIER_EMAIL);

  /* ---------------- 2. Aperçu : calcul par classe x jour ---------------- */
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders&semaine_debut=${semaine_debut}`);

  for (const [classe, parJour] of Object.entries(expected.counts)) {
    for (const [jour, n] of Object.entries(parJour)) {
      await expect(page.getByTestId(`supplier-cell-${classe}-${jour}`)).toHaveText(n > 0 ? String(n) : '—');
    }
    await expect(page.getByTestId(`supplier-total-classe-${classe}`)).toHaveText(String(expected.totaux_classe[classe]));
  }
  for (const [jour, n] of Object.entries(expected.totaux_jour)) {
    await expect(page.getByTestId(`supplier-total-jour-${jour}`)).toHaveText(String(n));
  }
  await expect(page.getByTestId('supplier-total-general')).toHaveText(String(expected.total));

  /* ---------------- 3. Envoi (confirm() natif accepté automatiquement) ---------------- */
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('supplier-send-button').click();

  await expect(page.getByTestId('notice-sent')).toBeVisible();
  await expect(page.getByTestId('notice-sent')).toContainText('Commande envoyée au fournisseur');

  /* ---------------- Vérification e-mail — API Mailpit, jamais l'UI ---------------- */
  const mail = await findLatestMessage(SUPPLIER_EMAIL, 'Commande cantine');
  expect(mail.Subject).toContain(`${expected.total} repas`);
  expect(mail.Text).toContain(String(expected.total));
  for (const classe of Object.keys(expected.classes)) {
    expect(mail.HTML, `classe ${classe} absente du corps de l'e-mail`).toContain(classe);
  }
  // Le piège du seed (Garderie Matin le même jour que la première Cantine
  // de la classe CP) ne doit jamais faire gonfler un total.
  expect(mail.Text).not.toContain(String(expected.total + 1));

  /* ---------------- 4. Historique : entrée + contenu exact archivé ---------------- */
  await page.reload();
  const historyRow = page.locator('[data-testid^="supplier-history-row-"]').first();
  await expect(historyRow).toBeVisible();
  await expect(historyRow.locator('[data-testid^="supplier-history-total-"]')).toHaveText(String(expected.total));
  await expect(historyRow.locator('[data-testid^="supplier-history-email-"]')).toHaveText(SUPPLIER_EMAIL);

  await historyRow.locator('summary').click();
  await expect(historyRow.locator('[data-testid^="supplier-history-subject-"]')).toContainText(`${expected.total} repas`);
  const frame = page.frameLocator('[data-testid^="supplier-history-iframe-"]').first();
  await expect(frame.locator('body')).toContainText(`${expected.total} repas`);
  for (const classe of Object.keys(expected.classes)) {
    await expect(frame.locator('body')).toContainText(classe);
  }
});

test('annulation de la cantine pour une classe (sortie scolaire)', async ({ page }) => {
  const data = seed();
  const { jours } = data;
  const reason = 'Sortie scolaire à la ferme pédagogique (e2e)';

  await loginAsAdmin(page);
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders`);

  /* ---------------- Premier passage : rien n'est supprimé, avertissement seul ---------------- */
  await page.getByTestId('cantine-date-input').fill(jours.lundi);
  await page.getByTestId('cantine-classe-select').selectOption('CP');
  await page.getByTestId('cantine-reason-input').fill(reason);
  await page.getByTestId('cantine-cancel-submit').click();

  await expect(page.getByTestId('notice-cantine_confirm_needed')).toBeVisible();
  await expect(page.getByTestId('cantine-pending-warning')).toContainText('1 inscription(s)');
  await expect(page.getByTestId('cantine-pending-warning')).toContainText('Aline Test');
  await expect(page.getByTestId('cantine-pending-warning')).toContainText(reason);

  // Rien n'a encore été supprimé : les deux inscriptions du lundi de CP
  // (Cantine + Garderie Matin, le piège du seed) doivent toujours exister.
  const beforeConfirm = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_registrations WHERE jour_date = %s", '${jours.lundi}'
    ));`
  );
  expect(beforeConfirm).toBe('2');

  /* ---------------- Confirmation : suppression ciblée + e-mail ---------------- */
  await page.getByTestId('cantine-confirm-button').click();

  await expect(page.getByTestId('notice-cantine_cancelled')).toBeVisible();
  await expect(page.getByTestId('notice-cantine_cancelled')).toContainText('1 inscription(s) supprimée(s)');

  // La Garderie Matin du même jour doit survivre : seule la Cantine a été
  // retirée (Psc_Supplier_Orders::cantine_registrations_for_class_day()
  // filtre strictement service = 'CANT').
  const remainingServices = wpCliEval(
    `global $wpdb; echo implode(',', $wpdb->get_col($wpdb->prepare(
      "SELECT service FROM {$wpdb->prefix}psc_registrations WHERE jour_date = %s", '${jours.lundi}'
    )));`
  );
  expect(remainingServices).toBe('GM');

  const mail = await findLatestMessage(PARENT_EMAIL, 'Cantine annulée');
  expect(mail.Subject).toContain('CP');
  expect(mail.Text).toContain(reason);
  expect(mail.Text).toContain('Aline Test');
  expect(mail.Text).not.toContain('Baptiste'); // seul l'enfant concerné doit apparaître

  /* ---------------- Redéclencher sur le même jour : plus rien à annuler ---------------- */
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders`);
  await page.getByTestId('cantine-date-input').fill(jours.lundi);
  await page.getByTestId('cantine-classe-select').selectOption('CP');
  await page.getByTestId('cantine-reason-input').fill(reason);
  await page.getByTestId('cantine-cancel-submit').click();
  await expect(page.getByTestId('notice-cantine_none')).toBeVisible();
});

});
