/**
 * Notes de changement de menus du fournisseur (Menus › Notes du fournisseur).
 *
 * Parcours de la mairie : dépôt de la note Word du fournisseur, lecture des
 * remplacements (« … est remplacé par … »), rapprochement avec le menu du
 * jour désigné par la date du nom du fichier, puis relecture et correction
 * libre du menu de ce jour, prérempli des remplacements reconnus — sans
 * aucun envoi aux familles, même si le menu leur a déjà été envoyé.
 *
 * Le document déposé est tests/fixtures/Note changement de menus
 * 06-02-90.docx (lundi 6 février 2090). La lecture du document, les
 * tournures reconnues et les règles d'application sont vérifiées en détail
 * par bin/verify-menu-notes.php ; ici, ce qui demande un écran, un dépôt de
 * fichier ou une vraie requête HTTP.
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import * as path from 'node:path';
import { APP_BASE, ENGINE, CONTAINER } from '../helpers/env';

const ADMIN_BASE = `${APP_BASE}/wp-admin`;
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';
const FICHIER = 'Note changement de menus 06-02-90.docx';
const FIXTURE = path.join(__dirname, 'fixtures', FICHIER);
const AGENT_LOGIN = 'psc-e2e-notes-facturation';
const AGENT_PASSWORD = 'Notes-Facturation-E2E-2026';
const SEMAINE = '2090-02-06';
const NOTES_URL = `${ADMIN_BASE}/admin.php?page=psc_menus&tab=notes`;
const LUNDI_AVANT = 'Velouté de légumes (chaud)\\nFilet de poulet\\nPoêlée de légumes*\\nCamembert*\\nCrème dessert vanille*';

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

/** Retire tout ce que ce scénario crée : notes, menu de la semaine, compte. */
function cleanup(): void {
  wpCliEval(`global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM '.psc_table('menu_notes').' WHERE fichier_nom IN (%s, %s)', '${FICHIER}', 'pas-une-note 06-02-90.docx'));
    foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('menus').' WHERE semaine_debut = %s', '${SEMAINE}')) as $id) {
      $wpdb->delete(psc_table('envois'), array('objet_type' => 'menu', 'objet_id' => (int) $id));
      $wpdb->delete(psc_table('menus'), array('id' => (int) $id));
    }
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

async function expectAccessible(page: Page): Promise<void> {
  const axe = await new AxeBuilder({ page }).include('#wpbody-content .wrap').withTags(['wcag2a', 'wcag2aa']).analyze();
  expect(axe.violations).toEqual([]);
}

function noteId(): number {
  return Number(wpCliEval(
    `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('menu_notes').' WHERE fichier_nom = %s ORDER BY id DESC LIMIT 1', '${FICHIER}'));`
  ));
}

test.describe.serial('Notes de changement de menus du fournisseur (backoffice)', () => {
  test.beforeAll(() => {
    cleanup();
    // Menu de la semaine, déjà envoyé aux familles : la correction ne doit rien renvoyer.
    wpCliEval(`global $wpdb;
      $id = Psc_Menus::save(0, '${SEMAINE}', array('lundi' => "${LUNDI_AVANT}", 'mardi' => "Salade verte\\nGratin de pâtes"));
      $wpdb->update(psc_table('menus'), array('sent_at' => '2090-01-30 10:00:00'), array('id' => (int) $id));
      $u = wp_insert_user(array('user_login' => '${AGENT_LOGIN}', 'user_pass' => '${AGENT_PASSWORD}', 'user_email' => '${AGENT_LOGIN}@example.test', 'role' => 'subscriber'));
      get_user_by('id', $u)->add_cap('psc_manage_billing'); echo 'ok';`);
  });

  test.afterAll(() => { cleanup(); });

  test('dépôt de la note, menu corrigé proposé, correction à la main et enregistrement sans envoi', async ({ page }) => {
    await login(page, 'admin', 'admin');

    /* ---------------- 1. Dépôt ---------------- */
    await page.goto(NOTES_URL);
    await expect(page.getByRole('heading', { name: 'Déposer une note de changement' })).toBeVisible();
    await expectAccessible(page);

    await page.locator('#psc-note-fichier').setInputFiles(FIXTURE);
    await page.getByTestId('note-import-submit').click();
    await expect(page.getByTestId('notice-note_importee')).toBeVisible();
    const detail = page.getByTestId('note-detail');
    await expect(detail).toHaveAttribute('data-statut', 'a_confirmer');
    await expect(page.getByTestId('note-statut')).toHaveText('À confirmer');

    // Le texte de la note est lu une seule fois, sans la zone d'exemple du modèle Word.
    const texte = page.getByTestId('note-texte');
    await expect(texte.getByText('Le camembert bio est remplacé par de la tomme noire')).toHaveCount(1);
    await expect(texte.locator('li')).toHaveCount(3);
    await expect(texte).not.toContainText('Attirez');

    /* ---------------- 2. Jour concerné : la date du nom du fichier ---------------- */
    await expect(page.locator('#psc-note-jour-date')).toHaveValue(SEMAINE);
    await expect(page.getByTestId('note-jour-contredit')).toHaveCount(0);
    await expect(page.getByTestId('note-menu-envoye')).toBeVisible();

    // Un autre jour change le menu rapproché : la note parle de lundi, le mardi est signalé.
    await page.locator('#psc-note-jour-date').fill('2090-02-07');
    await page.getByTestId('note-jour-changer').click();
    await expect(page.getByTestId('notice-note_jour_modifie')).toBeVisible();
    await expect(page.getByTestId('note-jour-contredit')).toContainText('lundi');
    await expect(page.getByTestId('note-remplacement-0')).toHaveAttribute('data-trouve', '0');
    // Menu saisi sans type : ses lignes sont réparties dans l'ordre du service.
    await expect(page.locator('#psc-note-menu-jour-entree')).toHaveValue('Salade verte');
    await expect(page.locator('#psc-note-menu-jour-plat')).toHaveValue('Gratin de pâtes');
    await expect(page.locator('#psc-note-menu-jour-dessert')).toHaveValue('');
    await page.locator('#psc-note-jour-date').fill(SEMAINE);
    await page.getByTestId('note-jour-changer').click();
    await expect(page.locator('#psc-note-jour-date')).toHaveValue(SEMAINE);

    /* ---------------- 3. Menu corrigé proposé ---------------- */
    // Le menu actuel reste affiché, à côté du menu corrigé à relire.
    await expect(page.getByTestId('note-menu-actuel').locator('li')).toHaveCount(5);
    // Crème dessert vanille bio et camembert bio sont retrouvés dans le menu ; les frites n'y sont pas.
    await expect(page.getByTestId('note-remplacement-0')).toHaveAttribute('data-trouve', '1');
    await expect(page.getByTestId('note-remplacement-0')).toContainText('la ligne « Crème dessert vanille* » devient « Crèmes dessert vanille »');
    await expect(page.getByTestId('note-remplacement-0')).toContainText('ne concerne que les clients ayant un contrat avec bio');
    await expect(page.getByTestId('note-remplacement-1')).toContainText('la ligne « Camembert* » devient « Tomme noire »');
    await expect(page.getByTestId('note-remplacement-2')).toHaveAttribute('data-trouve', '0');
    await expect(page.getByTestId('note-remplacement-2')).toContainText('non retrouvé');
    // Les cinq champs sont préremplis : remplacements reconnus reportés, le reste repris tel quel.
    const corrige = page.getByTestId('note-menu-corrige');
    await expect(corrige.locator('textarea')).toHaveCount(5);
    await expect(page.locator('#psc-note-menu-jour-entree')).toHaveValue('Velouté de légumes (chaud)');
    await expect(page.locator('#psc-note-menu-jour-plat')).toHaveValue('Filet de poulet');
    await expect(page.locator('#psc-note-menu-jour-accompagnement')).toHaveValue('Poêlée de légumes*');
    await expect(page.locator('#psc-note-menu-jour-fromage')).toHaveValue('Tomme noire');
    await expect(corrige.getByLabel('Dessert')).toHaveValue('Crèmes dessert vanille');
    await expectAccessible(page);

    // Rien n'est modifié tant que la mairie n'a pas enregistré.
    expect(wpCliEval(`echo str_replace("\\n", '|', Psc_Menus::get_by_week('${SEMAINE}')->lundi);`)).toContain('Camembert*');

    /* ---------------- 4. Correction à la main, puis enregistrement ---------------- */
    // La mairie corrige librement la proposition (ici, le pluriel repris de la note).
    await corrige.getByLabel('Dessert').fill('Crème dessert vanille');
    await page.getByTestId('note-appliquer').click();
    await expect(page.getByTestId('notice-note_appliquee')).toBeVisible();
    await expect(detail).toHaveAttribute('data-statut', 'appliquee');
    await expect(page.getByTestId('note-statut')).toHaveText('Appliquée au menu');
    await expect(page.getByTestId('note-form')).toHaveCount(0);
    const appliques = page.getByTestId('note-appliques');
    await expect(appliques.locator('tbody tr')).toHaveCount(2);
    await expect(appliques).toContainText('Camembert*');
    await expect(appliques).toContainText('Tomme noire');
    await expectAccessible(page);

    /* ---------------- 5. Menu corrigé, sans envoi ---------------- */
    await page.getByTestId('note-voir-menu').click();
    await expect(page.locator('#psc-menu-lundi-fromage')).toHaveValue('Tomme noire');
    await expect(page.locator('#psc-menu-lundi-dessert')).toHaveValue('Crème dessert vanille');
    await expect(page.locator('#psc-menu-mardi-entree')).toHaveValue('Salade verte');
    await expect(page.locator('#psc-menu-mardi-plat')).toHaveValue('Gratin de pâtes');
    // Le jour corrigé porte désormais ses types ; le mardi, non touché, n'en gagne pas.
    expect(wpCliEval(`$m = Psc_Menus::get_by_week('${SEMAINE}'); echo str_replace("\\n", '|', $m->lundi).' '.$m->lundi_types.' ['.$m->mardi_types.']';`)).toBe(
      'Velouté de légumes (chaud)|Filet de poulet|Poêlée de légumes*|Tomme noire|Crème dessert vanille entree,plat,accompagnement,fromage,dessert []'
    );
    // La date d'envoi d'origine est conservée et aucun envoi aux familles n'a été lancé.
    expect(wpCliEval(`global $wpdb; $m = Psc_Menus::get_by_week('${SEMAINE}');
      echo $m->sent_at.' '.(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.psc_table('envois').' WHERE objet_type = %s AND objet_id = %d', 'menu', $m->id));`
    )).toBe('2090-01-30 10:00:00 0');

    await page.goto(NOTES_URL);
    await expect(page.getByTestId(`note-ligne-${noteId()}`)).toContainText('Appliquée au menu');
  });

  test('un fichier qui n\'est pas un document Word est refusé, sans rien enregistrer', async ({ page }) => {
    const faux = path.join(mkdtempSync(path.join(tmpdir(), 'psc-notes-')), 'pas-une-note 06-02-90.docx');
    writeFileSync(faux, 'Ceci est un texte renommé en .docx');

    await login(page, 'admin', 'admin');
    await page.goto(NOTES_URL);
    await page.locator('#psc-note-fichier').setInputFiles(faux);
    await page.getByTestId('note-import-submit').click();
    await expect(page.getByTestId('notice-psc_docx_format')).toBeVisible();
    await expect(page.getByTestId('note-detail')).toHaveCount(0);
    expect(wpCliEval(
      `global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.psc_table('menu_notes').' WHERE fichier_nom = %s', 'pas-une-note 06-02-90.docx'));`
    )).toBe('0');
  });

  test('sans l\'habilitation « présences », l\'onglet et ses actions sont refusés', async ({ page }) => {
    const id = noteId();
    expect(id).toBeGreaterThan(0);

    await login(page, AGENT_LOGIN, AGENT_PASSWORD);
    const resp = await page.goto(`${NOTES_URL}&note=${id}`);
    expect(resp?.status()).toBe(403);
    await expect(page.getByTestId('note-detail')).toHaveCount(0);

    // Contrôle serveur : les requêtes forgées sont refusées pour défaut de droit.
    const actions = ['psc_import_menu_note', 'psc_set_menu_note_day', 'psc_apply_menu_note', 'psc_ignore_menu_note', 'psc_delete_menu_note'];
    for (const action of actions) {
      const post = await page.request.post(`${ADMIN_BASE}/admin-post.php`, {
        form: { action, id: String(id), jour_date: '2090-02-09', _wpnonce: 'x' },
        maxRedirects: 0,
      });
      expect(post.status(), action).toBe(403);
      expect(await post.text(), action).toContain('droits nécessaires');
    }
    // La note n'a été ni supprimée ni modifiée, le menu non plus.
    expect(wpCliEval(`$n = Psc_Menu_Notes::get(${id}); echo $n ? $n->statut.' '.$n->jour_date : 'absente';`)).toBe(`appliquee ${SEMAINE}`);
    expect(wpCliEval(`echo str_replace("\\n", '|', Psc_Menus::get_by_week('${SEMAINE}')->lundi);`)).toContain('Tomme noire');
  });
});
