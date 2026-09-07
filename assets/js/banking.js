(function () {
    'use strict';

    // Même normalisation et mêmes contrôles que helpers/banking.php.
    function validIban(value) {
        if (typeof value !== 'string' || value.length > 128) return false;
        var iban = value.replace(/[ \u00a0\u202f]/g, '');
        if (/[^A-Za-z0-9]/.test(iban)) return false;
        iban = iban.toUpperCase();
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) return false;
        var key = Number(iban.slice(2, 4));
        var country = iban.slice(0, 2);
        var rules = window.PSC_BANK;
        if (key < 2 || key > 98 || !rules || !rules.patterns[country]) return false;
        if (!(new RegExp('^' + country + rules.patterns[country] + '$')).test(iban)) return false;
        var numeric = (iban.slice(4) + iban.slice(0, 4)).replace(/[A-Z]/g, function (letter) {
            return String(letter.charCodeAt(0) - 55);
        });
        var remainder = 0;
        for (var i = 0; i < numeric.length; i++) {
            remainder = (remainder * 10 + Number(numeric[i])) % 97;
        }
        if (remainder !== 1) return false;
        if (country === 'FR' && !validFrenchRib(iban)) return false;
        if (country === 'GB' && !validUkAccount(iban.slice(8, 14), iban.slice(14), rules.uk)) return false;
        return true;
    }

    function validFrenchRib(iban) {
        var account = iban.slice(14, 25).replace(/[A-Z]/g, function (letter) {
            return '12345678912345678923456789'.charAt(letter.charCodeAt(0) - 65);
        });
        var remainder = 0;
        for (var i = 0; i < account.length; i++) remainder = (remainder * 10 + Number(account[i])) % 97;
        return Number(iban.slice(25)) === 97 - ((89 * Number(iban.slice(4, 9)) + 15 * Number(iban.slice(9, 14)) + 3 * remainder) % 97);
    }

    // VocaLink V900, adapté de cs278/bank-modulus (MIT, includes/data).
    function validUkAccount(sort, account, data) {
        var checks = data.rows.filter(function (row) { return Number(sort) >= row[0] && Number(sort) <= row[1]; });
        if (!checks.length) return true; // Aucun contrôle national applicable.
        var number = sort + account;
        if (checks.length === 2 && checks[0][4] === 2 && checks[1][4] === 9 && account[0] !== '0') {
            checks[0] = checks[0].slice();
            checks[0][3] = account[6] === '9' ? [0,0,0,0,0,0,0,0,8,7,10,9,3,1] : [0,0,1,2,5,3,6,4,8,7,10,9,3,1];
        }
        if (checks.length === 2 && checks[0][4] === 6 && checks[1][4] === 6
            && account[0] >= '4' && account[0] <= '8' && account[6] === account[7]) return true;
        var first = ukCheck(number, checks[0], 1, data.substitutes);
        if (checks.length === 1) return first || (checks[0][4] === 14 && ukCheck(number, checks[0], 2, data.substitutes));
        var second = ukCheck(number, checks[1], 2, data.substitutes);
        return ['2:9', '10:11', '12:13'].indexOf(checks[0][4] + ':' + checks[1][4]) !== -1 ? first || second : first && second;
    }

    function ukCheck(number, row, pass, substitutes) {
        var algorithm = row[2], weights = row[3].slice(), exception = row[4];
        if ((exception === 7 && number[12] === '9')
            || (exception === 10 && ['09', '99'].indexOf(number.slice(6, 8)) !== -1 && number[12] === '9')) {
            for (var j = 0; j < 8; j++) weights[j] = 0;
        }
        if (exception === 9 && pass === 2) number = '309634' + number.slice(6);
        if (exception === 8) number = '090126' + number.slice(6);
        if (exception === 5) number = (substitutes[number.slice(0, 6)] || number.slice(0, 6)) + number.slice(6);
        if (exception === 14 && pass === 2) {
            if (['0', '1', '9'].indexOf(number[13]) === -1) return false;
            number = number.slice(0, 6) + '0' + number.slice(6, 13);
        }
        if (algorithm === 'DBLAL' && exception === 3 && ['6', '9'].indexOf(number[8]) !== -1) return true;
        var total = 0;
        for (var i = 0; i < 14; i++) {
            var product = Number(number[i]) * weights[i];
            total += algorithm === 'DBLAL' ? String(product).split('').reduce(function (sum, digit) { return sum + Number(digit); }, 0) : product;
        }
        var remainder = total % (algorithm === 'MOD11' ? 11 : 10);
        if (exception === 4) return remainder === Number(number.slice(12));
        if (exception === 5) {
            var digit = Number(number[algorithm === 'DBLAL' ? 13 : 12]);
            if (remainder === 0 && digit === 0) return true;
            if (algorithm === 'MOD11' && remainder === 1) return false;
            return digit === (algorithm === 'MOD11' ? 11 : 10) - remainder;
        }
        if (exception === 1 && algorithm === 'DBLAL') return remainder === 0 || (total + 27) % 10 === 0;
        return remainder === 0;
    }

    function init() {
        document.querySelectorAll('[data-bank-validation]').forEach(function (field) {
            function validate() {
                var valid = field.dataset.bankValidation === 'iban'
                    ? validIban(field.value)
                    : /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(field.value.toUpperCase().replace(/\s+/g, ''));
                // required prend en charge le champ vide ; disabled exclut
                // les coordonnées lorsque le prélèvement est désélectionné.
                field.setCustomValidity(!field.value || valid ? '' : field.dataset.bankError);
            }
            // Revérifier aussi les valeurs remplies par script avant l’envoi.
            if (field.form) field.form.addEventListener('submit', function (event) {
                validate();
                if (!field.checkValidity()) {
                    event.preventDefault();
                    field.reportValidity();
                }
            });
            field.addEventListener('input', validate);
            field.addEventListener('change', validate);
            field.addEventListener('blur', function () {
                validate();
                if (!field.disabled && field.value) field.reportValidity();
            });
            validate();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
