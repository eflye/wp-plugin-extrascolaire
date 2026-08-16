/**
 * Personnes autorisées à récupérer un enfant — historique inviolable.
 *
 * Deux parcours indépendants, chacun avec sa propre moitié de la seed
 * dédiée (bin/seed-pickup-persons.php) — cf. son doc-block pour le détail
 * de la séparation "living" / "onboarding". Comme les autres specs à
 * seed dédiée, seed() est appelée indépendamment par chaque test
 * (purge-et-recrée à chaque fois), donc test.describe.serial.
 *
 * Couverture :
 *   1. Onboarding : une personne autorisée saisie dans le sous-répéteur
 *      de l'étape "Enfants" du wizard public, transmise via
 *      children_json, matérialisée à l'approbation de la mairie en une
 *      ligne wp_psc_pickup_persons + une entrée d'historique 'ajout'
 *      (source='parent', même si c'est le clic d'approbation de la
 *      mairie qui déclenche l'écriture).
 *   2. Fiche vivante (portail famille) : ajout, modification et retrait
 *      depuis "Mes enfants" — chacun produit exactement une entrée
 *      d'historique — puis consultation de la liste courante (vide après
 *      retrait) et de l'historique complet (3 entrées) côté mairie,
 *      lecture seule.
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const APP_PAGE = `${APP_BASE}/?page_id=6`;
const ADMIN_BASE = `${APP_BASE}/wp-admin`;

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_WP_PATH = '/var/www/html';
const CONTAINER_PLUGIN_PATH = `${CONTAINER_WP_PATH}/wp-content/plugins/periscolaire-registration`;

const ADMIN_USER = process.env.PSC_WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.PSC_WP_ADMIN_PASS ?? 'admin';

interface SeedResult {
  living_parent_email: string;
  living_parent_id: number;
  living_child_id: number;
  living_child_prenom: string;
  living_child_nom: string;
  onboarding_email: string;
}

/**
 * Exécute bin/seed-pickup-persons.php dans le conteneur via WP-CLI et
 * parse sa dernière ligne JSON — même contrat que les autres specs à
 * seed dédiée (le seed est LA source de vérité, jamais réimplémenté
 * côté Node).
 */
