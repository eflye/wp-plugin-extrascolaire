/**
 * Parcours "parent déjà connu" — écrit à la main depuis
 * journeys/parent-connu.md (aucun parseur du markdown). Un seul fichier
 * pour les deux profils Playwright (test/demo, cf. playwright.config.ts) :
 * la séquence de scènes est unique, seule l'implémentation de `scene.*`
 * change selon testInfo.project.name — en profil "test" ce sont des no-op
 * immédiats, en profil "demo" ce sont les vrais helpers d'habillage vidéo
 * (helpers/demo-overlay.ts, helpers/mouse-helper.ts). Aucune date ni index
 * en dur : tout vient de .playwright/seed.<profile>.json, écrit par
 * playwright/global-setup.ts à partir de la sortie de bin/seed-journey.php.
 *
 * `duration` (journeys/parent-connu.md, bloc Vidéo) est un BUDGET de
 * scène, pas une pause ajoutée après coup : playScene() chronomètre le
 * début de la scène, joue l'action technique, puis n'attend que le reste
 * du budget (jamais négatif). Le réel vs. le budget de chaque scène est
 * loggé à la fin du run.
 */

import { test, expect, type Response } from '@playwright/test';
import { readSeedResult } from '../playwright/seed-result';
import { installDemoOverlay, spotlight, subtitle, carton } from '../helpers/demo-overlay';
import { installMouseHelper } from '../helpers/mouse-helper';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const APP_PAGE = `${APP_BASE}/?page_id=6`;

// includes/helpers.php:psc_services() — valeurs par défaut. Pas exposées
// par le contrat de sortie de bin/seed-journey.php (qui documente
// open_day/locked_day/mois/parent/enfants, pas les tarifs) : à faire
// évoluer ensemble si psc_service_prices est un jour surchargé en base.
const SERVICE_PRICES = { GM: 1.85, CANT: 5.8, GS: 4.7, FORF: 11.7 } as const;

