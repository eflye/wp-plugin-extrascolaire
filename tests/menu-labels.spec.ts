import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

test('labels menus : aperçu local, compteur, logos et rendu partagé', async ({ page }, testInfo) => {
  const root = path.resolve(__dirname, '..');
  const config = JSON.parse(execFileSync(process.env.PSC_CONTAINER_ENGINE || 'podman', ['exec', process.env.PSC_WP_CONTAINER || 'plugin-extrascolaire-wordpress-1', 'php', '/usr/local/bin/wp-cli.phar', '--allow-root', 'eval-file', '/var/www/html/wp-content/plugins/periscolaire-registration/tests/integration/menu-label-rendering.php'], { encoding: 'utf8' }));
  const cases = JSON.parse(readFileSync(path.join(root, 'tests/unit/menu-label-cases.json'), 'utf8'));
  await page.goto('http://localhost:8080/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('admin');
  await page.locator('#wp-submit').click();
  await page.goto('http://localhost:8080/wp-admin/admin.php?page=psc_menus&semaine_debut=2026-09-14');
  const input = page.locator('[data-menu-input]').first();
  await expect(input).toBeVisible();
  await page.locator('[data-menu-input]').evaluateAll(inputs => inputs.forEach(input => { (input as HTMLTextAreaElement).value = ''; }));
  for (const item of cases) {
    await input.fill(item.raw);
    const chips = input.locator('..').locator('[data-menu-preview] .psc-menu-dish');
    await expect(chips).toHaveCount(item.expected.length);
    for (let i = 0; i < item.expected.length; i++) {
      await expect(chips.nth(i)).toHaveText(item.expected[i].name);
      await expect(chips.nth(i).locator('img')).toHaveCount(item.expected[i].label ? 1 : 0);
      if (item.expected[i].label) await expect(chips.nth(i).locator('img')).toHaveAttribute('alt', config.labels[item.expected[i].label].alt);
    }
  }
  await input.fill('Carottes râpées*\nSauté de dinde fermière**\nHaricots verts');
  await expect(page.locator('#psc-menu-summary')).toHaveText('3 plats saisis · 1 bio · 1 Label Rouge (67 % labellisés)');
  const requests: string[] = [];
  page.on('request', req => { if (req.url().includes('admin-ajax.php')) requests.push(req.url()); });
  await input.fill('Carottes râpées*\nPoulet fermier rôti**');
  expect(requests).toHaveLength(0);
  await expect(input.locator('..').locator('img').first()).toHaveJSProperty('naturalHeight', 1149);
  await page.screenshot({ path: testInfo.outputPath('menus-admin.png'), fullPage: true });
  await page.setContent(config.email);
  await expect(page.locator('img[src$="logo-ab.png"]').first()).toBeVisible();
  await expect(page.locator('img[src$="logo-ab.png"]').first()).toHaveAttribute('height', '22');
  await page.screenshot({ path: testInfo.outputPath('menu-email.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent('<main style="padding:16px;background:#fff;color:#24405C">' + config.portal + '</main>');
  await page.addStyleTag({ path: path.join(root, 'assets/css/portal.css') });
  await expect(page.locator('.psc-menu-legend img')).toHaveCount(2);
  await expect(page.locator('.psc-menu-dish img').first()).toHaveJSProperty('naturalHeight', 1149);
  await page.screenshot({ path: testInfo.outputPath('menu-familles-mobile.png'), fullPage: true });
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.psc-menu-dish img').first()).toBeVisible();
  expect(await page.locator('main').innerText()).not.toContain('*');

});
