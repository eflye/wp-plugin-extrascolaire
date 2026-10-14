<?php
if (!defined('ABSPATH')) exit;

/**
 * Registre des durées de conservation (RGPD-14) : il décrit les purges
 * RÉELLEMENT exécutées par le plugin, avec la durée lue à la même source
 * que la purge et la tâche planifiée qui l'exécute. Il ne déclenche rien :
 * chaque purge reste appelée par sa propre tâche ; Psc_Retention::run()
 * n'en fait qu'une simulation (comptage).
 *
 * Une catégorie inactive ('enabled' => false) n'a pas de purge propre :
 * 'reason' dit pourquoi (purgée avec la fiche enfant, ou décision à
 * prendre).
 *
 * @return array<string, array{label: string, enabled: bool, days: ?int, hook: ?string, reason: string}>
 */
function psc_retention_policies() {
    $children_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
    // Durées des demandes : constantes de Psc_Requests, lues par cleanup().
    $request_days = function ($constant, $default) {
        return class_exists('Psc_Requests') && defined('Psc_Requests::' . $constant) ? (int) constant('Psc_Requests::' . $constant) : $default;
    };
    $with_child = __('Supprimé avec la fiche enfant (cf. « Enfants sortis »).', 'periscolaire-registration');
    $policies = array(
        'requests_unconfirmed' => array(
            'label' => __('Demandes d’inscription non confirmées', 'periscolaire-registration'),
            'enabled' => true, 'days' => $request_days('UNVERIFIED_RETENTION_DAYS', 7), 'hook' => 'psc_cleanup_requests',
            'reason' => __('Adresse e-mail jamais confirmée, à compter du dépôt.', 'periscolaire-registration'),
        ),
        'requests_processed' => array(
            'label' => __('Demandes d’inscription traitées', 'periscolaire-registration'),
            'enabled' => true, 'days' => $request_days('DECIDED_RETENTION_DAYS', 90), 'hook' => 'psc_cleanup_requests',
            'reason' => __('Validées ou refusées, à compter de la décision.', 'periscolaire-registration'),
        ),
        'requests_pending' => array(
            'label' => __('Demandes d’inscription en attente', 'periscolaire-registration'),
            'enabled' => true,
            'days' => $request_days('PENDING_RETENTION_DAYS', 180),
            'hook' => 'psc_cleanup_requests',
            'reason' => __('Jamais traitées par la mairie, à compter du dépôt.', 'periscolaire-registration'),
        ),
        'departed_children' => array(
            'label' => __('Enfants sortis', 'periscolaire-registration'),
            'enabled' => true, 'days' => $children_days, 'hook' => 'psc_purge_departed_children',
            'reason' => __('À compter de la sortie ou de la fin de la dernière année d’inscription (création de la fiche pour un enfant sans année).', 'periscolaire-registration'),
        ),
        'childless_families' => array(
            'label' => __('Foyers sans enfant', 'periscolaire-registration'),
            'enabled' => true, 'days' => $children_days, 'hook' => 'psc_purge_departed_children',
            'reason' => __('À compter de la dernière activité ; fiche anonymisée s’il reste des factures.', 'periscolaire-registration'),
        ),
        'conversations' => array(
            'label' => __('Échanges avec les familles (pièces jointes comprises)', 'periscolaire-registration'),
            'enabled' => true, 'days' => max(1, (int) apply_filters('psc_conversations_retention_days', 395)), 'hook' => 'psc_purge_conversations',
            'reason' => __('À compter du dernier message.', 'periscolaire-registration'),
        ),
        'audit_log' => array(
            'label' => __('Journal d’audit', 'periscolaire-registration'),
            'enabled' => true,
            'days' => function_exists('psc_audit_retention_days') ? psc_audit_retention_days('normal') : 365,
            'hook' => 'psc_purge_audit_log',
            'reason' => function_exists('psc_audit_retention_days')
                /* translators: 1: jours (niveau critique), 2: jours (niveau normal), 3: jours (niveau volumineux) */
                ? sprintf(__('Par niveau : critique %1$d jours, normal %2$d jours, volumineux %3$d jours.', 'periscolaire-registration'), psc_audit_retention_days('critique'), psc_audit_retention_days('normal'), psc_audit_retention_days('volumineux'))
                : __('Par niveau (critique, normal, volumineux).', 'periscolaire-registration'),
        ),
        'planning_attendance' => array('label' => __('Planning et présences', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'hook' => null, 'reason' => $with_child),
        'pickup_persons'      => array('label' => __('Personnes autorisées et historique', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'hook' => null, 'reason' => $with_child),
        'allergies'           => array('label' => __('Allergies et régimes', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'hook' => null, 'reason' => $with_child),
        'private_files'       => array('label' => __('Justificatifs d’assurance', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'hook' => null, 'reason' => $with_child),
        'invoices'            => array('label' => __('Factures', 'periscolaire-registration'), 'enabled' => false, 'days' => null, 'hook' => null, 'reason' => __('Jamais supprimées ni réécrites ; durée de conservation à fixer par le DPO.', 'periscolaire-registration')),
    );
    return apply_filters('psc_retention_policies', $policies);
}
