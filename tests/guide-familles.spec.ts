/**
 * Lien vers le guide des familles — présent pour une famille connectée
 * comme pour un visiteur non connecté, sur ordinateur comme sur mobile, et
 * annoncé comme s'ouvrant dans un nouvel onglet.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const GUIDE = 'https://eflye.github.io/wp-plugin-extrascolaire/familles/';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}

async function expectGuideLink(page: Page, testid: string) {
  const link = page.getByTestId(testid);
  await expect(link).toBeVisible();
  await expect(link).toHaveAttribute('href', GUIDE);
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', /noopener/);
  await expect(link).toContainText('nouvel onglet'); // annonce lecteur d'écran
}

for (const viewport of [{ width: 1280, height: 800 }, { width: 390, height: 844 }]) {
  test(`lien vers le guide des familles — ${viewport.width} px`, async ({ browser }) => {
    const guest = await browser.newContext({ viewport });
    const g = await guest.newPage();
    await g.goto(readFormPageUrl());
    await expectGuideLink(g, 'guest-help-link');
    await guest.close();

    const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
    const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!);
    const login = wp('eval', `global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`).trim().split('\n').pop()!;
    const family = await browser.newContext({ viewport });
    const page = await family.newPage();
    await page.goto(login);
    await expect(page.getByTestId('portal-root')).toBeVisible();
    const tabs = await page.locator('[data-testid="portal-nav"] a').evaluateAll((as) => as.map((a) => (a as HTMLAnchorElement).href));
    for (const href of tabs) {
      await page.goto(href);
      await expectGuideLink(page, 'portal-help-link');
    }
    await family.close();
  });
}
