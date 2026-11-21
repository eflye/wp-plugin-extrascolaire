/**
 * Types de plat des menus de cantine : entrée, plat, accompagnement,
 * fromage / laitage, dessert.
 *
 *  - backoffice (Menus › Menus de la semaine) : cinq champs par jour,
 *    toujours affichés, y compris vides ; axe sans violation ;
 *  - espace des familles (onglet « Menu de la semaine ») et page publique :
 *    le type précède ses plats, un type sans plat n'apparaît pas ; un menu
 *    saisi avant les types reste affiché sans type ; axe sans violation,
 *    pas de débordement sur mobile ;
 *  - un jour vidé n'est plus affiché aux familles ;
 *  - droits : sans l'habilitation « présences », l'enregistrement d'un menu
 *    est refusé par le serveur et le menu reste intact.
 *
 * Le menu n'est pas une donnée de famille : il n'y a pas de cloisonnement
 * entre responsables à contrôler ici, seulement le droit d'écriture.
 * Composition, enregistrement et rendu sont vérifiés en détail par
 * bin/verify-menu-types.php.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];
const ADMIN_BASE = `${APP_BASE}/wp-admin`;
const WEEK = '2091-03-05';        // menu saisi par type dans le backoffice
const WEEK_LEGACY = '2091-03-12'; // menu en texte seul, comme avant le schéma 4.25.0
const AGENT_LOGIN = 'psc-e2e-menu-types-facturation';
const AGENT_PASSWORD = 'Menu-Types-E2E-2026';

function wp(...args: string[]): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', ...args], { encoding: 'utf8' });
}
function php(code: string): string {
  return wp('eval', code).trim().split('\n').pop() ?? '';
}

function seedFamily(): number {
  const out = wp('--require=/var/www/html/wp-content/plugins/periscolaire-registration/bin/seed-planning-2.php', 'seed-planning-2');
  return (JSON.parse(out.split('\n').map((l) => l.trim()).reverse().find((l) => l.startsWith('{'))!) as { parent_id: number }).parent_id;
}

function loginUrl(parentId: number): string {
  return php(`global $wpdb; $t=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($t),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>${parentId})); echo add_query_arg(array('psc_pid'=>${parentId},'psc_token'=>$t),Psc_Mailer::form_page_url());`);
}

function cleanup(): void {
  php(`global $wpdb;
    foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menus').' WHERE semaine_debut IN (%s, %s)', '${WEEK}', '${WEEK_LEGACY}')) as $id) {
      $wpdb->delete(psc_table('envois'), array('objet_type' => 'menu', 'objet_id' => (int) $id));
      $wpdb->delete(psc_table('menus'), array('id' => (int) $id));
    }
    require_once ABSPATH.'wp-admin/includes/user.php';
    $u = get_user_by('login', '${AGENT_LOGIN}'); if ($u) wp_delete_user((int) $u->ID);
    echo 'ok';`);
}

function storedMonday(): string {
  return php(`$m = Psc_Menus::get_by_week('${WEEK}'); echo $m ? str_replace("\\n", '|', $m->lundi).' ['.$m->lundi_types.']' : 'aucun';`);
}

async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function axeViolations(page: Page, include: string): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(TAGS).exclude('#wpadminbar').include(include).analyze();
  return results.violations.map((v) => `${v.id} : ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(' | ')}`);
}

/** Types affichés devant les plats d'un jour, dans l'ordre. */
async function typesOf(page: Page, table: string, day: string): Promise<string[]> {
  return page.locator(`${table} tr`).filter({ has: page.locator('th', { hasText: day }) }).locator('dt').allTextContents();
}

