<?php
if (!defined('ABSPATH')) exit;

/** Registre des catégories de conservation ; les durées restent à valider. */
function psc_retention_policies() {
    $policies = array(
        'requests_unconfirmed' => array('label' => __('Demandes non confirmées', 'periscolaire-registration'), 'enabled' => false, 'days' => 7, 'handler' => 'requests_unconfirmed'),
        'requests_processed'   => array('label' => __('Demandes traitées', 'periscolaire-registration'), 'enabled' => false, 'days' => 90, 'handler' => 'requests_processed'),
        'departed_children'    => array('label' => __('Enfants sortis', 'periscolaire-registration'), 'enabled' => true, 'days' => 400, 'handler' => 'departed_children'),
        'planning_attendance'  => array('label' => __('Planning et présences', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'planning_attendance'),
        'pickup_persons'       => array('label' => __('Personnes autorisées', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'pickup_persons'),
        'allergies'            => array('label' => __('Allergies', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'allergies'),
        'conversations'        => array('label' => __('Conversations', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'conversations'),
        'audit_log'            => array('label' => __('Journal d’audit', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'audit_log'),
        'private_files'        => array('label' => __('Fichiers privés', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'private_files'),
        'invoices'             => array('label' => __('Factures', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'handler' => 'invoices'),
    );
    $validated = get_option('psc_retention_validated_categories', array('departed_children'));
    if (!is_array($validated)) $validated = array();
    foreach ($policies as $key => &$policy) {
        $policy['validated'] = in_array($key, $validated, true);
        if ($key !== 'departed_children') $policy['enabled'] = false;
    }
    unset($policy);
    return apply_filters('psc_retention_policies', $policies);
}
