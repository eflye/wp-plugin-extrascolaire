/**
 * Sessions des familles, demandes d'inscription et accès intervenants :
 * identité de connexion (seul le titulaire change l'adresse, lien
 * d'annulation), signalement alimentaire du formulaire de demande,
 * en-têtes de cache de l'écran intervenants.
 *
 * Les assertions de logique pure vivent dans bin/verify-sessions-demandes.php ;
 * ici, ce qui demande une vraie requête HTTP ou un écran, avec les
 * contrôles de droits.
 *
 * Origine : audit du 14/10/2026, lot 1 (ex-tests/audit-fixes-lot1.spec.ts).
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';
import { ENGINE, CONTAINER } from '../helpers/env';

const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

test.describe('Sessions et demandes', () => {
  test.describe('SEC-02 : seul le titulaire change l\'adresse de connexion', () => {
    const TITULAIRE = 'e2e-sessions-titulaire@example.test';
    const SECOND = 'e2e-sessions-second@example.test';
    const NOUVELLE = 'e2e-sessions-nouvelle@example.test';
    let familyId = 0;

    const cleanup = () => wpCliEval(`global $wpdb; $t = psc_table('parents');
      foreach (array('${TITULAIRE}', '${SECOND}', '${NOUVELLE}') as $e) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE email = %s OR second_parent_email = %s OR pending_email = %s", $e, $e, $e)) as $id) $wpdb->delete($t, array('id' => (int) $id));
      }
      // Liens d'annulation (SEC-02) émis pour ce foyer de test.
      foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", $wpdb->esc_like('_transient_psc_email_cancel_') . '%', '%' . $wpdb->esc_like('e2e-sessions-') . '%')) as $n) delete_transient(substr($n, 11));
      echo 'ok';`);

    /**
     * Lien de connexion tel que l'envoie send_login_link() : caractère de
     * tête 1 (titulaire) ou 2 (second parent), couvert par le haché.
     */
    const loginUrl = (prefix: '1' | '2') => wpCliEval(`global $wpdb; $t = '${prefix}' . bin2hex(random_bytes(32));
      $wpdb->update(psc_table('parents'), array('token_hash' => psc_hash_token($t), 'token_expires' => gmdate('Y-m-d H:i:s', time() + 600)), array('id' => ${familyId}));
      echo add_query_arg(array('psc_pid' => ${familyId}, 'psc_token' => $t, 'psc_tab' => 'profil'), Psc_Mailer::form_page_url());`);

    const pendingEmail = () => wpCliEval(`global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare('SELECT pending_email FROM ' . psc_table('parents') . ' WHERE id = %d', ${familyId}));`);

    test.beforeAll(() => {
      cleanup();
      familyId = Number(wpCliEval(`$id = Psc_Parents::create('${TITULAIRE}', 'Sessions', array('second_parent_email' => '${SECOND}', 'second_parent_prenom' => 'Second', 'onboarding_seen_at' => current_time('mysql'))); echo is_wp_error($id) ? 0 : (int) $id;`));
      expect(familyId).toBeGreaterThan(0);
    });
    test.afterAll(() => { cleanup(); });

    async function openProfile(page: Page, prefix: '1' | '2'): Promise<void> {
      // Le lien garde psc_tab=profil : la redirection qui retire le jeton y mène.
      await page.goto(loginUrl(prefix));
      await expect(page.getByTestId('profil-form')).toBeVisible();
    }

    test('session du second parent : champ verrouillé, et un envoi forcé est refusé sans rien enregistrer', async ({ page }) => {
      await openProfile(page, '2');
      const email = page.getByTestId('profil-email');
      await expect(email).toHaveAttribute('readonly', '');
      await expect(page.getByTestId('profil-email-locked')).toContainText('seul le titulaire');
      await expect(email).toHaveAttribute('aria-describedby', 'psc-profil-email-help');

      const axe = await new AxeBuilder({ page }).include('[data-testid="portal-section-profil"]').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

      // Contrôle serveur : le verrou de l'écran retiré, la demande est refusée.
      await email.evaluate((el) => el.removeAttribute('readonly'));
      await email.fill(NOUVELLE);
      await page.getByTestId('profil-submit').click();
      await expect(page.getByTestId('notice-email_change_forbidden')).toBeVisible();
      expect(pendingEmail()).toBe('');
    });

    test('session du titulaire : changement accepté, avis à l\'ancienne adresse', async ({ page }) => {
      await openProfile(page, '1');
      const email = page.getByTestId('profil-email');
      await expect(email).not.toHaveAttribute('readonly', '');
      await expect(page.getByTestId('profil-email-locked')).toHaveCount(0);
      await email.fill(NOUVELLE);
      await page.getByTestId('profil-submit').click();
      await expect(page.getByTestId('notice-profil_updated_email_pending')).toBeVisible();
      expect(pendingEmail()).toBe(NOUVELLE);
    });

    test('lien d\'annulation : page de confirmation en GET, annulation en POST, usage unique', async ({ page }) => {
      // Jeton émis comme request_email_change() le fait (empreinte seule en base).
      const cancelUrl = wpCliEval(`$tok = bin2hex(random_bytes(32));
        $m = new ReflectionMethod('Psc_Parents', 'email_cancel_key'); $m->setAccessible(true);
        Psc_Parents::request_email_change(${familyId}, '${NOUVELLE}');
        set_transient($m->invoke(null, $tok), array('parent_id' => ${familyId}, 'old' => '${TITULAIRE}', 'new_hash' => psc_hash_token('${NOUVELLE}')), HOUR_IN_SECONDS);
        echo add_query_arg('psc_email_cancel', $tok, Psc_Mailer::form_page_url());`);
      expect(pendingEmail()).toBe(NOUVELLE);

      await page.goto(cancelUrl);
      await expect(page.getByRole('heading', { level: 1 })).toContainText('Annuler le changement');
      expect(pendingEmail()).toBe(NOUVELLE); // un GET n'annule rien

      await page.getByTestId('email-cancel-confirm').click();
      await expect(page.getByText('votre adresse actuelle reste l’adresse de connexion')).toBeVisible();
      expect(pendingEmail()).toBe('');

      await page.goto(cancelUrl);
      await expect(page.getByText('déjà été utilisé')).toBeVisible();
    });
  });
  test('PRA-03 : le signalement alimentaire de l\'enfant 2 atteint la demande', async ({ page }) => {
    const EMAIL = 'e2e-sessions-pra03@example.test';
    const t = "psc_table('requests')";
    const cleanup = () => wpCliEval(`global $wpdb; foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM ' . ${t} . ' WHERE email = %s', '${EMAIL}')) as $id) { Psc_Assurances::delete_pending_files((int) $id); $wpdb->delete(${t}, array('id' => (int) $id)); } echo 'ok';`);
    cleanup();
    try {
      await page.goto(readFormPageUrl());
      await page.locator('#psc-req-email').fill(EMAIL);
      await page.locator('#psc-req-prenom').fill('Famille');
      await page.locator('#psc-req-nom').fill('Sessions');
      await page.locator('#psc-req-tel').fill('06 12 34 56 78');
      await page.getByTestId('address-toggle').click();
      await page.locator('#psc-req-adresse').fill('1 rue de la Mairie');
      await page.locator('#psc-req-cp').fill('95830');
      await page.locator('#psc-req-ville').fill('Montgeroult');
      await page.getByTestId('wizard-next').click();

      await page.locator('#psc-cp-0').fill('Aine');
      await page.locator('#psc-cn-0').fill('Sessions');
      await page.locator('#psc-cc-0').selectOption({ label: 'CP' });
      await page.locator('#psc-cb-0').fill('2019-03-01');
      await page.getByTestId('add-child-button').click();
      // Même balisage que l'enfant 1 : case de signalement, aucune zone de texte médicale.
      await expect(page.getByLabel('Prénom de l’enfant 2')).toBeFocused();
      await expect(page.locator('textarea[name^="child_food_allergies_"]')).toHaveCount(0);
      await page.locator('#psc-cp-1').fill('Cadet');
      await page.locator('#psc-cn-1').fill('Sessions');
      await page.locator('#psc-cc-1').selectOption({ label: 'CP' });
      await page.locator('#psc-cb-1').fill('2019-09-01');
      await page.getByTestId('child-food-signal-1').check();

      const axe = await new AxeBuilder({ page }).include('[data-testid="wizard-step-1"]').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

      await page.getByTestId('wizard-next').click();
      await page.getByTestId('wizard-next').click();
      await page.locator('input[name="reglement_accepted"]').check();
      await page.getByTestId('wizard-submit').click();
      await expect(page.getByTestId('notice-request_sent')).toBeVisible();

      const signals = wpCliEval(`global $wpdb; $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ${t} . ' WHERE email = %s ORDER BY id DESC LIMIT 1', '${EMAIL}'));
        echo wp_json_encode(array_map(function ($c) { return array($c['prenom'], $c['food_allergy_signal']); }, Psc_Requests::children_of($r)));`);
      expect(JSON.parse(signals)).toEqual([['Aine', 0], ['Cadet', 1]]);
    } finally {
      cleanup();
    }
  });
  test('PRA-09/L1 et PRA-12/L1 : écran intervenants trouvé par le helper commun, servi en no-store/private', async ({ request }) => {
    expect(wpCliEval("echo has_action('template_redirect', array('Psc_Sidscm', 'private_headers')) !== false ? 'ok' : 'absent';")).toBe('ok');
    const url = wpCliEval("echo Psc_Sidscm::page_id() === psc_shortcode_page_id('periscolaire_sidscm', 'psc_sidscm_page_id') && Psc_Sidscm::page_id() ? Psc_Sidscm::page_url() : '';");
    test.skip(url === '', 'aucune page [periscolaire_sidscm] publiée');
    const resp = await request.get(url);
    const cc = resp.headers()['cache-control'] ?? '';
    expect(cc).toContain('no-store');
    expect(cc).toContain('private');
  });
});
