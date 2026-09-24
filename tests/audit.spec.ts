/**
 * Journal d'audit — capture générique + sémantique, écran de consultation,
 * export, conservation et oubli à la suppression d'une famille.
 *
 * beforeAll TRONQUE wp_psc_audit_log (et les options de suivi de chaîne) :
 * la table n'existe que pour cette fonctionnalité, aucune autre spec ne la
 * lit, et les scénarios de rétention/intégrité ci-dessous ont besoin d'un
 * historique dont l'ordre des id reflète l'ordre chronologique dès la
 * première ligne — impossible à garantir sur une table déjà peuplée par
 * les specs précédentes de la même exécution (cf. Psc_Audit::purge_expired(),
 * qui ne purge jamais qu'un préfixe contigu par id croissant).
 *
 * Comme les autres specs du projet, exécution strictement séquentielle
 * (playwright.config.ts) : une seule base, un seul compte admin.
 */
import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { readFormPageUrl } from '../playwright/seed-result';
import { findLatestMessage } from '../helpers/mailpit';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const LIMITED_USER = 'audit-limited-e2e';

const EMAIL_MOD = 'audit-mod.e2e@example.test';
const EMAIL_ALLERGY = 'audit-allergy.e2e@example.test';
const EMAIL_LOGIN = 'audit-login.e2e@example.test';
const EMAIL_PLANNING = 'audit-planning.e2e@example.test';
const EMAIL_CSV = 'audit-csv.e2e@example.test';
const EMAIL_DELETE = 'audit-delete.e2e@example.test';
const EMAIL_IMPERSONATE = 'audit-imp.e2e@example.test';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function cleanupFamilies(): void {
  wpEval(`global $wpdb;
    foreach(array('${EMAIL_MOD}','${EMAIL_ALLERGY}','${EMAIL_LOGIN}','${EMAIL_PLANNING}','${EMAIL_CSV}','${EMAIL_DELETE}','${EMAIL_IMPERSONATE}') as $email){
      $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",$email));
      if($pid){
        foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){
          $wpdb->delete($wpdb->prefix.'psc_exception',array('child_id'=>(int)$cid));
          $wpdb->delete($wpdb->prefix.'psc_pattern',array('child_id'=>(int)$cid));
          $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid));
          $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid));
        }
        $wpdb->delete($wpdb->prefix.'psc_impersonations',array('family_id'=>$pid));
        $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid));
      }
    }
    $user=get_user_by('login','${LIMITED_USER}');
    if($user){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user((int)$user->ID);}
    echo 'ok';`);
}

