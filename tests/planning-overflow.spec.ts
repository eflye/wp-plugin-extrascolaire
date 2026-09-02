/**
 * Test 7 de la spécification v4 — aucun débordement horizontal :
 *
 *   document.documentElement.scrollWidth === clientWidth
 *
 * sur les deux écrans Planning (variante 1 « jour par jour », variante 2
 * « rythme + exceptions ») et sur « Mes enfants », aux trois largeurs de
 * la spécification : 916 px, 768 px et 375 px.
 *
 * Le risque est documenté dans le gabarit : la largeur min-content d'un
 * tableau (jour × service, 7 colonnes d'enfants) force la piste de grille
 * si un `min-width: 0` manque sur les panneaux, et fait déborder la page
 * ENTIÈRE — le scrollWidth du document devient supérieur au clientWidth
 * même là où un défilement interne (overflow-x:auto) était prévu.
 *
 * La connexion suit le parcours du spec parent-connu : demande de lien
 * par e-mail (Mailpit), ouverture du lien, session parent établie.
 */

import { test, expect } from '@playwright/test';
import { readSeedResult, readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';

const VIEWPORTS = [916, 768, 375] as const;

async function loginAsSeedParent(page: import('@playwright/test').Page) {
  const seed = readSeedResult('test');
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(seed.parent_email);
  const anyNotice = page.locator('[data-testid^="notice-"]');
  await Promise.all([
    anyNotice.waitFor({ state: 'visible', timeout: 5_000 }),
    page.getByTestId('login-submit-button').click(),
  ]);
  const noticeTestid = await anyNotice.getAttribute('data-testid');
  expect(noticeTestid, 'redirection inattendue après la demande de lien').toBe('notice-link_sent');
  const loginMail = await findLatestMessage(seed.parent_email, 'Votre lien d\'accès aux inscriptions périscolaires');
  const loginLinkMatch = loginMail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(loginLinkMatch, 'lien de connexion introuvable dans le mail').toBeTruthy();
  await page.goto(loginLinkMatch![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
  return seed;
}

async function expectNoHorizontalOverflow(page: import('@playwright/test').Page, label: string) {
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(
    overflow.scrollWidth,
    `${label} : scrollWidth ${overflow.scrollWidth} > clientWidth ${overflow.clientWidth} — la page déborde horizontalement`
  ).toBeLessThanOrEqual(overflow.clientWidth);
  expect(overflow.scrollWidth).toBe(overflow.clientWidth);
}

test.describe('Planning : aucun débordement horizontal (spéc v4, test 7)', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsSeedParent(page);
  });

  for (const width of VIEWPORTS) {
    test(`Planning - 1, Planning - 2 et Mes enfants à ${width} px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });

      // Planning - 1 (saisie jour par jour).
      await page.goto(APP_BASE + '/?psc_tab=cantine');
      await expect(page.getByTestId('cantine-title')).toBeVisible();
      await expectNoHorizontalOverflow(page, `Planning - 1 @ ${width}px`);

      // Planning - 2 (rythme + exceptions), même modèle, autres zones.
      await page.goto(APP_BASE + '/?psc_tab=cantine2');
      await expect(page.getByTestId('cantine2-title')).toBeVisible();
      await expectNoHorizontalOverflow(page, `Planning - 2 @ ${width}px`);

      // Mes enfants : 7 colonnes dont un champ libre (allergies).
      await page.goto(APP_BASE + '/?psc_tab=enfants');
      await expect(page.getByTestId('enfants-title')).toBeVisible();
      await expectNoHorizontalOverflow(page, `Mes enfants @ ${width}px`);
    });
  }
});
