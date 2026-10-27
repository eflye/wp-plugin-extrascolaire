/**
 * Espace intervenants (SIDSCM) — déverrouillage, pointage à trois états
 * (à pointer / présent / absent), heures d'arrivée et de départ, « Tous
 * présents », échec d'enregistrement, contacts d'urgence.
 *
 * Le contrat de l'écran : un enfant attendu part « à pointer » (aucune
 * ligne wp_psc_attendance, ou present NULL) ; l'intervenant touche
 * Présent ou Absent, et toucher le bouton déjà actif annule le pointage.
 * La seed dédiée (bin/seed-sidscm.php) crée la famille et les
 * inscriptions SANS aucune ligne de pointage.
 *
 * Chaque pointage est vérifié par l'état réel de la base (wpCliEval),
 * pas par les compteurs : le site de CI porte les inscriptions des autres
 * seeds, ces compteurs n'y sont pas déterministes.
 *
 * Le pointage est en "optimistic UI" : la vue est intégralement re-rendue
 * après chaque appui (innerHTML), les éléments changent donc d'identité —
 * les sélecteurs sont relus après chaque action (jamais de référence
 * conservée), et l'aller/retour AJAX est attendu via expect.poll sur la
 * base plutôt que sur le DOM.
 *
 * Appels consommés par run : un seul mauvais code, quelques chargements,
 * une vingtaine de pointages, deux « tous présents », quelques heures,
 * quatre requêtes forgées refusées, quatre pointages simultanés, une
 * consultation de contact d'urgence et deux demandes de contacts
 * refusées ; le scénario reste très en dessous des limites de débit de
 * l'écran.
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { ENGINE, CONTAINER } from '../helpers/env';

const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const CONTAINER_WP_PATH = '/var/www/html';
const CONTAINER_PLUGIN_PATH = `${CONTAINER_WP_PATH}/wp-content/plugins/periscolaire-registration`;

interface SeedResult {
  page_url: string;
  access_code: string;
  enfant_a_id: number;
  enfant_a_prenom: string;
  enfant_b_id: number;
  enfant_b_prenom: string;
  first_jour: string;
  first_date: string;
}

/**
 * Exécute bin/seed-sidscm.php dans le conteneur via WP-CLI et parse sa
 * dernière ligne JSON — même contrat que les autres specs à seed
 * dédiée (purge-et-recrée à chaque appel, d'où test.describe.serial).
 */
