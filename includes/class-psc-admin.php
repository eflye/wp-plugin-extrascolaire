<?php
if (!defined('ABSPATH')) exit;

class Psc_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_psc_add_trimestre', array(__CLASS__, 'handle_add_trimestre'));
        add_action('admin_post_psc_activate_trimestre', array(__CLASS__, 'handle_activate_trimestre'));
        add_action('admin_post_psc_close_range', array(__CLASS__, 'handle_close_range'));
        add_action('admin_post_psc_add_child', array(__CLASS__, 'handle_add_child'));
        add_action('admin_post_psc_delete_child', array(__CLASS__, 'handle_delete_child'));
        add_action('admin_post_psc_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_psc_export_csv', array(__CLASS__, 'handle_export_csv'));
        add_action('admin_post_psc_add_parent', array(__CLASS__, 'handle_add_parent'));
        add_action('admin_post_psc_toggle_parent', array(__CLASS__, 'handle_toggle_parent'));
        add_action('admin_post_psc_send_link', array(__CLASS__, 'handle_send_link'));
        add_action('admin_post_psc_generate_invoices', array(__CLASS__, 'handle_generate_invoices'));
        add_action('admin_post_psc_send_invoice', array(__CLASS__, 'handle_send_invoice'));
        add_action('admin_post_psc_send_all_invoices', array(__CLASS__, 'handle_send_all_invoices'));
        add_action('admin_post_psc_download_invoice', array(__CLASS__, 'handle_download_invoice'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * Contrôle d'accès + nonce, appliqué en tête de chaque action.
     * Le nonce seul ne suffit pas (il prouve l'intention, pas le droit) ;
     * la capacité seule ne suffit pas non plus (elle n'empêche pas le CSRF).
     */
    protected static function guard($nonce_action) {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Vous n\'avez pas les droits nécessaires.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer($nonce_action);
    }

    public static function redirect_public($page, $msg) {
        self::redirect($page, $msg);
    }

    protected static function redirect($page, $msg) {
        wp_safe_redirect(add_query_arg(
            array('page' => $page, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_') === false) return;
        wp_enqueue_style('psc-admin', PSC_URL . 'assets/css/admin.css', array(), PSC_VERSION);
    }

    public static function menu() {
        $cap = psc_manage_cap();
        add_menu_page('Périscolaire', 'Périscolaire', $cap, 'psc_inscriptions', array(__CLASS__, 'page_inscriptions'), 'dashicons-groups', 58);
        add_submenu_page('psc_inscriptions', 'Inscriptions', 'Inscriptions', $cap, 'psc_inscriptions', array(__CLASS__, 'page_inscriptions'));
        add_submenu_page('psc_inscriptions', 'Trimestres', 'Trimestres', $cap, 'psc_trimestres', array(__CLASS__, 'page_trimestres'));
        $pending = Psc_Requests::pending_count();
        $req_label = $pending
            ? sprintf('Demandes <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $pending)
            : 'Demandes';
        add_submenu_page('psc_inscriptions', "Demandes d'inscription", $req_label, $cap, 'psc_requests', array(__CLASS__, 'page_requests'));
        add_submenu_page('psc_inscriptions', 'Familles', 'Familles', $cap, 'psc_parents', array(__CLASS__, 'page_parents'));
        add_submenu_page('psc_inscriptions', 'Enfants', 'Enfants', $cap, 'psc_children', array(__CLASS__, 'page_children'));
        add_submenu_page('psc_inscriptions', 'Factures', 'Factures', $cap, 'psc_factures', array(__CLASS__, 'page_factures'));
        add_submenu_page('psc_inscriptions', 'Réglages', 'Réglages', $cap, 'psc_settings', array(__CLASS__, 'page_settings'));
    }

    /* ---------------- Trimestres ---------------- */

    public static function handle_add_trimestre() {
        self::guard('psc_add_trimestre');
        global $wpdb;

        $label = psc_post('label');
        $debut = psc_valid_date(psc_post('date_debut'));
        $fin   = psc_valid_date(psc_post('date_fin'));

        if ($label === '' || !$debut || !$fin) {
            self::redirect('psc_trimestres', 'invalid_dates');
        }
        if (strtotime($fin) < strtotime($debut)) {
            self::redirect('psc_trimestres', 'order_dates');
        }
        // Garde-fou : une faute de frappe sur l'année générerait des millions de lignes.
        $span = (strtotime($fin) - strtotime($debut)) / DAY_IN_SECONDS;
        if ($span > psc_max_trimestre_days()) {
            self::redirect('psc_trimestres', 'too_long');
        }

        $wpdb->insert(psc_table('trimestres'), array(
            'label'      => mb_substr($label, 0, 190),
            'date_debut' => $debut,
            'date_fin'   => $fin,
            'active'     => 0,
        ), array('%s', '%s', '%s', '%d'));

        Psc_Installer::generate_calendar_days($wpdb->insert_id, $debut, $fin);
        self::redirect('psc_trimestres', 'created');
    }

    public static function handle_activate_trimestre() {
        self::guard('psc_activate_trimestre');
        global $wpdb;

        $id = psc_post_int('id');
        if (!$id) self::redirect('psc_trimestres', 'invalid');

        $t_trim = psc_table('trimestres');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_trim WHERE id = %d", $id));
        if (!$exists) self::redirect('psc_trimestres', 'invalid');

        $wpdb->query("UPDATE $t_trim SET active = 0");
        $wpdb->update($t_trim, array('active' => 1), array('id' => $id), array('%d'), array('%d'));
        self::redirect('psc_trimestres', 'activated');
    }

    public static function handle_close_range() {
        self::guard('psc_close_range');
        global $wpdb;

        $trimestre_id = psc_post_int('trimestre_id');
        $debut = psc_valid_date(psc_post('date_debut'));
        $fin   = psc_valid_date(psc_post('date_fin'));
        $label = psc_post('label');
        if ($label === '') $label = 'Vacances';

        if (!$trimestre_id || !$debut || !$fin || strtotime($fin) < strtotime($debut)) {
            self::redirect('psc_trimestres', 'invalid_dates');
        }
        $span = (strtotime($fin) - strtotime($debut)) / DAY_IN_SECONDS;
        if ($span > psc_max_trimestre_days()) {
            self::redirect('psc_trimestres', 'too_long');
        }

        $t_trim = psc_table('trimestres');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_trim WHERE id = %d", $trimestre_id));
        if (!$exists) self::redirect('psc_trimestres', 'invalid');

        Psc_Installer::set_range_closed($trimestre_id, $debut, $fin, mb_substr($label, 0, 100));
        self::redirect('psc_trimestres', 'closed');
    }

    public static function page_trimestres() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-trimestres.php';
    }

    /* ---------------- Enfants ---------------- */

    public static function handle_add_child() {
        self::guard('psc_add_child');
        global $wpdb;

        $parent_id = psc_post_int('parent_id');
        $nom    = psc_post('nom');
        $prenom = psc_post('prenom');
        $classe = psc_post('classe');

        if (!$parent_id || $nom === '' || $prenom === '') {
            self::redirect('psc_children', 'invalid');
        }

        if (!Psc_Parents::get_by_id($parent_id)) {
            self::redirect('psc_children', 'nouser');
        }

        $wpdb->insert(psc_table('children'), array(
            'parent_id'  => $parent_id,
            'nom'        => mb_substr($nom, 0, 190),
            'prenom'     => mb_substr($prenom, 0, 190),
            'classe'     => mb_substr($classe, 0, 100),
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));

        self::redirect('psc_children', 'added');
    }

    public static function handle_delete_child() {
        self::guard('psc_delete_child');
        global $wpdb;

        $id = psc_post_int('id');
        if (!$id) self::redirect('psc_children', 'invalid');

        $wpdb->delete(psc_table('registrations'), array('child_id' => $id), array('%d'));
        $wpdb->delete(psc_table('children'), array('id' => $id), array('%d'));
        self::redirect('psc_children', 'deleted');
    }

    public static function page_children() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $t_child = psc_table('children');
        $t_parent = psc_table('parents');
        $children = $wpdb->get_results("SELECT c.*, p.email AS parent_email FROM $t_child c LEFT JOIN $t_parent p ON p.id = c.parent_id ORDER BY c.nom");
        $parents = Psc_Parents::all();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-children.php';
    }

    /* ---------------- Réglages ---------------- */

    public static function handle_save_settings() {
        self::guard('psc_save_settings');

        $prices = array();
        foreach (psc_allowed_services() as $code) {
            $raw = isset($_POST['price_' . $code]) ? wp_unslash($_POST['price_' . $code]) : '0';
            $val = floatval(str_replace(',', '.', sanitize_text_field($raw)));
            $prices[$code] = max(0, min(1000, $val));
        }
        update_option('psc_service_prices', $prices);

        // Délai de prévenance, borné pour éviter une valeur absurde.
        $hours = psc_post_int('lock_hours', 48);
        update_option('psc_lock_hours', max(0, min(720, $hours)));

        update_option('psc_notify_mairie', isset($_POST['notify_mairie']) ? 1 : 0);

        $mairie_mail = isset($_POST['mairie_email']) ? sanitize_email(wp_unslash($_POST['mairie_email'])) : '';
        update_option('psc_mairie_email', is_email($mairie_mail) ? $mairie_mail : '');
        self::redirect('psc_settings', 'saved');
    }

    public static function page_settings() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $services = psc_services();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-settings.php';
    }

    /* ---------------- Inscriptions / récapitulatif ---------------- */

    public static function page_inscriptions() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;

        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
        $trimestre_id = psc_get_int('trimestre_id');
        if (!$trimestre_id && $trimestres) {
            foreach ($trimestres as $t) {
                if ($t->active) { $trimestre_id = (int) $t->id; break; }
            }
            if (!$trimestre_id) $trimestre_id = (int) $trimestres[0]->id;
        }
        $child_id = psc_get_int('child_id');
        $children = $wpdb->get_results('SELECT * FROM ' . psc_table('children') . ' ORDER BY nom');

        $rows = array();
        $totals = array_fill_keys(psc_allowed_services(), 0);

        if ($trimestre_id) {
            $t_days = psc_table('calendar_days');
            $days = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date", $trimestre_id
            ));

            $t_reg = psc_table('registrations');
            if ($child_id) {
                $regs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $t_reg WHERE trimestre_id = %d AND child_id = %d", $trimestre_id, $child_id
                ));
            } else {
                $regs = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $t_reg WHERE trimestre_id = %d", $trimestre_id
                ));
            }

            $reg_map = array();
            foreach ($regs as $r) {
                $reg_map[$r->jour_date . '|' . $r->child_id . '|' . $r->service] = 1;
                if (isset($totals[$r->service])) {
                    $totals[$r->service]++;
                }
            }

            $child_list = $child_id
                ? array_values(array_filter($children, function ($c) use ($child_id) { return (int) $c->id === $child_id; }))
                : $children;

            foreach ($days as $d) {
                foreach ($child_list as $c) {
                    $row = array('date' => $d->jour_date, 'child' => $c);
                    foreach (psc_allowed_services() as $s) {
                        $row[$s] = isset($reg_map[$d->jour_date . '|' . $c->id . '|' . $s]);
                    }
                    $rows[] = $row;
                }
            }
        }

        include PSC_PATH . 'templates/admin-inscriptions.php';
    }

    public static function handle_export_csv() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_export_csv');

        global $wpdb;
        $trimestre_id = psc_get_int('trimestre_id');
        if (!$trimestre_id) {
            wp_die(esc_html__('Trimestre invalide.', 'periscolaire-registration'));
        }

        $t_reg = psc_table('registrations');
        $t_child = psc_table('children');
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT r.jour_date, r.service, c.nom, c.prenom, c.classe, p.email AS parent_email
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             LEFT JOIN " . psc_table('parents') . " p ON p.id = c.parent_id
             WHERE r.trimestre_id = %d ORDER BY c.nom, r.jour_date", $trimestre_id
        ));

        $filename = 'inscriptions-periscolaire-' . $trimestre_id . '-' . gmdate('Ymd') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, array('Nom', 'Prénom', 'Classe', 'Contact parent', 'Date', 'Jour', 'Service'), ';');
        foreach ($data as $row) {
            // psc_csv_escape() neutralise les formules Excel : les noms
            // proviennent d'une saisie parent, donc de données non fiables.
            fputcsv($out, array(
                psc_csv_escape($row->nom),
                psc_csv_escape($row->prenom),
                psc_csv_escape($row->classe),
                psc_csv_escape($row->parent_email),
                $row->jour_date,
                psc_day_label($row->jour_date),
                $row->service,
            ), ';');
        }
        fclose($out);
        exit;
    }

    /* ---------------- Familles (comptes parents du plugin) ---------------- */

    public static function handle_add_parent() {
        self::guard('psc_add_parent');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $nom   = psc_post('nom');

        $result = Psc_Parents::create($email, $nom);
        if (is_wp_error($result)) {
            self::redirect('psc_parents', $result->get_error_code() === 'psc_exists' ? 'exists' : 'invalid');
        }
        self::redirect('psc_parents', 'added');
    }

    public static function handle_toggle_parent() {
        self::guard('psc_toggle_parent');
        global $wpdb;

        $id = psc_post_int('id');
        $parent = $id ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $id
        )) : null;
        if (!$parent) self::redirect('psc_parents', 'invalid');

        $wpdb->update(
            psc_table('parents'),
            array('active' => $parent->active ? 0 : 1),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
        self::redirect('psc_parents', $parent->active ? 'deactivated' : 'reactivated');
    }

    /**
     * Envoie manuellement un lien d'accès (première mise en service,
     * ou parent qui ne reçoit pas le message).
     */
    public static function handle_send_link() {
        self::guard('psc_send_link');

        $id = psc_post_int('id');
        $parent = Psc_Parents::get_by_id($id);
        if (!$parent) self::redirect('psc_parents', 'invalid');

        $ok = Psc_Parents::send_login_link($parent->email);
        self::redirect('psc_parents', $ok ? 'link_sent' : 'mail_failed');
    }

    public static function page_parents() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $parents = Psc_Parents::all();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-parents.php';
    }

    /* ---------------- Factures ---------------- */

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
        if ($msg === 'no_file') {
            // gardez le message tel quel
        } elseif (is_wp_error($result)) {
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

    public static function page_requests() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $pending = Psc_Requests::by_status('pending');
        $t = psc_table('requests');
        $handled = $wpdb->get_results(
            "SELECT * FROM $t WHERE status IN ('approved','rejected') ORDER BY decided_at DESC LIMIT 100"
        );
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-requests.php';
    }
}
