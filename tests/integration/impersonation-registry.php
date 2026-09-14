<?php
/** wp eval-file tests/integration/impersonation-registry.php */
if (!defined('WP_CLI') || !WP_CLI) return;

$registered = array();
foreach (array_keys($GLOBALS['wp_filter']) as $hook) {
    if (preg_match('/^(?:admin_post_nopriv_|wp_ajax_nopriv_)(psc_.+)$/', $hook, $matches)) {
        $registered[$matches[1]] = true;
    }
}

$policies = psc_impersonation_action_policy();
$allowed = array('lecture', 'ecriture', 'hors_portail');
foreach ($policies as $action => $policy) {
    if (!in_array($policy, $allowed, true)) {
        throw new RuntimeException(sprintf('Politique invalide pour %s : %s.', $action, $policy));
    }
}

$missing = array_diff_key($registered, $policies);
if ($missing) {
    throw new RuntimeException(
        'Actions famille absentes du registre : ' . implode(', ', array_keys($missing)) . '.'
    );
}

WP_CLI::log(sprintf(
    'OK : %d actions famille publiques sont classées dans le registre.',
    count($registered)
));