function seed(): SeedResult {
  const output = execFileSync(
    ENGINE,
    [
      'exec',
      CONTAINER,
      'php',
      CONTAINER_WP_CLI,
      `--require=${CONTAINER_PLUGIN_PATH}/bin/seed-sidscm.php`,
      'seed-sidscm',
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
    throw new Error(`seed-sidscm : aucune ligne JSON dans la sortie WP-CLI.\n--- sortie ---\n${output}`);
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

/** Ligne de pointage (enfant × jour × service) ou null si absente ; present NULL = à pointer. */
function attendanceRow(
  childId: number,
  date: string,
  service: string
): { present: number | null; arrival_time: string | null; departure_time: string | null } | null {
  const out = wpCliEval(
    `global $wpdb;
     $row = $wpdb->get_row($wpdb->prepare(
       "SELECT present, arrival_time, departure_time FROM {$wpdb->prefix}psc_attendance
        WHERE child_id = %d AND jour_date = %s AND service = %s",
       ${childId}, '${date}', '${service}'
     ));
     echo wp_json_encode($row
       ? array('present' => $row->present === null ? null : (int) $row->present, 'arrival_time' => $row->arrival_time, 'departure_time' => $row->departure_time)
       : null);`
  );
  return JSON.parse(out);
}

/** Nombre de lignes de pointage pour un enfant × jour × service. */
function attendanceCount(childId: number, date: string, service: string): number {
  const out = wpCliEval(
    `global $wpdb;
     echo (int) $wpdb->get_var($wpdb->prepare(
       "SELECT COUNT(*) FROM {$wpdb->prefix}psc_attendance
        WHERE child_id = %d AND jour_date = %s AND service = %s",
       ${childId}, '${date}', '${service}'
     ));`
  );
  return parseInt(out, 10);
}

/** Franchit l'écran de verrouillage (contexte neuf à chaque test : le code du localStorage ne survit pas). */
async function unlock(page: Page, data: SeedResult): Promise<void> {
  await page.goto(data.page_url);
  await expect(page.getByTestId('sidscm-lock')).toBeVisible();
  await page.getByTestId('sidscm-code-input').fill(data.access_code);
  await page.getByTestId('sidscm-code-submit').click();
  await expect(page.getByTestId('sidscm-app')).toBeVisible();
}

/** Déverrouille puis épingle le premier jour ouvert (date de toutes les vérifications) et un service. */
async function openDay(page: Page, data: SeedResult, service: 'GM' | 'CANT' | 'GS'): Promise<void> {
  await unlock(page, data);
  await page.getByTestId(`sidscm-day-${data.first_jour}`).click();
  await page.getByTestId(`sidscm-svc-${service}`).click();
}

const POLL = { intervals: [1_000], timeout: 15_000 };

test.describe.serial('Espace intervenants (SIDSCM)', () => {

test('déverrouillage — mauvais code refusé, bon code ouvre la feuille de pointage', async ({ page }) => {
  const data = seed();

  await page.goto(data.page_url);
  await expect(page.getByTestId('sidscm-root')).toBeVisible();
  await expect(page.getByTestId('sidscm-lock')).toBeVisible();
  await expect(page.getByTestId('sidscm-app')).toBeHidden();

  // Un seul essai erroné : il alimente le seau partagé des mauvais codes
  // (20/h par IP, tous endpoints confondus, cf. Psc_Sidscm::require_code())
  // — le scénario n'en consomme qu'un pour ne pas entamer le débit.
  await page.getByTestId('sidscm-code-input').fill('CODE-FAUX');
  await page.getByTestId('sidscm-code-submit').click();
  await expect(page.getByTestId('sidscm-code-error')).toBeVisible();
  await expect(page.getByTestId('sidscm-app')).toBeHidden();

  await page.getByTestId('sidscm-code-input').fill(data.access_code);
  await page.getByTestId('sidscm-code-submit').click();
  await expect(page.getByTestId('sidscm-app')).toBeVisible();
  await expect(page.getByTestId('sidscm-lock')).toBeHidden();
});

test('pointage GM — à pointer par défaut, Présent puis annulation au second appui, sans doublon', async ({ page }) => {
  const data = seed();
  await openDay(page, data, 'GM');

  const row = () => page.getByTestId(`sidscm-row-${data.enfant_a_id}`);
  const present = () => page.getByTestId(`sidscm-present-${data.enfant_a_id}`);
  const absent = () => page.getByTestId(`sidscm-absent-${data.enfant_a_id}`);

  // Nina est inscrite GM + CANT + GS tous les jours ouverts : elle part
  // « à pointer », sans aucune ligne de pointage ni heure saisissable.
  await expect(row()).toHaveClass(/\bis-todo\b/);
  await expect(present()).toHaveAttribute('aria-pressed', 'false');
  await expect(absent()).toHaveAttribute('aria-pressed', 'false');
  await expect(page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`)).toHaveCount(0);
  await expect(row().getByRole('group', { name: 'Présence de Nina SidscmTest' })).toBeVisible();
  expect(attendanceRow(data.enfant_a_id, data.first_date, 'GM')).toBeNull();
  // Marco n'est pas inscrit en Garderie matin : pas de ligne sur l'onglet GM.
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toBeHidden();

  // Présent : l'heure d'arrivée est préremplie avec l'heure courante et
  // envoyée avec le pointage ; le focus revient sur le bouton re-rendu.
  await present().click();
  await expect(row()).toHaveClass(/\bis-present\b/);
  await expect(present()).toHaveAttribute('aria-pressed', 'true');
  await expect(present()).toBeFocused();
  const arrival = page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`);
  await expect(arrival).toHaveValue(/^\d{2}:\d{2}$/);
  const prefilled = await arrival.inputValue();
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), POLL)
    .toEqual({ present: 1, arrival_time: `${prefilled}:00`, departure_time: null });

  // Second appui sur Présent : annulation, l'enfant revient à pointer
  // (present et heure à NULL, la ligne reste).
  await present().click();
  await expect(row()).toHaveClass(/\bis-todo\b/);
  await expect(present()).toHaveAttribute('aria-pressed', 'false');
  await expect(present()).toBeFocused();
  await expect(page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`)).toHaveCount(0);
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), POLL)
    .toEqual({ present: null, arrival_time: null, departure_time: null });

  // Re-pointage : le cycle complet laisse exactement UNE ligne
  // (upsert enfant × jour × service, jamais un doublon).
  await absent().click();
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), POLL)
    .toEqual({ present: 0, arrival_time: null, departure_time: null });
  expect(attendanceCount(data.enfant_a_id, data.first_date, 'GM')).toBe(1);
});

test('absent GS — passer de Présent à Absent efface l\'heure de départ', async ({ page }) => {
  const data = seed();
  await openDay(page, data, 'GS');

  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toBeVisible();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toBeVisible();

  await page.getByTestId(`sidscm-present-${data.enfant_b_id}`).click();
  await expect(page.getByTestId(`sidscm-departure-${data.enfant_b_id}`)).toHaveValue(/^\d{2}:\d{2}$/);
  await page.getByTestId(`sidscm-departure-${data.enfant_b_id}`).fill('17:05');
  await expect.poll(() => attendanceRow(data.enfant_b_id, data.first_date, 'GS'), POLL)
    .toEqual({ present: 1, arrival_time: null, departure_time: '17:05:00' });

  await page.getByTestId(`sidscm-absent-${data.enfant_b_id}`).click();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toHaveClass(/\bis-absent\b/);
  await expect(page.getByTestId(`sidscm-absent-${data.enfant_b_id}`)).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId(`sidscm-departure-${data.enfant_b_id}`)).toHaveCount(0);
  await expect.poll(() => attendanceRow(data.enfant_b_id, data.first_date, 'GS'), POLL)
    .toEqual({ present: 0, arrival_time: null, departure_time: null });
});

test('arrivée GM — heure préremplie, modifiée, conservée au rechargement', async ({ page }) => {
  const data = seed();
  await openDay(page, data, 'GM');

  await page.getByTestId(`sidscm-present-${data.enfant_a_id}`).click();
  await expect(page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`)).toHaveValue(/^\d{2}:\d{2}$/);
  // Le pointage (avec l'heure préremplie) est enregistré avant la modification.
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM')?.present ?? null, POLL).toBe(1);
  await page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`).fill('07:42');
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), POLL)
    .toEqual({ present: 1, arrival_time: '07:42:00', departure_time: null });

  // Rechargement : nouvelle authentification, l'état vient du serveur.
  await openDay(page, data, 'GM');
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-present\b/);
  await expect(page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`)).toHaveValue('07:42');
});

