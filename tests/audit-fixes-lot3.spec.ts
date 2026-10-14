/**
 * Corrections d'audit — lot 3 (envois, calendrier, facturation,
 * performance, administration). Les assertions de logique pure vivent
 * dans bin/verify-audit-lot3.php ; ici, ce qui demande une vraie requête
 * HTTP ou un écran.
 */

import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFormPageUrl } from '../playwright/seed-result';

const ENGINE = process.env.PSC_CONTAINER_ENGINE ?? 'podman';
const CONTAINER = process.env.PSC_WP_CONTAINER ?? 'plugin-extrascolaire-wordpress-1';
const CONTAINER_WP_CLI = '/usr/local/bin/wp-cli.phar';

function wpCliEval(php: string): string {
  return execFileSync(
    ENGINE,
    ['exec', CONTAINER, 'php', CONTAINER_WP_CLI, 'eval', php, '--path=/var/www/html', '--allow-root'],
    { encoding: 'utf8' }
  ).trim();
}

test.describe('Audit — lot 3', () => {
  test('PRA-09 : le portail part avec no-store/private, émis avant le rendu', async ({ request }) => {
    expect(wpCliEval(
      "echo has_action('template_redirect', array('Psc_Frontend', 'portal_private_headers')) !== false ? 'ok' : 'absent';"
    )).toBe('ok');
    const resp = await request.get(readFormPageUrl());
    const cc = resp.headers()['cache-control'] ?? '';
    expect(cc).toContain('no-store');
    expect(cc).toContain('private');
  });
});
