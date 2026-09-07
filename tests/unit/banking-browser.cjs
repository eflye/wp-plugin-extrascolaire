// Tests des vrais champs dans Chromium, sans serveur WordPress ni coordonnées réelles.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const cases = require('./iban-cases.json');
const rules = {
    patterns: require('../../includes/data/iban-patterns.json'),
    uk: require('../../includes/data/uk-modulus.json')
};
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        for (const file of ['guest-request.php', 'portal-profil.php', 'admin-parents.php', 'admin-settings.php']) {
            const template = fs.readFileSync(path.join(root, 'templates', file), 'utf8');
            const input = template.replace(/<\?php[\s\S]*?\?>/g, 'Erreur IBAN').match(/<input[^>]+name="(?:sepa|org)_iban"[^>]*>/)[0];
            assert.ok(input.includes('data-bank-validation="iban"'), file);
            await page.setContent('<form>' + input + '<button>Envoyer</button></form>');
            await page.evaluate(data => { window.PSC_BANK = data; }, rules);
            await page.addScriptTag({ path: path.join(root, 'assets/js/banking.js') });
            const failures = await page.evaluate(cases => {
                const input = document.querySelector('input');
                input.required = true;
                return cases.filter(c => {
                    // type=text retire lui-même CR/LF : tester aussi les caractères
                    // de contrôle au niveau PHP, où ils arrivent via POST direct.
                    if (/[\r\n]/.test(c.value)) return false;
                    input.value = c.value;
                    input.dispatchEvent(new Event('input'));
                    return input.checkValidity() !== c.valid;
                }).map(c => c.label);
            }, cases);
            assert.deepEqual(failures, [], file);
            await page.evaluate(() => {
                window.submissions = 0;
                document.querySelector('form').addEventListener('submit', e => { e.preventDefault(); window.submissions++; });
            });
            const field = page.locator('input');
            await field.fill('FR7630006000011234567890188');
            await page.locator('button').click();
            assert.equal(await page.evaluate(() => window.submissions), 0, file + ' bloque le formulaire invalide');
            await field.fill('FR76 3000 6000 0112 3456 7890 189');
            await page.locator('button').click();
            assert.equal(await page.evaluate(() => window.submissions), 1, file + ' accepte la correction');
            await field.fill('invalide');
            await field.evaluate(el => { el.disabled = true; });
            assert.equal(await field.evaluate(el => el.checkValidity()), true, 'prélèvement désactivé');
            await field.evaluate(el => { el.disabled = false; });
            assert.equal(await field.evaluate(el => el.checkValidity()), false, 'prélèvement réactivé');
            console.log(file + ' : ' + cases.filter(c => !/[\r\n]/.test(c.value)).length + ' cas IBAN, blocage, correction et désactivation vérifiés.');
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
