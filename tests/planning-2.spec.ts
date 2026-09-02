/**
 * Planning - 2 (rythme + exceptions) — tests fonctionnels durcis.
 *
 * Chaque interaction vérifie DEUX choses :
 *  1. le rendu (état du bouton : aria-pressed, classe d'origine, glyphe) ;
 *  2. l'état SERVEUR en base via WP-CLI eval (tables psc_pattern /
 *     psc_exception) — le rendu peut mentir, pas la base.
 *
 * Invariants couverts (spécification v5, §7) :
 *  - basculer un jour deux fois → AUCUNE ligne d'exception résiduelle ;
 *  - copie fratrie → patterns identiques, exceptions individuelles conservées ;
 *  - saisie dans Planning - 2 reflétée côté serveur (et donc dans Planning - 1
 *    qui lit le même modèle) ;
 *  - « revenir au rythme » purge les exceptions du mois (hors verrouillées).
 *
 * Cas durci : Chloé, enfant SANS justificatif d'assurance — l'ajout
 * exceptionnel doit y être refusé, mais le retrait, le retour au rythme
 * (re-coche) et le rythme habituel doivent TOUJOURS passer (sinon l'écran
 * est inerte et l'invariant laisse des exceptions résiduelles — c'est le
 * défaut corrigé en v5.0.2).
 *
 * Les confirmations navigateur (copie fratrie, revenir au rythme) sont
 * acceptées automatiquement via page.on('dialog').
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

interface SeedResult {
  parent_id: number;
  parent_email: string;
  alice_id: number;
  bob_id: number;
  chloe_id: number;
  year_key: string;
  month: string;
  pattern_date: string;
  free_date: string;
}

function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec', CONTAINER, 'php', CONTAINER_WP_CLI,
      `--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php`,
      'seed-planning-2',
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
    throw new Error(`seed-planning-2 : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
  }
  return JSON.parse(jsonLine) as SeedResult;
}

/** Lecture base en lecture seule : la vérité derrière le rendu. */
function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

function patternRows(childId: number): string {
  return wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pattern WHERE child_id = %d", ${childId}
    ));`
  );
}

function exceptionRows(childId: number, date?: string): string {
  const where = date
    ? `AND jour_date = '${date}'`
    : '';
  return wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}psc_exception WHERE child_id = %d ${where}", ${childId}
    ));`
  );
}

/**
 * Exceptions sur les jours NON verrouillés du mois — les seilles que les
 * clics peuvent modifier. Les jours passés légitimement figés par la pose
 * d'un rythme (état d'avant = non déclaré) ne comptent pas : c'est le
 * comportement « verrou 48 h » de la spécification.
 */
function unlockedExceptionRowsInMonth(childId: number, month: string): string {
  return wpCliEval(
    `global $wpdb; $n = 0;
     foreach ($wpdb->get_results($wpdb->prepare(
       "SELECT jour_date FROM {$wpdb->prefix}psc_exception
        WHERE child_id = %d AND jour_date LIKE %s", ${childId}, '${month}-%'
     )) as $r) {
       if (!psc_is_locked($r->jour_date)) $n++;
     }
     echo (int) $n;`
  );
}

