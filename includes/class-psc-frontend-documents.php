<?php
if (!defined('ABSPATH')) exit;

/**
 * Documents de la famille : dépôt et consultation du justificatif
 * d'assurance scolaire, téléchargement des factures PDF. Les trois
 * handlers téléchargent un fichier du répertoire privé — le contrôle
 * d'appartenance (l'enfant ou la facture est-il celui de la famille
 * connectée ?) y est systématique : le nonce prouve l'intention, pas
 * le droit.
 */
class Psc_Frontend_Documents extends Psc_Frontend_Base {

    public static function init() {
        add_action('admin_post_nopriv_psc_parent_upload_assurance', array(__CLASS__, 'handle_parent_upload_assurance'));
        add_action('admin_post_psc_parent_upload_assurance', array(__CLASS__, 'handle_parent_upload_assurance'));
        add_action('admin_post_nopriv_psc_parent_download_assurance', array(__CLASS__, 'handle_parent_download_assurance'));
        add_action('admin_post_psc_parent_download_assurance', array(__CLASS__, 'handle_parent_download_assurance'));
        add_action('admin_post_nopriv_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));
        add_action('admin_post_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));
    }

    /**
     * Upload par le parent du justificatif d'assurance scolaire d'un
     * enfant déjà existant (remplacement depuis « Mes enfants »).
     */
    public static function handle_parent_upload_assurance() {
        $parent = self::authed_parent('psc_parent_upload_assurance');
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id = psc_post_int('child_id');
        $t_child  = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('assurance_invalid');

        $result = Psc_Assurances::store_upload($child_id, isset($_FILES['assurance_file']) ? $_FILES['assurance_file'] : null);
        if ($result !== true) {
            $codes = array('too_large' => 'assurance_too_large', 'invalid_type' => 'assurance_invalid_type');
            self::parent_form_redirect(isset($codes[$result]) ? $codes[$result] : 'assurance_upload_failed');
        }

        self::parent_form_redirect('assurance_uploaded');
    }

    /**
     * Téléchargement du justificatif d'assurance par la famille elle-même.
     * Même logique de contrôle d'appartenance que
     * handle_parent_download_invoice() : le nonce prouve l'intention, mais
     * c'est la vérification ci-dessous qui empêche une famille connectée de
     * consulter le document d'un enfant qui n'est pas le sien.
     */
    public static function handle_parent_download_assurance() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à ce document.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $child_id = psc_get_int('child_id');
        check_admin_referer('psc_parent_download_assurance_' . $child_id);

        global $wpdb;
        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$child) {
            wp_die(esc_html__('Enfant introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        $year_id = Psc_School_Years::active_id();
        $doc = $year_id ? Psc_School_Years::enrollment($child_id, $year_id) : null;
        if (!$doc || !$doc->assurance_file_path) {
            wp_die(esc_html__('Aucun document pour cette année.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Assurances::stream($doc->assurance_file_path, $doc->assurance_original_filename);
    }

    /**
     * Téléchargement d'une facture par la famille elle-même. Le nonce
     * prouve l'intention, mais le contrôle qui compte vraiment est la
     * vérification d'appartenance ci-dessous : sans elle, une famille
     * connectée pourrait télécharger la facture d'une autre en devinant
     * un identifiant dans l'URL.
     */
    public static function handle_parent_download_invoice() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à cette facture.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $invoice_id = psc_get_int('invoice_id');
        check_admin_referer('psc_parent_download_invoice_' . $invoice_id);

        $invoice = Psc_Invoices::get($invoice_id);
        if (!$invoice || (int) $invoice->parent_id !== (int) $parent->id) {
            wp_die(esc_html__('Facture introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Invoices::download($invoice_id);
    }
}
