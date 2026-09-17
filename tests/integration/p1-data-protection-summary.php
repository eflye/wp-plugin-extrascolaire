<?php
/** Sonde non destructive des contrats techniques P1-06 à P1-11. */
if (!defined('ABSPATH')) exit('WordPress requis\n');

$failures = array();
$check = function ($label, $ok) use (&$failures) {
    if (!$ok) $failures[] = $label;
};

$settings = Psc_Privacy::privacy_settings();
$check('réglages de confidentialité normalisés', is_array($settings) && isset($settings['municipality'], $settings['rights_email'], $settings['policy_url']));
$check('notice sans case de consentement globale', strpos(Psc_Privacy::privacy_notice_html('probe'), 'consentement global') === false);

$report = Psc_Retention::run('simulation', time());
$check('rapport de rétention structuré', is_array($report) && isset($report['categories'], $report['examined'], $report['removed'], $report['retained'], $report['errors']));
$check('simulation sans suppression', (int) $report['removed'] === 0);

$check('action export RGPD classée', psc_audit_categorie_for_action('privacy.export') === 'donnees_famille');
$check('action effacement RGPD classée', psc_audit_niveau_for_action('privacy.effacement') === 'critique');

if ($failures) {
    foreach ($failures as $failure) echo "FAIL : $failure\n";
    exit(1);
}
echo "P1 protection des données : OK (sonde non destructive)\n";