test('tous présents — un absent déjà marqué reste absent ; ✓ sur l\'onglet et pointage terminé', async ({ page }) => {
  const data = seed();
  await openDay(page, data, 'GS');

  await expect(page.getByTestId('sidscm-done')).toHaveCount(0);
  await expect(page.locator('[data-testid="sidscm-svc-GS"] .psc-sidscm-svc-done')).toHaveCount(0);

  await page.getByTestId(`sidscm-absent-${data.enfant_b_id}`).click();
  await expect.poll(() => attendanceRow(data.enfant_b_id, data.first_date, 'GS'), POLL)
    .toEqual({ present: 0, arrival_time: null, departure_time: null });

  // « Tous présents » ne marque que les enfants à pointer (Nina), avec
  // l'heure courante ; Marco, pointé absent, n'est jamais modifié.
  await expect(page.getByTestId('sidscm-all-present')).toBeEnabled();
  await page.getByTestId('sidscm-all-present').click();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-present\b/);
  await expect(page.getByTestId(`sidscm-departure-${data.enfant_a_id}`)).toHaveValue(/^\d{2}:\d{2}$/);
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toHaveClass(/\bis-absent\b/);
  const nina = attendanceRow(data.enfant_a_id, data.first_date, 'GS');
  expect(nina?.present).toBe(1);
  expect(nina?.departure_time).toMatch(/^\d{2}:\d{2}:00$/);
  expect(attendanceRow(data.enfant_b_id, data.first_date, 'GS')).toEqual({ present: 0, arrival_time: null, departure_time: null });

  // Plus personne à pointer : bouton désactivé, ✓ sur l'onglet, pied.
  await expect(page.getByTestId('sidscm-all-present')).toBeDisabled();
  await expect(page.locator('[data-testid="sidscm-svc-GS"] .psc-sidscm-svc-done')).toHaveAttribute('aria-label', 'pointage terminé');
  await expect(page.getByTestId('sidscm-svc-GS')).toHaveAccessibleName(/pointage terminé/);
  await expect(page.getByTestId('sidscm-done')).toContainText(/Pointage terminé : \d+ présents?, \d+ absents?\./);
  await expect(page.getByTestId('sidscm-present-count')).toContainText('0 à pointer');
  // Nina reste à pointer en Garderie matin : pas de ✓ sur cet onglet.
  await expect(page.locator('[data-testid="sidscm-svc-GM"] .psc-sidscm-svc-done')).toHaveCount(0);
});

