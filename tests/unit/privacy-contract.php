<?php
/** Contrats purs de confidentialité et de collecte des allergies. */
define('ABSPATH', '/wp/');

$GLOBALS['psc_test_options'] = array(
    'psc_privacy_municipality' => 'Ville test',
    'psc_privacy_dpo_email' => 'dpo@ville.test',
    'psc_privacy_rights_email' => 'droits@ville.test',
    'psc_privacy_policy_url' => 'https://ville.test/confidentialite',
);

function get_option($key, $default = false) { return $GLOBALS['psc_test_options'][$key] ?? $default; }
function apply_filters($tag, $value) { return $value; }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return esc_html($text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function esc_url_raw($url) { return filter_var($url, FILTER_SANITIZE_URL); }
function esc_attr($text) { return esc_html($text); }
function sanitize_email($email) { return filter_var((string) $email, FILTER_SANITIZE_EMAIL); }
function wp_json_encode($value) { return json_encode($value); }

require __DIR__ . '/../../includes/class-psc-privacy.php';

$failures = array();
$assert = function ($label, $actual, $expected) use (&$failures) {
    if ($actual !== $expected) $failures[] = $label . ': attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true);
};

$settings = Psc_Privacy::privacy_settings();
$assert('nom de collectivité normalisé', $settings['municipality'], 'Ville test');
$assert('contact DPO normalisé', $settings['dpo_email'], 'dpo@ville.test');
$assert('contact droits normalisé', $settings['rights_email'], 'droits@ville.test');
$assert('URL notice normalisée', $settings['policy_url'], 'https://ville.test/confidentialite');

$GLOBALS['psc_test_options']['psc_privacy_municipality'] = '<Ville>'; 
$notice = Psc_Privacy::privacy_notice_html('guest');
$assert('notice échappée', strpos($notice, '&lt;Ville&gt;') !== false, true);
$assert('notice sans case globale', strpos($notice, 'consentement global') === false, true);

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Contrats confidentialité : OK\n";
