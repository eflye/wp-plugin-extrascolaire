<?php
if (!defined('ABSPATH')) exit;

/**
 * Demandes d'inscription : écran de modération.
 *
 * Le traitement lui-même (validation, refus, purge) vit dans Psc_Requests,
 * qui porte le cycle de vie complet d'une demande, dépôt public compris.
 */
class Psc_Admin_Requests extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_download_pending_assurance', array(__CLASS__, 'handle_download_pending_assurance'));
    }

    public static function page_requests() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $pending = Psc_Requests::by_status('pending');
        $t   = psc_table('requests');
        $t_p = psc_table('parents');
        $handled = $wpdb->get_results(
            "SELECT r.*, COALESCE(p.nom, r.nom) AS nom
             FROM $t r
             LEFT JOIN $t_p p ON p.email = r.email
             WHERE r.status IN ('approved','rejected')
             ORDER BY r.decided_at DESC LIMIT 100"
        );
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-requests.php';
    }

    /**
     * Consultation par la mairie d'un justificatif déposé avec une demande
     * d'inscription pas encore approuvée (zone d'attente, aucun child_id
     * n'existe encore). Le chemin n'est jamais pris depuis la requête
     * cliente : toujours re-dérivé de children_json par index, comme dans
     * Psc_Requests::handle_approve().
     */
    public static function handle_download_pending_assurance() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $request_id = psc_get_int('request_id');
        $index      = psc_get_int('index');
        check_admin_referer('psc_download_pending_assurance_' . $request_id . '_' . $index);

        $req = Psc_Requests::get($request_id);
        if (!$req) {
            wp_die(esc_html__('Demande introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }
        $children = Psc_Requests::children_of($req);
        if (empty($children[$index]['assurance_rel_path'])) {
            wp_die(esc_html__('Aucun justificatif pour cet enfant.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Assurances::stream($children[$index]['assurance_rel_path'], $children[$index]['assurance_original_filename']);
    }
}