test('échec d\'enregistrement — l\'état revient, la notice s\'affiche puis disparaît au succès suivant', async ({ page }) => {
  const data = seed();
  await openDay(page, data, 'GM');

  const failing = (action: string) => page.route('**/admin-ajax.php', async (route) => {
    if ((route.request().postData() ?? '').includes(`action=${action}`)) {
      await route.fulfill({ status: 500, contentType: 'text/html', body: 'Erreur serveur simulée' });
      return;
    }
    await route.continue();
  });

  await failing('psc_sidscm_toggle');
  await page.getByTestId(`sidscm-present-${data.enfant_a_id}`).click();
  const notice = page.getByTestId('sidscm-save-error');
  await expect(notice).toBeVisible();
  await expect(notice).toHaveAttribute('role', 'alert');
  await expect(notice).toContainText('Nina SidscmTest');
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-todo\b/);
  await expect(page.getByTestId(`sidscm-present-${data.enfant_a_id}`)).toHaveAttribute('aria-pressed', 'false');
  await expect(page.getByTestId(`sidscm-arrival-${data.enfant_a_id}`)).toHaveCount(0);
  expect(attendanceRow(data.enfant_a_id, data.first_date, 'GM')).toBeNull();

  // « Tous présents » en échec : rien ne change.
  await page.unroute('**/admin-ajax.php');
  await failing('psc_sidscm_all_present');
  await page.getByTestId('sidscm-all-present').click();
  await expect(notice).toBeVisible();
  await expect(page.getByTestId('sidscm-all-present')).toBeEnabled();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-todo\b/);
  expect(attendanceRow(data.enfant_a_id, data.first_date, 'GM')).toBeNull();

  // Retour du réseau : le pointage passe et la notice disparaît.
  await page.unroute('**/admin-ajax.php');
  await page.getByTestId(`sidscm-present-${data.enfant_a_id}`).click();
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM')?.present ?? null, POLL).toBe(1);
  await expect(notice).toBeHidden();
});

test('accessibilité — vue Jour sans violation axe avec des lignes à pointer, présentes et absentes', async ({ page }) => {
  const data = seed();
  // Troisième enfant du foyer de test, attendu en Garderie soir le premier
  // jour ouvert : la liste porte ainsi les trois états à la fois. Purgé à
  // la fin du test (et, à défaut, par la seed suivante, qui supprime tous
  // les enfants de ce foyer).
  const thirdId = Number(wpCliEval(
    `global $wpdb;
     $parent_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . psc_table('parents') . " WHERE email = %s", 'sidscm.e2e@example.test'));
     $wpdb->insert(psc_table('children'), array('parent_id' => $parent_id, 'nom' => 'SidscmTest', 'prenom' => 'Lou', 'created_at' => current_time('mysql')));
     $id = (int) $wpdb->insert_id;
     Psc_School_Years::enroll($id, Psc_School_Years::id_for_date('${data.first_date}'), 'CP');
     Psc_Planning::toggle_exception($id, '${data.first_date}', 'GS', true, true);
     Psc_Planning::flush_cache();
     echo $id;`
  ));
  expect(thirdId).toBeGreaterThan(0);
  try {
    await openDay(page, data, 'GS');
    await page.getByTestId(`sidscm-present-${data.enfant_a_id}`).click();
    await page.getByTestId(`sidscm-absent-${data.enfant_b_id}`).click();
    await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-present\b/);
    await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toHaveClass(/\bis-absent\b/);
    await expect(page.getByTestId(`sidscm-row-${thirdId}`)).toHaveClass(/\bis-todo\b/);
    await expect.poll(() => attendanceRow(data.enfant_b_id, data.first_date, 'GS')?.present ?? null, POLL).toBe(0);

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('#wpadminbar').analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)).toEqual([]);
  } finally {
    wpCliEval(
      `global $wpdb;
       $wpdb->delete(psc_table('attendance'), array('child_id' => ${thirdId}), array('%d'));
       Psc_Planning::delete_for_child(${thirdId});
       Psc_School_Years::unenroll(${thirdId}, Psc_School_Years::id_for_date('${data.first_date}'));
       $wpdb->delete(psc_table('children'), array('id' => ${thirdId}), array('%d'));`
    );
  }
});

