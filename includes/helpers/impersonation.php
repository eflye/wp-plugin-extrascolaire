<?php
if (!defined('ABSPATH')) exit;

/**
 * Politique exhaustive des points d'entrée publics du portail famille.
 *
 * Ce registre central rend explicite ce qu'une future consultation en
 * lecture seule pourra laisser passer. Toute nouvelle action publique devra
 * être classée ici avant d'être accessible pendant une consultation.
 *
 * @return array<string,string> Action sans préfixe de hook => politique.
 */
function psc_impersonation_action_policy() {
    return array(
        // Lectures nécessaires au rendu et aux téléchargements du foyer.
        'psc_load_month'                         => 'lecture',
        'psc_menu_week'                          => 'lecture',
        'psc_message_notifications'              => 'lecture',
        'psc_parent_download_invoice'            => 'lecture',
        'psc_parent_download_assurance'          => 'lecture',

        // Écran intervenants indépendant du portail famille.
        'psc_sidscm_unlock'                      => 'hors_portail',
        'psc_sidscm_data'                        => 'hors_portail',
        'psc_sidscm_toggle'                      => 'hors_portail',
        'psc_sidscm_arrival'                     => 'hors_portail',
        'psc_sidscm_departure'                   => 'hors_portail',

        // Écritures du planning.
        'psc_toggle_exception'                   => 'ecriture',
        'psc_toggle_exception_bulk'              => 'ecriture',
        'psc_toggle_pattern'                     => 'ecriture',
        'psc_apply_pattern_to_siblings'          => 'ecriture',
        'psc_reset_month_exceptions'             => 'ecriture',
        'psc_confirm'                            => 'ecriture',
        'psc_cancel_absence'                     => 'ecriture',

        // Authentification, demandes publiques et messagerie.
        'psc_request_link'                       => 'ecriture',
        'psc_submit_request'                     => 'ecriture',
        'psc_cancel_email_change'                => 'ecriture',
        'psc_message_ack'                        => 'ecriture',
        // Cas spécial : la garde future terminera la consultation sans
        // toucher à une éventuelle session famille du même navigateur.
        'psc_logout'                             => 'ecriture',

        // Profil, enfants, documents et habilitations du foyer.
        'psc_parent_update_profile'              => 'ecriture',
        'psc_parent_enable_sepa'                 => 'ecriture',
        'psc_parent_update_second_parent'        => 'ecriture',
        'psc_parent_remove_second_parent'        => 'ecriture',
        'psc_parent_dismiss_onboarding'           => 'ecriture',
        'psc_parent_reinscription'               => 'ecriture',
        'psc_parent_update_child_identity'       => 'ecriture',
        'psc_parent_add_child'                   => 'ecriture',
        'psc_parent_update_pickup_person'        => 'ecriture',
        'psc_parent_remove_pickup_person'        => 'ecriture',
        'psc_parent_add_household_pickup_person' => 'ecriture',
        'psc_parent_upload_assurance'            => 'ecriture',
    );
}
