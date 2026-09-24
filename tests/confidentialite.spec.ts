/**
 * Rubrique Confidentialité des Réglages : la notice montrée aux familles
 * se paramètre depuis le back office, avec un aperçu fidèle.
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const APP_BASE = 'http://localhost:8080';
const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const OPTIONS = ['psc_privacy_municipality', 'psc_privacy_dpo_email', 'psc_privacy_rights_email', 'psc_privacy_policy_url'];

function php(code: string): string {
  return execFileSync(ENGINE, ['exec', CONTAINER, 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', '--path=/var/www/html', 'eval', code], { encoding: 'utf8' })
    .trim().split('\n').pop() ?? '';
}

async function loginAsAdmin(page: Page) {
  await page.goto(`${APP_BASE}/wp-login.php`);
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.waitForURL('**/wp-admin/**');
}

test('Réglages › Confidentialité : notice paramétrée, aperçu et affichage aux familles', async ({ page, browser }) => {
  const phpList = `json_decode(base64_decode('${Buffer.from(JSON.stringify(OPTIONS)).toString('base64')}'), true)`;
  const saved = php(`echo base64_encode(wp_json_encode(array_map(function($o){ return get_option($o, ''); }, ${phpList})));`);
  try {
    php(`foreach (${phpList} as $o) update_option($o, ''); echo 'ok';`);
    await loginAsAdmin(page);
    await page.goto(`${APP_BASE}/wp-admin/admin.php?page=psc_settings`);

    // Tant que le responsable n'est pas renseigné : alerte et « à adapter » dans l'aperçu.
    await expect(page.getByTestId('notice-privacy-incomplete')).toBeVisible();
    await expect(page.getByTestId('privacy-preview')).toContainText('Collectivité (à adapter)');

    await page.locator('#psc-privacy-municipality').fill('Commune de Test-sur-Oise');
    await page.locator('#psc-privacy-dpo-email').fill('dpo.e2e@example.test');
    await page.locator('#psc-privacy-rights-email').fill('droits.e2e@example.test');
    await page.locator('#psc-privacy-policy-url').fill('https://example.test/confidentialite');
    await page.getByRole('button', { name: 'Enregistrer' }).last().click();

    const preview = page.getByTestId('privacy-preview');
    await expect(preview).toContainText('Les données sont traitées par Commune de Test-sur-Oise');
    await expect(preview).toContainText('droits.e2e@example.test');
    await expect(preview.getByRole('link', { name: 'Lire la notice de confidentialité' })).toHaveAttribute('href', 'https://example.test/confidentialite');
    await expect(page.getByTestId('notice-privacy-incomplete')).toHaveCount(0);

    const axe = await new AxeBuilder({ page }).include('#wpbody-content .wrap').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((v) => `${v.id} : ${v.nodes.length}`)).toEqual([]);

    // Une adresse invalide est refusée, l'ancienne conservée.
    await page.locator('#psc-privacy-rights-email').fill('pas-une-adresse');
    await page.locator('#psc-privacy-rights-email').evaluate((el) => el.setAttribute('type', 'text')); // contourne la validation du navigateur
    await page.getByRole('button', { name: 'Enregistrer' }).last().click();
    await expect(page.getByText(/sauf un champ de la rubrique Confidentialité/)).toBeVisible();
    expect(php(`echo get_option('psc_privacy_rights_email');`)).toBe('droits.e2e@example.test');

    // Les familles voient la notice paramétrée au formulaire d'inscription.
    const guest = await browser.newContext();
    const g = await guest.newPage();
    await g.goto(readFormPageUrl());
    await expect(g.locator('.psc-privacy-notice')).toContainText('Commune de Test-sur-Oise');
    await expect(g.getByRole('link', { name: 'Lire la notice de confidentialité' })).toBeVisible();
    await guest.close();
  } finally {
    // Valeurs d'origine rétablies telles quelles (base64 : aucun échappement à gérer).
    php(`foreach (array_combine(${phpList}, json_decode(base64_decode('${saved}'), true)) as $o => $v) update_option($o, $v); echo 'ok';`);
  }
});
