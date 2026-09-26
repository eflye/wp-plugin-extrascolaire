/**
 * P3-01 — contrats d'une journée : l'annulation de la cantine d'une classe
 * suit la même lecture que la facturation. Un enfant au forfait journée
 * est concerné comme les autres : sa cantine est retirée, ses garderies
 * restent. Contrôle des droits : sans session d'administration, l'action
 * est refusée et rien ne change.
 *
 * Les autres canaux (commande fournisseur, avis de fermeture, facture) sont
 * confrontés à la table de décision par bin/verify-channel-contracts.php.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const PLUGIN = '/var/www/html/wp-content/plugins/periscolaire-registration';

type Seed = { date: string; forfait: { parent_id: number; child_id: number }; cantine: { parent_id: number; child_id: number } };

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}

function seed(purge = false): Seed {
  const out = wp(`--require=${PLUGIN}/bin/seed-channel-contracts.php`, 'seed-channel-contracts', ...(purge ? ['--purge'] : []));
  return JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!);
}

/** Carte résolue d'un enfant ce jour-là, pour les codes demandés. */
function declared(childId: number, date: string): Record<string, boolean> {
  const out = wp('eval', `Psc_Planning::flush_cache(); $m = Psc_Planning::declared_map(array(${childId}), array('${date}')); $d = $m[${childId}]['${date}'] ?? array(); echo wp_json_encode(array('FORF' => !empty($d['FORF']), 'GM' => !empty($d['GM']), 'CANT' => !empty($d['CANT']), 'GS' => !empty($d['GS'])));`);
  return JSON.parse(out.trim().split('\n').pop()!);
}

async function loginAsAdmin(page: Page) {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test.describe.serial('Contrats d’une journée : annulation de la cantine d’une classe', () => {
  test.afterAll(() => { seed(true); });

  test('un enfant au forfait perd sa cantine et garde ses garderies', async ({ page }) => {
    const data = seed();
    expect(declared(data.forfait.child_id, data.date)).toEqual({ FORF: true, GM: true, CANT: true, GS: true });

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_supplier_orders`);
    await page.getByTestId('cantine-date-input').fill(data.date);
    await page.getByTestId('cantine-classe-select').selectOption('CE1');
    await page.getByTestId('cantine-reason-input').fill('Sortie scolaire');
    await page.getByTestId('cantine-cancel-submit').click();

    // Confirmation : les deux enfants, le forfait signalé en toutes lettres.
    const table = page.getByTestId('cantine-pending-table');
    await expect(page.getByTestId('cantine-pending-warning')).toContainText('retirera la cantine de 2 enfant(s)');
    await expect(table.getByRole('row', { name: /Forfait Contrat/ })).toContainText('Oui : garderies conservées');
    await expect(table.getByRole('row', { name: /Cantine Contrat/ })).toContainText('Non');

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    await page.getByTestId('cantine-confirm-button').click();
    await expect(page.getByTestId('notice-cantine_cancelled')).toContainText('2 enfant(s) sans cantine ce jour-là');

    expect(declared(data.forfait.child_id, data.date)).toEqual({ FORF: true, GM: true, CANT: false, GS: true });
    expect(declared(data.cantine.child_id, data.date).CANT).toBe(false);
  });

  test('fermeture de la garderie du soir : l’enfant au forfait annoncé à part', async ({ page }) => {
    const data = seed();
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_school_calendar_v2&month=${data.date.slice(0, 7)}`);
    await page.locator(`.psc-cal2-day[data-date="${data.date}"]`).click();
    await page.locator('.psc-cal2-menu-item', { hasText: /^Fermer Garderie Soir$/ }).click();

    // Aperçu : un enfant au forfait, aucune inscription directe (l'autre
    // enfant ne vient qu'à la cantine) — le forfait n'est pas compté deux fois.
    const body = page.locator('#psc-cal2-modal-body');
    await expect(body).toHaveText(/^1 enfant\(s\) au forfait journée \(1 famille\(s\)\) : ce jour-là, leurs prestations restantes/);

    const axe = await new AxeBuilder({ page }).include('#wpbody-content').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    await page.locator('#psc-cal2-modal-cancel').click();
    await expect(page.locator('#psc-cal2-modal')).toBeHidden();
  });

  test('sans session d’administration, l’annulation est refusée', async ({ page, request }) => {
    const data = seed();
    // Formulaire rejoué hors session : le nonce et la capacité manquent.
    const res = await request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_cancel_class_meals', date: data.date, classe: 'CE1', reason: 'Intrusion', confirm: '1' },
      maxRedirects: 0,
    });
    expect([302, 400, 403]).toContain(res.status());
    expect(declared(data.forfait.child_id, data.date).CANT).toBe(true);

    // Une famille connectée au portail n'est pas un compte d'administration.
    const login = wp('eval', `global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${data.forfait.parent_id})); echo add_query_arg(array('psc_pid'=>${data.forfait.parent_id},'psc_token'=>$t),Psc_Mailer::form_page_url());`).trim().split('\n').pop()!;
    await page.goto(login);
    const asFamily = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
      form: { action: 'psc_cancel_class_meals', date: data.date, classe: 'CE1', reason: 'Intrusion', confirm: '1' },
      maxRedirects: 0,
    });
    expect([302, 400, 403]).toContain(asFamily.status());
    expect(declared(data.forfait.child_id, data.date).CANT).toBe(true);

    // L'aperçu d'une fermeture (liste des familles concernées) n'est pas
    // lisible depuis une session famille.
    const preview = await page.request.post(`${APP_BASE}/wp-admin/admin-ajax.php`, {
      form: { action: 'psc_cal_v2_preview_close_service', date: data.date, service: 'GS', nonce: 'x' },
    });
    expect(await preview.text()).not.toContain('forf_registrations');
  });
});