test('cantine — chaque enfant affiche son régime alimentaire en jour et semaine', async ({ page }) => {
  const data = seed();
  await unlock(page, data);
  await page.getByTestId(`sidscm-day-${data.first_jour}`).click();
  await page.getByTestId('sidscm-svc-CANT').click();

  await expect(page.getByTestId(`sidscm-diet-${data.enfant_a_id}`)).toHaveText('Sans porc');
  await expect(page.getByTestId(`sidscm-diet-${data.enfant_b_id}`)).toHaveText('Standard');

  // Cantine : jamais d'heure, même pour un enfant pointé présent.
  await page.getByTestId(`sidscm-present-${data.enfant_a_id}`).click();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toHaveClass(/\bis-present\b/);
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`).locator('input[type="time"]')).toHaveCount(0);
  await expect.poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'CANT'), POLL)
    .toEqual({ present: 1, arrival_time: null, departure_time: null });

  await page.getByTestId('sidscm-mode-week').click();
  await expect(page.getByTestId(`sidscm-diet-${data.enfant_a_id}`)).toHaveText('Sans porc');
  await expect(page.getByTestId(`sidscm-diet-${data.enfant_b_id}`)).toHaveText('Standard');
});

test('semaines : navigation future et dates distinctes pour chaque service', async ({ page }) => {
  const data = seed();
  await unlock(page, data);
  await page.getByTestId('sidscm-mode-week').click();
  const select = page.getByTestId('sidscm-week-select');
  await expect(select.locator('option')).toHaveCount(9);
  await expect(page.locator('[data-day]')).toHaveCount(0);
  const weeks = await select.locator('option').evaluateAll(options => options.map(o => (o as HTMLOptionElement).value));
  const responsePromise = page.waitForResponse(async response => response.url().includes('admin-ajax.php') && (response.request().postData() || '').includes('psc_sidscm_data'));
  await select.selectOption(weeks[1]);
  const payload = (await (await responsePromise).json()).data;
  await expect(select).toBeEnabled();
  expect(payload.week).toBe(weeks[1]);
  for (const service of ['GM', 'CANT', 'GS']) {
    await page.getByTestId('sidscm-svc-' + service).click();
    const headers = page.locator('.psc-sidscm-week-day[scope="col"]');
    await expect(headers).toHaveCount(Object.keys(payload.days).length);
    for (const [day, date] of Object.entries(payload.days)) {
      const parts = String(date).split('-');
      await expect(headers.filter({ hasText: day.charAt(0).toUpperCase() + day.slice(1) })).toContainText(parts[2] + '/' + parts[1]);
    }
  }
  await page.getByTestId('sidscm-mode-day').click();
  await expect(page.getByTestId('sidscm-day-' + data.first_jour)).toBeVisible();
  await expect(select).toHaveCount(0);
});

test('cohérence jour/service — pointage et heures forgés refusés, aucune ligne créée', async ({ page, request }) => {
  const data = seed();
  await unlock(page, data);

  // Le nonce prouve l'intention (PSC_SIDSCM, localisé par
  // wp_localize_script) ; c'est la vérification jour/service côté serveur
  // (Psc_Sidscm::require_day_service()) qui refuse la donnée : le pointage
  // ne peut viser que ce que l'écran affiche, même avec un code valide.
  const nonce = await page.evaluate(
    () => (window as unknown as { PSC_SIDSCM?: { nonce?: string } }).PSC_SIDSCM?.nonce
  );
  expect(nonce).toBeTruthy();

  const ajaxUrl = `${new URL(data.page_url).origin}/wp-admin/admin-ajax.php`;
  const forgedToggle = (childId: number, date: string, service: string) =>
    request.post(ajaxUrl, {
      form: {
        action: 'psc_sidscm_toggle',
        code: data.access_code,
        nonce: nonce as string,
        child_id: String(childId),
        jour_date: date,
        service,
        state: 'absent',
      },
    });
  const errorBody = async (resp: Awaited<ReturnType<typeof forgedToggle>>) =>
    (await resp.json()) as { success: boolean; data: { code: string } };

  // Jour non ouvert : le mercredi de la même semaine — jamais dans
  // psc_open_days() (cf. psc_service_jour_offsets(), lundi/mardi/jeudi/
  // vendredi seulement). Refusé avant toute écriture.
  const mercredi = wpCliEval(
    `$monday = psc_week_start('${data.first_date}');
     echo gmdate('Y-m-d', strtotime($monday . ' +2 days'));`
  );
  const respNotOpen = await forgedToggle(data.enfant_a_id, mercredi, 'GM');
  expect(await errorBody(respNotOpen)).toEqual({ success: false, data: { code: 'not_open' } });
  expect(attendanceRow(data.enfant_a_id, mercredi, 'GM')).toBeNull();

  // Jour ouvert mais enfant non attendu au service : Marco n'est pas inscrit
  // en Garderie matin — un pointage GM à son sujet est refusé.
  const respNotExpected = await forgedToggle(data.enfant_b_id, data.first_date, 'GM');
  expect(await errorBody(respNotExpected)).toEqual({ success: false, data: { code: 'not_expected' } });
  expect(attendanceRow(data.enfant_b_id, data.first_date, 'GM')).toBeNull();

  // Heure d'arrivée ou de départ pour un enfant à pointer : refusée
  // (not_present), aucune ligne n'est créée pour une heure seule.
  const forgedTime = (action: string, param: string, childId: number, time: string) =>
    request.post(ajaxUrl, {
      form: { action, code: data.access_code, nonce: nonce as string, child_id: String(childId), jour_date: data.first_date, [param]: time },
    });
  const respDeparture = await forgedTime('psc_sidscm_departure', 'departure_time', data.enfant_b_id, '17:05');
  expect(respDeparture.status()).toBe(400);
  expect(await errorBody(respDeparture)).toEqual({ success: false, data: { code: 'not_present' } });
  expect(attendanceRow(data.enfant_b_id, data.first_date, 'GS')).toBeNull();
  const respArrival = await forgedTime('psc_sidscm_arrival', 'arrival_time', data.enfant_a_id, '07:42');
  expect(respArrival.status()).toBe(400);
  expect(await errorBody(respArrival)).toEqual({ success: false, data: { code: 'not_present' } });
  expect(attendanceRow(data.enfant_a_id, data.first_date, 'GM')).toBeNull();
});

test('pointages simultanés (double appui) — tous enregistrés, une seule ligne', async ({ page, request }) => {
  const data = seed();
  await unlock(page, data);

  const nonce = await page.evaluate(
    () => (window as unknown as { PSC_SIDSCM?: { nonce?: string } }).PSC_SIDSCM?.nonce
  );
  expect(nonce).toBeTruthy();

  // Aucune ligne au départ (seed) : des appels parallèles sur le même
  // enfant × jour × service se disputent la création de la ligne. Chacun
  // doit répondre succès et la base n'en garder qu'une (écriture atomique
  // de Psc_Sidscm::save_attendance()).
  const ajaxUrl = `${new URL(data.page_url).origin}/wp-admin/admin-ajax.php`;
  const responses = await Promise.all(
    Array.from({ length: 4 }, () =>
      request.post(ajaxUrl, {
        form: {
          action: 'psc_sidscm_toggle',
          code: data.access_code,
          nonce: nonce as string,
          child_id: String(data.enfant_a_id),
          jour_date: data.first_date,
          service: 'GM',
          state: 'absent',
        },
      })
    )
  );
  for (const resp of responses) {
    expect(await resp.json()).toEqual({ success: true, data: { state: 'absent', time: null } });
  }
  expect(attendanceCount(data.enfant_a_id, data.first_date, 'GM')).toBe(1);
  expect(attendanceRow(data.enfant_a_id, data.first_date, 'GM')).toEqual({
    present: 0,
    arrival_time: null,
    departure_time: null,
  });
});

test('contact d’urgence — numéros des parents sur demande, sur chaque service, journalisés', async ({ page, request }) => {
  const data = seed();
  // Numéros du foyer de la seed (titulaire mobile + fixe, second parent).
  const parentId = Number(wpCliEval(
    `global $wpdb; $t = psc_table('parents');
     $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE email = %s", 'sidscm.e2e@example.test'));
     $wpdb->update($t, array('prenom' => 'Claire', 'telephone_mobile' => '06 12 34 56 78', 'telephone_fixe' => '01 30 00 00 00',
       'second_parent_prenom' => 'Paul', 'second_parent_nom' => 'SidscmTest', 'second_parent_telephone' => '+33 7 11 22 33 44'), array('id' => $id));
     echo $id;`
  ));
  const consultations = () => Number(wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . psc_table('audit_log') . " WHERE action = 'intervenant.contacts_consultes' AND resultat = 'succes' AND acteur_type = 'intervenant' AND enfant_id = %d AND famille_id = %d", ${data.enfant_a_id}, ${parentId}));`
  ));
  const before = consultations();

  // Le fixe du titulaire ne part qu'à la demande : la liste du jour n'en
  // porte aucun (les « Personnes autorisées » de la garderie du soir ne
  // reprennent qu'un numéro par parent, le mobile).
  const dataResponse = page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() ?? '').includes('psc_sidscm_data'));
  await unlock(page, data);
  expect(await (await dataResponse).text()).not.toContain('01 30 00 00 00');

  await page.getByTestId(`sidscm-day-${data.first_jour}`).click();
  for (const svc of ['GM', 'CANT', 'GS']) {
    await page.getByTestId(`sidscm-svc-${svc}`).click();
    await expect(page.getByTestId(`sidscm-contacts-toggle-${data.enfant_a_id}`), svc).toBeVisible();
  }

  await page.getByTestId('sidscm-svc-CANT').click();
  const toggle = () => page.getByTestId(`sidscm-contacts-toggle-${data.enfant_a_id}`);
  await expect(toggle()).toHaveAttribute('aria-expanded', 'false');
  await toggle().focus();
  await page.keyboard.press('Enter');
  const panel = page.getByTestId(`sidscm-contacts-panel-${data.enfant_a_id}`);
  await expect(panel).toContainText('Titulaire');
  await expect(panel).toContainText('Claire SidscmTest');
  await expect(panel).toContainText('Second parent');
  await expect(panel).toContainText('Paul SidscmTest');
  await expect(toggle()).toHaveAttribute('aria-expanded', 'true');
  await expect(toggle()).toBeFocused();
  expect(await panel.locator('a[href^="tel:"]').evaluateAll((as) => as.map((a) => a.getAttribute('href'))))
    .toEqual(['tel:0612345678', 'tel:0130000000', 'tel:+33711223344']);
  await expect(panel.getByRole('link', { name: /Appeler Claire SidscmTest \(Mobile\)/ })).toBeVisible();

  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('#wpadminbar').analyze();
  expect(axe.violations.map((v) => `${v.id} : ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)).toEqual([]);

  // Consultation journalisée : quel intervenant, quel enfant, quelle famille.
  await expect.poll(consultations, { intervals: [500], timeout: 10_000 }).toBe(before + 1);

  // Droits : sans code valide, ou pour un enfant non attendu cette semaine, rien n'est renvoyé.
  const nonce = await page.evaluate(() => (window as unknown as { PSC_SIDSCM: { nonce: string } }).PSC_SIDSCM.nonce);
  const ajaxUrl = `${new URL(data.page_url).origin}/wp-admin/admin-ajax.php`;
  const ask = (form: Record<string, string>) => request.post(ajaxUrl, { form: { action: 'psc_sidscm_contacts', nonce, ...form } });
  const noCode = await ask({ code: '', child_id: String(data.enfant_a_id) });
  expect(noCode.status()).toBe(403);
  expect(await noCode.text()).not.toContain('06 12');
  const notExpected = await ask({ code: data.access_code, child_id: '999999999' });
  expect(await notExpected.json()).toEqual({ success: false, data: { code: 'not_expected' } });
});

});
