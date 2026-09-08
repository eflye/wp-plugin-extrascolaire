const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
 const browser = await chromium.launch();
 try {
  for (const scenario of ['valid', 'missing', 'empty', 'unreadable']) {
   const page = await browser.newPage();
   await page.setContent(`<div id="psc-wizard" data-default-step="3"><form><div class="psc-wizard-step"></div><div class="psc-wizard-step"><input type="file" required name="child_assurance_0"></div><div class="psc-wizard-step"></div><div class="psc-wizard-step"><input name="nom" value="Famille Test"></div><button type="button" id="psc-wizard-prev">Précédent</button><button type="button" id="psc-wizard-next">Suivant</button><button id="psc-wizard-submit">Envoyer</button></form></div>`);
   await page.evaluate(() => { window.PSC = { i18n: { insurance_unreadable: 'Fichier inaccessible' } }; window.submitted = 0; });
   await page.addScriptTag({ path: path.resolve(__dirname, '../../assets/js/guest.js') });
   await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded'));
    document.querySelector('form').addEventListener('submit', e => { if (!e.defaultPrevented) window.submitted++; e.preventDefault(); });
   });
   if (scenario !== 'missing') await page.locator('input[type=file]').setInputFiles({ name: 'assurance.pdf', mimeType: 'application/pdf', buffer: scenario === 'empty' ? Buffer.alloc(0) : Buffer.from('%PDF-1.4\n' + ' '.repeat(18000)) });
   if (scenario === 'unreadable') await page.evaluate(() => { window.FileReader = class { readAsArrayBuffer() { this.onerror(); } }; });
   await page.locator('#psc-wizard-submit').click();
   if (scenario === 'valid') {
    await page.waitForFunction(() => window.submitted === 1);
   } else {
    await page.waitForFunction(() => document.querySelectorAll('.psc-wizard-step')[1].classList.contains('is-active'));
    assert.equal(await page.evaluate(() => window.submitted), 0);
    assert.equal(await page.locator('[name=nom]').inputValue(), 'Famille Test');
   }
   await page.close();
  }
  console.log('OK : PDF 18 ko, fichier absent/vide/illisible, retour Enfants et saisie conservée.');
 } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
