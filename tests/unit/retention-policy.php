<?php
/**
 * Registre des durées de conservation (RGPD-14) : il décrit les purges
 * réelles. Chaque catégorie active porte une durée > 0 et une tâche
 * planifiée récurrente connue ; une catégorie inactive dit pourquoi.
 */
define('ABSPATH', '/wp/');
$GLOBALS['psc_test_options'] = array();
function get_option($key, $default = false) { return $GLOBALS['psc_test_options'][$key] ?? $default; }
function apply_filters($tag, $value) { return $value; }
function __($text, $domain = null) { return $text; }
require __DIR__ . '/../../includes/helpers/retention.php';

$recurring = array(
    'psc_purge_departed_children', 'psc_purge_audit_log', 'psc_purge_conversations', 'psc_cleanup_requests',
    'psc_cleanup_impersonations', 'psc_cleanup_message_receipts', 'psc_send_scheduled_messages', 'psc_envois_reprise',
);
$policies = psc_retention_policies();
if (count($policies) !== 12) exit("Nombre de catégories inattendu\n");
foreach (array('requests_pending', 'childless_families', 'departed_children', 'conversations', 'audit_log') as $key) {
    if (empty($policies[$key]['enabled'])) exit("Catégorie active attendue : $key\n");
}
foreach ($policies as $key => $policy) {
    if (!empty($policy['enabled'])) {
        if ((int) $policy['days'] <= 0) exit("Durée absente : $key\n");
        if (!in_array($policy['hook'], $recurring, true)) exit("Tâche planifiée inconnue : $key\n");
    } elseif (trim((string) $policy['reason']) === '') {
        exit("Catégorie inactive sans raison : $key\n");
    }
}
if ($policies['requests_pending']['days'] !== 180 || $policies['departed_children']['days'] !== 400) exit("Durées par défaut inattendues\n");
if (!empty($policies['invoices']['enabled'])) exit("Les factures ne doivent jamais être purgées par le registre\n");
echo "Politique de rétention : OK\n";
