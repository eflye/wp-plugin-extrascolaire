/**
 * Barre latérale du portail : un seul lien surligné à la fois.
 *
 * Le lien « Messages » n'a pas de data-portal-tab (il recharge la page
 * pour marquer les annonces lues côté serveur). Une page chargée sur
 * Messages, puis un changement d'onglet côté client, laissaient « Messages »
 * surligné à côté du nouvel onglet.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { ENGINE, CONTAINER } from '../helpers/env';


function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

test('quitter Messages par la barre latérale retire son surlignage', async ({ page }) => {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  const data = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as { parent_id: number };
  const login = php(`global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.parent_id})); echo add_query_arg(array('psc_pid'=>${data.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`);

  await page.goto(login);
  await expect(page.getByTestId('portal-root')).toBeVisible();
  await page.goto(`${new URL(login).origin}/?psc_tab=messages`);

  const active = page.locator('[data-testid="portal-nav"] a.is-active');
  await expect(active).toHaveCount(1);
  await expect(active).toHaveAttribute('data-testid', 'portal-nav-messages');

  await page.getByTestId('portal-nav-enfants').click();
  await expect(active).toHaveCount(1);
  await expect(active).toHaveAttribute('data-testid', 'portal-nav-enfants');

  await page.getByTestId('portal-nav-dashboard').click();
  await expect(active).toHaveCount(1);
  await expect(active).toHaveAttribute('data-testid', 'portal-nav-dashboard');
});
