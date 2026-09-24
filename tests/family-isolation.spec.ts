/**
 * Isolation entre familles (P2-10) — matrice d'accès famille A / famille B
 * / visiteur anonyme, en vraies requêtes HTTP.
 *
 * La famille A, connectée, vise les données de la famille B par chaque
 * voie du portail : appels AJAX du planning, formulaires, téléchargements.
 * Un visiteur anonyme vise la famille A. La famille A agit sur ses propres
 * données avec un jeton absent, étranger (celui de B) ou périmé. Tout doit
 * être refusé, et la base de B rester intacte : c'est la base qui est
 * vérifiée, pas seulement le code de réponse.
 *
 * Les nonces WordPress d'un visiteur non connecté sont les mêmes pour tous
 * (utilisateur 0) : ils sont calculés côté serveur, comme le jeton propre
 * à chaque famille (psc_parent_nonce), pour isoler ce que l'on teste —
 * l'appartenance des données et le jeton famille.
 */
import { test, expect, type APIRequestContext, type BrowserContext } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const AJAX = `${APP_BASE}/wp-admin/admin-ajax.php`;
const POST = `${APP_BASE}/wp-admin/admin-post.php`;
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const EMAILS = { a: 'isolation-a.e2e@example.test', b: 'isolation-b.e2e@example.test' };

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

interface Family { parent: number; child: number; invoice: number; pickup: number; login: string }

function cleanup(): void {
  php(`global $wpdb; foreach (array('${EMAILS.a}','${EMAILS.b}') as $e) {
    $pid=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.psc_table('parents').' WHERE email=%s',$e)); if(!$pid) continue;
    foreach($wpdb->get_results($wpdb->prepare('SELECT id,pdf_path FROM '.psc_table('invoices').' WHERE parent_id=%d',$pid)) as $i){ @unlink(psc_private_path($i->pdf_path)); $wpdb->delete(psc_table('invoices'),array('id'=>(int)$i->id)); }
    foreach($wpdb->get_col($wpdb->prepare('SELECT id FROM '.psc_table('children').' WHERE parent_id=%d',$pid)) as $c){
      foreach(array('pattern','exception','pickup_persons','pickup_history','child_school_years') as $t) $wpdb->delete(psc_table($t),array('child_id'=>(int)$c));
      $wpdb->delete(psc_table('children'),array('id'=>(int)$c)); }
    $wpdb->delete(psc_table('parents'),array('id'=>$pid)); } echo 'ok';`);
}

function createFamily(email: string, tag: string): Family {
  return JSON.parse(php(`global $wpdb; $now=current_time('mysql');
    $wpdb->insert(psc_table('parents'),array('email'=>'${email}','nom'=>'Isolation${tag}','prenom'=>'Famille','active'=>1,'onboarding_seen_at'=>$now,'created_at'=>$now)); $pid=(int)$wpdb->insert_id;
    $wpdb->insert(psc_table('children'),array('parent_id'=>$pid,'nom'=>'Isolation${tag}','prenom'=>'Enfant${tag}','statut'=>'actif','created_at'=>$now)); $cid=(int)$wpdb->insert_id;
    $year=Psc_School_Years::active_id(); if($year) Psc_School_Years::enroll($cid,$year,'CE1','inscrit',$now);
    require_once PSC_PATH.'includes/fpdf/fpdf.php'; $pdf=new FPDF(); $pdf->AddPage(); $bytes=$pdf->Output('S');
    $rel=Psc_Assurances::BASE.'/isolation/child-'.$cid.'.pdf'; wp_mkdir_p(dirname(psc_private_path($rel))); file_put_contents(psc_private_path($rel),$bytes);
    if($year) Psc_Assurances::upsert_row($cid,$rel,'assurance.pdf',$year);
    $inv='factures/isolation-'.$pid.'.pdf'; wp_mkdir_p(dirname(psc_private_path($inv))); file_put_contents(psc_private_path($inv),$bytes);
    $wpdb->insert(psc_table('invoices'),array('parent_id'=>$pid,'mois'=>'2089-01','total'=>10,'pdf_path'=>$inv,'created_at'=>$now)); $iid=(int)$wpdb->insert_id;
    $wpdb->insert(psc_table('pickup_persons'),array('child_id'=>$cid,'nom'=>'Tiers${tag}','prenom'=>'Mamie','lien'=>'Grand-mère','telephone'=>'0600000000','statut'=>'active','created_at'=>$now,'updated_at'=>$now)); $pk=(int)$wpdb->insert_id;
    $token=bin2hex(random_bytes(32)); $wpdb->update(psc_table('parents'),array('token_hash'=>psc_hash_token($token),'token_expires'=>gmdate('Y-m-d H:i:s',time()+600)),array('id'=>$pid));
    echo wp_json_encode(array('parent'=>$pid,'child'=>$cid,'invoice'=>$iid,'pickup'=>$pk,'login'=>add_query_arg(array('psc_pid'=>$pid,'psc_token'=>$token),Psc_Mailer::form_page_url())));`));
}

