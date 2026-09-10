/**
 * Messages aux familles — contrats critiques de diffusion et de traçabilité.
 *
 * L'interface est couverte par Playwright, l'état réel par WP-CLI. Ces tests
 * ciblent surtout les garanties qui ne doivent jamais régresser : ciblage
 * figé, envoi idempotent, HTML filtré et première lecture immuable.
 */
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const WP = '/usr/local/bin/wp-cli.phar';
const EMAIL = 'messages.e2e@example.test';

function wpEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', WP, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

function cleanup(): void {
  wpEval(`global $wpdb;
    $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_parents WHERE email=%s",'${EMAIL}'));
    if($pid){
      $mids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT message_id FROM {$wpdb->prefix}psc_message_destinataires WHERE family_id=%d",$pid));
      foreach($mids as $mid){Psc_Messages::delete((int)$mid);}
      foreach($wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}psc_children WHERE parent_id=%d",$pid)) as $cid){
        $wpdb->delete($wpdb->prefix.'psc_child_school_years',array('child_id'=>(int)$cid));
        $wpdb->delete($wpdb->prefix.'psc_children',array('id'=>(int)$cid));
      }
      $wpdb->delete($wpdb->prefix.'psc_parents',array('id'=>$pid));
    } echo 'ok';`);
}

function seedFamily(): number {
  return Number(wpEval(`global $wpdb;
    $wpdb->insert($wpdb->prefix.'psc_parents',array('email'=>'${EMAIL}','nom'=>'MessagesE2E','prenom'=>'Famille','active'=>1,'created_at'=>current_time('mysql')));
    $pid=(int)$wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'psc_children',array('parent_id'=>$pid,'nom'=>'MessagesE2E','prenom'=>'Lou','statut'=>'actif','created_at'=>current_time('mysql')));
    Psc_School_Years::enroll((int)$wpdb->insert_id,Psc_School_Years::active_id(),'CE2','inscrit',current_time('mysql'));
    echo $pid;`));
}

function createAndSend(familyId: number, category = 'urgent'): { id: number; token: string } {
  return JSON.parse(wpEval(`$id=Psc_Messages::save(array(
      'titre'=>'Alerte E2E','corps'=>'<p>Texte sûr</p><script>alert(1)</script>',
      'categorie'=>'${category}','statut'=>'brouillon','cible_type'=>'familles',
      'cible_valeur'=>array('family_ids'=>array(${familyId})),
      'canaux'=>array('portail'=>true,'email'=>true,'push'=>false),'auteur_id'=>1));
    Psc_Messages::send($id); global $wpdb;
    $token=$wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=%d AND family_id=%d",$id,${familyId}));
    echo wp_json_encode(array('id'=>(int)$id,'token'=>$token));`));
}

test.describe('Messages aux familles', () => {
  test.beforeEach(() => cleanup());
  test.afterEach(() => cleanup());

  test('fige le ciblage, filtre le HTML et bloque un second envoi', () => {
    const familyId = seedFamily();
    const message = createAndSend(familyId);
    const state = JSON.parse(wpEval(`global $wpdb; $m=Psc_Messages::get(${message.id});
      $again=Psc_Messages::send(${message.id});
      echo wp_json_encode(array(
        'status'=>$m->statut,'body'=>$m->corps,
        'recipients'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}"),
        'duplicate'=>is_wp_error($again)
      ));`));
    expect(state.status).toBe('envoye');
    expect(state.body).toContain('<p>Texte sûr</p>');
    expect(state.body).not.toContain('<script');
    expect(state.recipients).toBe(1);
    expect(state.duplicate).toBe(true);
  });

  test('un lien e-mail valide affiche uniquement le message et inscrit la première lecture', async ({ page }) => {
    const familyId = seedFamily();
    const message = createAndSend(familyId);
    await page.goto(`${APP_BASE}/?psc_msg=${message.id}&t=${message.token}`);
    await expect(page.getByRole('heading', { name: 'Alerte E2E' })).toBeVisible();
    await expect(page.getByText('Texte sûr')).toBeVisible();
    await expect(page.locator('.psc-portal-sidebar')).toHaveCount(0);

    const first = wpEval(`global $wpdb; echo $wpdb->get_var("SELECT vu_le FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}");`);
    expect(first).not.toBe('');
    await page.reload();
    const second = wpEval(`global $wpdb; echo $wpdb->get_var("SELECT vu_le FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id}");`);
    expect(second).toBe(first);
  });

  test('un jeton invalide ne modifie aucune preuve de lecture', async ({ page }) => {
    const familyId = seedFamily();
    const message = createAndSend(familyId, 'information');
    await page.goto(`${APP_BASE}/?psc_msg=${message.id}&t=jeton-invalide`);
    const seen = wpEval(`global $wpdb; echo (string)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}psc_message_destinataires WHERE message_id=${message.id} AND vu_le IS NOT NULL");`);
    expect(seen).toBe('0');
  });

  test('ne relance pas par e-mail un message diffusé uniquement au portail', () => {
    const familyId = seedFamily();
    const count = wpEval(`$id=Psc_Messages::save(array('titre'=>'Portail seul','corps'=>'<p>Info</p>','categorie'=>'information','statut'=>'brouillon','cible_type'=>'familles','cible_valeur'=>array('family_ids'=>array(${familyId})),'canaux'=>array('portail'=>true,'email'=>false,'push'=>false),'auteur_id'=>1)); Psc_Messages::send($id); echo Psc_Messages::resend_unread($id);`);
    expect(count).toBe('0');
  });
});
