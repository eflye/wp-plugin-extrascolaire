const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const path = require('node:path');
(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        await page.setContent('<form id="request"><div id="psc-children-list"><div class="psc-wizard-child-row" data-index="0"><div data-pickup-list></div><button type="button" class="psc-wizard-add-pickup-btn">Ajouter</button></div></div></form>');
        await page.evaluate(() => { window.PSC = { i18n: { phone_pattern_title: 'Téléphone invalide' } }; });
        await page.addScriptTag({ path: path.resolve(__dirname, '../../assets/js/guest.js') });
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        const add = page.locator('.psc-wizard-add-pickup-btn');
        await add.click();
        assert.equal(await page.locator('form').evaluate(f => f.checkValidity()), true, 'ligne vide facultative');
        await page.locator('[name="child_pickup_prenom_0_0"]').fill('Marie');
        assert.equal(await page.locator('form').evaluate(f => f.checkValidity()), false, 'ligne partielle bloquée avant envoi');
        await page.locator('[name="child_pickup_nom_0_0"]').fill('Test');
        for (const phone of ['06 12 34 56 78', '+33 6 12 34 56 78', '0033 6 12 34 56 78', '06.12.34.56.78']) {
            await page.locator('input[type="tel"]').fill(phone);
            assert.equal(await page.locator('form').evaluate(f => f.checkValidity()), true, phone);
        }
        await page.locator('input[type="tel"]').fill('123');
        assert.equal(await page.locator('form').evaluate(f => f.checkValidity()), false);
        await add.click();
        await page.locator('[name="child_pickup_prenom_0_1"]').fill('Paul');
        await page.locator('.psc-wizard-remove-pickup-btn').first().click();
        await add.click();
        const names = await page.locator('input').evaluateAll(inputs => inputs.map(i => i.name));
        assert.equal(new Set(names).size, names.length, 'indices uniques après suppression et ajout');
        assert.equal(await page.locator('[name="child_pickup_prenom_0_1"]').inputValue(), 'Paul');
        console.log('Personnes autorisées : validations et ajout/suppression vérifiés.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
