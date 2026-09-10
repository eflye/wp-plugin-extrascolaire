/**
 * P0-01 — Les allergies déclarées à l'inscription publique doivent suivre
 * TOUT le parcours d'approbation :
 *  1. la demande publique stocke l'allergie (children_json) ;
 *  2. la relecture (children_of) la restitue — l'écran de validation
 *     mairie l'affiche, l'édition des autres champs ne l'efface pas ;
 *  3. l'approbation MANUELLE crée la fiche avec l'allergie et déclenche
 *     le rappel PAI ;
 *  4. l'approbation AUTOMATIQUE (confirmation d'adresse par le parent)
 *     fait de même ;
 *  5. le rapprochement contrôlé complète les fiches des demandes
 *     approuvées AVANT le correctif, sans jamais écraser une fiche déjà
 *     renseignée.
 *
 * Chaque vérification coute la base (wp-cli) au rendu : le rendu peut
 * mentir, pas la table psc_children.
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

const ALLERGIES = 'Arachides — PAI requis (E2E)';

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

function childAllergies(email: string, prenom: string): string {
  const out = wpCliEval(
    `global $wpdb;
     $p = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${email}'));
     echo (string) $wpdb->get_var($wpdb->prepare("SELECT IFNULL(food_allergies,'') FROM {$wpdb->prefix}psc_children WHERE parent_id = %d AND prenom = %s", $p, '${prenom}'));`
  );
  return out.split('\n').filter(Boolean).reverse().find((l) => !l.startsWith('Warning')) ?? '';
}

/** Destinataire des alertes PAI : la mairie (option, à défaut admin_email). */
function mairieEmail(): string {
  return wpCliEval(
    `echo (string) psc_mairie_email();`
  );
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
}

/**
 * Remplit et envoie le wizard public avec UN enfant, allergy facultative
 * et second parent facultatif.
 */
async function submitRequest(page: Page, email: string, prenom: string, allergies: string | null, secondEmail?: string) {
  await page.goto(readFormPageUrl());

  // Étape 0 — coordonnées. L'adresse passe par la saisie manuelle : le
  // mode par défaut (autocomplétion BAN) masque le champ réel derrière
  // une recherche réseau, hors de propos pour ce test.
  await page.locator('#psc-req-email').fill(email);
  await page.locator('#psc-req-prenom').fill('Famille');
  await page.locator('#psc-req-nom').fill('TestDemande');
  await page.locator('#psc-req-tel').fill('06 12 34 56 78');
  await page.getByTestId('address-toggle').click();
  await page.locator('#psc-req-adresse').fill('1 rue de la Mairie');
  await page.locator('#psc-req-cp').fill('95830');
  await page.locator('#psc-req-ville').fill('Montgeroult');
  if (secondEmail) {
    await page.getByTestId('add-second-parent-button').click();
    await page.locator('#psc-sp-prenom').fill('Second');
    await page.locator('#psc-sp-email').fill(secondEmail);
  }
  await page.getByTestId('wizard-next').click();

  // Étape 1 — l'enfant (+ allergie déclarée le cas échéant).
  await page.locator('#psc-cp-0').fill(prenom);
  await page.locator('#psc-cn-0').fill('TestDemande');
  await page.locator('#psc-cc-0').selectOption({ label: 'CP' });
  await page.locator('#psc-cb-0').fill('2020-03-01');
  await expect(page.locator('input[name^="child_assurance_"]')).toHaveCount(0);
  if (allergies !== null) {
    await page.locator('input[name="child_has_allergy_0"]').check();
    await page.locator('textarea[name="child_food_allergies_0"]').fill(allergies);
  }
  await page.getByTestId('wizard-next').click();

  // Étape 2 — paiement « autre » par défaut ; étape 3 — règlement à
  // approuver avant l'envoi.
  await page.getByTestId('wizard-next').click();
  await page.locator('input[name="reglement_accepted"]').check();
  await page.getByTestId('wizard-submit').click();
  await expect(page.getByTestId('notice-request_sent')).toBeVisible();
}

