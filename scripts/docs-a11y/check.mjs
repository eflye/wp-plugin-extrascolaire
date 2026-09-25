import { spawn } from 'node:child_process';
import process from 'node:process';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const baseUrl = 'http://127.0.0.1:8000';
const pages = [
  '', 'demarrage-rapide',
  'configuration/annee-scolaire', 'configuration/calendrier-vacances', 'configuration/services-tarifs',
  'configuration/regimes-alimentaires', 'configuration/modeles-emails',
  'gestion-quotidienne/tableau-de-bord', 'gestion-quotidienne/moderation-inscriptions',
  'gestion-quotidienne/annulations-absences', 'gestion-quotidienne/menus-cantine',
  'gestion-quotidienne/documents-assurance', 'gestion-quotidienne/personnes-autorisees',
  'facturation/factures-pdf', 'facturation/mandats-sepa',
  'cycle-annuel/reinscription', 'cycle-annuel/passage-de-classe', 'rgpd',
  'installation/prerequis', 'installation/installation-activation', 'installation/deploiement-zip', 'installation/notes-mise-a-jour', 'installation/cle-chiffrement', 'installation/emails-smtp',
  'installation/taches-planifiees', 'installation/sauvegarde-mise-a-jour', 'installation/depannage-faq',
  'glossaire', 'contribuer',
];

async function waitForServer() {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await fetch(`${baseUrl}/`);
      if (response.ok) return;
    } catch {}
    await delay(100);
  }
  throw new Error('Le serveur HTTP de prévisualisation ne répond pas.');
}

const server = spawn('python3', ['-m', 'http.server', '8000', '--directory', 'site'], {
  stdio: ['ignore', 'ignore', 'inherit'],
});

let browser;
let exitCode = 0;
try {
  await waitForServer();
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  for (const path of pages) {
    const url = `${baseUrl}/${path ? `${path}/` : ''}`;
    await page.goto(url, { waitUntil: 'networkidle' });
    const results = await new AxeBuilder({ page }).include('.md-content').analyze();
    if (results.violations.length === 0) {
      console.log(`OK ${path || '/'}`);
      continue;
    }

    console.log(`\n${path || '/'} — ${results.violations.length} violation(s)`);
    for (const violation of results.violations) {
      console.log(`- [${violation.impact || 'unknown'}] ${violation.id}: ${violation.help}`);
      for (const node of violation.nodes) console.log(`  ${node.html}`);
      if (violation.impact === 'serious' || violation.impact === 'critical') exitCode = 1;
    }
  }
} finally {
  if (browser) await browser.close();
  server.kill('SIGTERM');
}

process.exitCode = exitCode;
