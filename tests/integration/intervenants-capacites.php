<?php
if (!defined('ABSPATH')) exit('WordPress requis\n');
$admin = get_role('administrator');
$editor = get_role('editor');
$required = array('psc_manage_families', 'psc_manage_presence', 'psc_manage_billing', 'psc_manage_messages', 'psc_manage_config', 'psc_view_health', 'psc_view_audit');
$failures = array();
foreach ($required as $cap) if (!$admin || !$admin->has_cap($cap)) $failures[] = 'administrateur: ' . $cap;
if ($editor && $editor->has_cap('psc_manage_periscolaire')) $failures[] = 'éditeur conserve la capacité globale';
if ($failures) { echo implode("\n", $failures) . "\n"; exit(1); }
echo "Capacités composables : OK\n";