const JOURS_FR = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
/** Reproduit includes/helpers.php:psc_day_label() + date_i18n('d/m/Y', ...) côté Node. */
function dayLabelFr(iso: string): string {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  const dow = (date.getUTCDay() + 6) % 7; // 0 = lundi, comme date('N') - 1
  return `${JOURS_FR[dow]} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

const monthKeyOf = (iso: string) => iso.slice(0, 7);

/* ------------------------------------------------------------------ */

test('parent déjà connu — de la connexion au récapitulatif', async ({ page, context }, testInfo) => {
  const profile = testInfo.project.name === 'demo' ? 'demo' : 'test';
  const isDemo = profile === 'demo';
  const seed = readSeedResult(profile);

  if (!seed.open_day || !seed.locked_day) {
    throw new Error(
      `seed.${profile}.json : open_day/locked_day manquant(s) — relancer bin/seed-journey.php --profile=${profile}.`
    );
  }
  const openDay = seed.open_day;
  const lockedDay = seed.locked_day;
  // fixtures.md : le parcours ne déclare que le premier enfant (index 0
  // dans la boucle $children, triée par prénom) — dérivé du seed, jamais
  // un "0" en dur.
  const childIndex = seed.enfants[0].index;

  const testid = {
    monthToggle: (m: string) => `month-toggle-${childIndex}-${m}`,
    monthSummary: (m: string) => `month-summary-${childIndex}-${m}`,
    calendarTable: (m: string) => `calendar-table-${childIndex}-${m}`,
    dayRow: (d: string) => `day-row-${childIndex}-${d}`,
    cell: (d: string, s: string) => `cell-${childIndex}-${d}-${s}`,
    check: (d: string, s: string) => `check-${childIndex}-${d}-${s}`,
  };

  /**
   * Lit l'état réel affiché ("Aucun jour déclaré" ou "N jour(s) · X €")
   * plutôt que de supposer un mois vide au départ : le profil demo
   * pré-coche délibérément quelques jours (bin/seed-journey.php), donc le
   * seul état fiable est celui constaté avant/après chaque action.
   */
  async function readMonthSummary(testId: string): Promise<{ days: number; total: number }> {
    const text = (await page.getByTestId(testId).innerText()).replace(/\s+/g, ' ').trim();
    if (text.includes('Aucun jour déclaré')) return { days: 0, total: 0 };
    const m = text.match(/(\d+)\s*jours?\s*·\s*([\d,.]+)\s*€/);
    if (!m) throw new Error(`Résumé de mois illisible pour ${testId} : "${text}"`);
    return { days: parseInt(m[1], 10), total: parseFloat(m[2].replace(',', '.')) };
  }

  /**
   * Habillage vidéo : appels présents inconditionnellement dans le
   * déroulé ci-dessous (une seule source pour test et démo), mais no-op
   * immédiats hors profil demo — c'est le seul point de branchement sur
   * testInfo.project.name de tout ce fichier.
   */
  const scene = isDemo
    ? {
        spotlight: (t: string | null) => spotlight(page, t),
        subtitle: (t: string, ms: number) => subtitle(page, t, ms),
        carton: (t: string, ms: number) => carton(page, t, ms),
      }
    : {
        spotlight: async (_t: string | null) => {},
        subtitle: async (_t: string, _ms: number) => {},
        carton: async (_t: string, _ms: number) => {},
      };

  const sceneLogs: Array<{ id: string; budgetMs: number; actualMs: number }> = [];

  /**
   * Joue une scène du storyboard : chronomètre le début, met en avant
   * `focus`, exécute l'action technique (le vrai travail — clics,
   * assertions, requêtes), puis complète UNIQUEMENT le reste du budget
   * avec le sous-titre (jamais une pause qui s'ajoute après l'action).
   * En profil test, scene.subtitle() est un no-op immédiat : le padding
   * ne coûte rien, seule l'action compte pour la durée réelle du test.
   */
  async function playScene(
    id: string,
    budgetMs: number,
    focus: string | null,
    narration: string,
    action: () => Promise<void>
  ): Promise<void> {
    const start = Date.now();
    await scene.spotlight(focus);
    await action();
    const elapsed = Date.now() - start;
    const remaining = Math.max(0, budgetMs - elapsed);
    await scene.subtitle(narration, remaining);
    sceneLogs.push({ id, budgetMs, actualMs: Date.now() - start });
  }

  if (isDemo) {
    await installDemoOverlay(context);
    await installMouseHelper(context);
    // Masque le curseur natif : seul le point mouse-helper doit rester
    // visible dans la vidéo.
    await context.addInitScript(() => {
      const style = document.createElement('style');
      style.textContent = '* { cursor: none !important; }';
      (document.head || document.documentElement).appendChild(style);
    });
    // Un carton (00) doit s'afficher avant toute navigation applicative :
    // on force un document réel (about:blank) pour que les addInitScript
    // ci-dessus s'appliquent avant le premier appel à scene.carton().
    await page.goto('about:blank');
  }

  /* ---------------- 00-carton-titre (demo uniquement) ---------------- */
  if (isDemo) {
    const start = Date.now();
    await scene.carton("Inscription périscolaire — du papier à l'écran", 3_000);
    sceneLogs.push({ id: '00-carton-titre', budgetMs: 3_000, actualMs: Date.now() - start });
  }

  /* ---------------- 01-arrivee-page-connexion + 02-saisie-email ---------------- */
  // Scène partagée (journeys/parent-connu.md) : un seul budget pour les
  // deux étapes techniques.
  await playScene(
    '01-02-arrivee-et-email',
    5_000,
    'login-form',
    "Aucun compte à créer : le parent saisit son e-mail",
    async () => {
      await page.goto(APP_PAGE);
      await expect(page.getByTestId('login-card')).toBeVisible();
      await expect(page.getByTestId('login-card').locator('h2')).toHaveText('Déclarer les jours de présence');
      await expect(page.getByTestId('login-email-input')).toHaveValue('');

      await page.getByTestId('login-email-input').fill(seed.parent_email);
      await expect(page.getByTestId('login-email-input')).toHaveValue(seed.parent_email);
    }
  );

  /* ---------------- 03-demande-lien-connexion + 04-consultation-email-connexion ---------------- */
  let loginLink = '';
  await playScene(
    '03-04-demande-et-reception-lien',
    5_000,
    'overlay',
    "Il reçoit un lien d'accès valable 30 minutes",
    async () => {
      // N'attend pas spécifiquement ?psc_msg=link_sent : un rejet serveur
      // (nonce expiré, validation, rate-limit...) redirige vers un AUTRE
      // psc_msg, et attendre une regex qui ne matchera jamais dégénère en
      // timeout de 10-30s indiscernable d'un vrai blocage réseau. On attend
      // n'importe quel notice, vite, puis on vérifie lequel c'est.
      const anyNotice = page.locator('[data-testid^="notice-"]');
      await Promise.all([
        anyNotice.waitFor({ state: 'visible', timeout: 2_000 }),
        page.getByTestId('login-submit-button').click(),
      ]);
      const noticeTestid = await anyNotice.getAttribute('data-testid');
      expect(
        noticeTestid,
        `redirection inattendue après la demande de lien (rejet serveur probable) : ${noticeTestid}`
      ).toBe('notice-link_sent');
      await expect(page.getByTestId('notice-link_sent')).toContainText(
        "un lien de connexion vient d'être envoyé"
      );

      // 04 : lecture via l'API Mailpit, jamais via l'UI/iframe de prévisualisation.
      const loginMail = await findLatestMessage(
        seed.parent_email,
        "Votre lien d'accès aux inscriptions périscolaires"
      );
      const loginLinkMatch = loginMail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
      expect(loginLinkMatch, `lien de connexion introuvable dans l'e-mail :\n${loginMail.Text}`).toBeTruthy();
      loginLink = loginLinkMatch![0];
      expect(loginLink).toContain(`psc_pid=${seed.parent_id}&`);
    }
  );

  /* ---------------- 05-ouverture-lien-de-connexion ---------------- */
  await playScene(
    '05-ouverture-lien-connexion',
    5_000,
    'account-bar',
    'Il arrive directement sur le planning de ses enfants',
    async () => {
      await page.goto(loginLink);
      await expect(page.getByTestId('account-bar')).toBeVisible();
      await expect(page.getByTestId('notice-welcome')).toBeVisible();
      await expect(page.getByTestId('notice-welcome')).toHaveText('Vous êtes connecté.');
      await expect(page.getByTestId('account-email')).toHaveText(seed.parent_email);
      const cookies = await context.cookies();
      expect(cookies.some((c) => c.name === 'psc_session')).toBe(true);
    }
  );

  /* ---------------- 06-ouverture-du-mois ---------------- */
  const openMonth = monthKeyOf(openDay);
  let summaryBeforeToggle = { days: 0, total: 0 };
  await playScene(
    '06-ouverture-du-mois',
    4_000,
    testid.calendarTable(openMonth),
    'Un calendrier par enfant, mois par mois',
    async () => {
      await page.getByTestId(testid.monthToggle(openMonth)).click();
      await expect(page.getByTestId(testid.calendarTable(openMonth))).toBeVisible();
      await expect(page.getByTestId(testid.dayRow(openDay))).toBeVisible();
      // Pas d'hypothèse "Aucun jour déclaré" : le profil demo pré-coche
      // délibérément des jours proches qui peuvent tomber dans le même
      // mois qu'open_day (bin/seed-journey.php). 07/08 vérifient un delta
      // par rapport à cet état réel, jamais un total absolu.
      summaryBeforeToggle = await readMonthSummary(testid.monthSummary(openMonth));
    }
  );

  /* ---------------- 07-cocher-cantine-jour-ouvert ---------------- */
  let summaryAfterCant = summaryBeforeToggle;
  await playScene(
    '07-cocher-cantine',
    5_000,
    testid.cell(openDay, 'CANT'),
    'Chaque case cochée est enregistrée aussitôt',
    async () => {
      const toggleCant = page.waitForResponse(
        (res) => res.url().includes('admin-ajax.php') && (res.request().postData() ?? '').includes('action=psc_toggle')
      );
      await page.getByTestId(testid.check(openDay, 'CANT')).click();
      await toggleCant; // jamais l'animation .psc-ok, cf. journeys/parent-connu.md #07

      await expect(page.getByTestId(testid.check(openDay, 'CANT'))).toBeChecked();
      summaryAfterCant = await readMonthSummary(testid.monthSummary(openMonth));
      // open_day n'était pas encore déclaré avant cette étape (distinct par
      // construction des jours proches pré-cochés par le seed demo — cf.
      // règle open_day dans journeys/parent-connu.md) : un nouveau jour
      // apparaît donc forcément dans le compte.
      expect(summaryAfterCant.days).toBe(summaryBeforeToggle.days + 1);
      expect(summaryAfterCant.total).toBeCloseTo(summaryBeforeToggle.total + SERVICE_PRICES.CANT, 2);
    }
  );

  /* ---------------- 08-cocher-garderie-matin-meme-jour ---------------- */
  await playScene(
    '08-cocher-garderie-matin',
    4_000,
    testid.monthSummary(openMonth),
    'Le total du mois se met à jour en direct',
    async () => {
      const toggleGm = page.waitForResponse(
        (res) => res.url().includes('admin-ajax.php') && (res.request().postData() ?? '').includes('action=psc_toggle')
      );
      await page.getByTestId(testid.check(openDay, 'GM')).click();
      await toggleGm;

      await expect(page.getByTestId(testid.check(openDay, 'GM'))).toBeChecked();
      const summaryAfterGm = await readMonthSummary(testid.monthSummary(openMonth));
      // Même jour que l'étape 07 : le nombre de jours ne change pas, seul
      // le montant augmente (cumul des deux prestations sur open_day).
      expect(summaryAfterGm.days).toBe(summaryAfterCant.days);
      expect(summaryAfterGm.total).toBeCloseTo(summaryBeforeToggle.total + SERVICE_PRICES.CANT + SERVICE_PRICES.GM, 2);
    }
  );

  /* ---------------- 09-jour-verrouille-non-cliquable (2 prises, 1 seule action) ---------------- */
  await playScene(
    '09-jour-verrouille-prise-1',
    5_000,
    testid.dayRow(lockedDay),
    'Les jours à moins de 48 h sont verrouillés',
    async () => {
      let toggleFiredOnLockedDay = false;
      const onResponse = (res: Response) => {
        if (res.url().includes('admin-ajax.php') && (res.request().postData() ?? '').includes('action=psc_toggle')) {
          toggleFiredOnLockedDay = true;
        }
      };
      page.on('response', onResponse);
      await page.getByTestId(testid.check(lockedDay, 'CANT')).click({ force: true });
      await page.waitForTimeout(500); // attente négative : on confirme l'absence de requête
      page.off('response', onResponse);

      expect(toggleFiredOnLockedDay).toBe(false);
      await expect(page.getByTestId(testid.check(lockedDay, 'CANT'))).toBeDisabled();
      await expect(page.getByTestId(testid.check(lockedDay, 'CANT'))).toHaveAttribute(
        'aria-label',
        /\(non modifiable\)/
      );
      await expect(page.getByTestId(testid.dayRow(lockedDay))).toHaveClass(/psc-row-locked/);
    }
  );
  // Deuxième prise : "idem, on reste" (journeys/parent-connu.md #09) — même
  // écran, aucune action technique supplémentaire entre les deux.
  await playScene(
    '09-jour-verrouille-prise-2',
    5_000,
    testid.dayRow(lockedDay),
    'Le prestataire reçoit des effectifs fiables',
    async () => {}
  );

  /* ---------------- 10-valider-planning ---------------- */
  await playScene(
    '10-valider-planning',
    5_000,
    'confirm-button',
    'Le parent valide et reçoit son récapitulatif',
    async () => {
      await page.getByTestId('confirm-button').click();
      const feedback = page.getByTestId('confirm-feedback');
      await expect(feedback).not.toBeEmpty();
      await expect(feedback).toContainText(`Récapitulatif envoyé à ${seed.parent_email}.`);
      await expect(feedback).toHaveClass(/psc-ok-text/);
    }
  );

  /* ---------------- 11-verification-email-recap ---------------- */
  await playScene(
    '11-verification-email-recap',
    5_000,
    'overlay',
    'Une trace écrite, côté parent comme côté mairie',
    async () => {
      const recapMail = await findLatestMessage(
        seed.parent_email,
        'Confirmation de votre planning périscolaire'
      );
      const openDayLabel = dayLabelFr(openDay);

      // Pas d'assertion sur un total absolu ici : en profil demo, le total
      // du mois inclut aussi les jours pré-cochés par le seed. Le delta
      // CANT+GM est déjà vérifié en direct sur le DOM aux étapes 07/08 ;
      // ce qui reste à prouver, c'est que ces deux ajouts sont bien listés
      // comme nouveaux dans le bloc de diff de l'e-mail.
      expect(recapMail.HTML, "ligne du jour ouvert absente du récapitulatif").toContain(openDayLabel);
      expect(recapMail.HTML).toContain('Cantine');
      expect(recapMail.HTML).toContain('Garderie Matin');
      expect(
        recapMail.HTML,
        'bloc de modifications absent — le transient psc_recap_snap_* a-t-il bien été purgé avant ce run (bloc env de journeys/parent-connu.md) ?'
      ).toContain('Modifications depuis votre dernier récapitulatif');
      const additionCount = (recapMail.HTML.match(/\+ Ajout/g) ?? []).length;
      expect(additionCount, 'deux ajouts attendus : Cantine et Garderie Matin sur le jour ouvert').toBeGreaterThanOrEqual(2);
    }
  );

  /* ---------------- 12-carton-fin (demo uniquement) ---------------- */
  if (isDemo) {
    const start = Date.now();
    await scene.carton('Plus de fichier papier. Zéro ressaisie au secrétariat.', 5_000);
    sceneLogs.push({ id: '12-carton-fin', budgetMs: 5_000, actualMs: Date.now() - start });
  }

  /* ---------------- bilan réel vs. budget ---------------- */
  const totalBudget = sceneLogs.reduce((s, l) => s + l.budgetMs, 0);
  const totalActual = sceneLogs.reduce((s, l) => s + l.actualMs, 0);
  console.log(`\n[${profile}] Réel vs. budget par scène :`);
  for (const l of sceneLogs) {
    const over = l.actualMs > l.budgetMs ? `  ⚠ dépassement +${l.actualMs - l.budgetMs}ms` : '';
    console.log(`  ${l.id.padEnd(30)} budget=${String(l.budgetMs).padStart(6)}ms  réel=${String(l.actualMs).padStart(6)}ms${over}`);
  }
  console.log(`  ${'TOTAL'.padEnd(30)} budget=${String(totalBudget).padStart(6)}ms  réel=${String(totalActual).padStart(6)}ms`);
});
