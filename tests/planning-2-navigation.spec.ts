/**
 * Planning - 2 : changements rapides d'enfant et réseau capricieux (P2-07).
 *
 * Le réseau est ralenti ou coupé en interceptant admin-ajax.php. Aucun
 * affichage ni aucun clic ne doit alors viser le mauvais enfant :
 *  - un chargement en cours verrouille les onglets ;
 *  - une écriture lente dont la réponse arrive après un changement
 *    d'enfant n'écrase pas l'affichage du nouvel enfant, et reste bien
 *    enregistrée pour l'enfant cliqué ;
 *  - un chargement en échec ramène les onglets sur l'enfant réellement
 *    affiché, et les clics suivants le visent lui.
 */
import { test, expect, type Page, type Route } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';

interface SeedResult { parent_email: string; alice_id: number; bob_id: number; chloe_id: number; month: string }

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', WP, '--path=/var/www/html', '--allow-root', ...args], { encoding: 'utf8' });
}

function seed(): SeedResult {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const line = out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{') && l.endsWith('}'));
  return JSON.parse(line!) as SeedResult;
}

async function login(page: Page, email: string) {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(email);
  await Promise.all([
    page.locator('[data-testid^="notice-"]').waitFor({ state: 'visible', timeout: 5_000 }),
    page.getByTestId('login-submit-button').click(),
  ]);
  const mail = await findLatestMessage(email, 'Votre lien d\'accès aux inscriptions périscolaires');
  const link = mail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  await page.goto(link![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

/** Intercepte les appels AJAX du planning selon leur action et leur enfant. */
async function interceptAjax(page: Page, handler: (params: URLSearchParams, route: Route) => Promise<boolean>) {
  await page.route('**/admin-ajax.php', async (route) => {
    const params = new URLSearchParams(route.request().postData() || '');
    if (!(await handler(params, route))) await route.continue();
  });
}

const grid = (page: Page) => page.getByTestId('exception-grid');
const freeCell = (page: Page) => grid(page).locator('.psc-exc-cell:not([disabled])').first();
const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

test.describe('Planning - 2 : navigation entre enfants sous réseau dégradé', () => {
  let data: SeedResult;

  test.beforeEach(async ({ page }) => {
    data = seed();
    wp('eval', `Psc_Assurances::upsert_row(${data.chloe_id}, 'test/assurance.pdf', 'assurance.pdf');`);
    await login(page, data.parent_email);
    await page.goto(`${APP_BASE}/?psc_tab=cantine2&psc_mois=${data.month}&psc_child=${data.alice_id}`);
    await expect(grid(page)).toHaveAttribute('data-child', String(data.alice_id), { timeout: 10_000 }).catch(async () => {
      // Premier rendu serveur : data-child n'est posé qu'au premier rendu JS.
      await expect(page.getByTestId(`child-tab-${data.alice_id}`)).toHaveAttribute('aria-selected', 'true');
    });
  });

  test('un chargement lent verrouille les onglets, puis affiche le bon enfant', async ({ page }) => {
    await interceptAjax(page, async (p, route) => {
      if (p.get('action') === 'psc_load_month' && p.get('child_id') === String(data.bob_id)) {
        await sleep(1500);
        await route.continue();
        return true;
      }
      return false;
    });
    await page.getByTestId(`child-tab-${data.bob_id}`).click();
    await expect(page.getByTestId(`child-tab-${data.alice_id}`)).toBeDisabled();
    await expect(page.getByTestId(`child-tab-${data.chloe_id}`)).toBeDisabled();
    await expect(grid(page)).toHaveAttribute('data-child', String(data.bob_id));
    await expect(page.getByTestId(`child-tab-${data.bob_id}`)).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId(`child-tab-${data.alice_id}`)).toBeEnabled();
  });

  test('une écriture lente ne réécrit pas l’affichage de l’enfant suivant', async ({ page }) => {
    const cell = freeCell(page);
    const date = await cell.getAttribute('data-date');
    const service = await cell.getAttribute('data-service');
    const wasPressed = (await cell.getAttribute('aria-pressed')) === 'true';
    let writtenFor = '';
    await interceptAjax(page, async (p, route) => {
      if (p.get('action') === 'psc_toggle_exception') {
        writtenFor = p.get('child_id') || '';
        await sleep(2000);
        await route.continue();
        return true;
      }
      return false;
    });

    await cell.click();
    await page.getByTestId(`child-tab-${data.bob_id}`).click();
    await expect(grid(page)).toHaveAttribute('data-child', String(data.bob_id));
    // La réponse de l'écriture (état d'Alice) arrive après : l'affichage reste celui de Bob.
    await page.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('psc_toggle_exception'));
    await sleep(300);
    await expect(grid(page)).toHaveAttribute('data-child', String(data.bob_id));
    await expect(page.getByTestId(`child-tab-${data.bob_id}`)).toHaveAttribute('aria-selected', 'true');

    // L'écriture a visé Alice, et elle est enregistrée pour Alice.
    expect(writtenFor).toBe(String(data.alice_id));
    const declared = wp('eval', `echo Psc_Planning::is_declared(${data.alice_id}, '${date}', '${service}') ? '1' : '0';`).trim().split('\n').pop();
    expect(declared).toBe(wasPressed ? '0' : '1');
  });

  test('un chargement en échec revient à l’enfant affiché, et les clics le visent', async ({ page }) => {
    await interceptAjax(page, async (p, route) => {
      if (p.get('action') === 'psc_load_month' && p.get('child_id') === String(data.bob_id)) {
        await route.abort('failed');
        return true;
      }
      return false;
    });
    await page.getByTestId(`child-tab-${data.bob_id}`).click();
    await expect(page.getByTestId(`child-tab-${data.alice_id}`)).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId(`child-tab-${data.bob_id}`)).toHaveAttribute('aria-selected', 'false');
    await expect(page.getByTestId(`child-tab-${data.alice_id}`)).toBeEnabled();

    const request = page.waitForRequest((r) => r.url().includes('admin-ajax.php') && (r.postData() || '').includes('psc_toggle_exception'));
    await freeCell(page).click();
    const params = new URLSearchParams((await request).postData() || '');
    expect(params.get('child_id')).toBe(String(data.alice_id));
  });
});
