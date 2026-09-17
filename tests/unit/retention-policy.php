<?php
define('ABSPATH', '/wp/');
$GLOBALS['psc_test_options'] = array();
function get_option($key, $default = false) { return $GLOBALS['psc_test_options'][$key] ?? $default; }
function apply_filters($tag, $value) { return $value; }
function __($text, $domain = null) { return $text; }
require __DIR__ . '/../../includes/helpers/retention.php';

$policies = psc_retention_policies();
if (count($policies) !== 10) exit("Nombre de catégories inattendu\n");
if (empty($policies['departed_children']['enabled']) || !$policies['departed_children']['validated']) exit("La politique enfants sortis doit rester active et validée\n");
foreach ($policies as $key => $policy) {
    if ($key !== 'departed_children' && $policy['enabled']) exit("Catégorie non validée activée: $key\n");
}
echo "Politique de rétention : OK\n";
