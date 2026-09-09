(function () {
    'use strict';
    var PSC_MENU = window.PSC_MENU;
    // Expressions fournies par Psc_Menus::label_rules(), aucune règle dupliquée ici.
    function parse(raw) {
        return raw.split(/\r\n|\r|\n/).map(function (line) {
            line = line.trim();
            var label = null;
            Object.keys(PSC_MENU.rules.suffixes).some(function (key) {
                if (new RegExp(PSC_MENU.rules.suffixes[key], 'u').test(line)) { label = key; return true; }
                return false;
            });
            PSC_MENU.rules.cleanup.forEach(function (pattern) { line = line.replace(new RegExp(pattern, 'gu'), ''); });
            return { name: line.trim(), label: label };
        }).filter(function (dish) { return dish.name !== ''; });
    }
    function render() {
        var total = 0, bio = 0, rouge = 0;
        document.querySelectorAll('[data-menu-input]').forEach(function (input) {
            var preview = input.parentElement.querySelector('[data-menu-preview]');
            preview.replaceChildren();
            parse(input.value).forEach(function (dish) {
                total++;
                if (dish.label === 'bio') bio++;
                if (dish.label === 'label_rouge') rouge++;
                var chip = document.createElement('span');
                chip.className = 'psc-menu-dish';
                chip.textContent = dish.name;
                if (dish.label) {
                    var label = PSC_MENU.labels[dish.label];
                    var img = document.createElement('img');
                    img.src = label.url;
                    img.alt = img.title = label.alt;
                    img.height = 16;
                    img.style.cssText = 'height:16px;width:auto;flex:none;border:0;';
                    chip.appendChild(img);
                }
                preview.appendChild(chip);
            });
        });
        var summary = document.getElementById('psc-menu-summary');
        if (summary) summary.textContent = PSC_MENU.summary.replace('%n', total).replace('%b', bio).replace('%r', rouge).replace('%p', total ? Math.round((bio + rouge) * 100 / total) : 0);
    }
    document.querySelectorAll('[data-menu-input]').forEach(function (input) { input.addEventListener('input', render); });
    render();
})();
