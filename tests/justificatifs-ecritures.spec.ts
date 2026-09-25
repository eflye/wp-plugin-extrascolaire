/**
 * P1-13 — messages d'échec des écritures de justificatifs. Les pannes
 * elles-mêmes (disque, SQL, deuxième enfant invalide, reprise) sont
 * provoquées par bin/verify-document-writes.php ; ici, la famille doit
 * lire un message explicite, jamais une confirmation, et l'écran reste
 * conforme (axe).
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}

const MESSAGES = {
  child_add_failed: "L'enfant n'a pas pu être ajouté : rien n'a été enregistré.",
  reinscription_failed: "L'enregistrement a été interrompu. Renvoyez le formulaire",
};

test('échecs d’écriture des justificatifs : message explicite, sans confirmation', async ({ browser }) => {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!);
  const login = wp('eval', `global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`).trim().split('\n').pop()!;
  const family = await browser.newContext();
  const page = await family.newPage();
  await page.goto(login);
  const portal = page.url().split('?')[0];

  for (const [code, text] of Object.entries(MESSAGES)) {
    await page.goto(`${portal}?psc_tab=enfants&psc_msg=${code}`);
    const notice = page.getByTestId(`notice-${code}`);
    await expect(notice).toBeVisible();
    await expect(notice).toContainText(text);
    await expect(notice).toHaveClass(/psc-notice-err/);
    await expect(page.getByTestId('notice-child_added')).toHaveCount(0);

    const axe = await new AxeBuilder({ page }).include('.psc-portal-main').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);
  }
  await family.close();
});
