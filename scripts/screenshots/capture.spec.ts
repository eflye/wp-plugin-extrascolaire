import { test, expect, type Locator, type Page } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import * as path from 'node:path';
import { findLatestMessage } from '../../helpers/mailpit';

const APP_BASE = process.env.PSC_APP_BASE ?? 'http://localhost:8080';
const ADMIN_BASE = `${APP_BASE}/wp-admin`;
const ADMIN_USER = process.env.PSC_WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.PSC_WP_ADMIN_PASS ?? 'admin';
const SCREENSHOTS_DIR = path.resolve(__dirname, '../../documentation/assets/screenshots');
const SEED_RESULT_FILE = path.resolve(__dirname, '../../.playwright/seed.screenshots.json');

interface SeedResult {
  parent_email: string;
  parent_id: number;
  noe_id: number;
  alma_id: number;
  sophie_id: number;
  request_email: string;
  year_id: number;
  year_key: string;
  next_year_id: number;
  next_week: string;
  invoice_mois_envoye: string;
  invoice_mois_a_envoyer: string;
  form_page_url: string;
}

function seed(): SeedResult {
  return JSON.parse(readFileSync(SEED_RESULT_FILE, 'utf8')) as SeedResult;
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(ADMIN_USER);
  await page.locator('#user_pass').fill(ADMIN_PASS);
  await page.locator('#wp-submit').click();
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

async function openAdmin(page: Page, relativeUrl: string): Promise<void> {
  await loginAsAdmin(page);
  await page.goto(`${ADMIN_BASE}/${relativeUrl}`);
  await expect(page.locator('#wpadminbar')).toBeVisible();
  await expect(page.getByText(/Horloge figée \(tests\)/)).toHaveCount(0);
}

async function loginAsFamily(page: Page): Promise<void> {
  const fixture = seed();
  await page.goto(fixture.form_page_url);
  await page.getByTestId('login-email-input').fill(fixture.parent_email);
  await Promise.all([
    page.locator('[data-testid^="notice-"]').waitFor({ state: 'visible' }),
    page.getByTestId('login-submit-button').click(),
  ]);
  await expect(page.getByTestId('notice-link_sent')).toBeVisible();

  const loginMail = await findLatestMessage(
    fixture.parent_email,
    "Votre lien d'accès aux inscriptions périscolaires"
  );
  const loginLink = loginMail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/)?.[0];
  expect(loginLink, `Lien de connexion introuvable dans l'e-mail :\n${loginMail.Text}`).toBeTruthy();
  await page.goto(loginLink!);
  await expect(page.getByTestId('account-bar')).toBeVisible();
}

async function frame(page: Page, locator: Locator): Promise<void> {
  await expect(locator).toBeVisible();
  await locator.scrollIntoViewIfNeeded();
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
}

async function capture(page: Page, filename: string, fullPage = false): Promise<void> {
  await page.screenshot({ path: path.join(SCREENSHOTS_DIR, filename), fullPage });
}

test.beforeAll(() => mkdirSync(SCREENSHOTS_DIR, { recursive: true }));

