/**
 * Entité "année scolaire" — passage d'année (backoffice) et réinscription
 * annuelle (portail famille).
 *
 * Deux parcours indépendants, chacun avec sa propre moitié de la seed
 * dédiée (bin/seed-school-year-promotion.php) : "promo" (Camille en CP,
 * Hugo en CM2) pour le passage d'année admin, "reinscription" (Léa en
 * CE1) pour la réinscription famille — cf. le doc-block du seed pour le
 * détail de cette séparation. Comme tests/supplier-order.spec.ts, seed()
 * est appelée indépendamment par chaque test (purge-et-recrée à chaque
 * fois), donc test.describe.serial pour éviter toute course entre eux.
 *
 * Couverture :
 *   1. Réinscription (famille) : l'onglet n'apparaît que pendant la
 *      fenêtre ouverte, propose la bonne année cible et la bonne classe
 *      proposée, exige le justificatif d'assurance et l'acceptation du
 *      règlement, et n'écrase jamais la classe historisée de l'année en
 *      cours.
 *   2. Passage d'année (admin) : récapitulatif avant toute écriture,
 *      correction manuelle d'une ligne réellement appliquée, sortie de
 *      fin de cycle (CM2 -> sortie), classe de l'année précédente
 *      toujours consultable après coup, et l'année cible ne devient pas
 *      automatiquement active (action distincte et explicite).
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
  year_a_id: number;
  year_a_label: string;
  year_b_id: number;
  year_b_label: string;
  promo_parent_email: string;
  promo_parent_id: number;
  camille_id: number;
  hugo_id: number;
  reins_parent_email: string;
  reins_parent_id: number;
  lea_id: number;
}

/**
 * Exécute bin/seed-school-year-promotion.php dans le conteneur via WP-CLI
 * et parse sa dernière ligne JSON — même contrat que
 * tests/supplier-order.spec.ts (le seed est LA source de vérité, jamais
 * réimplémenté côté Node).
 */
function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec',
      CONTAINER,
      'php',
      CONTAINER_WP_CLI,
      `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-school-year-promotion.php`,
      'seed-school-year-promotion',
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
    throw new Error(`seed-school-year-promotion : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
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

/** Connexion famille sans mot de passe — même séquence que parent-connu.spec.ts. */
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
  expect(loginLinkMatch, `lien de connexion introuvable dans l'e-mail :\n${loginMail.Text}`).toBeTruthy();
  const loginLink = loginLinkMatch![0];
  expect(loginLink).toContain(`psc_pid=${parentId}&`);

  await page.goto(loginLink);
  await expect(page.getByTestId('account-bar')).toBeVisible();
}