function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec',
      CONTAINER,
      'php',
      CONTAINER_WP_CLI,
      `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-pickup-persons.php`,
      'seed-pickup-persons',
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
    throw new Error(`seed-pickup-persons : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
  }
  return JSON.parse(jsonLine) as SeedResult;
}

/** Requête WP-CLI eval en lecture seule, pour vérifier un invariant côté base. */
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

/** Connexion famille sans mot de passe — même séquence que les autres specs. */
async function loginAsFamily(page: Page, email: string, parentId: number): Promise<void> {
  await page.goto(APP_PAGE);
  await page.getByTestId('login-email-input').fill(email);

  const anyNotice = page.locator('[data-testid^="notice-"]');
  await Promise.all([
    anyNotice.waitFor({ state: 'visible', timeout: 5_000 }),
    page.getByTestId('login-submit-button').click(),
  ]);
  await expect(page.getByTestId('notice-link_sent')).toBeVisible();

  const loginMail = await findLatestMessage(email, "Votre lien d'accès aux inscriptions périscolaires");
  const loginLinkMatch = loginMail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(loginLinkMatch, `lien de connexion introuvable :\n${loginMail.Text}`).toBeTruthy();
  const loginLink = loginLinkMatch![0];
  expect(loginLink).toContain(`psc_pid=${parentId}&`);

  await page.goto(loginLink);
  await expect(page.getByTestId('account-bar')).toBeVisible();
}

test.describe.serial('Personnes autorisées à récupérer un enfant', () => {

test('onboarding — une personne autorisée saisie à la demande devient une entrée d\'historique "ajout"', async ({ page }) => {
  const data = seed();

  /* ---------------- Wizard public : Coordonnées ---------------- */
  await page.goto(APP_PAGE);
  await page.locator('#psc-req-email').fill(data.onboarding_email);
  await page.locator('#psc-req-prenom').fill('Onboarding');
  await page.locator('#psc-req-nom').fill('E2E');
  await page.locator('#psc-req-tel').fill('0600000001');
  await page.locator('#psc-req-adresse').fill('1 rue de Test');
  await page.locator('#psc-req-cp').fill('95000');
  await page.locator('#psc-req-ville').fill('Testville');
  await page.getByTestId('wizard-next').click();

  /* ---------------- Enfants : un enfant + une personne autorisée ---------------- */
  await page.locator('#psc-cp-0').fill('Timéo');
  await page.locator('#psc-cn-0').fill('Onboarding');
  await page.locator('#psc-cc-0').selectOption('CP');
  await page.locator('#psc-cb-0').fill('2019-03-10');
  await page.locator('#psc-ca-0').setInputFiles({
    name: 'assurance.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4 e2e pickup-persons spec'),
  });

  await page.getByTestId('add-pickup-person-0').click();
  const pickupRow = page.locator('.psc-wizard-pickup-row').first();
  await pickupRow.locator('input[name^="child_pickup_prenom_"]').fill('Grand');
  await pickupRow.locator('input[name^="child_pickup_nom_"]').fill('Parent');
  await pickupRow.locator('input[name^="child_pickup_telephone_"]').fill('0600000099');
  await pickupRow.locator('input[name^="child_pickup_lien_"]').fill('Grand-parent');
  await pickupRow.locator('input[name^="child_pickup_piece_identite_"]').check();

  await page.getByTestId('wizard-next').click();

  /* ---------------- Paiement (chèque/espèces par défaut) ---------------- */
  await page.getByTestId('wizard-next').click();

  /* ---------------- Règlement + envoi ---------------- */
  await page.locator('input[name="reglement_accepted"]').check();
  await page.getByTestId('wizard-submit').click();
  await expect(page.getByTestId('notice-request_sent')).toBeVisible();

  /* ---------------- Confirmation d'adresse (lien reçu par e-mail) ---------------- */
  const verifyMail = await findLatestMessage(data.onboarding_email, 'Confirmez votre demande');
  const verifyLinkMatch = verifyMail.Text.match(/https?:\/\/\S*psc_req=\d+&psc_vtoken=[0-9a-f]+/);
  expect(verifyLinkMatch, `lien de vérification introuvable dans l'e-mail :\n${verifyMail.Text}`).toBeTruthy();
  await page.goto(verifyLinkMatch![0]);

  /* ---------------- La mairie approuve la demande ---------------- */
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_requests`);
  const requestBox = page.locator('.psc-request', { hasText: data.onboarding_email });
  await expect(requestBox).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await requestBox.locator('.psc-approve-form button.button-primary').click();
  await expect(page.locator('.notice-success')).toContainText('validée');

  /* ---------------- Vérification base ---------------- */
  const childId = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT c.id FROM {$wpdb->prefix}psc_children c
       JOIN {$wpdb->prefix}psc_parents p ON p.id = c.parent_id
       WHERE p.email = %s", '${data.onboarding_email}'
    ));`
  );
  expect(Number(childId), 'enfant créé à l\'approbation').toBeGreaterThan(0);

  const row = wpCliEval(
    `global $wpdb; $r = $wpdb->get_row($wpdb->prepare(
      "SELECT action, source, person_snapshot FROM {$wpdb->prefix}psc_pickup_history
       WHERE child_id = %d ORDER BY id DESC LIMIT 1", ${childId}
    )); echo json_encode($r);`
  );
  const parsed = JSON.parse(row);
  expect(parsed.action).toBe('ajout');
  expect(parsed.source).toBe('parent');
  const snap = JSON.parse(parsed.person_snapshot);
  expect(snap.prenom).toBe('Grand');
  expect(snap.nom).toBe('Parent');
  expect(snap.telephone).toBe('0600000099');
  expect(snap.piece_identite).toBe(1);

  const currentCount = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pickup_persons WHERE child_id = %d AND statut = 'active'", ${childId}
    ));`
  );
  expect(currentCount).toBe('1');
});

test('fiche vivante — ajout, modification, retrait, puis consultation mairie', async ({ page }) => {
  const data = seed();

  await loginAsFamily(page, data.living_parent_email, data.living_parent_id);
  await page.goto(`${APP_BASE}/?psc_tab=enfants`);

  /* ---------------- Ajout ---------------- */
  await page.locator('[data-pickup-add-trigger]').click();
  await expect(page.getByTestId('pickup-modal')).toBeVisible();
  await page.locator('#psc-pickup-prenom').fill('Sophie');
  await page.locator('#psc-pickup-nom').fill('Martin');
  await page.locator('#psc-pickup-telephone').fill('0600000002');
  await page.locator('#psc-pickup-lien').fill('Nounou');
  await page.getByTestId('pickup-submit').click();
  await expect(page.getByTestId('notice-pickup_added')).toBeVisible();

  const table = page.getByTestId(`pickup-table-${data.living_child_id}`);
  await expect(table).toContainText('Sophie');

  /* ---------------- Modification ---------------- */
  await table.locator('tr', { hasText: 'Sophie' }).getByRole('button', { name: 'Modifier' }).click();
  await expect(page.getByTestId('pickup-modal')).toBeVisible();
  await expect(page.locator('#psc-pickup-prenom')).toHaveValue('Sophie');
  await page.locator('#psc-pickup-telephone').fill('0600000003');
  await page.getByTestId('pickup-submit').click();
  await expect(page.getByTestId('notice-pickup_updated')).toBeVisible();
  await expect(page.getByTestId(`pickup-table-${data.living_child_id}`)).toContainText('0600000003');

  /* ---------------- Retrait ---------------- */
  page.once('dialog', (dialog) => dialog.accept());
  await page
    .getByTestId(`pickup-table-${data.living_child_id}`)
    .locator('tr', { hasText: 'Sophie' })
    .getByRole('button', { name: 'Retirer' })
    .click();
  await expect(page.getByTestId('notice-pickup_removed')).toBeVisible();
  await expect(page.getByTestId(`pickup-empty-${data.living_child_id}`)).toBeVisible();

  /* ---------------- Consultation mairie : liste courante + historique ---------------- */
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_pickup_persons&child_id=${data.living_child_id}`);
  await expect(page.locator('body')).toContainText('Aucune personne autorisée déclarée');

  const bodyText = await page.locator('body').innerText();
  expect(bodyText).toContain('Ajout');
  expect(bodyText).toContain('Modification');
  expect(bodyText).toContain('Retrait');
  expect(bodyText).toContain('Sophie Martin');

  /* ---------------- Vérification base : exactement 3 entrées, jamais modifiées ---------------- */
  const historyCount = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pickup_history WHERE child_id = %d", ${data.living_child_id}
    ));`
  );
  expect(historyCount).toBe('3');

  const actions = wpCliEval(
    `global $wpdb; echo implode(',', $wpdb->get_col($wpdb->prepare(
      "SELECT action FROM {$wpdb->prefix}psc_pickup_history WHERE child_id = %d ORDER BY id ASC", ${data.living_child_id}
    )));`
  );
  expect(actions).toBe('ajout,modification,retrait');
});

});
