/**
 * Choix des menus proposés par le fournisseur (Menus › Choix fournisseur).
 *
 * Parcours de la mairie : dépôt du classeur du fournisseur, choix d'un plat
 * par catégorie et par jour de cantine, vérification, envoi de la réponse
 * (classeur rempli en pièce jointe), puis remplissage des menus — qui
 * n'intervient qu'une fois la réponse partie.
 *
 * Le classeur déposé est tests/fixtures/menus-fournisseur.xlsx (trois
 * semaines de janvier 2090, dont la troisième est fermée au calendrier par
 * ce scénario, et une feuille d'information sans menu). La lecture du
 * classeur, le contenu du fichier rempli et les règles de remplissage des
 * menus sont vérifiés en détail par bin/verify-menu-choix.php ; ici, ce
 * qui demande un écran, un dépôt de fichier ou une vraie requête HTTP.
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import * as path from 'node:path';
import { APP_BASE, ENGINE, CONTAINER, MAILPIT_API } from '../helpers/env';

const ADMIN_BASE = `${APP_BASE}/wp-admin`;
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const FIXTURE = path.join(__dirname, 'fixtures', 'menus-fournisseur.xlsx');
const FICHIER = 'menus-fournisseur.xlsx';
const SUPPLIER_EMAIL = 'e2e-choix-menus@example.test';
const ETABLISSEMENT = 'École E2E / 95';
const AGENT_LOGIN = 'psc-e2e-choix-facturation';
const AGENT_PASSWORD = 'Choix-Facturation-E2E-2026';
const S1 = '2090-01-09';
const S2 = '2090-01-16';
const S3 = '2090-01-23';
const CHOIX_URL = `${ADMIN_BASE}/admin.php?page=psc_menus&tab=choix`;

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

/** Retire tout ce que ce scénario crée : propositions, menus des trois semaines, fermetures, compte. */
function cleanup(): void {
  wpCliEval(`global $wpdb;
    foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menu_propositions').' WHERE fichier_nom IN (%s, %s)', '${FICHIER}', 'pas-un-classeur.xlsx')) as $id) Psc_Menu_Propositions::delete((int) $id);
    foreach (array('${S1}', '${S2}', '${S3}') as $w) {
      foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menus').' WHERE semaine_debut = %s', $w)) as $id) {
        $wpdb->delete(psc_table('envois'), array('objet_type' => 'menu', 'objet_id' => (int) $id));
        $wpdb->delete(psc_table('menus'), array('id' => (int) $id));
      }
    }
    $wpdb->delete(psc_table('school_calendar'), array('label' => 'e2e-menu-choix'));
    require_once ABSPATH.'wp-admin/includes/user.php';
    $u = get_user_by('login', '${AGENT_LOGIN}'); if ($u) wp_delete_user((int) $u->ID);
    echo 'ok';`);
}

async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(user);
  await page.locator('#user_pass').fill(pass);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

/**
 * L'aperçu de l'e-mail (iframe) est exclu : il affiche le gabarit commun
 * des e-mails (templates/email/layout.php), dont le pied de page ne relève
 * pas de cet écran — son contraste est suivi dans docs/feuille-de-route.md.
 */
async function expectAccessible(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page })
    .include('#wpbody-content .wrap')
    .exclude('[data-testid="choix-apercu"]')
    .withTags(['wcag2a', 'wcag2aa'])
    .analyze();
  expect(axe.violations).toEqual([]);
}

