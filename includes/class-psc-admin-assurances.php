<?php
if (!defined('ABSPATH')) exit;

class Psc_Admin_Assurances {
    public static function init() {
        add_action('admin_post_psc_review_assurance', array(__CLASS__, 'handle_review'));
    }

    public static function handle_review() {
        if (!psc_user_can_manage()) wp_die('Accès refusé.', '', array('response' => 403));
        $child_id = psc_post_int('child_id');
        $year_id = psc_post_int('school_year_id');
        check_admin_referer('psc_review_assurance_' . $child_id . '_' . $year_id);
        $status = psc_post('decision');
        $note = sanitize_textarea_field(wp_unslash($_POST['review_note'] ?? ''));
        if ($status === 'rejected' && trim($note) === '') wp_die('Précisez le motif du refus pour la famille.');
        $ok = Psc_Assurances::review($child_id, $year_id, psc_post('revision'), $status, $note);
        wp_safe_redirect(add_query_arg(array('page' => 'psc_assurances', 'child_id' => $child_id, 'review' => $ok ? 'saved' : 'stale'), admin_url('admin.php')));
        exit;
    }

    public static function page() {
        if (!psc_user_can_manage()) wp_die('Accès refusé.', '', array('response' => 403));
        global $wpdb;
        $year_id = Psc_School_Years::active_id();
        $children_table = psc_table('children');
        $enrollments = psc_table('child_school_years');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id AS child_id, c.prenom, c.nom, cy.assurance_file_path, cy.assurance_original_filename,
                    cy.assurance_uploaded_at, cy.assurance_status, cy.assurance_revision, cy.assurance_review_note,
                    cy.assurance_reviewed_at
             FROM $children_table c LEFT JOIN $enrollments cy ON cy.child_id = c.id AND cy.school_year_id = %d
             WHERE c.statut = 'actif' ORDER BY CASE WHEN cy.assurance_status = 'pending' THEN 0 ELSE 1 END, c.nom, c.prenom", $year_id
        ));
        $selected = null;
        foreach ($rows as $row) {
            if ((int) $row->child_id === psc_get_int('child_id')) $selected = $row;
        }
        include PSC_PATH . 'templates/admin-assurances.php';
    }
}