/** Nonces calculés côté serveur : WordPress (utilisateur 0) et jeton famille. */
function wpNonce(action: string): string {
  return php(`wp_set_current_user(0); echo wp_create_nonce('${action}');`);
}
function familyNonce(action: string, parentId: number, tickOffset = 0): string {
  return php(`echo psc_parent_nonce('${action}', ${parentId}, ${tickOffset});`);
}

/** Photographie de ce que la famille B possède, pour vérifier qu'elle est intacte. */
function snapshotB(b: Family): string {
  return php(`global $wpdb; echo md5(wp_json_encode(array(
    $wpdb->get_row($wpdb->prepare('SELECT nom,prenom,food_allergy_signal FROM '.psc_table('children').' WHERE id=%d',${b.child})),
    $wpdb->get_row($wpdb->prepare('SELECT nom,prenom,telephone,statut FROM '.psc_table('pickup_persons').' WHERE id=%d',${b.pickup})),
    $wpdb->get_col($wpdb->prepare('SELECT CONCAT(jour_date,service_code,value) FROM '.psc_table('exception').' WHERE child_id=%d',${b.child})),
    $wpdb->get_col($wpdb->prepare('SELECT CONCAT(weekday,service_code) FROM '.psc_table('pattern').' WHERE child_id=%d',${b.child})))));`);
}

async function loggedIn(context: BrowserContext, family: Family): Promise<APIRequestContext> {
  const page = await context.newPage();
  await page.goto(family.login);
  await expect(page.getByTestId('portal-root')).toBeVisible();
  await page.close();
  return context.request;
}