function seedFamily(email: string, nom = 'AuditE2E'): number {
  return Number(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array(
      'email'=>'${email}','nom'=>'${nom}','prenom'=>'Famille','active'=>1,
      'adresse'=>'1 rue avant test','code_postal'=>'95000','ville'=>'Testville','payment_mode'=>'autre',
      'onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    echo (int) $wpdb->insert_id;`));
}

function seedFamilyWithChild(email: string): { familyId: number; childId: number } {
  return JSON.parse(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array(
      'email'=>'${email}','nom'=>'AuditE2E','prenom'=>'Famille','active'=>1,
      'onboarding_seen_at'=>current_time('mysql'),'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array('parent_id'=>$pid,'nom'=>'AuditE2E','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    $cid=(int)$wpdb->insert_id;
    Psc_School_Years::enroll($cid,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    Psc_Assurances::upsert_row($cid,'test/assurance.pdf','assurance.pdf');
    echo wp_json_encode(array('familyId'=>$pid,'childId'=>$cid));`));
}

async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

async function loginAsWordPressUser(page: Page, login: string, password: string): Promise<void> {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill(login);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function loginAsFamily(page: Page, email: string): Promise<void> {
  await page.goto(readFormPageUrl());
  await page.getByTestId('login-email-input').fill(email);
  await page.getByTestId('login-submit-button').click();
  const mail = await findLatestMessage(email, 'Votre lien d\'accès aux inscriptions périscolaires');
  const link = mail.Text.match(/https?:\/\/\S*psc_pid=\d+&psc_token=[0-9a-f]+/);
  expect(link, 'lien de connexion famille introuvable').toBeTruthy();
  await page.goto(link![0]);
  await expect(page.getByTestId('portal-root')).toBeVisible();
}

async function startConsultation(page: Page, familyId: number): Promise<void> {
  await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents`);
  await page.getByTestId(`impersonate-open-${familyId}`).click();
  await expect(page.getByRole('heading', { name: 'Consulter un espace famille' })).toBeVisible();
  await page.getByTestId('impersonate-motif-reclamation').check();
  await page.getByTestId('impersonate-submit').click();
  await expect(page.getByTestId('portal-root')).toHaveAttribute('data-psc-readonly', '1');
}

/** Dernière ligne du journal pour ce code d'action, ou null. */
function latestAuditRow(actionCode: string, whereExtra = ''): Record<string, unknown> | null {
  const raw = wpEval(`global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}psc_audit_log WHERE action=%s ${whereExtra} ORDER BY id DESC LIMIT 1",'${actionCode}'),ARRAY_A);
    echo wp_json_encode($row);`);
  return raw === 'null' ? null : JSON.parse(raw);
}

function countAuditRows(whereClause: string): number {
  return Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_audit_log WHERE ${whereClause}");`));
}

test.describe('Journal d’audit', () => {
  test.beforeAll(() => {
    wpEval(`global $wpdb;
      $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}psc_audit_log");
      delete_option('psc_audit_last_hash');
      delete_option('psc_audit_failures');
      delete_option('psc_audit_unknown_actions');
      update_option('psc_audit_retention_critique', 1095);
      update_option('psc_audit_retention_normal', 365);
      update_option('psc_audit_retention_volumineux', 180);
      echo 'ok';`);
  });
  test.beforeEach(() => cleanupFamilies());
  test.afterEach(() => cleanupFamilies());
  test.afterAll(() => {
    cleanupFamilies();
    wpEval(`global $wpdb;
      $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}psc_audit_log");
      delete_option('psc_audit_last_hash');
      update_option('psc_audit_retention_critique',1095);
      update_option('psc_audit_retention_normal',365);
      update_option('psc_audit_retention_volumineux',180);
      echo 'ok';`);
  });

  test('conservation : la purge quotidienne respecte les durées réglées sans jamais casser la chaîne', async ({ page }) => {
    const ids = JSON.parse(wpEval(`global $wpdb; $t=$wpdb->prefix.'psc_audit_log';
      $wpdb->query("TRUNCATE TABLE $t"); delete_option('psc_audit_last_hash');
      $cases=array(
        array('critique_expiree','famille.suppression',1001),
        array('normale_expiree','menu.enregistrement',301),
        array('volumineuse_expiree','planning.exception_modifiee',101),
        array('critique_conservee','famille.suppression',999),
        array('normale_conservee','menu.enregistrement',299),
        array('volumineuse_conservee','planning.exception_modifiee',99)
      );
      $ids=array();
      foreach($cases as $case){
        Psc_Audit::log($case[1],array('resume'=>'AuditE2E '.$case[0]));
        $id=(int)$wpdb->get_var("SELECT MAX(id) FROM $t"); $ids[$case[0]]=$id;
        $wpdb->update($t,array('horodatage'=>gmdate('Y-m-d H:i:s',time()-$case[2]*DAY_IN_SECONDS)),array('id'=>$id));
      }
      $previous='';
      foreach($wpdb->get_results("SELECT * FROM $t ORDER BY id ASC") as $row){
        $hash=psc_audit_compute_hash($previous,$row->horodatage,$row->action,$row->acteur_type,$row->acteur_id,$row->objet_type,$row->objet_id,$row->resume);
        $wpdb->update($t,array('empreinte'=>$hash),array('id'=>$row->id)); $previous=$hash;
      }
      update_option('psc_audit_last_hash',$previous,false);
      echo wp_json_encode($ids);`));

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-retention-critique').fill('1000');
    await page.locator('#psc-retention-normal').fill('300');
    await page.locator('#psc-retention-volumineux').fill('100');
    await page.getByTestId('audit-retention-save').click();
    await expect(page.getByText('Durées de rétention enregistrées.')).toBeVisible();

    wpEval(`do_action('psc_purge_audit_log'); echo 'ok';`);
    for (const key of ['critique_expiree', 'normale_expiree', 'volumineuse_expiree']) {
      expect(countAuditRows(`id=${ids[key]}`), key).toBe(0);
    }
    for (const key of ['critique_conservee', 'normale_conservee', 'volumineuse_conservee']) {
      expect(countAuditRows(`id=${ids[key]}`), key).toBe(1);
    }

    await page.getByTestId('audit-verify-submit').click();
    await expect(page.locator('.notice-success').getByText('Chaîne d’intégrité vérifiée', { exact: false })).toBeVisible();

    wpEval(`update_option('psc_audit_retention_critique',1095);update_option('psc_audit_retention_normal',365);update_option('psc_audit_retention_volumineux',180);echo 'ok';`);
  });

  test('intégrité : une altération directe en base est détectée puis réparée sans effet sur les lignes suivantes', async ({ page }) => {
    wpEval(`Psc_Audit::log('menu.enregistrement', array('objet_type'=>'menu','resume'=>'AuditE2E ligne A')); echo 'ok';`);
    wpEval(`Psc_Audit::log('menu.enregistrement', array('objet_type'=>'menu','resume'=>'AuditE2E ligne B')); echo 'ok';`);
    const targetId = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}psc_audit_log");`));
    const original = wpEval(`global $wpdb; echo (string)$wpdb->get_var($wpdb->prepare("SELECT resume FROM {$wpdb->prefix}psc_audit_log WHERE id=%d",${targetId}));`);

    // Altération directe : le résumé change, l'empreinte stockée non — un
    // vecteur qu'aucune API du plugin ne permet, simulant un accès direct
    // à la base (le scénario que ce mécanisme doit détecter).
    wpEval(`global $wpdb; $wpdb->update($wpdb->prefix.'psc_audit_log', array('resume'=>'Altéré directement en base'), array('id'=>${targetId})); echo 'ok';`);

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.getByTestId('audit-verify-submit').click();
    await expect(page.getByText(`Rupture de chaîne détectée : la ligne #${targetId}`, { exact: false })).toBeVisible();

    wpEval(`global $wpdb; $wpdb->update($wpdb->prefix.'psc_audit_log', array('resume'=>'${original}'), array('id'=>${targetId})); echo 'ok';`);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.getByTestId('audit-verify-submit').click();
    await expect(page.locator('.notice-success').getByText('Chaîne d’intégrité vérifiée', { exact: false })).toBeVisible();
  });

  test('modification de famille : diff affiché, IBAN masqué, titulaire et BIC jamais en clair', async ({ page }) => {
    const familyId = seedFamily(EMAIL_MOD);
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyId}`);
    await page.locator('#psc-edit-adresse').fill('2 rue après test');
    await page.locator('input[name="payment_mode"][value="prelevement"]').check();
    await page.locator('#psc-edit-sepa-titulaire').fill('Jean Titulaire');
    await page.locator('#psc-edit-sepa-iban').fill('FR76 3000 6000 0112 3456 7890 189');
    await page.locator('#psc-edit-sepa-bic').fill('AGRIFRPP882');
    await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
    await page.waitForURL(/page=psc_parents/);

    const auditRow = latestAuditRow('famille.modification', `AND famille_id=${familyId}`);
    expect(auditRow?.acteur_type).toBe('agent');
    expect(auditRow?.famille_id).toBe(String(familyId));
    const auditDetails = JSON.parse((auditRow?.details as string) ?? '{}');
    expect(auditDetails.avant.adresse).toBe('1 rue avant test');
    expect(auditDetails.apres.adresse).toBe('2 rue après test');

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit&famille_id=${familyId}`);
    const row = page.getByRole('row', { name: /Famille — modification/ }).first();
    await row.getByRole('button', { name: 'Détails' }).click();
    const bodyText = await page.locator('body').innerText();

    expect(bodyText).toContain('1 rue avant test');
    expect(bodyText).toContain('2 rue après test');
    expect(bodyText).toContain('FR76 •••• •••• 0189');
    expect(bodyText).not.toContain('FR7630006000011234567890189');
    expect(bodyText).not.toContain('Jean Titulaire');
    expect(bodyText).not.toContain('AGRIFRPP882');
    // sepa_titulaire / sepa_bic exclus : présents au journal comme "changés",
    // jamais avec leur valeur (cf. psc_audit_field_policy()).
    expect(bodyText.match(/modifié/g)?.length ?? 0).toBeGreaterThanOrEqual(2);
  });

  test('modification d’enfant : le signalement alimentaire est journalisé comme modifié, sans valeur, et survit à une correction de prénom', async ({ page }) => {
    const { familyId, childId } = seedFamilyWithChild(EMAIL_ALLERGY);
    const signal = (): string => wpEval(
      `global $wpdb; echo (string) $wpdb->get_var("SELECT food_allergy_signal FROM {$wpdb->prefix}psc_children WHERE id=${childId}");`
    ).trim().split('\n').pop() ?? '';

    await loginAsFamily(page, EMAIL_ALLERGY);
    await page.goto(`${APP_BASE}/?psc_tab=enfants`);
    await page.locator(`[data-child-edit-trigger][data-child-id="${childId}"]`).click();
    await page.getByTestId('child-edit-food-signal').check();
    await page.getByTestId('child-edit-submit').click();
    await page.waitForURL(/psc_msg=child_updated/);
    expect(signal()).toBe('1');

    const row = latestAuditRow('enfant.modification', `AND enfant_id=${childId}`);
    expect(row?.acteur_type).toBe('famille');
    expect(row?.famille_id).toBe(String(familyId));
    const serialized = String(row?.details ?? '');
    expect(serialized).toContain('"food_signal":"modifié"');

    // La modale rouvre la case cochée : corriger le prénom ne doit pas
    // effacer le signalement en silence.
    await page.locator(`[data-child-edit-trigger][data-child-id="${childId}"]`).click();
    await expect(page.getByTestId('child-edit-food-signal')).toBeChecked();
    await page.locator('#psc-child-edit-prenom').fill('Prénom-Corrigé');
    await page.getByTestId('child-edit-submit').click();
    await page.waitForURL(/psc_msg=child_updated/);
    expect(signal()).toBe('1');
  });

  test('connexion : jeton invalide journalisé en refus, connexion réussie journalisée avec le bon acteur', async ({ page }) => {
    const familyId = seedFamily(EMAIL_LOGIN);
    const base = readFormPageUrl();
    await page.goto(`${base}${base.includes('?') ? '&' : '?'}psc_pid=${familyId}&psc_token=0000000000000000000000000000000000000000000000000000000000000000`);

    const failed = latestAuditRow('famille.connexion_echouee', `AND famille_id=${familyId}`);
    expect(failed?.resultat).toBe('refus');
    expect(failed?.famille_id).toBe(String(familyId));

    const expiredToken = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    wpEval(`global $wpdb; $wpdb->update($wpdb->prefix.'psc_parents',array(
      'token_hash'=>psc_hash_token('${expiredToken}'),'token_expires'=>'2020-01-01 00:00:00'),array('id'=>${familyId})); echo 'ok';`);
    await page.goto(`${base}${base.includes('?') ? '&' : '?'}psc_pid=${familyId}&psc_token=${expiredToken}`);
    const expired = latestAuditRow('famille.connexion_echouee', `AND famille_id=${familyId}`);
    expect(expired?.resultat).toBe('refus');
    expect(expired?.resume).toContain('expiré');

    await loginAsFamily(page, EMAIL_LOGIN);
    const success = latestAuditRow('famille.connexion', `AND famille_id=${familyId}`);
    expect(success?.resultat).toBe('succes');
    expect(success?.acteur_type).toBe('famille');
    expect((success?.acteur_libelle as string) ?? '').toContain(EMAIL_LOGIN);
  });

  test('planning : une bascule en bloc de plusieurs jours ne produit qu’une seule ligne d’audit', async ({ page }) => {
    const { familyId, childId } = seedFamilyWithChild(EMAIL_PLANNING);
    const dates: string[] = JSON.parse(wpEval(`$y=Psc_School_Year::active();
      $days=array_values(array_filter(Psc_School_Year::school_days($y->date_start,$y->date_end),function($d){return !psc_is_locked($d);}));
      echo wp_json_encode(array_slice($days,0,3));`));
    expect(dates.length).toBe(3);

    await loginAsFamily(page, EMAIL_PLANNING);
    const nonces = await page.evaluate(() => ({
      nonce: (window as unknown as { PSC: { nonce: string } }).PSC.nonce,
      parent_nonce: (window as unknown as { PSC: { parent_nonce: string } }).PSC.parent_nonce,
    }));

    const before = countAuditRows(`action='planning.exception_modifiee' AND enfant_id=${childId}`);
    const response = await page.request.post(`${APP_BASE}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'psc_toggle_exception_bulk', child_id: String(childId), service_code: 'CANT',
        checked: '1', dates: dates.join(','), nonce: nonces.nonce, parent_nonce: nonces.parent_nonce,
      },
    });
    const responseBody = await response.text();
    expect(response.ok(), `HTTP ${response.status()}: ${responseBody}`).toBe(true);
    const after = countAuditRows(`action='planning.exception_modifiee' AND enfant_id=${childId}`);
    expect(after - before).toBe(1);

    const row = latestAuditRow('planning.exception_modifiee', `AND enfant_id=${childId}`);
    const details = JSON.parse((row?.details as string) ?? '{}');
    expect(details.meta.jours).toBe(3);
  });

  test('sécurité : une écriture sans nonce valide est refusée et journalisée comme telle', async ({ page }) => {
    seedFamilyWithChild(EMAIL_PLANNING);
    await loginAsFamily(page, EMAIL_PLANNING);

    const before = countAuditRows(`action='planning.exception_modifiee' AND resultat='refus'`);
    const response = await page.request.post(`${APP_BASE}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'psc_toggle_exception', child_id: '1', date: '2026-09-21',
        service_code: 'CANT', checked: '1', nonce: 'nonce-invalide', parent_nonce: 'nonce-invalide',
      },
    });
    expect(response.status()).toBe(403);
    expect(countAuditRows(`action='planning.exception_modifiee' AND resultat='refus'`) - before).toBe(1);
  });

  test('écran : filtrage par catégorie et recherche libre, pagination sur un grand nombre de lignes', async ({ page }) => {
    const marker = 'AuditE2E-Pagination';
    const familyId = seedFamily(EMAIL_MOD);
    wpEval(`global $wpdb; $t=$wpdb->prefix.'psc_audit_log'; $now=current_time('mysql',true);
      for($i=0;$i<62;$i++){
        $wpdb->insert($t, array(
          'horodatage'=>$now,'requete_id'=>substr(md5((string)$i),0,12).'p','acteur_type'=>'systeme','acteur_libelle'=>'AuditE2E',
          'action'=>'menu.enregistrement','categorie'=>'configuration','resultat'=>'succes',
          'famille_id'=>$i===0?${familyId}:null,'resume'=>'${marker} ligne '.$i,
          'ip'=>'127.0.0.1','canal'=>'cli','empreinte'=>str_repeat('a',64),
        ));
      }
      $wpdb->insert($t,array(
        'horodatage'=>'2020-01-01 00:00:00','requete_id'=>'horsperiodep','acteur_type'=>'systeme','acteur_libelle'=>'AuditE2E',
        'action'=>'menu.enregistrement','categorie'=>'configuration','resultat'=>'succes','famille_id'=>${familyId},
        'resume'=>'AuditE2E-HorsPeriode','canal'=>'cli','empreinte'=>str_repeat('a',64)));
      echo 'ok';`);

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-recherche').fill(marker);
    await page.getByTestId('audit-filter-submit').click();
    await expect(page.getByText('62 lignes', { exact: false })).toBeVisible();
    await expect(page.locator('.tablenav-pages')).toBeVisible();
    const auditRows = page.getByRole('table', { name: /Journal d’audit du plugin/ }).locator('tbody').first().locator(':scope > tr');
    await expect(auditRows).toHaveCount(50);

    await page.locator('.tablenav-pages a.button').last().click();
    await expect(auditRows).toHaveCount(12);

    // Filtrer aussi par catégorie inexistante pour ces lignes : 0 résultat.
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-recherche').fill(marker);
    await page.locator('#psc-audit-categorie').selectOption('securite');
    await page.getByTestId('audit-filter-submit').click();
    await expect(page.getByText('Aucune ligne pour ces filtres.')).toBeVisible();

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-famille').selectOption(String(familyId));
    await page.getByTestId('audit-filter-submit').click();
    await expect(page.getByText(`${marker} ligne 0`)).toBeVisible();
    await expect(page.getByText('AuditE2E-HorsPeriode')).toHaveCount(0);

    wpEval(`global $wpdb; $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}psc_audit_log WHERE resume LIKE %s", '${marker}%')); echo 'ok';`);
  });

  test('écran : 403 pour un utilisateur WordPress sans la capacité psc_view_audit', async ({ page }) => {
    const password = 'Limited-Audit-E2E-2026!';
    wpEval(`wp_insert_user(array('user_login'=>'${LIMITED_USER}','user_pass'=>'${password}','user_email'=>'limited-audit@example.test','role'=>'gestionnaire_periscolaire')); echo 'ok';`);
    await loginAsWordPressUser(page, LIMITED_USER, password);

    const pageResponse = await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    expect(pageResponse?.status()).toBe(403);

    const loggedIn = (await page.context().cookies()).find((cookie) => cookie.name.startsWith('wordpress_logged_in_'));
    expect(loggedIn, 'cookie WordPress introuvable').toBeTruthy();
    const encodedCookie = Buffer.from(loggedIn!.value).toString('base64');
    const userId = Number(wpEval(`echo (int) get_user_by('login','${LIMITED_USER}')->ID;`));
    for (const format of ['csv', 'ods']) {
      const action = `psc_audit_export_${format}`;
      const nonce = wpEval(`$_COOKIE[LOGGED_IN_COOKIE]=base64_decode('${encodedCookie}'); wp_set_current_user(${userId}); echo wp_create_nonce('${action}');`);
      const forged = await page.request.post(`${APP_BASE}/wp-admin/admin-post.php`, {
        form: { action, _wpnonce: nonce },
        maxRedirects: 0,
      });
      expect(forged.status()).toBe(403);
    }
  });

  test('export CSV : BOM, séparateur, protection contre l’injection de formule', async ({ page }) => {
    const familyId = seedFamily(EMAIL_CSV, '=CMD(calc)');
    wpEval(`global $wpdb; $wpdb->insert($wpdb->prefix.'psc_audit_log',array(
      'horodatage'=>'2020-01-01 00:00:00','requete_id'=>'horsperiodep','acteur_type'=>'systeme','acteur_libelle'=>'AuditE2E',
      'action'=>'famille.creation','categorie'=>'donnees_famille','resultat'=>'succes','famille_id'=>${familyId},
      'resume'=>'AuditE2E hors période','canal'=>'cli','empreinte'=>str_repeat('a',64))); echo 'ok';`);
    wpEval(`Psc_Audit::log('famille.creation', array('objet_type'=>'famille','objet_id'=>${familyId},'famille_id'=>${familyId},'resume'=>'=CMD(calc)')); echo 'ok';`);

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit&famille_id=${familyId}`);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('audit-export-csv').click(),
    ]);
    const csvPath = await download.path();
    expect(csvPath).toBeTruthy();
    const buffer = readFileSync(csvPath!);
    expect(buffer.subarray(0, 3).toString('hex')).toBe('efbbbf'); // BOM UTF-8
    const text = buffer.toString('utf8');
    expect(text).toContain(';');
    expect(text).toContain('"Horodatage (UTC)"');
    expect(text).toContain("'=CMD(calc)"); // apostrophe protectrice ajoutée par psc_csv_escape()
    expect(text).not.toMatch(/[^']=CMD\(calc\)/); // jamais la formule nue en tête de cellule
    expect(text).not.toContain('AuditE2E hors période');

    const exportRow = latestAuditRow('audit.export');
    const exportDetails = JSON.parse((exportRow?.details as string) ?? '{}');
    expect(exportDetails.meta.format).toBe('csv');
    expect(exportDetails.meta.lignes).toBe(1);
  });

  test('export ODS : plafond de lignes, refus au-delà, sans limiter le CSV', async ({ page }) => {
    const marker = 'AuditE2E-OdsCap';
    wpEval(`global $wpdb; $t=$wpdb->prefix.'psc_audit_log'; $now=current_time('mysql',true);
      $total=20001; $chunk=2000; $done=0;
      while($done<$total){
        $n=min($chunk,$total-$done);
        $rows=array();
        for($i=0;$i<$n;$i++){
          $rows[]="('".esc_sql($now)."','".substr(md5((string)($done+$i)),0,12)."p','systeme',NULL,'AuditE2E',NULL,'menu.enregistrement','configuration','succes',NULL,NULL,NULL,NULL,'${marker} ligne',NULL,'127.0.0.1','cli','".str_repeat('a',64)."')";
        }
        $wpdb->query("INSERT INTO $t (horodatage,requete_id,acteur_type,acteur_id,acteur_libelle,pour_le_compte_de,action,categorie,resultat,objet_type,objet_id,famille_id,enfant_id,resume,details,ip,canal,empreinte) VALUES ".implode(',',$rows));
        $done+=$n;
      }
      echo (int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE resume LIKE '${marker}%'");`);

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-recherche').fill(marker);
    await page.getByTestId('audit-filter-submit').click();
    await expect(page.getByText(/L’export ODS est limité à 20.?000 lignes/)).toBeVisible();

    const odsHref = await page.getByTestId('audit-export-ods').getAttribute('href'); // URL absolue (admin_url())
    const odsResponse = await page.request.get(odsHref!, { maxRedirects: 0 });
    expect(odsResponse.status()).toBe(400);

    const csvHref = await page.getByTestId('audit-export-csv').getAttribute('href');
    const csvResponse = await page.request.get(csvHref!, { maxRedirects: 0 });
    expect(csvResponse.status()).toBe(200); // le CSV, en flux, n'a pas cette limite.

    wpEval(`global $wpdb; $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}psc_audit_log WHERE resume LIKE %s", '${marker}%')); echo 'ok';`);

    const smallMarker = 'AuditE2E-OdsValide';
    wpEval(`Psc_Audit::log('menu.enregistrement', array('objet_type'=>'menu','resume'=>'${smallMarker}')); echo 'ok';`);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit&recherche=${encodeURIComponent(smallMarker)}`);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('audit-export-ods').click(),
    ]);
    const odsPath = await download.path();
    expect(odsPath).toBeTruthy();
    expect(readFileSync(odsPath!).subarray(0, 4).toString('hex')).toBe('504b0304');
    const entries = execFileSync('unzip', ['-Z1', odsPath!], { encoding: 'utf8' }).trim().split('\n');
    expect(entries).toEqual(['mimetype', 'META-INF/manifest.xml', 'content.xml']);
  });

  test('consultation d’espace famille : l’acteur reste l’agent, "pour le compte de" porte la famille consultée', async ({ page }) => {
    const familyId = seedFamily(EMAIL_IMPERSONATE);
    await loginAsAdmin(page);
    await startConsultation(page, familyId);

    const row = latestAuditRow('consultation.ouverture');
    expect(row?.acteur_type).toBe('agent');
    expect(row?.acteur_libelle).toBe('admin');
    expect(row?.pour_le_compte_de).toBe(String(familyId));

    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit&famille_id=${familyId}`);
    await expect(page.getByText('via consultation de l’espace de', { exact: false })).toHaveCount(0); // pour_le_compte_de, pas famille_id : hors du filtre par famille.
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-action').fill('consultation.ouverture');
    await page.getByTestId('audit-filter-submit').click();
    await expect(page.getByText('via consultation de l’espace de', { exact: false }).first()).toBeVisible();
  });

  test('suppression de famille : anonymise son historique sans effacer la trace de l’action', async ({ page }) => {
    const familyId = seedFamily(EMAIL_DELETE);
    // Une ligne dont le résumé nomme la famille, comme le ferait une vraie
    // connexion — c'est justement ce que forget_family() doit réécrire.
    wpEval(`Psc_Audit::log('famille.connexion', array('objet_type'=>'famille','objet_id'=>${familyId},'famille_id'=>${familyId},
        'acteur'=>array('type'=>'famille','id'=>${familyId},'libelle'=>'AuditE2E (${EMAIL_DELETE})'),
        'resume'=>'Connexion de AuditE2E (${EMAIL_DELETE}).')); echo 'ok';`);
    const loginRowId = Number(wpEval(`global $wpdb; echo (int)$wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}psc_audit_log WHERE action='famille.connexion'");`));

    await loginAsAdmin(page);
    page.on('dialog', (d) => void d.accept());
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_parents&edit=${familyId}`);
    const deleteForm = page.locator(`form:has(input[name="action"][value="psc_delete_family"]):has(input[name="id"][value="${familyId}"])`);
    await deleteForm.getByRole('button', { name: 'Supprimer' }).click();
    await page.waitForURL('**/admin.php?page=psc_parents&psc_msg=family_deleted');

    const loginRow = JSON.parse(wpEval(`global $wpdb; echo wp_json_encode($wpdb->get_row($wpdb->prepare("SELECT famille_id,resume,details,acteur_libelle FROM {$wpdb->prefix}psc_audit_log WHERE id=%d",${loginRowId}),ARRAY_A));`));
    expect(loginRow.famille_id).toBeNull();
    expect(loginRow.details).toBeNull();
    expect(loginRow.resume).not.toContain(EMAIL_DELETE);
    expect(loginRow.acteur_libelle).toBe(`famille supprimée #${familyId}`);

    const deletionRow = latestAuditRow('famille.suppression', `AND objet_id=${familyId}`);
    expect(deletionRow?.famille_id).toBeNull(); // anonymisée juste après avoir été écrite, comme les autres.
    expect(deletionRow?.details).toBeNull();

    const summary = latestAuditRow('audit.purge', `AND resume LIKE '%${familyId}%'`);
    expect(summary).toBeTruthy();
  });

  test('parcours clavier : filtrer puis déplier une ligne au clavier', async ({ page }) => {
    wpEval(`Psc_Audit::log('menu.enregistrement', array('objet_type'=>'menu','resume'=>'AuditE2E clavier','meta'=>array('x'=>1))); echo 'ok';`);

    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_audit`);
    await page.locator('#psc-audit-recherche').focus();
    await page.keyboard.type('AuditE2E clavier');
    await page.getByTestId('audit-filter-submit').focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('AuditE2E clavier')).toBeVisible();

    const table = page.getByRole('table', { name: /Journal d’audit du plugin/ });
    await expect(table.locator('caption')).toContainText('Journal d’audit du plugin périscolaire');
    await expect(table.locator('thead th')).toHaveCount(7);
    expect(await table.locator('thead th').evaluateAll((headers) => headers.every((header) => header.getAttribute('scope') === 'col'))).toBe(true);

    const toggle = page.getByRole('row', { name: /AuditE2E clavier/ }).locator('button[aria-expanded]');
    await toggle.focus();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(await toggle.evaluate((element) => element === document.activeElement && element.matches(':focus-visible'))).toBe(true);
    expect(await toggle.evaluate((element) => {
      const style = getComputedStyle(element);
      return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
    })).toBe(true);
    await page.keyboard.press('Enter');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(toggle).toHaveText('Masquer');
    await expect(page.getByText('Informations complémentaires')).toBeVisible();
  });
});