function propositionId(): number {
  return Number(wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('menu_propositions').' WHERE fichier_nom = %s ORDER BY id DESC LIMIT 1', '${FICHIER}'));`
  ));
}

test.describe.serial('Choix des menus du fournisseur (backoffice)', () => {
  let oldSupplier = '';
  let oldEtablissement = '';

  test.beforeAll(() => {
    cleanup();
    oldSupplier = wpCliEval("echo get_option('psc_supplier_email', '');");
    oldEtablissement = wpCliEval("echo get_option('psc_supplier_etablissement', '__absent__');");
    // Troisième semaine du classeur fermée au calendrier scolaire (vacances).
    wpCliEval(`global $wpdb; update_option('psc_supplier_email', '${SUPPLIER_EMAIL}'); update_option('psc_supplier_etablissement', '${ETABLISSEMENT}');
      foreach (array('2090-01-23', '2090-01-24', '2090-01-26', '2090-01-27') as $d) {
        $wpdb->insert(psc_table('school_calendar'), array('jour_date' => $d, 'label' => 'e2e-menu-choix', 'is_closed' => 1, 'source' => 'manual', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
      }
      $id = wp_insert_user(array('user_login' => '${AGENT_LOGIN}', 'user_pass' => '${AGENT_PASSWORD}', 'user_email' => '${AGENT_LOGIN}@example.test', 'role' => 'subscriber'));
      get_user_by('id', $id)->add_cap('psc_manage_billing'); echo 'ok';`);
  });

  test.afterAll(() => {
    cleanup();
    wpCliEval(`update_option('psc_supplier_email', '${oldSupplier}');
      ${oldEtablissement === '__absent__' ? "delete_option('psc_supplier_etablissement');" : `update_option('psc_supplier_etablissement', '${oldEtablissement}');`} echo 'ok';`);
  });

  test('dépôt du classeur, choix, vérification, envoi puis remplissage des menus', async ({ page }) => {
    await login(page, 'admin', 'admin');

    /* ---------------- 1. Dépôt ---------------- */
    await page.goto(CHOIX_URL);
    await expect(page.getByRole('heading', { name: 'Déposer le fichier du fournisseur' })).toBeVisible();
    await expectAccessible(page);

    await page.locator('#psc-choix-fichier').setInputFiles(FIXTURE);
    await page.getByTestId('choix-import-submit').click();
    await expect(page.getByTestId('notice-choix_importe')).toBeVisible();
    const detail = page.getByTestId('choix-detail');
    await expect(detail).toHaveAttribute('data-etape', 'choix');
    await expect(detail.getByRole('heading', { name: FICHIER })).toBeVisible();
    await expect(page.getByTestId('choix-statut')).toHaveText('Choix en cours');
    // La feuille d'information, sans menu, est signalée et laissée de côté.
    await expect(page.getByTestId('choix-feuilles-ignorees')).toContainText('Informations');

    /* ---------------- 2. Choix ---------------- */
    // Semaine fermée au calendrier : rien à choisir ; le mercredi n'est jamais proposé.
    await expect(page.getByTestId(`choix-semaine-${S3}`)).toContainText('Aucun jour de cantine');
    await expect(page.getByTestId(`choix-semaine-${S3}`).locator('input[type="radio"]')).toHaveCount(0);
    await expect(page.getByTestId(`choix-${S1}-mercredi-entree`)).toHaveCount(0);

    // La suggestion de la diététicienne est cochée d'avance.
    const lundiEntree = page.getByTestId(`choix-${S1}-lundi-entree`);
    await expect(lundiEntree.getByRole('radio', { name: /^Velouté de légumes BIO/ })).toBeChecked();
    await expectAccessible(page);

    // Une option permanente en entrée, et aucun accompagnement pour le plat complet du jeudi.
    await lundiEntree.getByRole('radio', { name: /Velouté de légumes \(chaud\)/ }).check();
    await page.getByTestId(`choix-${S1}-jeudi-accompagnement`).getByRole('radio', { name: 'Aucun' }).check();
    await page.getByTestId('choix-enregistrer').click();
    await expect(page.getByTestId('notice-choix_enregistres')).toBeVisible();
    await expect(page.getByTestId(`choix-${S1}-lundi-entree`).getByRole('radio', { name: /Velouté de légumes \(chaud\)/ })).toBeChecked();
    await expect(page.getByTestId(`choix-${S1}-jeudi-accompagnement`).getByRole('radio', { name: 'Aucun' })).toBeChecked();
    // Enregistrer n'envoie rien et ne remplit aucun menu.
    expect(wpCliEval(`echo Psc_Menus::get_by_week('${S1}') ? 'menu' : 'aucun';`)).toBe('aucun');

    /* ---------------- 3. Vérification avant envoi ---------------- */
    await page.getByTestId('choix-verifier').click();
    await expect(detail).toHaveAttribute('data-etape', 'envoi');
    await expect(page.getByTestId('choix-destinataire')).toHaveText(SUPPLIER_EMAIL);
    // 2 semaines ouvertes × 4 jours × 5 catégories, moins l'accompagnement écarté.
    await expect(page.getByTestId('choix-total')).toHaveText('39 plats cochés sur 8 jours de cantine');
    await expect(page.getByTestId(`choix-recap-${S1}-lundi-entree`)).toHaveText('Velouté de légumes (chaud)');
    await expect(page.getByTestId(`choix-recap-${S1}-jeudi-accompagnement`)).toContainText('Aucun plat');
    await expect(page.getByTestId(`choix-menu-etat-${S1}`)).toHaveAttribute('data-etat', 'absent');
    await expect(page.getByTestId('choix-sujet')).toContainText('09/01/2090 au 27/01/2090');
    await expectAccessible(page);

    // Le fichier rempli se relit avant l'envoi : un vrai classeur, au nom du fournisseur.
    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('choix-telecharger').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('menus-fournisseur-reponse.xlsx');
    const saved = path.join(mkdtempSync(path.join(tmpdir(), 'psc-choix-')), 'reponse.xlsx');
    await download.saveAs(saved);
    expect(readFileSync(saved).subarray(0, 4).toString('latin1')).toBe('PK\u0003\u0004');

    /* ---------------- 4. Envoi ---------------- */
    await page.getByTestId('choix-confirmer').click();
    await expect(page.getByTestId('notice-choix_envoyes')).toBeVisible();
    await expect(detail).toHaveAttribute('data-etape', 'suivi');
    await expect(page.getByTestId('choix-statut')).toHaveText('Réponse envoyée, menus remplis');
    await expect(page.getByTestId('choix-form')).toHaveCount(0);
    await expect(page.getByTestId(`choix-menu-etat-${S1}`)).toHaveAttribute('data-etat', 'a_jour');
    await expect(page.getByTestId(`choix-menu-etat-${S3}`)).toHaveCount(0);
    await expectAccessible(page);

    // Le fournisseur reçoit le classeur rempli en pièce jointe.
    const query = `to:${SUPPLIER_EMAIL} subject:"Choix des menus"`;
    type Reponse = { Subject: string; Attachments: Array<{ FileName: string; Size: number }> };
    let message = null as Reponse | null;
    await expect.poll(async () => {
      const res = await fetch(`${MAILPIT_API}/search?${new URLSearchParams({ query, limit: '5' })}`);
      const data = (await res.json()) as { messages: Array<{ ID: string; Created: string }> };
      if (!data.messages.length) return 0;
      const latest = data.messages.reduce((a, b) => (a.Created > b.Created ? a : b));
      message = (await (await fetch(`${MAILPIT_API}/message/${latest.ID}`)).json()) as Reponse;
      return message.Attachments.length;
    }, { timeout: 10_000 }).toBe(1);
    expect(message!.Subject).toContain('09/01/2090 au 27/01/2090');
    expect(message!.Attachments[0].FileName).toBe('menus-fournisseur-reponse.xlsx');
    expect(message!.Attachments[0].Size).toBeGreaterThan(1000);

    /* ---------------- 5. Menus remplis, restés brouillons ---------------- */
    await page.goto(`${ADMIN_BASE}/admin.php?page=psc_menus&semaine_debut=${S1}`);
    await expect(page.locator('#psc-menu-lundi')).toHaveValue(
      "Velouté de légumes (chaud)\nFilet de poulet NOUVELLE AGRICULTURE Vallée d'auge\nPoêlée de légumes*\nCamembert*\nCrème dessert vanille*"
    );
    await expect(page.locator('#psc-menu-jeudi')).toHaveValue(
      'Coleslaw\nLasagnes bolognaises VBF (plat complet)\nMimolette\nYaourt sucré COMMERCE EQUITABLE'
    );
    expect(wpCliEval(`$m = Psc_Menus::get_by_week('${S1}'); echo $m && !$m->sent_at ? 'brouillon' : 'autre';`)).toBe('brouillon');
    expect(wpCliEval(`echo Psc_Menus::get_by_week('${S2}') ? 'menu' : 'aucun';`)).toBe('menu');
    expect(wpCliEval(`echo Psc_Menus::get_by_week('${S3}') ? 'menu' : 'aucun';`)).toBe('aucun');

    // La liste garde la trace de la réponse.
    await page.goto(CHOIX_URL);
    await expect(page.getByTestId(`choix-ligne-${propositionId()}`)).toContainText('Réponse envoyée, menus remplis');
  });

  test('un fichier qui n\'est pas un classeur est refusé, sans rien enregistrer', async ({ page }) => {
    const faux = path.join(mkdtempSync(path.join(tmpdir(), 'psc-choix-')), 'pas-un-classeur.xlsx');
    writeFileSync(faux, 'Ceci est un texte renommé en .xlsx');

    await login(page, 'admin', 'admin');
    await page.goto(CHOIX_URL);
    await page.locator('#psc-choix-fichier').setInputFiles(faux);
    await page.getByTestId('choix-import-submit').click();
    await expect(page.getByTestId('notice-psc_xlsx_format')).toBeVisible();
    await expect(page.getByTestId('choix-detail')).toHaveCount(0);
    expect(wpCliEval(
      `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.psc_table('menu_propositions').' WHERE fichier_nom = %s', 'pas-un-classeur.xlsx'));`
    )).toBe('0');
  });

  test('sans l\'habilitation « présences », l\'onglet et ses actions sont refusés', async ({ page }) => {
    const id = propositionId();
    expect(id).toBeGreaterThan(0);

    await login(page, AGENT_LOGIN, AGENT_PASSWORD);
    const resp = await page.goto(`${CHOIX_URL}&proposition=${id}`);
    expect(resp?.status()).toBe(403);
    await expect(page.getByTestId('choix-detail')).toHaveCount(0);

    // Contrôle serveur : les requêtes forgées sont refusées pour défaut de droit.
    const actions = [
      'psc_import_menu_proposition', 'psc_save_menu_choix', 'psc_send_menu_choix', 'psc_retry_menu_choix',
      'psc_fill_menus_from_choix', 'psc_download_menu_choix', 'psc_delete_menu_proposition',
    ];
    for (const action of actions) {
      const post = await page.request.post(`${ADMIN_BASE}/admin-post.php`, {
        form: { action, id: String(id), _wpnonce: 'x' },
        maxRedirects: 0,
      });
      expect(post.status(), action).toBe(403);
      expect(await post.text(), action).toContain('droits nécessaires');
    }
    // La proposition n'a été ni supprimée ni modifiée.
    expect(wpCliEval(`$p = Psc_Menu_Propositions::get(${id}); echo $p ? $p->statut : 'absente';`)).toBe('menus_remplis');
  });
});
