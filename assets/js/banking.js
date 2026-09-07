(function () {
    'use strict';

    // Même normalisation et mêmes contrôles que helpers/banking.php.
    function validIban(value) {
        var iban = value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban) || iban.length < 15 || iban.length > 34) return false;
        var numeric = (iban.slice(4) + iban.slice(0, 4)).replace(/[A-Z]/g, function (letter) {
            return String(letter.charCodeAt(0) - 55);
        });
        var remainder = 0;
        for (var i = 0; i < numeric.length; i++) {
            remainder = (remainder * 10 + Number(numeric[i])) % 97;
        }
        return remainder === 1;
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
