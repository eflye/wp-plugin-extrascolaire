/**
 * Commande fournisseur — e-mail de quantités (refonte).
 *
 * Un seul tableau, une ligne par jour de service, ventilation par régime
 * (Standard / Sans porc / Végétarien — les trois mutuellement exclusifs,
 * Total midi = somme des trois), Goûters détachés, ligne Total semaine.
 * Plus aucun découpage par classe : le fournisseur livre pour
 * l'établissement. Les zéros s'affichent — un zéro est une information de
 * commande, un tiret est une ambiguïté.
 *
 * Cas couverts par le jeu de données du seed :
 *   Aline    standard (CANT lun+mar, GM lun hors comptage, GS lun → goûter)
 *   Baptiste sans porc (CANT jeu, GS jeu → goûter)
 *   Chloé    sans viande → Végétarien (CANT mar)
 *   Théo     allergies alimentaires → compté dans AUCUNE colonne
 *
 * Le pied de mail est configurable (Modèles e-mails) : le test vérifie le
 * défaut rendu, puis la personnalisation (variables interpolées) et le
 * pied vide (aucun filet résiduel).
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ADMIN_BASE = `${APP_BASE}/wp-admin`;
// Le nom du conteneur vient de l'environnement CI (PSC_WP_CONTAINER, posé
// par le workflow après `docker compose ps`) : sur CI le projet compose ne
// s'appelle pas forcément comme le dépôt — le nom en dur faisait échouer
// TOUTE la commande de seed (« no such container »), d'où les runs rouges.
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_PLUGIN_PATH = '/var/www/html/wp-content/plugins/periscolaire-registration';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin';

// Adresse dédiée à ce spec : ne pas polluer/dépendre d'une adresse
// fournisseur réelle déjà configurée.
const SUPPLIER_EMAIL = 'e2e-fournisseur@example.test';

// bin/seed-supplier-order.php : adresse fixe de la famille de test.
const PARENT_EMAIL = 'fournisseur.e2e@example.test';

type Jour = 'lundi' | 'mardi' | 'jeudi' | 'vendredi';

interface SeedResult {
  semaine_debut: string;
  jours: Record<Jour, string>;
  year_id: number;
  parent_id: number;
  child_ids: number[];
  expected: {
    rows: Record<Jour, { standard: number; sans_porc: number; vegetarien: number; midi: number; gouter: number }>;
    totaux: { standard: number; sans_porc: number; vegetarien: number; midi: number; gouter: number };
    total: number;
    total_standard: number;
    total_sans_porc: number;
    total_vegetarien: number;
    total_gouters: number;
  };
}

function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec', CONTAINER, 'php', CONTAINER_WP_CLI,
      `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-supplier-order.php`,
      'seed-supplier-order',
      '--path=/var/www/html',
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

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
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

/** Texte brut du HTML de l'e-mail : la ventilation se vérifie sur le texte. */
function emailText(html: string): string {
  return html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

// serial : les deux tests partagent la même identité de seed (adresse
// e-mail fixe du parent) — seed() purge-et-recrée à chaque appel, une
// exécution en parallèle des deux tests provoquerait une course entre la
// purge de l'un et la lecture de l'autre.
test.describe.serial('Commande fournisseur (backoffice)', () => {

  test('configuration, quantités par jour et régime, envoi et historique', async ({ page }) => {
    const data = seed();
    const { semaine_debut, expected } = data;

    await loginAsAdmin(page);

    /* ---------------- 1. Réglages : e-mail fournisseur configurable ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_settings`);
    await page.locator('#psc-supplier-mail').fill(SUPPLIER_EMAIL);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.locator('.notice-success')).toBeVisible();
    await expect(page.locator('#psc-supplier-mail')).toHaveValue(SUPPLIER_EMAIL);

    /* ---------------- 2. Aperçu : une ligne par jour, ventilation par régime ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders&semaine_debut=${semaine_debut}`);

    // Les zéros s'affichent : un zéro est une information de commande,
    // un tiret est une ambiguïté.
    const COLS = ['standard', 'sansporc', 'vegetarien', 'midi', 'gouter'] as const;
    const KEYS = { standard: 'standard', sansporc: 'sans_porc', vegetarien: 'vegetarien', midi: 'midi', gouter: 'gouter' } as const;
    for (const [jour, row] of Object.entries(expected.rows)) {
      for (const col of COLS) {
        await expect(page.getByTestId(`supplier-cell-${jour}-${col}`)).toHaveText(String(row[KEYS[col]]));
      }
    }
    await expect(page.getByTestId('supplier-total-standard')).toHaveText(String(expected.totaux.standard));
    await expect(page.getByTestId('supplier-total-sansporc')).toHaveText(String(expected.totaux.sans_porc));
    await expect(page.getByTestId('supplier-total-vegetarien')).toHaveText(String(expected.totaux.vegetarien));
    await expect(page.getByTestId('supplier-total-midi')).toHaveText(String(expected.totaux.midi));
    await expect(page.getByTestId('supplier-total-gouter')).toHaveText(String(expected.totaux.gouter));

    /* ---------------- 3. Envoi : popin de visualisation puis confirmation ---------------- */
    await page.getByTestId('supplier-send-button').click();

    // La popin montre l'e-mail EXACT qui partira (sujet + rendu autonome),
    // rendu dans une iframe srcdoc.
    const modal = page.getByTestId('supplier-send-modal');
    await expect(modal).toBeVisible();
    await expect(page.getByTestId('supplier-modal-subject')).toContainText(`${expected.total} repas`);
    const previewFrame = page.frameLocator('[data-testid="supplier-modal-iframe"]');
    await expect(previewFrame.locator('body')).toContainText(`Total semaine ${expected.totaux.standard} ${expected.totaux.sans_porc} ${expected.totaux.vegetarien} ${expected.totaux.midi} ${expected.totaux.gouter}`);
    await expect(previewFrame.locator('body')).toContainText('Végétarien');

    // « Retour » referme sans envoyer : aucune commande, aucun mail.
    await page.getByTestId('supplier-modal-cancel').click();
    await expect(modal).toBeHidden();
    await expect(page.getByTestId('supplier-history-table')).toBeHidden();

    // Réouverture puis confirmation : l'e-mail part.
    await page.getByTestId('supplier-send-button').click();
    await expect(modal).toBeVisible();
    await page.getByTestId('supplier-modal-confirm').click();

    await expect(page.getByTestId('notice-sent')).toBeVisible();
    await expect(page.getByTestId('notice-sent')).toContainText('Commande envoyée au fournisseur');

    /* ---------------- Vérification e-mail — API Mailpit, jamais l'UI ---------------- */
    const mail = await findLatestMessage(SUPPLIER_EMAIL, 'Commande cantine');
    expect(mail.Subject).toContain(`${expected.total} repas`);

    const text = emailText(mail.HTML);

    // Ventilation par régime, jour par jour : Standard / Sans porc /
    // Végétarien / Total midi / Goûters. Un enfant « sans viande »
    // apparaît en Végétarien, jamais en Standard ; l'enfant allergique
    // n'est compté dans aucune colonne.
    for (const [jour, row] of Object.entries(expected.rows)) {
      // Le libellé du mail porte le jour + la date grise (d/m) : "Lundi 27/08 1 0 0 1 1".
      const dayLabel = jour.charAt(0).toUpperCase() + jour.slice(1);
      const date = data.jours[jour as Jour].split('-').slice(1).reverse().join('/');
      const rowText = `${dayLabel} ${date} ${row.standard} ${row.sans_porc} ${row.vegetarien} ${row.midi} ${row.gouter}`;
      expect(text, `ligne du ${jour} incorrecte : "${rowText}" attendu`).toContain(rowText);
    }

    // Total midi de chaque ligne = somme des trois régimes (découle des
    // valeurs ci-dessus) ; total semaine = somme des lignes.
    // Ligne Total semaine : le libellé tient lieu de première colonne, puis
    // les cinq totaux dans l'ordre des colonnes (Total midi = somme des
    // trois régimes).
    expect(text).toContain(`Total semaine ${expected.totaux.standard} ${expected.totaux.sans_porc} ${expected.totaux.vegetarien} ${expected.totaux.midi} ${expected.totaux.gouter}`);

    // Le piège du seed (Garderie Matin le même jour que la première Cantine)
    // ne doit jamais faire gonfler un total : vérifié sur le sujet, qui porte
    // le nombre suivi de « repas ».
    expect(mail.Subject).not.toContain(`${expected.total + 1} repas`);

    // Le pied de mail configurable par défaut est rendu.
    expect(text).toContain('Service périscolaire — Montgeroult Courcelles');

    /* ---------------- 4. Historique : entrée + contenu exact archivé ---------------- */
    await page.reload();
    const historyRow = page.locator('[data-testid^="supplier-history-row-"]').first();
    await expect(historyRow).toBeVisible();
    await expect(historyRow.locator('[data-testid^="supplier-history-total-"]')).toHaveText(String(expected.total));
    await expect(historyRow.locator('[data-testid^="supplier-history-email-"]')).toHaveText(SUPPLIER_EMAIL);

    await historyRow.locator('summary').click();
    await expect(historyRow.locator('[data-testid^="supplier-history-subject-"]')).toContainText(`${expected.total} repas`);
    const frame = page.frameLocator('[data-testid^="supplier-history-iframe-"]').first();
    // Le contenu archivé est le rendu autonome : ligne Total semaine
    // complète (ventilation par régime + goûters).
    await expect(frame.locator('body')).toContainText(`Total semaine ${expected.totaux.standard} ${expected.totaux.sans_porc} ${expected.totaux.vegetarien} ${expected.totaux.midi} ${expected.totaux.gouter}`);
    await expect(frame.locator('body')).toContainText('Végétarien');
  });

  test('pied de mail configurable : variables interpolées, vide → aucun filet résiduel', async ({ page }) => {
    const data = seed();

    await loginAsAdmin(page);

    /* ---------------- 1. Personnaliser le pied avec des variables ---------------- */
    // Sans {{site}} : l'assertion ne doit pas dépendre du titre du site,
    // qui diffère entre l'installation de CI et le poste de développement.
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_email_templates`);
    const footerField = page.locator('#tpl_supplier_order_footer');
    await footerField.fill('Pied de test — semaine du {{semaine}} : {{gouters}} goûters, {{standard}} repas standard.');
    // Deux boutons identiques existent (un dans un bloc replié) : le dernier
    // est celui du formulaire principal, toujours visible.
    await page.getByRole('button', { name: 'Enregistrer tous les modèles' }).last().click();
    await expect(page.locator('.notice-success, .updated').first()).toBeVisible();

    /* ---------------- 2. Envoyer : le pied interpole les variables ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_settings`);
    await page.locator('#psc-supplier-mail').fill(SUPPLIER_EMAIL);
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(page.locator('.notice-success')).toBeVisible();

    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders&semaine_debut=${data.semaine_debut}`);
    await page.getByTestId('supplier-send-button').click();
    await expect(page.getByTestId('supplier-send-modal')).toBeVisible();
    await page.getByTestId('supplier-modal-confirm').click();
    await expect(page.getByTestId('notice-sent')).toBeVisible();

    const mail = await findLatestMessage(SUPPLIER_EMAIL, 'Commande cantine');
    const text = emailText(mail.HTML);
    expect(text).toContain(`Pied de test — semaine du ${data.semaine_debut.split('-').reverse().join('/')} : ${data.expected.total_gouters} goûters, ${data.expected.total_standard} repas standard.`);
    // Toutes les variables ont été interpolées (aucun {{ }} résiduel).
    expect(text).not.toContain('{{');

    /* ---------------- 3. Pied vide : le bloc ET son filet disparaissent ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_email_templates`);
    await page.locator('#tpl_supplier_order_footer').fill('');
    await page.getByRole('button', { name: 'Enregistrer tous les modèles' }).last().click();
    await expect(page.locator('.notice-success, .updated').first()).toBeVisible();

    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_supplier_orders&semaine_debut=${data.semaine_debut}`);
    await page.getByTestId('supplier-send-button').click();
    await expect(page.getByTestId('supplier-send-modal')).toBeVisible();
    await page.getByTestId('supplier-modal-confirm').click();
    await expect(page.getByTestId('notice-sent')).toBeVisible();

    const mail2 = await findLatestMessage(SUPPLIER_EMAIL, 'Commande cantine');
    const text2 = emailText(mail2.HTML);
    expect(text2).not.toContain('Pied de test');
    // Aucun filet orphelin : le HTML ne contient plus la bordure du pied
    // (border-top EDEAE4 suivie du padding du pied) après la table.
    const afterTable = mail2.HTML.slice(mail2.HTML.lastIndexOf('Total semaine'));
    expect(afterTable).not.toContain('border-top:1px solid #EDEAE4;padding-top:14px');

    /* ---------------- 4. Retour au pied par défaut ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_email_templates`);
    await page.locator('#tpl_supplier_order_footer').fill('Service périscolaire — Montgeroult Courcelles');
    await page.getByRole('button', { name: 'Enregistrer tous les modèles' }).last().click();
    await expect(page.locator('.notice-success, .updated').first()).toBeVisible();
  });
});