test.describe.serial('Captures de la documentation administrateur', () => {
  test('tableau de bord — section À faire', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_dashboard');
    const section = page.getByRole('heading', { name: 'À faire' });
    await frame(page, section);
    await expect(page.getByRole('link', { name: /demande.+d.inscription en attente de traitement/i })).toBeVisible();
    await capture(page, 'dashboard-a-faire.png');
  });

  test('menu Périscolaire — six sections', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_dashboard');
    const menu = page.locator('#toplevel_page_psc_dashboard');
    await frame(page, menu);
    for (const label of [
      'À traiter',
      'Cantine & garderie',
      'Familles',
      'Facturation',
      'Communication',
      'Configuration',
    ]) {
      await expect(menu.locator('.psc-menu-section', { hasText: label })).toBeVisible();
    }
    await capture(page, 'menu-periscolaire-sections.png');
  });

  test('année scolaire — formulaire de création', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_school_calendar_v2&tab=historique');
    await frame(page, page.getByRole('heading', { name: 'Créer une année scolaire' }));
    await capture(page, 'annee-scolaire-creer.png');
  });

  test('année scolaire — liste des années existantes', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_school_calendar_v2&tab=historique');
    await frame(page, page.getByRole('heading', { name: 'Années existantes' }));
    await expect(page.locator('input[value="Documentation active"]')).toBeVisible();
    await capture(page, 'annee-scolaire-liste.png');
  });

  test('calendrier — vue du mois courant', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_school_calendar_v2');
    const monthHeading = page.getByRole('heading', { level: 2, name: /20\d{2}/ }).first();
    await frame(page, monthHeading);
    await capture(page, 'calendrier-vue-mois.png');
  });

  test('calendrier — import officiel', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_school_calendar_v2&tab=historique');
    const heading = page.getByRole('heading', { name: /calendrier officiel/i }).first();
    await frame(page, heading);
    await capture(page, 'calendrier-import-officiel.png');
  });

  test('réglages — tarifs des prestations', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_settings');
    await frame(page, page.getByRole('heading', { name: 'Tarifs des prestations' }));
    await capture(page, 'services-tarifs.png');
  });

  test('portail — signalement alimentaire', async ({ page }) => {
    await loginAsFamily(page);
    const url = new URL(seed().form_page_url);
    url.searchParams.set('psc_tab', 'enfants');
    await page.goto(url.toString());
    const signal = page.locator('input[name="new_food_signal"]');
    await signal.check();
    await expect(signal).toBeChecked();
    await frame(page, signal);
    await capture(page, 'regimes-signalement-alimentaire.png');
  });

  test("modèles d'e-mails — liste complète", async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_email_templates');
    await frame(page, page.getByRole('heading', { name: /modèles d.e-mails/i }).first());
    await capture(page, 'modeles-emails-liste.png', true);
  });

  test('modération — demandes en attente', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_requests');
    await frame(page, page.getByRole('heading', { name: 'En attente' }).first());
    await expect(page.getByText(seed().request_email)).toBeVisible();
    await capture(page, 'moderation-demandes-attente.png');
  });

  test('portail — annulation d’une prestation', async ({ page }) => {
    await loginAsFamily(page);
    await page.getByRole('button', { name: 'Annulation prestations' }).click();
    const cancel = page.getByRole('button', { name: /annuler/i }).first();
    await frame(page, cancel);
    await capture(page, 'annulation-absence-famille.png');
  });

  test('menus de cantine — saisie de la semaine du seed', async ({ page }) => {
    await openAdmin(page, `admin.php?page=psc_menus&week=${seed().next_week}`);
    const form = page.locator('form').filter({ has: page.locator('textarea') }).first();
    await frame(page, form);
    await expect(form.locator('textarea').first()).not.toHaveValue('');
    await capture(page, 'menus-cantine-saisie.png');
  });

  test('documents — assurance de Noé en attente', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_assurances');
    const noe = page.getByRole('row', { name: /Rivière Noé.+En attente/i });
    await frame(page, noe);
    await capture(page, 'documents-assurance-liste.png');
  });

  test('personnes autorisées — Sophie Martin', async ({ page }) => {
    await openAdmin(page, `admin.php?page=psc_pickup_persons&child_id=${seed().noe_id}`);
    const sophie = page.getByRole('row', { name: /Sophie Martin.+Grand-mère/i }).first();
    await frame(page, sophie);
    await capture(page, 'personnes-autorisees-liste.png');
  });

  test('factures — génération', async ({ page }) => {
    await openAdmin(page, `admin.php?page=psc_factures&mois=${seed().invoice_mois_a_envoyer}`);
    await expect(page.locator('select[name="mois"]').first()).toHaveValue(seed().invoice_mois_a_envoyer);
    const button = page.getByRole('button', { name: /Générer \/ Regénérer les factures de/i });
    await frame(page, button);
    await capture(page, 'factures-generer.png');
  });

  test('factures — exports par mois', async ({ page }) => {
    await openAdmin(page, `admin.php?page=psc_factures&mois=${seed().invoice_mois_a_envoyer}`);
    await expect(page.locator('select[name="mois"]').first()).toHaveValue(seed().invoice_mois_a_envoyer);
    await frame(page, page.getByRole('heading', { name: 'Exports par mois' }));
    await capture(page, 'factures-exports.png');
  });

  test('factures — export pain.008', async ({ page }) => {
    await openAdmin(page, `admin.php?page=psc_factures&mois=${seed().invoice_mois_a_envoyer}`);
    await expect(page.locator('select[name="mois"]').first()).toHaveValue(seed().invoice_mois_a_envoyer);
    const exportButton = page.getByRole('button', { name: /pain\.008/i }).first();
    await frame(page, exportButton);
    await capture(page, 'sepa-export-pain008.png');
  });

  test('réglages — fenêtre de réinscription', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_settings');
    await frame(page, page.getByRole('heading', { name: 'Fenêtre de réinscription' }));
    await capture(page, 'reinscription-fenetre.png');
  });

  test('passage de classe — récapitulatif', async ({ page }) => {
    await openAdmin(page, 'admin.php?page=psc_passage_annee');
    const noe = page.getByText('Noé Rivière', { exact: true }).first();
    await frame(page, noe);
    await expect(page.getByText('Alma Rivière', { exact: true })).toBeVisible();
    await capture(page, 'passage-classe-recapitulatif.png');
  });

  test('WordPress — export des données personnelles', async ({ page }) => {
    await openAdmin(page, 'export-personal-data.php');
    const email = page.locator('#username_or_email_for_privacy_request');
    await email.fill(seed().parent_email);
    await frame(page, email);
    await capture(page, 'rgpd-export-wp.png');
  });

  test('WordPress — plugin activé', async ({ page }) => {
    await openAdmin(page, 'plugins.php');
    const plugin = page.locator('tr[data-plugin="periscolaire-registration/periscolaire-registration.php"]');
    await frame(page, plugin);
    await expect(plugin).toHaveClass(/active/);
    await capture(page, 'plugins-activation.png');
  });
});