test.describe('Isolation entre familles', () => {
  let a: Family;
  let b: Family;
  let firstSchoolDay: string;

  test.beforeAll(() => {
    cleanup();
    a = createFamily(EMAILS.a, 'A');
    b = createFamily(EMAILS.b, 'B');
    firstSchoolDay = php(`$y=Psc_School_Year::active(); $d=Psc_School_Year::school_days($y->date_start,$y->date_end); $f=array_values(array_filter($d,function($x){return !psc_is_locked($x);})); echo $f ? $f[0] : '';`);
  });
  test.afterAll(() => cleanup());

  test('la famille A ne peut ni lire ni écrire les données de la famille B', async ({ browser }) => {
    const context = await browser.newContext();
    const req = await loggedIn(context, a);
    const before = snapshotB(b);
    const ajax = { nonce: wpNonce('psc_front'), parent_nonce: familyNonce('psc_front', a.parent) };
    const month = firstSchoolDay.slice(0, 7);

    const ajaxCalls: Record<string, Record<string, string>> = {
      psc_load_month: { child_id: String(b.child), month },
      psc_toggle_exception: { child_id: String(b.child), date: firstSchoolDay, service_code: 'GS', checked: '1' },
      psc_toggle_exception_bulk: { child_id: String(b.child), service_code: 'GS', checked: '1', dates: firstSchoolDay },
      psc_toggle_pattern: { child_id: String(b.child), weekday: '1', service_code: 'GS', checked: '1' },
      psc_reset_month_exceptions: { child_id: String(b.child), month },
      psc_apply_pattern_to_siblings: { source_child_id: String(b.child) },
    };
    for (const [action, params] of Object.entries(ajaxCalls)) {
      const res = await req.post(AJAX, { form: { action, ...ajax, ...params } });
      expect(res.status(), action).toBeGreaterThanOrEqual(400);
      expect((await res.text()).includes('"success":true'), action).toBe(false);
    }

    const forms: Record<string, Record<string, string>> = {
      psc_parent_update_child_identity: { child_id: String(b.child), prenom: 'Pirate', nom: 'Pirate', food_allergy_signal: '1' },
      psc_parent_update_pickup_person: { pickup_id: String(b.pickup), prenom: 'Pirate', nom: 'Pirate', telephone: '0611111111', lien: 'Autre' },
      psc_parent_remove_pickup_person: { pickup_id: String(b.pickup) },
    };
    for (const [action, params] of Object.entries(forms)) {
      await req.post(POST, { form: { action, _wpnonce: wpNonce(action), psc_nonce: familyNonce(action, a.parent), ...params }, maxRedirects: 0 });
    }

    for (const [action, idKey, id] of [
      ['psc_parent_download_assurance', 'child_id', b.child],
      ['psc_parent_download_invoice', 'invoice_id', b.invoice],
    ] as const) {
      const res = await req.get(`${POST}?action=${action}&${idKey}=${id}&_wpnonce=${wpNonce(`${action}_${id}`)}`, { maxRedirects: 0 });
      expect((await res.body()).subarray(0, 5).toString(), action).not.toBe('%PDF-');
    }

    expect(snapshotB(b)).toBe(before);
    await context.close();
  });

  test('un visiteur anonyme n’atteint aucune donnée de famille', async ({ playwright }) => {
    const req = await playwright.request.newContext();
    const res = await req.post(AJAX, { form: { action: 'psc_load_month', nonce: wpNonce('psc_front'), parent_nonce: familyNonce('psc_front', a.parent), child_id: String(a.child), month: firstSchoolDay.slice(0, 7) } });
    expect(res.status()).toBeGreaterThanOrEqual(400);
    const doc = await req.get(`${POST}?action=psc_parent_download_assurance&child_id=${a.child}&_wpnonce=${wpNonce(`psc_parent_download_assurance_${a.child}`)}`, { maxRedirects: 0 });
    expect((await doc.body()).subarray(0, 5).toString()).not.toBe('%PDF-');
    await req.dispose();
  });

  test('un jeton absent, étranger ou périmé est refusé même sur ses propres données', async ({ browser }) => {
    const context = await browser.newContext();
    const req = await loggedIn(context, a);
    const base = { action: 'psc_load_month', child_id: String(a.child), month: firstSchoolDay.slice(0, 7) };
    const variants: Record<string, Record<string, string>> = {
      'jeton famille absent': { nonce: wpNonce('psc_front') },
      'jeton famille de B': { nonce: wpNonce('psc_front'), parent_nonce: familyNonce('psc_front', b.parent) },
      'jeton famille périmé': { nonce: wpNonce('psc_front'), parent_nonce: familyNonce('psc_front', a.parent, 2) },
      'nonce WordPress absent': { parent_nonce: familyNonce('psc_front', a.parent) },
    };
    for (const [label, nonces] of Object.entries(variants)) {
      const res = await req.post(AJAX, { form: { ...base, ...nonces } });
      expect(res.status(), label).toBeGreaterThanOrEqual(400);
    }
    // Contrôle positif : avec les bons jetons, la même requête passe.
    const ok = await req.post(AJAX, { form: { ...base, nonce: wpNonce('psc_front'), parent_nonce: familyNonce('psc_front', a.parent) } });
    expect(ok.status()).toBe(200);

    // Formulaire : jeton famille de B sur son propre enfant → rien ne change.
    const nameBefore = php(`global $wpdb; echo $wpdb->get_var($wpdb->prepare('SELECT prenom FROM '.psc_table('children').' WHERE id=%d', ${a.child}));`);
    await req.post(POST, { form: { action: 'psc_parent_update_child_identity', _wpnonce: wpNonce('psc_parent_update_child_identity'), psc_nonce: familyNonce('psc_parent_update_child_identity', b.parent), child_id: String(a.child), prenom: 'Pirate', nom: 'Pirate' }, maxRedirects: 0 });
    expect(php(`global $wpdb; echo $wpdb->get_var($wpdb->prepare('SELECT prenom FROM '.psc_table('children').' WHERE id=%d', ${a.child}));`)).toBe(nameBefore);
    await context.close();
  });
});
