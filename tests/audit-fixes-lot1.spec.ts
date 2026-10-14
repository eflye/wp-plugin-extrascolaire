/**
 * Corrections d'audit — lot 1 (accès, sessions, demandes, intervenants).
 * Les assertions de logique pure vivent dans bin/verify-audit-lot1.php ;
 * ici, ce qui demande une vraie requête HTTP ou un écran, avec les
 * contrôles de droits.
 */

import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim().split('\n').pop() ?? '';
}

test.describe('Audit — lot 1', () => {
  test.describe('SEC-02 : seul le titulaire change l\'adresse de connexion', () => {
    const TITULAIRE = 'e2e-lot1-titulaire@example.test';
    const SECOND = 'e2e-lot1-second@example.test';
    const NOUVELLE = 'e2e-lot1-nouvelle@example.test';
    let familyId = 0;

    const cleanup = () => wpCliEval(`global $wpdb; $t = psc_table('parents');
      foreach (array('${TITULAIRE}', '${SECOND}', '${NOUVELLE}') as $e) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE email = %s OR second_parent_email = %s OR pending_email = %s", $e, $e, $e)) as $id) $wpdb->delete($t, array('id' => (int) $id));
      } echo 'ok';`);

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
      familyId = Number(wpCliEval(`$id = Psc_Parents::create('${TITULAIRE}', 'Lot1', array('second_parent_email' => '${SECOND}', 'second_parent_prenom' => 'Second', 'onboarding_seen_at' => current_time('mysql'))); echo is_wp_error($id) ? 0 : (int) $id;`));
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
});
