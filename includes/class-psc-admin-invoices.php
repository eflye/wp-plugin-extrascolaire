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
    }

    public static function handle_generate_invoices() {
        self::guard('psc_generate_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $count = Psc_Invoices::generate_month($mois);
        if (is_wp_error($count)) {
            $code = $count->get_error_code();
            self::redirect('psc_factures', $code === 'month_not_finished' ? 'month_not_finished' : 'gen_error');
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
