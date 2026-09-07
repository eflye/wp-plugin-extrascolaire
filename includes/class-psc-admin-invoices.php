<?php
if (!defined('ABSPATH')) exit;

/**
 * Factures : génération, envoi et téléchargement.
 */
class Psc_Admin_Invoices extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_generate_invoices', array(__CLASS__, 'handle_generate_invoices'));
        add_action('admin_post_psc_send_invoice', array(__CLASS__, 'handle_send_invoice'));
        add_action('admin_post_psc_send_all_invoices', array(__CLASS__, 'handle_send_all_invoices'));
        add_action('admin_post_psc_download_invoice', array(__CLASS__, 'handle_download_invoice'));
        add_action('admin_post_psc_delete_invoices', array(__CLASS__, 'handle_delete_invoices'));
        add_action('admin_post_psc_download_pain008', array(__CLASS__, 'handle_download_pain008'));
        add_action('admin_post_psc_download_sepa', array(__CLASS__, 'handle_download_sepa'));
    }

    /**
     * Supprime toutes les factures d'un mois (lignes et PDF) — la mairie
     * efface un mois pour le regénérer quand elle veut, y compris un mois
     * déjà envoyé. La confirmation navigateur est le garde-fou côté UI.
     */
    public static function handle_delete_invoices() {
        self::guard('psc_delete_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        $result = Psc_Invoices::delete_month($mois);
        if (is_wp_error($result)) {
            self::redirect('psc_factures', 'invalid');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => 'deleted'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Export .ods des prélèvements SEPA du mois : une ligne par famille en
     * prélèvement, montant de sa facture, IBAN déchiffré, référence de
     * mandat — tout ce qu'il faut pour saisir les prélèvements dans
     * l'outil bancaire de la mairie. Le fichier est construit à la volée,
     * jamais stocké ; le téléchargement est journalisé (données bancaires).
     */
    public static function handle_download_sepa() {
        self::guard('psc_download_sepa');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $rows = Psc_Invoices::sepa_rows($mois);
        if (is_wp_error($rows)) {
            // Le mois est préservé dans la redirection : retomber sur le
            // premier mois de la liste (2029-09 avec les seeds) ferait
            // croire à la mairie que c'est celui qu'elle a exporté.
            wp_safe_redirect(add_query_arg(
                array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => $rows->get_error_code() === 'not_generated' ? 'sepa_need_generate' : 'invalid'),
                admin_url('admin.php')
            ));
            exit;
        }
        if (!$rows) {
            wp_safe_redirect(add_query_arg(
                array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => 'sepa_none'),
                admin_url('admin.php')
            ));
            exit;
        }

        $tmp = tempnam(get_temp_dir(), 'psc-sepa-');
        if (!$tmp) {
            self::redirect('psc_factures', 'sepa_failed');
        }
        $built = Psc_Invoices::build_sepa_ods($tmp, $rows);
        if (is_wp_error($built)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            self::redirect('psc_factures', 'sepa_failed');
        }

        // Journalisation : mêmes règles que les autres lectures du
        // répertoire privé — ce fichier contient des IBAN en clair.
        psc_log_download('prelevements', 'periscolaire/factures/' . $mois . '/prelevements-' . $mois . '.ods');

        $filename = 'prelevements-' . $mois . '.ods';
        nocache_headers();
        header('Content-Type: application/vnd.oasis.opendocument.spreadsheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        exit;
    }

    /** Téléchargement XML privé, sans envoi à la banque ni modification des factures. */
    public static function handle_download_pain008() {
        self::guard('psc_download_pain008');
        $month = psc_post('mois');
        $date = psc_post('collection_date');
        $rows = Psc_Invoices::sepa_rows($month);
        $xml = is_wp_error($rows) ? $rows : Psc_Sepa_Export::build($rows, Psc_Sepa_Export::creditor(), $month, $date);
        if (is_wp_error($xml)) {
            wp_die(nl2br(esc_html($xml->get_error_message())), esc_html__('Export pain.008 impossible', 'periscolaire-registration'), array('response' => 400, 'back_link' => true));
        }
        $filename = 'prelevements-' . $month . '-' . $date . '-pain008.xml';
        psc_log_download('prelevements', 'periscolaire/factures/' . $month . '/' . $filename);
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($xml));
        echo $xml; // XML construit par DOM et validé par XSD.
        exit;
    }

    public static function handle_generate_invoices() {
        self::guard('psc_generate_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $count = Psc_Invoices::generate_month($mois);
        if (is_wp_error($count)) {
            self::redirect('psc_factures', 'gen_error');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => ($count > 0 ? 'generated' : 'gen_zero')),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_send_invoice() {
        self::guard('psc_send_invoice');

        $invoice_id = psc_post_int('invoice_id');
        $mois       = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!$invoice_id) {
            self::redirect('psc_factures', 'invalid');
        }

        $result = Psc_Invoices::send($invoice_id);
        $msg    = is_wp_error($result) ? $result->get_error_code() : 'sent';
        if ($msg !== 'no_file' && is_wp_error($result)) {
            $msg = 'mail_failed';
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_send_all_invoices() {
        self::guard('psc_send_all_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $invoices = Psc_Invoices::get_for_month($mois);
        foreach ($invoices as $inv) {
            if (!$inv->sent_at) {
                Psc_Invoices::send((int) $inv->id);
            }
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => 'sent_all'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_download_invoice() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $invoice_id = psc_get_int('invoice_id');
        check_admin_referer('psc_download_invoice_' . $invoice_id);
        Psc_Invoices::download($invoice_id);
    }

    public static function page_factures() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $all_months    = Psc_Invoices::months_with_data();
        $selected_mois = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : '';
        if (!$selected_mois && !empty($all_months)) {
            $selected_mois = $all_months[0];
        }
        $invoices = $selected_mois ? Psc_Invoices::get_for_month($selected_mois) : array();
        $psc_msg  = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        include PSC_PATH . 'templates/admin-factures.php';
    }
}
