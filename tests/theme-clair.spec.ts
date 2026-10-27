/**
 * Thème « Clair » du portail famille (bascule soleil/lune).
 *
 *  - Encre par défaut : pas d'attribut data-psc-theme, bouton soleil,
 *    aria-pressed="false" ;
 *  - bascule sans rechargement, au clavier : nom accessible fixe « Thème
 *    clair », aria-pressed et title à jour, préférence écrite dans le cookie psc_theme et dans
 *    localStorage ;
 *  - pas de flash : avec le cookie, le HTML servi porte déjà l'attribut ;
 *  - filet localStorage : cookie effacé, préférence Clair rétablie avant
 *    le rendu par le script de <head> ;
 *  - axe (WCAG 2.1 A et AA) sans violation en Clair sur l'accueil visiteur
 *    et sur chaque écran du portail ;
 *  - écran intervenants : jamais de bouton ni d'attribut, même avec le
 *    cookie (il reste en Encre).
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { ENGINE, CONTAINER } from '../helpers/env';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const TOGGLE = '[data-psc-theme-toggle]';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

interface Seed { parent_id: number; chloe_id: number }

function seedAndLogin(): string {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as Seed;
  php(`Psc_Assurances::upsert_row(${data.chloe_id}, 'test/assurance.pdf', 'assurance.pdf');`);
  return php(`global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`);
}

async function theme(page: Page): Promise<string | null> {
  return page.evaluate(() => document.documentElement.getAttribute('data-psc-theme'));
}

async function themeCookie(page: Page): Promise<string | undefined> {
  return (await page.context().cookies()).find((c) => c.name === 'psc_theme')?.value;
}

async function axeViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('#wpadminbar').analyze();
  return results.violations.map((v) => `${v.id} : ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
}

test.describe('Thème Clair du portail famille', () => {
  test('bascule au clavier, persistance sans flash et filet localStorage', async ({ browser }) => {
    const login = seedAndLogin();
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(login);
    await expect(page.getByTestId('portal-root')).toBeVisible();

    // Encre par défaut : le Clair n'est jamais imposé, ni par
    // prefers-color-scheme.
    const toggle = page.locator(TOGGLE).first();
    expect(await theme(page)).toBeNull();
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await expect(toggle).toHaveAccessibleName('Thème clair');
    await expect(toggle).toHaveAttribute('title', 'Activer le thème clair');
    await expect(toggle.locator('.psc-theme-toggle-sun')).toBeVisible();
    await expect(toggle.locator('.psc-theme-toggle-moon')).toBeHidden();

    // Bascule au clavier, sans rechargement.
    await toggle.focus();
    await page.keyboard.press('Enter');
    expect(await theme(page)).toBe('light');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(toggle).toHaveAccessibleName('Thème clair');
    await expect(toggle).toHaveAttribute('title', 'Activer le thème encre');
    await expect(toggle.locator('.psc-theme-toggle-moon')).toBeVisible();
    await expect(toggle.locator('.psc-theme-toggle-sun')).toBeHidden();
    expect(await themeCookie(page)).toBe('light');
    expect(await page.evaluate(() => localStorage.getItem('psc-theme'))).toBe('light');

    // Pas de flash : le HTML servi porte déjà l'attribut.
    const html = await (await page.request.get(page.url())).text();
    expect(html).toMatch(/<html[^>]*data-psc-theme="light"/);
    await page.goto(`${new URL(page.url()).origin}/?psc_tab=menu`);
    expect(await theme(page)).toBe('light');

    // Filet de sécurité : cookie effacé, préférence localStorage rétablie
    // par le script de <head>, cookie réécrit.
    await context.clearCookies({ name: 'psc_theme' });
    await page.reload();
    expect(await theme(page)).toBe('light');
    expect(await themeCookie(page)).toBe('light');
    await expect(page.locator(TOGGLE).first()).toHaveAttribute('aria-pressed', 'true');

    // Retour à l'Encre (Espace) : attribut retiré, préférence « ink ».
    await page.locator(TOGGLE).first().focus();
    await page.keyboard.press('Space');
    expect(await theme(page)).toBeNull();
    expect(await themeCookie(page)).toBe('ink');
    await page.reload();
    expect(await theme(page)).toBeNull();
    await context.close();
  });

  test('axe : aucune violation en Clair sur l’accueil visiteur ni sur les écrans du portail', async ({ browser }) => {
    test.setTimeout(180_000);
    const formUrl = readFormPageUrl();
    const cookie = { name: 'psc_theme', value: 'light', url: new URL(formUrl).origin };

    const guestContext = await browser.newContext();
    await guestContext.addCookies([cookie]);
    const guest = await guestContext.newPage();
    await guest.goto(formUrl);
    expect(await theme(guest)).toBe('light');
    await expect(guest.locator(TOGGLE).first()).toBeVisible();
    expect(await axeViolations(guest), 'accueil visiteur').toEqual([]);
    await guestContext.close();

    const login = seedAndLogin();
    const context = await browser.newContext();
    await context.addCookies([cookie]);
    const page = await context.newPage();
    await page.goto(login);
    await expect(page.getByTestId('portal-root')).toBeVisible();
    const tabs = await page.locator('[data-testid="portal-nav"] a').evaluateAll((as) =>
      as.map((a) => [String(a.getAttribute('data-testid')).replace('portal-nav-', ''), (a as HTMLAnchorElement).href] as [string, string]));
    expect(tabs.length).toBeGreaterThan(5);
    for (const [tab, href] of tabs) {
      await page.goto(href);
      expect(await theme(page), tab).toBe('light');
      expect(await axeViolations(page), `écran ${tab}`).toEqual([]);
    }
    await context.close();
  });

  test('écran intervenants : ni bouton ni attribut, même avec le cookie Clair', async ({ browser }) => {
    const url = php(`$id = Psc_Sidscm::page_id(); if (!$id) { $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Intervenants', 'post_content' => '[periscolaire_sidscm]')); update_option('psc_sidscm_page_id', $id); } echo get_permalink($id);`);
    const context = await browser.newContext();
    await context.addCookies([{ name: 'psc_theme', value: 'light', url: new URL(url).origin }]);
    const page = await context.newPage();
    const response = await page.goto(url);
    expect(response?.ok()).toBe(true);
    expect(await theme(page)).toBeNull();
    await expect(page.locator(TOGGLE)).toHaveCount(0);
    expect(await page.locator('link[href*="portal-light.css"]').count()).toBe(0);
    await context.close();
  });
});