async function verifyLink(email: string, page: Page) {
  const mail = await findLatestMessage(email, 'Confirmez votre demande');
  const match = mail.Text.match(/https?:\/\/\S*psc_vtoken=[0-9a-f]+/);
  expect(match, 'lien de confirmation introuvable dans le mail').toBeTruthy();
  await page.goto(match![0]);
}

test.describe('P0-01 — allergies et approbation des demandes', () => {
  test.beforeEach(async () => {
    // Parcours par défaut : approbation manuelle par la mairie.
    wpCli(['option', 'update', 'psc_auto_approve_requests', '0']);
    // Purge des exécutions précédentes de CE spec (les demandes
    // approuvées restent 90 jours en base et alourdiraient les écrans).
    wpCliEval(
      `global $wpdb;
       foreach (array('demande-manuelle.e2e+', 'demande-auto.e2e+', 'demande-rap-proch.e2e+', 'demande-revoc.e2e+', 'profil-sepa.e2e+') as $prefix) {
         $like = $prefix . '%@example.test';
         $wpdb->query($wpdb->prepare("DELETE r FROM {$wpdb->prefix}psc_requests r WHERE r.email LIKE %s", $like));
         $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email LIKE %s", $like));
         foreach ((array) $ids as $id) { $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}psc_parents WHERE id = %d", $id)); }
       }`
    );
  });

  test.afterEach(async () => {
    // Les tests qui activent l'approbation automatique doivent rendre
    // l'option : un spec suivant qui soumet une demande s'attend à la
    // trouver EN ATTENTE côté mairie, jamais approuvée à sa place.
    wpCli(['option', 'update', 'psc_auto_approve_requests', '0']);
  });

  test('approbation manuelle : allergie affichée, conservation malgré la correction mairie, alerte PAI', async ({ page }) => {
    const email = `demande-manuelle.e2e+${Date.now()}@example.test`;
    page.on('dialog', (d) => d.accept());

    await submitRequest(page, email, 'Zoé', ALLERGIES);
    await verifyLink(email, page);

    // Écran mairie : l'allergie est affichée (le décodeur la restitue).
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_requests`);
    const box = page.locator(`.psc-request:has-text("${email}")`);
    await expect(box).toBeVisible();
    await expect(box.locator(`text=${ALLERGIES}`)).toBeVisible();

    // La mairie corrige le prénom : l'allergie doit suivre (re-dérivée
    // de la demande par index, jamais écrasée par l'édition).
    await box.locator('input[name="child_prenom_0"]').fill('Zoé-Corrigée');
    await box.locator('button:has-text("Valider et donner l\'accès")').click();
    // La notice admin de cet écran ne porte pas de testid : texte du bandeau.
    await expect(page.locator('.notice-success:has-text("Demande validée")')).toBeVisible();

    // Fiche enfant : prénom corrigé ET allergie conservée.
    const stored = childAllergies(email, 'Zoé-Corrigée');
    expect(stored).toContain('Arachides');

    // Alerte PAI envoyée à la MAIRIE pour l'enfant complété.
    const pai = await findLatestMessage(mairieEmail(), 'Allergie alimentaire déclarée');
    expect(pai.Subject).toContain('Zoé-Corrigée');
  });

  test('approbation automatique : fiche créée avec allergie et alerte PAI', async ({ page }) => {
    const email = `demande-auto.e2e+${Date.now()}@example.test`;
    wpCli(['option', 'update', 'psc_auto_approve_requests', '1']);

    await submitRequest(page, email, 'Léo', ALLERGIES);
    await verifyLink(email, page);

    // Auto-approbation : le parent arrive directement dans son espace.
    await expect(page.getByTestId('notice-welcome')).toBeVisible();

    const stored = childAllergies(email, 'Léo');
    expect(stored).toContain('Arachides');

    const pai = await findLatestMessage(mairieEmail(), 'Allergie alimentaire déclarée');
    expect(pai.Subject).toContain('Léo');
  });

  test('Mon profil : passage de chèque ou espèces au prélèvement SEPA', async ({ page }) => {
    const email = `profil-sepa.e2e+${Date.now()}@example.test`;
    wpCli(['option', 'update', 'psc_auto_approve_requests', '1']);

    await submitRequest(page, email, 'Noé', null);
    await verifyLink(email, page);
    await expect(page.getByTestId('notice-welcome')).toBeVisible();

    const profileUrl = `${readFormPageUrl()}${readFormPageUrl().includes('?') ? '&' : '?'}psc_tab=profil`;
    await page.goto(profileUrl);
    const skip = page.getByTestId('onboarding-skip');
    if (await skip.isVisible().catch(() => false)) {
      await skip.click();
      await page.waitForLoadState('load');
      await page.goto(profileUrl);
    }

    await expect(page.getByTestId('profil-payment-autre-active')).toBeVisible();
    await expect(page.getByTestId('profil-sepa-fields')).toBeHidden();
    await page.getByTestId('profil-payment-enable-sepa').click();
    await expect(page.getByTestId('profil-sepa-fields')).toBeVisible();
    await expect(page.getByTestId('profil-payment-autre-active')).not.toHaveClass(/is-active/);
    await expect(page.getByTestId('profil-payment-enable-sepa')).toHaveClass(/is-active/);
    await page.getByTestId('profil-payment-autre-active').click();
    await expect(page.getByTestId('profil-payment-autre-active')).toHaveClass(/is-active/);
    await expect(page.getByTestId('profil-payment-enable-sepa')).not.toHaveClass(/is-active/);
    await expect(page.getByTestId('profil-sepa-fields')).toBeHidden();
    await page.getByTestId('profil-payment-enable-sepa').click();
    await expect(page.getByTestId('profil-sepa-fields')).toBeVisible();
    await expect(page.locator('#psc-profile-sepa-titulaire')).toHaveAttribute('required', '');
    await expect(page.locator('#psc-profile-sepa-iban')).toHaveAttribute('required', '');
    await expect(page.locator('#psc-profile-sepa-bic')).toHaveAttribute('required', '');

    await page.locator('#psc-profile-sepa-iban').fill('FR76 3000 6000 0112 3456 7890 189');
    await page.locator('#psc-profile-sepa-bic').fill('AGRIFRPP882');
    await page.locator('#psc-profile-sepa-same-address').check();
    await page.getByTestId('profil-sepa-accept').check();
    await page.getByTestId('profil-sepa-submit').click();

    await expect(page.getByTestId('notice-profil_sepa_enabled')).toBeVisible();
    await expect(page.getByTestId('profil-payment-prelevement-active')).toBeVisible();
    const stored = JSON.parse(wpCliEval(
      `global $wpdb;
       $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}psc_parents WHERE email = %s", '${email}'));
       echo wp_json_encode(array(
         'mode' => $p->payment_mode,
         'iban' => psc_read_iban($p),
         'bic' => $p->sepa_bic,
         'rum' => $p->sepa_mandate_ref,
         'accepted' => !empty($p->sepa_reglement_accepted_at),
       ));`
    ));
    expect(stored).toEqual({
      mode: 'prelevement',
      iban: 'FR7630006000011234567890189',
      bic: 'AGRIFRPP882',
      rum: expect.stringMatching(/^RUMP\d{8}$/),
      accepted: true,
    });
    const confirmation = await findLatestMessage(email, 'Prélèvement automatique activé');
    expect(confirmation.Text).toContain('prélèvement automatique SEPA');
  });

  test('ajout du second parent : conserve la session du parent connecté', async ({ page }) => {
    const email = `demande-second.e2e+${Date.now()}@example.test`;
    wpCli(['option', 'update', 'psc_auto_approve_requests', '1']);
    await submitRequest(page, email, 'Iris', null);
    await verifyLink(email, page);
    const formUrl = readFormPageUrl();
    const tabUrl = formUrl + (formUrl.includes('?') ? '&' : '?') + 'psc_tab=profil';
    await page.goto(tabUrl);
    const skip = page.getByTestId('onboarding-skip');
    if (await skip.isVisible().catch(() => false)) {
      await skip.click();
      await page.goto(tabUrl);
    }
    await page.getByTestId('profil-add-second-parent').click();
    const form = page.getByTestId('profil-second-parent-form');
    await form.locator('[name="second_parent_prenom"]').fill('Alex');
    await form.locator('[name="second_parent_nom"]').fill('Test');
    await form.locator('[name="second_parent_email"]').fill(`second-${email}`);
    await form.locator('[name="second_parent_telephone"]').fill('0600000099');
    await page.getByTestId('profil-second-parent-submit').click();
    await expect(page.getByTestId('notice-second_parent_updated')).toBeVisible();
    await page.goto(tabUrl);
    await expect(form.locator('[name="second_parent_email"]')).toHaveValue(`second-${email}`);
    await expect(page.getByTestId('login-card')).toHaveCount(0);
  });

  test('retrait du second parent : conserve la session courante et révoque les autres accès', async ({ browser }) => {
    const email = `demande-revoc.e2e+${Date.now()}@example.test`;
    const second = `second-${email}`;
    wpCli(['option', 'update', 'psc_auto_approve_requests', '1']);

    // Famille créée avec second parent ; le parent titulaire est connecté
    // par l'approbation automatique (session ouverte).
    const page = await browser.newContext().then((c) => c.newPage());
    await submitRequest(page, email, 'Iris', null, second);
    await verifyLink(email, page);
    await expect(page.getByTestId('notice-welcome')).toBeVisible();

    // Un lien de connexion pour le SECOND parent, capturé AVANT le
    // retrait — il doit devenir inutilisable.
    const page2 = await browser.newContext().then((c) => c.newPage());
    await page2.goto(readFormPageUrl());
    await page2.getByTestId('login-email-input').fill(second);
    await page2.getByTestId('login-submit-button').click();
    const linkMail = await findLatestMessage(second, 'Votre lien d\'accès');
    const linkMatch = linkMail.Text.match(/https?:\/\/\S*psc_token=[0-9a-f]+/);
    expect(linkMatch, 'lien du second parent introuvable').toBeTruthy();

    await page2.goto(linkMatch![0]);
    await expect(page2.getByTestId('login-card')).toHaveCount(0);

    // Retrait du second parent depuis « Mon profil » (session titulaire).
    const formUrl = readFormPageUrl();
    const tabUrl = formUrl + (formUrl.includes('?') ? '&' : '?') + 'psc_tab=profil';
    await page.goto(tabUrl);
    // Popin de première connexion : « Passer » soumet un formulaire et
    // redirige — on revient au profil, désormais sans popin.
    const skip = page.getByTestId('onboarding-skip');
    if (await skip.isVisible().catch(() => false)) {
      await skip.click();
      await page.waitForLoadState('load');
      await page.goto(tabUrl);
    }
    await expect(page.getByTestId('profil-second-parent-block')).toBeVisible();
    page.on('dialog', (d) => d.accept());
    await page.getByTestId('profil-remove-second-parent').click();

    await expect(page.getByTestId('notice-second_parent_removed')).toBeVisible();
    await page.goto(tabUrl);
    await expect(page.getByTestId('profil-add-second-parent')).toBeVisible();
    await expect(page.getByTestId('login-card')).toHaveCount(0);

    // L’autre session ouverte avant le retrait est invalidée.
    await page2.goto(readFormPageUrl());
    await expect(page2.getByTestId('login-card')).toBeVisible();

    // Le lien capturé du second parent est refusé : vue invité, jamais le
    // portail.
    await page2.goto(linkMatch![0]);
    await expect(page2.getByTestId('login-card')).toBeVisible();

    // Et le lien ne rouvre rien : l'époque du foyer a changé même pour un
    // cookie fraîchement forgé depuis l'ancien jeton.
    expect(await wpCliEval(
      `global $wpdb;
       $e = '${email}';
       echo (string) $wpdb->get_var($wpdb->prepare("SELECT token_hash IS NULL FROM {$wpdb->prefix}psc_parents WHERE email = %s", $e));`
    ).trim().split('\n').pop()).toBe('1');
  });

  test('en-têtes : aucune page du plugin ne doit être conservée par un intermédiaire', async ({ request }) => {
    // P1-03 — le portail authentifie hors WordPress et des URL portent
    // des jetons : un cache partagé ne doit jamais stocker ce rendu.
    const resp = await request.get(readFormPageUrl());
    expect((resp.headers()['cache-control'] ?? '')).toContain('no-store');
  });

  test('rapprochement contrôlé : les demandes déjà approuvées complètent les fiches vides', async ({ page }) => {
    const email = `demande-rap-proch.e2e+${Date.now()}@example.test`;

    // Simule l'état d'avant correctif : demande approuvée dont la fiche
    // enfant (créée sans allergie) existe, children_json intact.
    wpCliEval(
      `global $wpdb;
       $wpdb->query('START TRANSACTION');
       $wpdb->insert($wpdb->prefix.'psc_parents', array('email' => '${email}', 'nom' => 'Rapproch', 'active' => 1, 'created_at' => current_time('mysql')), array('%s','%s','%d','%s'));
       $pid = (int) $wpdb->insert_id;
       $wpdb->insert($wpdb->prefix.'psc_children', array('parent_id' => $pid, 'nom' => 'Rapproch', 'prenom' => 'Mila', 'statut' => 'actif', 'created_at' => current_time('mysql')), array('%d','%s','%s','%s','%s'));
       $cid = (int) $wpdb->insert_id;
       $json = json_encode(array(array('nom' => 'Rapproch', 'prenom' => 'Mila', 'classe' => 'CP', 'date_naissance' => '2020-03-01', 'sans_porc' => 0, 'vegan' => 0, 'food_allergies' => '${ALLERGIES}', 'personnes_autorisees' => array())));
       $wpdb->insert($wpdb->prefix.'psc_requests', array('email' => '${email}', 'children_json' => $json, 'status' => 'approved', 'verified' => 1, 'decided_at' => current_time('mysql'), 'created_at' => current_time('mysql')), array('%s','%s','%s','%d','%s','%s'));
       $wpdb->query('COMMIT');
       echo (string) $cid;`
    );

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_requests`);

    // La section de rapprochement liste la demande et l'enfant concerné.
    // (L'e-mail apparaît aussi dans l'historique « Traitées » et dans les
    // autres blocs de rapprochement : on cible le bloc qui porte le
    // bouton de report ET l'e-mail de CE test.)
    const gapSection = page.locator('.psc-box').filter({ hasText: 'Allergies non reportées' });
    const reportButton = page.locator('button:has-text("Reporter les allergies sur les fiches")');
    const gapBlock = gapSection
      .locator('div')
      .filter({ has: reportButton })
      .filter({ hasText: email })
      .last();
    await expect(gapBlock).toBeVisible();
    await expect(gapBlock).toContainText('Arachides');
    await gapBlock.locator('button:has-text("Reporter les allergies sur les fiches")').click();
    // Notice admin sans testid sur cet écran : texte du bandeau.
    await expect(page.locator('.notice-success:has-text("Allergies reportées")')).toBeVisible();

    // La fiche est complétée, l'alerte PAI partie ; un second passage
    // n'a plus rien à faire (idempotent).
    expect(childAllergies(email, 'Mila')).toContain('Arachides');
    await findLatestMessage(mairieEmail(), 'Allergie alimentaire déclarée');
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_requests`);
    await expect(
      page
        .locator('.psc-box')
        .filter({ hasText: 'Allergies non reportées' })
        .filter({ hasText: email })
    ).toHaveCount(0);
  });
});
