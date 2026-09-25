/**
 * P2-06 — aucune ressource tierce : les pages du site (thème et portail
 * des familles) ne chargent rien depuis un autre domaine. Google Fonts,
 * en particulier, recevait l'adresse IP de chaque visiteur. La police
 * Public Sans est servie par le thème et par l'extension.
 *
 * L'appel à l'API Adresse du formulaire d'inscription (Géoplateforme) n'est
 * déclenché qu'à la saisie d'une adresse : il n'apparaît pas au chargement.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}

/** Charge la page et renvoie les requêtes sorties du domaine du site. */
async function thirdParty(page: Page, url: string): Promise<string[]> {
  const outside: string[] = [];
  const own = new URL(APP_BASE).host;
  const listener = (req: { url(): string }) => {
    const u = new URL(req.url());
    if ((u.protocol === 'http:' || u.protocol === 'https:') && u.host !== own) outside.push(u.host + u.pathname);
  };
  page.on('request', listener);
  await page.goto(url);
  await page.waitForLoadState('networkidle');
  page.off('request', listener);
  return outside;
}

test('aucune ressource tierce sur les pages publiques et le portail', async ({ page, browser }) => {
  expect(await thirdParty(page, `${APP_BASE}/`), 'accueil').toEqual([]);
  expect(await thirdParty(page, readFormPageUrl()), 'formulaire d’inscription').toEqual([]);

  // Police chargée localement : Public Sans effectivement disponible.
  await page.evaluate(() => document.fonts.ready);
  expect(await page.evaluate(() => document.fonts.check('16px "Public Sans"'))).toBe(true);

  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!);
  const login = wp('eval', `global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`).trim().split('\n').pop()!;
  const family = await browser.newContext();
  const portal = await family.newPage();
  await portal.goto(login);
  for (const tab of ['', '?psc_tab=cantine2', '?psc_tab=enfants', '?psc_tab=profil']) {
    const url = portal.url().split('?')[0] + tab;
    expect(await thirdParty(portal, url), `portail ${tab || 'accueil'}`).toEqual([]);
  }
  await family.close();
});