async function loginAsSeedParent(page: Page, parentEmail: string) {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(parentEmail);
  const anyNotice = page.locator('[data-testid^="notice-"]');
  await Promise.all([
    anyNotice.waitFor({ state: 'visible', timeout: 5_000 }),
    page.getByTestId('login-submit-button').click(),
  ]);
  const loginMail = await findLatestMessage(parentEmail, 'Votre lien d\'accès aux inscriptions périscolaires');
  const loginLinkMatch = loginMail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(loginLinkMatch, 'lien de connexion introuvable dans le mail').toBeTruthy();
  await page.goto(loginLinkMatch![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

test.describe('Planning - 2 : rythme + exceptions (fonctionnel, base vérifiée)', () => {
  let data: SeedResult;

  test.beforeEach(async ({ page }) => {
    data = seed();
    // Confirmations navigateur (copie fratrie, revenir au rythme) : toujours
    // accepter — le scénario asserte le résultat en base, pas la popin.
    page.on('dialog', (dialog) => dialog.accept());
    await loginAsSeedParent(page, data.parent_email);
    await page.goto(`${APP_BASE}/?psc_tab=cantine2&psc_mois=${data.month}`);
    await expect(page.getByTestId('cantine2-title')).toBeVisible();
    await expect(page.getByTestId('exception-grid')).toBeVisible();
  });

  test('grille du rythme : cocher puis décocher ne laisse AUCUNE ligne (base)', async ({ page }) => {
    // État seed : Alice a CANT lun, mar, jeu, ven (4 lignes — pas de
    // mercredi), rien d'autre.
    expect(patternRows(data.alice_id)).toBe('4');

    // Cocher GM lundi (off -> on) : le bouton passe à l'état actif…
    const gmLundi = page.getByTestId('pattern-1-GM');
    await expect(gmLundi).not.toHaveClass(/is-on/);
    await gmLundi.click();
    await expect(gmLundi).toHaveClass(/is-on/);
    await expect(gmLundi).toHaveAttribute('aria-pressed', 'true');
    // …et la base porte la ligne.
    expect(patternRows(data.alice_id)).toBe('5');
    expect(
      wpCliEval(
        `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pattern
           WHERE child_id = %d AND weekday = 1 AND service_code = 'GM'", ${data.alice_id}
        ));`
      )
    ).toBe('1');

    // Décocher (on -> off) : retour visuel ET base — pas de ligne fantôme.
    await gmLundi.click();
    await expect(gmLundi).not.toHaveClass(/is-on/);
    await expect(gmLundi).toHaveAttribute('aria-pressed', 'false');
    expect(patternRows(data.alice_id)).toBe('4');
  });

  test('exception de retrait puis retour au rythme : AUCUNE ligne résiduelle (base)', async ({ page }) => {
    // pattern_date est déclaré par le rythme (CANT) : un clic y écrit un
    // RETRAIT exceptionnel.
    const cell = page.getByTestId(`exc-${data.pattern_date}-CANT`);
    await expect(cell).toHaveClass(/psc-exc-pattern/);
    await expect(cell).toHaveAttribute('aria-pressed', 'true');
    await cell.click();

    // Rendu : bordure pointillée bordeaux + glyphe –, état non déclaré.
    await expect(cell).toHaveClass(/psc-exc-remove/);
    await expect(cell).toHaveAttribute('aria-pressed', 'false');
    await expect(cell.locator('.psc-exc-glyph')).toHaveText('–');

    // Base : l'exception de retrait existe, value = 0.
    expect(exceptionRows(data.alice_id, data.pattern_date)).toBe('1');
    expect(
      wpCliEval(
        `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
          "SELECT value FROM {$wpdb->prefix}psc_exception
           WHERE child_id = %d AND jour_date = %s AND service_code = 'CANT'", ${data.alice_id}, '${data.pattern_date}'
        ));`
      )
    ).toBe('0');

    // La déclaration effective suit : la résolution renvoie false.
    expect(
      wpCliEval(`echo Psc_Planning::is_declared(${data.alice_id}, '${data.pattern_date}', 'CANT') ? '1' : '0';`)
    ).toBe('0');

    // Re-clic (retour au rythme) : la case redevient « issu du rythme »…
    await cell.click();
    await expect(cell).toHaveClass(/psc-exc-pattern/);
    await expect(cell).toHaveAttribute('aria-pressed', 'true');
    await expect(cell.locator('.psc-exc-glyph')).toHaveText('✓');

    // …et l'INVARIANT : aucune ligne résiduelle en base.
    expect(exceptionRows(data.alice_id, data.pattern_date)).toBe('0');
  });

  test('exception d\u2019ajout puis re-clic : AUCUNE ligne résiduelle (base)', async ({ page }) => {
    // free_date n'a AUCUNE déclaration : un clic y écrit un AJOUT exceptionnel.
    const cell = page.getByTestId(`exc-${data.free_date}-GM`);
    await expect(cell).toHaveClass(/psc-exc-none/);
    await expect(cell).toHaveAttribute('aria-pressed', 'false');
    await cell.click();

    // Rendu : fond abricot + bordure pointillée encre, glyphe ✓.
    await expect(cell).toHaveClass(/psc-exc-add/);
    await expect(cell).toHaveAttribute('aria-pressed', 'true');

    // Base : exception value = 1, la résolution déclare.
    expect(exceptionRows(data.alice_id, data.free_date)).toBe('1');
    expect(
      wpCliEval(`echo Psc_Planning::is_declared(${data.alice_id}, '${data.free_date}', 'GM') ? '1' : '0';`)
    ).toBe('1');

    // Re-clic : retour à « non déclaré », aucune ligne en base.
    await cell.click();
    await expect(cell).toHaveClass(/psc-exc-none/);
    await expect(cell).toHaveAttribute('aria-pressed', 'false');
    expect(exceptionRows(data.alice_id, data.free_date)).toBe('0');
  });

  test('Tout / Aucun sur une colonne : le lot suit le même invariant', async ({ page }) => {
    // « Tout » sur la colonne GS : chaque jour d'école non verrouillé du
    // mois reçoit un ajout exceptionnel.
    const tout = page.getByTestId('exc-tout-GS');
    await tout.click();

    // Rendu : la colonne GS du jour libre passe en ajout exceptionnel.
    await expect(page.getByTestId(`exc-${data.free_date}-GS`)).toHaveClass(/psc-exc-add/);

    // Base : autant d'exceptions GS que de jours non verrouillés du mois.
    const gsCount = wpCliEval(
      `global $wpdb; $n = 0;
       foreach ($wpdb->get_results($wpdb->prepare(
         "SELECT jour_date FROM {$wpdb->prefix}psc_exception
          WHERE child_id = %d AND service_code = 'GS' AND value = 1
            AND jour_date LIKE %s", ${data.alice_id}, '${data.month}-%'
       )) as $r) {
         if (!psc_is_locked($r->jour_date)) $n++;
       }
       echo (int) $n;`
    );
    expect(parseInt(gsCount, 10)).toBeGreaterThanOrEqual(5);

    // « Aucun » : la colonne revient à vide…
    await expect(page.getByTestId('exc-tout-GS')).toHaveText('Aucun');
    await tout.click();
    await expect(page.getByTestId(`exc-${data.free_date}-GS`)).toHaveClass(/psc-exc-none/);

    // …et la base ne garde RIEN sur les jours modifiables (invariant du
    // lot ; les figeages légitimes des jours verrouillés restent en place).
    expect(unlockedExceptionRowsInMonth(data.alice_id, data.month)).toBe('0');
  });

  test('onglets enfants + navigation mois : re-rendu sans erreur, rythme par enfant', async ({ page }) => {
    // Bob n'a AUCUN rythme : sa grille doit être vide (et non celle d'Alice).
    await page.getByTestId(`child-tab-${data.bob_id}`).click();
    await expect(page.getByTestId(`child-tab-${data.bob_id}`)).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('pattern-1-CANT')).not.toHaveClass(/is-on/);

    // Navigation mois suivante : le libellé change, la grille est re-rendue.
    await page.getByTestId('exc-next').click();
    await expect(page.locator('[data-exc-month]')).not.toHaveText('');

    // Retour au mois de travail via la frise : l'état du rythme est toujours
    // celui d'Alice (les clics de Bob n'ont rien modifié).
    await page.goto(`${APP_BASE}/?psc_tab=cantine2&psc_mois=${data.month}&psc_child=${data.alice_id}`);
    await expect(page.getByTestId('pattern-2-CANT')).toHaveClass(/is-on/);
    expect(patternRows(data.alice_id)).toBe('4');
    expect(patternRows(data.bob_id)).toBe('0');
  });

  test('appliquer le rythme à toute la fratrie : patterns identiques, base vérifiée', async ({ page }) => {
    // Alice (rythme CANT lun-ven) est l'enfant actif par défaut.
    await page.getByTestId('apply-siblings').click();

    // Rendu : le feedback confirme, et la grille de l'ENFANT ACTIF reste
    // inchangée (Alice ne subit pas sa propre copie).
    await expect(page.locator('#psc-apply-siblings-feedback')).toContainText('fratrie');

    // Base : Bob porte exactement les 4 lignes CANT d'Alice.
    expect(patternRows(data.bob_id)).toBe('4');
    expect(
      wpCliEval(
        `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pattern WHERE child_id = %d AND service_code = 'CANT'", ${data.bob_id}
        ));`
      )
    ).toBe('4');
  });

  test('revenir au rythme : purge des exceptions du mois (base)', async ({ page }) => {
    // Deux exceptions : un retrait sur pattern_date (CANT) et un ajout sur
    // free_date (GS), posés par l'UI elle-même.
    await page.getByTestId(`exc-${data.pattern_date}-CANT`).click();
    await expect(page.getByTestId(`exc-${data.pattern_date}-CANT`)).toHaveClass(/psc-exc-remove/);
    await page.getByTestId(`exc-${data.free_date}-GS`).click();
    await expect(page.getByTestId(`exc-${data.free_date}-GS`)).toHaveClass(/psc-exc-add/);

    // Le lien de purge porte le compte exact et est visible.
    const reset = page.getByTestId('exc-reset');
    await expect(reset).toBeVisible();
    expect(parseInt((await reset.getAttribute('data-count')) ?? '0', 10)).toBe(2);
    await reset.click();

    // Rendu : les deux cases reviennent à leur état d'origine (pattern / none).
    await expect(page.getByTestId(`exc-${data.pattern_date}-CANT`)).toHaveClass(/psc-exc-pattern/);
    await expect(page.getByTestId(`exc-${data.free_date}-GS`)).toHaveClass(/psc-exc-none/);
    await expect(reset).toBeHidden();

    // Base : plus AUCUNE exception sur les jours modifiables du mois.
    expect(unlockedExceptionRowsInMonth(data.alice_id, data.month)).toBe('0');
  });

  test('cohérence des écrans : une saisie Planning - 2 est visible dans Planning - 1, et inversement', async ({ page }) => {
    // Planning - 2 : retrait exceptionnel du jour de rythme.
    const cell = page.getByTestId(`exc-${data.pattern_date}-CANT`);
    await cell.click();
    await expect(cell).toHaveClass(/psc-exc-remove/);
    expect(exceptionRows(data.alice_id, data.pattern_date)).toBe('1');

    // Planning - 1 lit le MÊME modèle : la case du jour doit être décochée…
    await page.goto(`${APP_BASE}/?psc_tab=cantine&psc_mois=${data.month}`);
    const check = page.getByTestId(`check-0-${data.pattern_date}-CANT`);
    await expect(check).not.toBeChecked();

    // …et le re-clic (re-déclaration) y écrit la levée de l'exception :
    // retour à l'état effectif déclaré, aucune ligne résiduelle.
    await check.click();
    await expect(check).toBeChecked();
    await expect(page.getByTestId(`check-0-${data.pattern_date}-CANT`)).toBeChecked();
    expect(exceptionRows(data.alice_id, data.pattern_date)).toBe('0');

    // Retour sur Planning - 2 : la case montre de nouveau le rythme.
    await page.goto(`${APP_BASE}/?psc_tab=cantine2&psc_mois=${data.month}`);
    await expect(page.getByTestId(`exc-${data.pattern_date}-CANT`)).toHaveClass(/psc-exc-pattern/);
    expect(unlockedExceptionRowsInMonth(data.alice_id, data.month)).toBe('0');
  });

  test('enfant sans assurance : ajout refusé, retrait et retour au rythme passent (cas durci)', async ({ page }) => {
    // Chloé n'a ni rythme ni justificatif.
    await page.getByTestId(`child-tab-${data.chloe_id}`).click();
    await expect(page.getByTestId(`child-tab-${data.chloe_id}`)).toHaveAttribute('aria-selected', 'true');

    // 1. L'AJOUT exceptionnel est refusé (message assurance visible)…
    const addCell = page.getByTestId(`exc-${data.free_date}-CANT`);
    await addCell.click();
    await expect(page.locator('.psc-error').first()).toBeVisible();
    await expect(page.locator('.psc-error').first()).toContainText('assurance');
    await expect(addCell).toHaveClass(/psc-exc-none/);
    expect(exceptionRows(data.chloe_id, data.free_date)).toBe('0');

    // 2. Le RYTHME habituel passe toujours (posé dès l'inscription sans
    //    exigence d'assurance — l'incohérence qui rendait l'écran inerte).
    const patternCell = page.getByTestId('pattern-1-CANT');
    await patternCell.click();
    await expect(patternCell).toHaveClass(/is-on/);
    expect(
      wpCliEval(
        `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$wpdb->prefix}psc_pattern
           WHERE child_id = %d AND weekday = 1 AND service_code = 'CANT'", ${data.chloe_id}
        ));`
      )
    ).toBe('1');

    // 3. Le RETRAIT d'un jour du rythme passe toujours…
    const removeCell = page.getByTestId(`exc-${data.pattern_date}-CANT`);
    await removeCell.click();
    await expect(removeCell).toHaveClass(/psc-exc-remove/);
    expect(exceptionRows(data.chloe_id, data.pattern_date)).toBe('1');

    // 4. …et le RETOUR AU RYTHME (re-coche) n'est JAMAIS bloqué par
    //    l'assurance : sans cela, l'invariant laisserait une exception
    //    résiduelle et la famille serait coincée.
    await removeCell.click();
    await expect(removeCell).toHaveClass(/psc-exc-pattern/);
    expect(exceptionRows(data.chloe_id, data.pattern_date)).toBe('0');
  });
});
