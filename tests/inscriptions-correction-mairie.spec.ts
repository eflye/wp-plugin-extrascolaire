/**
 * Périscolaire › Inscriptions — correction manuelle par la mairie.
 *
 * Le formulaire envoie l'état complet des cases du mois (regs[enfant][date]
 * [service]) ; le serveur applique le diff avec l'état effectif en
 * exceptions, sans verrou de 48 h. Jusqu'en 5.38.1, ce diff livrait des
 * drapeaux `true` à explode() au lieu des clés « enfant|date|service » :
 * aucune correction ne s'appliquait, en silence (révélé par PHPStan
 * niveau 5 le 27/10/2026). Ce scénario fixe le nominal (cocher puis
 * décocher, lu en base), et le refus d'un visiteur sans session.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

interface Seed { parent_id: number; alice_id: number; month: string }

/** État effectif d'une case (enfant, jour, prestation), lu par le même chemin que l'écran. */
function declared(childId: number, date: string, service: string): boolean {
  return php(`$m = Psc_Planning::declared_map(array(${childId}), array('${date}')); echo empty($m[${childId}]['${date}']['${service}']) ? '0' : '1';`) === '1';
}

async function loginAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test.describe('Inscriptions : correction manuelle par la mairie', () => {
  let seed: Seed;
  let date: string;

  test.beforeAll(() => {
    const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
    seed = JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as Seed;
    // Premier jour d'école du mois seedé : c'est une case du formulaire.
    date = php(`$d = Psc_School_Year::school_days_in_month('${seed.month}'); echo $d ? $d[0] : '';`);
    expect(date, 'aucun jour d’école dans le mois seedé').toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  test('cocher puis décocher une case écrit bien en base', async ({ page }) => {
    await loginAdmin(page);
    const screen = `${APP_BASE}/wp-admin/admin.php?page=psc_inscriptions&parent_id=${seed.parent_id}&mois=${seed.month}`;
    const box = () => page.locator(`input[type="checkbox"][name="regs[${seed.alice_id}][${date}][CANT]"]`);

    await page.goto(screen);
    await expect(box()).toBeVisible();
    const initial = declared(seed.alice_id, date, 'CANT');
    expect(await box().isChecked(), 'la case reflète l’état en base').toBe(initial);

    // Nominal : inversion, enregistrement, relecture en base puis à l'écran.
    await box().setChecked(!initial);
    await page.locator('form:has(input[name="action"][value="psc_admin_update_registrations"]) [type="submit"]').first().click();
    await page.waitForURL(/page=psc_inscriptions/);
    expect(declared(seed.alice_id, date, 'CANT'), 'la correction est appliquée en base').toBe(!initial);
    await page.goto(screen);
    expect(await box().isChecked()).toBe(!initial);

    // Retour à l'état initial par le même chemin : le diff fonctionne dans les deux sens.
    await box().setChecked(initial);
    await page.locator('form:has(input[name="action"][value="psc_admin_update_registrations"]) [type="submit"]').first().click();
    await page.waitForURL(/page=psc_inscriptions/);
    expect(declared(seed.alice_id, date, 'CANT'), 'le retour arrière est appliqué en base').toBe(initial);
  });

  test('droits : sans session WordPress, rien n’est écrit', async ({ request }) => {
    const before = declared(seed.alice_id, date, 'CANT');
    const res = await request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: {
        action: 'psc_admin_update_registrations',
        parent_id: String(seed.parent_id),
        mois: seed.month,
        [`regs[${seed.alice_id}][${date}][CANT]`]: before ? '' : '1',
      },
      maxRedirects: 0,
    });
    // admin-post.php sans session : 400 (action inconnue hors connexion), ou redirection vers la connexion.
    expect([302, 400, 403]).toContain(res.status());
    expect(declared(seed.alice_id, date, 'CANT'), 'l’état en base est inchangé').toBe(before);
  });
});