test.describe.serial('Types de plat des menus', () => {
  let family = 0;

  test.beforeAll(() => {
    cleanup();
    family = seedFamily();
    php(`Psc_Menus::save(0, '${WEEK_LEGACY}', array('lundi' => "Salade verte\\nGratin de pâtes\\nFruit"));
      $u = wp_insert_user(array('user_login' => '${AGENT_LOGIN}', 'user_pass' => '${AGENT_PASSWORD}', 'user_email' => '${AGENT_LOGIN}@example.test', 'role' => 'subscriber'));
      get_user_by('id', $u)->add_cap('psc_manage_billing'); echo 'ok';`);
  });

  test.afterAll(() => { cleanup(); });

  test('backoffice : cinq champs par jour, toujours affichés, enregistrés par type', async ({ page }) => {
    await login(page, 'admin', 'admin');
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_menus&semaine_debut=${WEEK}`);

    // Menu vierge : les cinq types sont proposés pour chacun des quatre jours.
    for (const jour of ['lundi', 'mardi', 'jeudi', 'vendredi']) {
      const groupe = page.getByTestId(`menu-jour-${jour}`);
      await expect(groupe.locator('textarea')).toHaveCount(5);
      for (const type of ['Entrée', 'Plat', 'Accompagnement', 'Fromage / Laitage', 'Dessert']) {
        await expect(groupe.getByLabel(type, { exact: true })).toHaveValue('');
      }
    }
    await expect(page.getByRole('group', { name: /^Lundi/ })).toBeVisible();
    expect(await axeViolations(page, '#wpbody-content .wrap'), 'Menus (saisie)').toEqual([]);

    // Lundi : plat complet, donc pas d'accompagnement ; deux desserts.
    const lundi = page.getByTestId('menu-jour-lundi');
    await lundi.getByLabel('Entrée', { exact: true }).fill('Carottes râpées*');
    await lundi.getByLabel('Plat', { exact: true }).fill('Lasagnes bolognaises (plat complet)');
    await lundi.getByLabel('Fromage / Laitage', { exact: true }).fill('Mimolette');
    await lundi.getByLabel('Dessert', { exact: true }).fill('Compote de pommes\nBiscuit');
    // L'aperçu suit la saisie, champ par champ.
    await expect(lundi.locator('[data-menu-preview] .psc-menu-dish')).toHaveCount(5);
    await expect(lundi.locator('[data-menu-preview] img')).toHaveCount(1);
    // Mardi : un seul type.
    await page.getByTestId('menu-jour-mardi').getByLabel('Plat', { exact: true }).fill('Poisson pané');
    await page.getByRole('button', { name: 'Enregistrer le menu' }).click();
    await expect(page.getByText('Menu enregistré.')).toBeVisible();

    expect(storedMonday()).toBe('Carottes râpées*|Lasagnes bolognaises (plat complet)|Mimolette|Compote de pommes|Biscuit [entree,plat,fromage,dessert,dessert]');

    // À la réouverture, chaque plat revient dans son champ ; l'accompagnement, vide, reste proposé.
    await expect(page.locator('#psc-menu-lundi-entree')).toHaveValue('Carottes râpées*');
    await expect(page.locator('#psc-menu-lundi-plat')).toHaveValue('Lasagnes bolognaises (plat complet)');
    await expect(page.locator('#psc-menu-lundi-accompagnement')).toHaveValue('');
    await expect(page.locator('#psc-menu-lundi-accompagnement')).toBeVisible();
    await expect(page.locator('#psc-menu-lundi-fromage')).toHaveValue('Mimolette');
    await expect(page.locator('#psc-menu-lundi-dessert')).toHaveValue('Compote de pommes\nBiscuit');
    await expect(page.locator('#psc-menu-mardi-plat')).toHaveValue('Poisson pané');
    expect(await axeViolations(page, '#wpbody-content .wrap'), 'Menus (modification)').toEqual([]);

    // Menu saisi avant les types : ses lignes sont réparties dans l'ordre du service, à corriger.
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_menus&semaine_debut=${WEEK_LEGACY}`);
    await expect(page.locator('#psc-menu-lundi-entree')).toHaveValue('Salade verte');
    await expect(page.locator('#psc-menu-lundi-plat')).toHaveValue('Gratin de pâtes');
    await expect(page.locator('#psc-menu-lundi-accompagnement')).toHaveValue('Fruit');
    await expect(page.locator('#psc-menu-lundi-dessert')).toHaveValue('');
  });

  test('familles : le type précède ses plats, un type vide n\'apparaît pas', async ({ page }) => {
    await page.goto(loginUrl(family));
    await expect(page.getByTestId('portal-root')).toBeVisible();
    await page.goto(`${APP_BASE}/?psc_tab=menu&psc_semaine=${WEEK}`);

    const table = page.getByTestId('portal-menu-table');
    await expect(table).toBeVisible();
    expect(await typesOf(page, '[data-testid="portal-menu-table"]', 'Lundi')).toEqual(['Entrée', 'Plat', 'Fromage / Laitage', 'Dessert']);
    expect(await typesOf(page, '[data-testid="portal-menu-table"]', 'Mardi')).toEqual(['Plat']);
    await expect(table).not.toContainText('Accompagnement');
    // Chaque type est suivi de ses plats ; les deux desserts partagent leur type.
    const dessert = table.locator('.psc-menu-type[data-type="dessert"]');
    await expect(dessert.locator('.psc-menu-dish')).toHaveText(['Compote de pommes', 'Biscuit']);
    await expect(table.locator('.psc-menu-type[data-type="entree"] img')).toHaveAttribute('alt', /agriculture biologique/);
    await expect(table).not.toContainText('*');
    // Le type est à gauche du plat.
    const dt = await table.locator('.psc-menu-type[data-type="entree"] dt').boundingBox();
    const dd = await table.locator('.psc-menu-type[data-type="entree"] dd').boundingBox();
    expect(dt!.x + dt!.width).toBeLessThanOrEqual(dd!.x + 1);
    expect(Math.abs(dt!.y - dd!.y)).toBeLessThan(20);
    expect(await axeViolations(page, '[data-testid="portal-root"]'), 'Menu de la semaine (familles)').toEqual([]);

    // Mobile : pas de débordement horizontal.
    await page.setViewportSize({ width: 360, height: 780 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect(table.locator('.psc-menu-type[data-type="fromage"] dt')).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 800 });

    // Menu saisi avant les types : affiché comme avant, sans type.
    await page.goto(`${APP_BASE}/?psc_tab=menu&psc_semaine=${WEEK_LEGACY}`);
    await expect(page.getByTestId('portal-menu-table').locator('.psc-menu-dish')).toHaveText(['Salade verte', 'Gratin de pâtes', 'Fruit']);
    await expect(page.getByTestId('portal-menu-table').locator('dt')).toHaveCount(0);
  });

  test('page publique : mêmes types, sans connexion', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto(`${APP_BASE}/?psc_semaine=${WEEK}`);
    const table = page.locator('.psc-guest-menu-table');
    await expect(table).toBeVisible();
    expect(await typesOf(page, '.psc-guest-menu-table', 'Lundi')).toEqual(['Entrée', 'Plat', 'Fromage / Laitage', 'Dessert']);
    await expect(table).not.toContainText('Accompagnement');
    expect(await axeViolations(page, '.psc-guest-menu-table'), 'Menu (page publique)').toEqual([]);
    await context.close();
  });

  test('un jour vidé dans le backoffice n\'est plus affiché aux familles', async ({ page }) => {
    await login(page, 'admin', 'admin');
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_menus&semaine_debut=${WEEK}`);
    await page.getByTestId('menu-jour-mardi').getByLabel('Plat', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await expect(page.getByText('Menu enregistré.')).toBeVisible();
    expect(php(`$m = Psc_Menus::get_by_week('${WEEK}'); echo '['.$m->mardi.']['.$m->mardi_types.']';`)).toBe('[][]');

    await page.goto(loginUrl(family));
    await page.goto(`${APP_BASE}/?psc_tab=menu&psc_semaine=${WEEK}`);
    const table = page.getByTestId('portal-menu-table');
    await expect(table.locator('th', { hasText: 'Lundi' })).toBeVisible();
    await expect(table.locator('th', { hasText: 'Mardi' })).toHaveCount(0);
  });

  test('sans l\'habilitation « présences », l\'enregistrement d\'un menu est refusé', async ({ page }) => {
    const avant = storedMonday();
    await login(page, AGENT_LOGIN, AGENT_PASSWORD);
    const resp = await page.goto(`${ADMIN_BASE}/admin.php?page=psc_menus&semaine_debut=${WEEK}`);
    expect(resp?.status()).toBe(403);

    const post = await page.request.post(`${ADMIN_BASE}/admin-post.php`, {
      form: { action: 'psc_save_menu', id: '0', semaine_debut: WEEK, 'lundi[entree]': 'Menu forgé', _wpnonce: 'x' },
      maxRedirects: 0,
    });
    expect(post.status()).toBe(403);
    expect(await post.text()).toContain('droits nécessaires');
    expect(storedMonday()).toBe(avant);
  });
});
