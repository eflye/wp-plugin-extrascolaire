/**
 * Accessibilité des parcours essentiels (P2-15) — contrôles automatisables.
 *
 *  - axe (WCAG 2.1 A et AA) sans violation sur l'accueil visiteur et sur
 *    chaque écran du portail des familles ;
 *  - réaffichage à 320 px de large (équivalent d'un zoom à 400 %, critère
 *    1.4.10) sans défilement horizontal de la page ;
 *  - correction du planning au clavier seul : atteindre l'onglet d'un
 *    enfant et une case du planning par la touche Tab, les activer au
 *    clavier, et constater l'écriture en base ;
 *  - demande du lien de connexion au clavier seul.
 *
 * Le lecteur d'écran réel et le jugement d'un référent accessibilité ne
 * sont pas automatisables : cf. documentation/accessibilite.md.
 */
import { test, expect, type Page, type BrowserContext } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

interface Seed { parent_id: number; alice_id: number; bob_id: number; chloe_id: number; month: string }

function seedAndLogin(): { data: Seed; login: string } {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as Seed;
  php(`Psc_Assurances::upsert_row(${data.chloe_id}, 'test/assurance.pdf', 'assurance.pdf');`);
  const login = php(`global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`);
  return { data, login };
}

async function portalTabs(context: BrowserContext, login: string): Promise<{ page: Page; tabs: [string, string][] }> {
  const page = await context.newPage();
  await page.goto(login);
  await expect(page.getByTestId('portal-root')).toBeVisible();
  const tabs = await page.locator('[data-testid="portal-nav"] a').evaluateAll((as) =>
    as.map((a) => [String(a.getAttribute('data-testid')).replace('portal-nav-', ''), (a as HTMLAnchorElement).href] as [string, string]));
  expect(tabs.length).toBeGreaterThan(5);
  return { page, tabs };
}

async function axeViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('#wpadminbar').analyze();
  return results.violations.map((v) => `${v.id} : ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
}

/** Appuie sur Tab jusqu'à ce que le focus atteigne l'élément visé (preuve qu'il est atteignable au clavier). */
async function tabTo(page: Page, selector: string, max = 250): Promise<void> {
  for (let i = 0; i < max; i++) {
    if (await page.evaluate((sel) => document.activeElement?.matches(sel) ?? false, selector)) return;
    await page.keyboard.press('Tab');
  }
  throw new Error(`Élément jamais atteint au clavier : ${selector}`);
}

test.describe('Accessibilité des parcours essentiels', () => {
  test('axe : aucune violation sur l’accueil visiteur ni sur les écrans du portail', async ({ browser }) => {
    test.setTimeout(180_000);
    const guestContext = await browser.newContext();
    const guest = await guestContext.newPage();
    await guest.goto(readFormPageUrl());
    expect(await axeViolations(guest), 'accueil visiteur').toEqual([]);
    await guestContext.close();

    const { login } = seedAndLogin();
    const context = await browser.newContext();
    const { page, tabs } = await portalTabs(context, login);
    for (const [name, href] of tabs) {
      await page.goto(href);
      expect(await axeViolations(page), name).toEqual([]);
    }
    await context.close();
  });

  test('zoom 400 % (320 px) : aucune page ne défile horizontalement', async ({ browser }) => {
    test.setTimeout(180_000);
    const viewport = { width: 320, height: 640 };
    const overflow = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

    const guestContext = await browser.newContext({ viewport });
    const guest = await guestContext.newPage();
    await guest.goto(readFormPageUrl());
    expect(await overflow(guest), 'accueil visiteur').toBeLessThanOrEqual(0);
    await guestContext.close();

    const { login } = seedAndLogin();
    const context = await browser.newContext({ viewport });
    const { page, tabs } = await portalTabs(context, login);
    for (const [name, href] of tabs) {
      await page.goto(href);
      expect(await overflow(page), name).toBeLessThanOrEqual(0);
    }
    await context.close();
  });

  test('correction du planning au clavier seul', async ({ browser }) => {
    const { data, login } = seedAndLogin();
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(login);
    await page.goto(`${new URL(login).origin}/?psc_tab=cantine2&psc_mois=${data.month}&psc_child=${data.alice_id}`);
    await expect(page.getByTestId('exception-grid')).toBeVisible();

    // Onglet d'un autre enfant : atteint par Tab, activé par Entrée.
    await page.locator('body').focus();
    await tabTo(page, `[data-testid="child-tab-${data.bob_id}"]`);
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('exception-grid')).toHaveAttribute('data-child', String(data.bob_id));

    // Une case modifiable : atteinte par Tab, basculée par Espace, enregistrée en base.
    await tabTo(page, '[data-testid="exception-grid"] .psc-exc-cell:not([disabled])');
    const cell = page.locator(':focus');
    const date = await cell.getAttribute('data-date');
    const service = await cell.getAttribute('data-service');
    const before = await cell.getAttribute('aria-pressed');
    await page.keyboard.press('Space');
    await expect(page.locator(`[data-testid="exception-grid"] .psc-exc-cell[data-date="${date}"][data-service="${service}"]`))
      .not.toHaveAttribute('aria-pressed', String(before));
    const declared = php(`echo Psc_Planning::is_declared(${data.bob_id}, '${date}', '${service}') ? 'true' : 'false';`);
    expect(declared).toBe(before === 'true' ? 'false' : 'true');
    await context.close();
  });

  test('demande du lien de connexion au clavier seul', async ({ page }) => {
    await page.goto(readFormPageUrl());
    await page.locator('body').focus();
    await tabTo(page, '[data-testid="login-email-input"]');
    await page.keyboard.type('clavier.e2e@example.test');
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-testid^="notice-"]').first()).toBeVisible();
  });
});