test.describe.serial('Entité année scolaire', () => {

test('réinscription — famille confirme un enfant pour l\'année suivante', async ({ page }) => {
  const data = seed();

  await loginAsFamily(page, data.reins_parent_email, data.reins_parent_id);

  // L'onglet n'existe que pendant la fenêtre ouverte par la mairie (le
  // seed la configure toujours large autour d'aujourd'hui).
  const reinsTab = page.getByTestId('portal-nav-reinscription');
  await expect(reinsTab).toBeVisible();
  await reinsTab.click();

  const section = page.getByTestId('portal-section-reinscription');
  await expect(section.getByTestId('reinscription-intro')).toContainText(data.year_b_label);

  const childBlock = section.getByTestId(`reinscription-child-${data.lea_id}`);
  await expect(childBlock).toBeVisible();
  // CE1 (classe actuelle du seed, cf. bin/seed-school-year-promotion.php)
  // -> CE2 par la progression par défaut.
  await expect(childBlock.getByTestId(`reinscription-confirm-${data.lea_id}`)).toBeChecked();
  await expect(childBlock).toContainText('CE1');
  await expect(childBlock).toContainText('CE2');

  // Soumission sans justificatif (règlement coché pour ne pas être bloqué
  // par la validation HTML5 native de la case, et exercer réellement la
  // validation serveur du fichier) : rejetée, rien n'est écrit.
  await section.getByTestId('reinscription-reglement').check();
  await section.getByTestId('reinscription-submit').click();
  await expect(page.getByTestId('notice-reinscription_required')).toBeVisible();

  const beforeAny = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.lea_id}, ${data.year_b_id}
    ));`
  );
  expect(beforeAny, "un justificatif/règlement manquant ne doit rien écrire").toBe('0');

  // Nouveau passage sur l'onglet (le formulaire a été rechargé par la
  // redirection ci-dessus) : cette fois avec justificatif + règlement.
  await page.getByTestId('portal-nav-reinscription').click();
  const section2 = page.getByTestId('portal-section-reinscription');
  const childBlock2 = section2.getByTestId(`reinscription-child-${data.lea_id}`);

  await childBlock2.getByTestId(`reinscription-assurance-${data.lea_id}`).setInputFiles({
    name: 'assurance-lea.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4 e2e school-year-promotion spec'),
  });
  await section2.getByTestId('reinscription-reglement').check();
  await section2.getByTestId('reinscription-submit').click();

  await expect(page.getByTestId('notice-reinscription_confirmee')).toBeVisible();

  /* ---------------- Vérification base : upsert correct, rien écrasé ---------------- */
  const row = wpCliEval(
    `global $wpdb; $r = $wpdb->get_row($wpdb->prepare(
      "SELECT classe, statut, reglement_accepted_at, assurance_original_filename
       FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.lea_id}, ${data.year_b_id}
    )); echo json_encode($r);`
  );
  const parsed = JSON.parse(row);
  expect(parsed.classe).toBe('CE2');
  expect(parsed.statut).toBe('inscrit');
  expect(parsed.reglement_accepted_at).not.toBeNull();
  expect(parsed.assurance_original_filename).toBe('assurance-lea.pdf');

  // La classe de l'année en cours (A) n'a jamais été touchée par la
  // réinscription (qui écrit exclusivement dans l'année cible B).
  const classeA = wpCliEval(
    `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT classe FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.lea_id}, ${data.year_a_id}
    ));`
  );
  expect(classeA).toBe('CE1');
});

test('passage d\'année — admin prépare, corrige et confirme', async ({ page }) => {
  const data = seed();

  await loginAsAdmin(page);
  await page.goto(`${ADMIN_BASE}/admin.php?page=psc_school_years`);

  await expect(page.getByTestId(`year-row-${data.year_a_id}`)).toContainText('Active');
  await expect(page.getByTestId(`year-row-${data.year_b_id}`)).toContainText('préparation');

  await page.getByTestId('promotion-from-select').selectOption(String(data.year_a_id));
  await page.getByTestId('promotion-to-select').selectOption(String(data.year_b_id));
  await page.getByTestId('promotion-stage-submit').click();

  /* ---------------- Récapitulatif : rien écrit, corrigible ---------------- */
  await expect(page).toHaveURL(/page=psc_passage_annee/);

  const camilleRow = page.getByTestId(`promotion-row-${data.camille_id}`);
  await expect(camilleRow).toContainText('Camille');
  // CP -> CE1 proposé par défaut (psc_classe_progression).
  await expect(camilleRow.getByTestId(`promotion-classe-select-${data.camille_id}`)).toHaveValue('CE1');

  const hugoRow = page.getByTestId(`promotion-row-${data.hugo_id}`);
  await expect(hugoRow).toContainText('Hugo');
  // CM2 -> fin de cycle, proposé "sortie".
  await expect(hugoRow.getByTestId(`promotion-classe-select-${data.hugo_id}`)).toHaveValue('sortie');

  const beforeConfirm = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.camille_id}, ${data.year_b_id}
    ));`
  );
  expect(beforeConfirm, "le récapitulatif ne doit rien écrire avant confirmation").toBe('0');

  // Correction manuelle : la mairie sait que Camille redouble, CE2 plutôt
  // que le CE1 proposé.
  await camilleRow.getByTestId(`promotion-classe-select-${data.camille_id}`).selectOption('CE2');

  await page.getByTestId('promotion-confirm-submit').click();
  await expect(page).toHaveURL(/psc_msg=promoted/);

  /* ---------------- Vérification base ---------------- */
  const camilleB = wpCliEval(
    `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT classe FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.camille_id}, ${data.year_b_id}
    ));`
  );
  expect(camilleB, "l'override manuel (CE2) doit être appliqué, pas la proposition (CE1)").toBe('CE2');

  // La classe de l'année A reste consultable telle quelle : le passage
  // d'année historise, il ne remplace jamais l'ancienne ligne.
  const camilleA = wpCliEval(
    `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT classe FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.camille_id}, ${data.year_a_id}
    ));`
  );
  expect(camilleA).toBe('CP');

  // Hugo : sorti, aucune inscription créée pour l'année B.
  const hugoStatut = wpCliEval(
    `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT statut FROM {$wpdb->prefix}psc_children WHERE id = %d", ${data.hugo_id}
    ));`
  );
  expect(hugoStatut).toBe('sorti');

  const hugoB = wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_child_school_years WHERE child_id = %d AND school_year_id = %d",
      ${data.hugo_id}, ${data.year_b_id}
    ));`
  );
  expect(hugoB, "un enfant sorti ne doit avoir aucune inscription dans l'année cible").toBe('0');

  // Le passage d'année ne rend pas B active tout seul : ça reste une
  // action manuelle distincte ("Activer").
  const yearBStatut = wpCliEval(
    `global $wpdb; echo $wpdb->get_var($wpdb->prepare(
      "SELECT statut FROM {$wpdb->prefix}psc_school_years WHERE id = %d", ${data.year_b_id}
    ));`
  );
  expect(yearBStatut, "le passage d'année n'active jamais l'année cible automatiquement").toBe('preparation');
});

});
