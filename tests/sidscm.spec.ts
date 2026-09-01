/**
 * Espace intervenants (SIDSCM) — déverrouillage, pointage aller/retour,
 * re-pointage, heure de départ.
 *
 * Le contrat de l'écran : les enfants attendus sont PRÉSENTS par défaut
 * (toute ligne wp_psc_attendance absente vaut present=1) — le décochage
 * est l'action à fort enjeu, c'est elle que ce scénario verrouille en
 * premier. La seed dédiée (bin/seed-sidscm.php) crée justement la
 * famille et les inscriptions SANS aucune ligne de pointage.
 *
 * Chaque pointage est vérifié par l'état réel de la base (wpCliEval),
 * pas par le compteur "X / Y présents" : le site de CI porte les
 * inscriptions des autres seeds, ce compteur n'y est pas déterministe.
 *
 * Le toggle est en "optimistic UI" : la vue est intégralement re-rendue
 * après le clic (innerHTML), les éléments changent donc d'identité à
 * chaque interaction — les sélecteurs sont systématiquement relus après
 * chaque action (jamais de référence conservée), et l'aller/retour AJAX
 * est attendu via expect.poll sur la base plutôt que sur le DOM.
 *
 * Appels consommés par run : 1 mauvais code (seau partagé sidscm_bad_,
 * 20/h tous endpoints confondus — le scénario s'en tient là), 4
 * déverrouillages, 4 chargements de semaine, 3 pointages, 1 départ,
 * 2 pointages forgés refusés par la cohérence jour/service : très en
 * dessous des quatre rate-limits de l'écran.
 */

import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
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

/** Ligne de pointage (enfant × jour × service) ou null si absente. */
function attendanceRow(
  childId: number,
  date: string,
  service: string
): { present: number; departure_time: string | null } | null {
  const out = wpCliEval(
    `global $wpdb;
     $row = $wpdb->get_row($wpdb->prepare(
       "SELECT present, departure_time FROM {$wpdb->prefix}psc_attendance
        WHERE child_id = %d AND jour_date = %s AND service = %s",
       ${childId}, '${date}', '${service}'
     ));
     echo wp_json_encode($row
       ? array('present' => (int) $row->present, 'departure_time' => $row->departure_time)
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

test('pointage GM — décoché repasse absent, recoché revient présent, re-pointage sans doublon', async ({ page }) => {
  const data = seed();
  await unlock(page, data);

  // Vue "jour" par défaut, onglet Garderie matin par défaut ; on clique
  // explicitement le premier jour ouvert de la semaine pour épingler la
  // date de toutes les vérifications suivantes (data.first_date).
  await page.getByTestId(`sidscm-day-${data.first_jour}`).click();

  // Nina est inscrite GM + CANT + GS tous les jours ouverts : sa case est
  // cochée sans qu'aucune ligne de pointage n'existe (présent par défaut).
  await expect(page.getByTestId(`sidscm-check-${data.enfant_a_id}`)).toBeChecked();
  // Marco n'est inscrit qu'en Garderie soir : pas de ligne sur l'onglet GM.
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toBeHidden();

  // Décochage : la vue est re-rendue après le clic (innerHTML), le
  // sélecteur est donc relu à chaque action, et l'aller/retour AJAX est
  // attendu sur la base — pas sur le DOM détaché.
  await page.getByTestId(`sidscm-check-${data.enfant_a_id}`).click();
  await expect
    .poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), { intervals: [1_000], timeout: 15_000 })
    .toEqual({ present: 0, departure_time: null });

  // Recochage : retour à présent=1.
  await page.getByTestId(`sidscm-check-${data.enfant_a_id}`).click();
  await expect
    .poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), { intervals: [1_000], timeout: 15_000 })
    .toEqual({ present: 1, departure_time: null });

  // Re-pointage : le cycle complet laisse exactement UNE ligne
  // (upsert enfant × jour × service, jamais un doublon).
  await page.getByTestId(`sidscm-check-${data.enfant_a_id}`).click();
  await expect
    .poll(() => attendanceRow(data.enfant_a_id, data.first_date, 'GM'), { intervals: [1_000], timeout: 15_000 })
    .toEqual({ present: 0, departure_time: null });
  expect(attendanceCount(data.enfant_a_id, data.first_date, 'GM')).toBe(1);
});

test('départ GS — l\'heure de départ crée la ligne sans toucher au pointage de présence', async ({ page }) => {
  const data = seed();
  await unlock(page, data);

  await page.getByTestId(`sidscm-day-${data.first_jour}`).click();
  await page.getByTestId('sidscm-svc-GS').click();

  // Nina et Marco sont tous deux inscrits en Garderie soir ce jour.
  await expect(page.getByTestId(`sidscm-row-${data.enfant_a_id}`)).toBeVisible();
  await expect(page.getByTestId(`sidscm-row-${data.enfant_b_id}`)).toBeVisible();

  // Aucun pointage préalable : saisir une heure de départ part d'une
  // ligne inexistante — c'est le cas d'école "le départ crée la ligne".
  expect(attendanceRow(data.enfant_b_id, data.first_date, 'GS')).toBeNull();

  await page.getByTestId(`sidscm-departure-${data.enfant_b_id}`).fill('17:05');

  // La ligne créée porte l'heure (format TIME MySQL HH:MM:SS) et la
  // présence garde sa valeur par défaut (1) : le départ n'écrase jamais
  // un pointage — cf. Psc_Sidscm::ajax_set_departure(), l'insert omet
  // volontairement la colonne present.
  await expect
    .poll(() => attendanceRow(data.enfant_b_id, data.first_date, 'GS'), { intervals: [1_000], timeout: 15_000 })
    .toEqual({ present: 1, departure_time: '17:05:00' });
});

test('cohérence jour/service — pointage forgé refusé, aucune ligne créée', async ({ page, request }) => {
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
        present: '0',
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

  // Jour ouvert mais enfant non attendu au service : Marco n'est inscrit
  // qu'en Garderie soir — un pointage cantine à son sujet est refusé.
  const respNotExpected = await forgedToggle(data.enfant_b_id, data.first_date, 'GM');
  expect(await errorBody(respNotExpected)).toEqual({ success: false, data: { code: 'not_expected' } });
  expect(attendanceRow(data.enfant_b_id, data.first_date, 'GM')).toBeNull();
});

});
