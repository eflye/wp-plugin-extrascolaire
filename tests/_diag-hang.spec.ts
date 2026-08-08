/**
 * Diagnostic temporaire — reproduction du hang au clic sur
 * login-submit-button (étape 03 de journeys/parent-connu.md). Pas un
 * livrable : instrumentation seule, aucune correction. À supprimer après
 * usage.
 *
 * Couvre uniquement 01→02→03 (jusqu'au hang observé), pas le reste du
 * parcours : pas besoin de Mailpit ici, et ça évite que le rate-limit sur
 * 'mail_<email>' (3/15min, toujours actif — WP_ENVIRONMENT_TYPE n'est pas
 * configuré sur ce site) ne vienne polluer le diagnostic sur des runs
 * répétés.
 */

import { test } from '@playwright/test';
import { readSeedResult } from '../playwright/seed-result';

const APP_PAGE = 'http://localhost:8080/?page_id=6';

test('diag: instrumentation du clic login-submit-button', async ({ page }, testInfo) => {
  const profile = testInfo.project.name === 'demo' ? 'demo' : 'test';
  const seed = readSeedResult(profile);
  const t0 = Date.now();
  const rel = () => Date.now() - t0;

  const pending = new Map<object, { url: string; method: string; resourceType: string; start: number }>();

  page.on('request', (req) => {
    pending.set(req, { url: req.url(), method: req.method(), resourceType: req.resourceType(), start: Date.now() });
    console.log(`[REQ  +${rel()}ms] ${req.method()} ${req.resourceType()} ${req.url()}`);
  });

  page.on('requestfinished', (req) => {
    const info = pending.get(req);
    pending.delete(req);
    req
      .response()
      .then((res) => {
        console.log(
          `[FIN  +${rel()}ms] status=${res?.status() ?? '?'} ${req.method()} ${req.url()} (${info ? Date.now() - info.start : '?'}ms)`
        );
      })
      .catch(() => {
        console.log(`[FIN  +${rel()}ms] status=? ${req.method()} ${req.url()} (${info ? Date.now() - info.start : '?'}ms)`);
      });
  });

  page.on('requestfailed', (req) => {
    const info = pending.get(req);
    pending.delete(req);
    console.log(
      `[FAIL +${rel()}ms] errorText=${req.failure()?.errorText ?? '?'} ${req.method()} ${req.url()} (${info ? Date.now() - info.start : '?'}ms)`
    );
  });

  function dumpPending(label: string) {
    console.log(`[PENDING @ ${label} +${rel()}ms] ${pending.size} requête(s) encore ouverte(s) :`);
    for (const info of pending.values()) {
      console.log(`    ${info.method} ${info.resourceType} ${info.url}  (ouverte depuis ${Date.now() - info.start}ms)`);
    }
  }

  async function timed<T>(label: string, fn: () => Promise<T>): Promise<T> {
    const start = Date.now();
    console.log(`[ACTION START +${rel()}ms] ${label}`);
    try {
      const result = await fn();
      console.log(`[ACTION END   +${rel()}ms] ${label} (${Date.now() - start}ms)`);
      return result;
    } catch (e) {
      console.log(`[ACTION ERROR +${rel()}ms] ${label} (${Date.now() - start}ms) : ${(e as Error).message}`);
      dumpPending(`erreur sur "${label}"`);
      throw e;
    }
  }

  await timed('goto login page', () => page.goto(APP_PAGE));
  await timed('fill email', () => page.getByTestId('login-email-input').fill(seed.parent_email));
  await timed('click login-submit-button', () => page.getByTestId('login-submit-button').click());
  await timed('waitForURL psc_msg=link_sent', () => page.waitForURL(/psc_msg=link_sent/));

  dumpPending('fin de run (devrait être vide)');
});
