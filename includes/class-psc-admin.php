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
        add_action('admin_post_psc_edit_parent', array(__CLASS__, 'handle_edit_parent'));
        add_action('admin_post_psc_save_email_templates', array(__CLASS__, 'handle_save_email_templates'));
        add_action('admin_post_psc_reset_email_template', array(__CLASS__, 'handle_reset_email_template'));
        add_action('admin_post_psc_reset_email_templates', array(__CLASS__, 'handle_reset_email_templates'));
        add_action('admin_post_psc_generate_invoices', array(__CLASS__, 'handle_generate_invoices'));
        add_action('admin_post_psc_send_invoice', array(__CLASS__, 'handle_send_invoice'));
        add_action('admin_post_psc_send_all_invoices', array(__CLASS__, 'handle_send_all_invoices'));
        add_action('admin_post_psc_download_invoice', array(__CLASS__, 'handle_download_invoice'));
        add_action('admin_post_psc_admin_update_registrations', array(__CLASS__, 'handle_admin_update_registrations'));
        add_action('admin_post_psc_save_menu', array(__CLASS__, 'handle_save_menu'));
        add_action('admin_post_psc_send_menu', array(__CLASS__, 'handle_send_menu'));
        add_action('admin_post_psc_delete_menu', array(__CLASS__, 'handle_delete_menu'));
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
        if (strpos($hook, 'psc_settings') !== false) {
            wp_enqueue_media();
        }
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
        add_submenu_page('psc_inscriptions', 'Menus cantine', 'Menus cantine', $cap, 'psc_menus', array(__CLASS__, 'page_menus'));
        add_submenu_page('psc_inscriptions', 'Modèles e-mails', 'Modèles e-mails', $cap, 'psc_email_templates', array(__CLASS__, 'page_email_templates'));
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
        $children = $wpdb->get_results("SELECT c.*, p.nom AS parent_nom, p.email AS parent_email FROM $t_child c LEFT JOIN $t_parent p ON p.id = c.parent_id ORDER BY c.nom");
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

        // Billing / invoice settings
        $billing_fields = array(
            'psc_billing_org_intro'   => 'sanitize_text_field',
            'psc_billing_org_name'    => 'sanitize_text_field',
            'psc_billing_org_address' => 'sanitize_text_field',
            'psc_billing_org_phone'   => 'sanitize_text_field',
            'psc_billing_org_fax'     => 'sanitize_text_field',
            'psc_billing_org_email'   => 'sanitize_email',
            'psc_billing_org_city'    => 'sanitize_text_field',
            'psc_billing_footer'      => 'sanitize_text_field',
        );
        foreach ($billing_fields as $option => $sanitizer) {
            $post_key = str_replace('psc_billing_', '', $option);
            $val = isset($_POST[$post_key]) ? call_user_func($sanitizer, wp_unslash($_POST[$post_key])) : '';
            update_option($option, $val);
        }
        update_option('psc_billing_logo_left_id',  absint(isset($_POST['logo_left_id'])  ? $_POST['logo_left_id']  : 0));
        update_option('psc_billing_logo_right_id', absint(isset($_POST['logo_right_id']) ? $_POST['logo_right_id'] : 0));

        self::redirect('psc_settings', 'saved');
    }

    public static function page_settings() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $services = psc_services();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-settings.php';
    }

    /* ---------------- Inscriptions (édition admin) ---------------- */

    public static function page_inscriptions() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;

        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
        $trimestre_id = psc_get_int('trimestre_id');
        if (!$trimestre_id && $trimestres) {
            foreach ($trimestres as $t) {
                if ($t->active) { $trimestre_id = (int) $t->id; break; }
            }
            if (!$trimestre_id && $trimestres) $trimestre_id = (int) $trimestres[0]->id;
        }
        $trimestre = null;
        foreach ($trimestres as $t) {
            if ((int) $t->id === $trimestre_id) { $trimestre = $t; break; }
        }

        $parents   = Psc_Parents::all();
        $parent_id = psc_get_int('parent_id');
        $selected_parent = $parent_id ? Psc_Parents::get_by_id($parent_id) : null;

        $children      = array();
        $days_by_month = array();
        $reg_map       = array();
        $services      = psc_services();

        if ($selected_parent && $trimestre_id) {
            $t_child  = psc_table('children');
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom", $parent_id
            ));

            $t_days = psc_table('calendar_days');
            $days   = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date", $trimestre_id
            ));
            foreach ($days as $d) {
                $month_key = date_i18n('F Y', strtotime($d->jour_date));
                $days_by_month[$month_key][] = $d;
            }

            if (!empty($children)) {
                $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
                $ph        = implode(',', array_fill(0, count($child_ids), '%d'));
                $t_reg     = psc_table('registrations');
                $regs      = $wpdb->get_results($wpdb->prepare(
                    "SELECT child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
                    array_merge(array($trimestre_id), $child_ids)
                ));
                foreach ($regs as $r) {
                    $reg_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = true;
                }
            }
        }

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-inscriptions.php';
    }

    public static function handle_admin_update_registrations() {
        self::guard('psc_admin_update_registrations');
        global $wpdb;

        $parent_id    = psc_post_int('parent_id');
        $trimestre_id = psc_post_int('trimestre_id');
        if (!$parent_id || !$trimestre_id) self::redirect('psc_inscriptions', 'invalid');

        $parent = Psc_Parents::get_by_id($parent_id);
        if (!$parent) self::redirect('psc_inscriptions', 'invalid');

        $trimestre = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE id = %d', $trimestre_id
        ));
        if (!$trimestre) self::redirect('psc_inscriptions', 'invalid');

        $t_child  = psc_table('children');
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom", $parent_id
        ));
        if (empty($children)) {
            self::redirect_to_inscriptions($parent_id, $trimestre_id, 'saved');
        }

        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $ph        = implode(',', array_fill(0, count($child_ids), '%d'));
        $t_days    = psc_table('calendar_days');
        $open_days   = $wpdb->get_col($wpdb->prepare(
            "SELECT jour_date FROM $t_days WHERE trimestre_id = %d AND is_open = 1", $trimestre_id
        ));
        $open_set = array_flip($open_days);

        // Build set of submitted registrations (only for valid children + open dates + allowed services).
        $submitted  = array();
        $regs_post  = isset($_POST['regs']) && is_array($_POST['regs']) ? $_POST['regs'] : array();
        foreach ($regs_post as $cid => $dates) {
            $cid = (int) $cid;
            if (!in_array($cid, $child_ids, true)) continue;
            if (!is_array($dates)) continue;
            foreach ($dates as $date => $svcs) {
                $date = sanitize_text_field(wp_unslash($date));
                if (!isset($open_set[$date])) continue;
                if (!is_array($svcs)) continue;
                foreach ($svcs as $svc => $on) {
                    $svc = strtoupper(sanitize_key($svc));
                    if (!in_array($svc, psc_allowed_services(), true)) continue;
                    $submitted[$cid . '|' . $date . '|' . $svc] = true;
                }
            }
        }

        // Current registrations for this family + trimestre.
        $t_reg      = psc_table('registrations');
        $current_rs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
            array_merge(array($trimestre_id), $child_ids)
        ));
        $current_map = array();
        foreach ($current_rs as $r) {
            $current_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = (int) $r->id;
        }

        // Compute diff before applying (for the email).
        $diff_added   = array_keys(array_diff_key($submitted, $current_map));
        $diff_removed = array_keys(array_diff_key($current_map, $submitted));

        // Apply diff.
        foreach (array_diff_key($current_map, $submitted) as $key => $reg_id) {
            $wpdb->delete($t_reg, array('id' => $reg_id), array('%d'));
        }
        foreach ($diff_added as $key) {
            list($cid, $date, $svc) = explode('|', $key);
            $wpdb->insert($t_reg, array(
                'trimestre_id' => $trimestre_id,
                'child_id'     => (int) $cid,
                'jour_date'    => $date,
                'service'      => $svc,
            ), array('%d', '%d', '%s', '%s'));
        }

        // Re-fetch final state for email.
        $new_regs = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
            array_merge(array($trimestre_id), $child_ids)
        ));
        $reg_map = array();
        foreach ($new_regs as $r) {
            $reg_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = true;
        }

        Psc_Mailer::send_admin_correction($parent, $trimestre, $children, $reg_map, psc_services(), $diff_added, $diff_removed);

        self::redirect_to_inscriptions($parent_id, $trimestre_id, 'saved');
    }

    private static function redirect_to_inscriptions($parent_id, $trimestre_id, $msg) {
        wp_safe_redirect(add_query_arg(array(
            'page'         => 'psc_inscriptions',
            'parent_id'    => $parent_id,
            'trimestre_id' => $trimestre_id,
            'psc_msg'      => $msg,
        ), admin_url('admin.php')));
        exit;
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

    public static function handle_edit_parent() {
        self::guard('psc_edit_parent');

        $id = psc_post_int('id');
        if (!$id) self::redirect('psc_parents', 'invalid');

        Psc_Parents::update($id, array(
            'nom'         => psc_post('nom'),
            'adresse'     => psc_post('adresse'),
            'code_postal' => psc_post('code_postal'),
            'ville'       => psc_post('ville'),
        ));
        self::redirect('psc_parents', 'updated');
    }

    public static function page_parents() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $parents     = Psc_Parents::all();
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $edit_id     = psc_get_int('edit');
        $edit_parent = $edit_id ? Psc_Parents::get_by_id($edit_id) : null;
        include PSC_PATH . 'templates/admin-parents.php';
    }

    /* ---------------- Modèles e-mails ---------------- */

    public static function handle_save_email_templates() {
        self::guard('psc_save_email_templates');
        $input = isset($_POST['templates']) ? wp_unslash($_POST['templates']) : array();
        Psc_Email_Templates::save(is_array($input) ? $input : array());
        self::redirect('psc_email_templates', 'saved');
    }

    public static function handle_reset_email_template() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
        check_admin_referer('psc_reset_email_template_' . $key);
        if ($key) {
            Psc_Email_Templates::reset($key);
        }
        self::redirect('psc_email_templates', 'reset_one');
    }

    public static function handle_reset_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_reset_email_templates');
        Psc_Email_Templates::reset();
        self::redirect('psc_email_templates', 'reset_all');
    }

    public static function page_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $templates = Psc_Email_Templates::get_all();
        $psc_msg   = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-email-templates.php';
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

    /* ---------------- Menus cantine ---------------- */

    public static function handle_save_menu() {
        self::guard('psc_save_menu');

        $id      = psc_post_int('id');
        $semaine = psc_post('semaine_debut');
        $jours   = array();
        foreach (Psc_Menus::JOURS as $jour) {
            // Pas psc_post() ici : sanitize_text_field() collapse les
            // retours à la ligne (conçu pour du texte sur une ligne). Ces
            // champs sont des textarea multi-lignes ; Psc_Menus::save()
            // applique déjà sanitize_textarea_field(), qui les préserve —
            // un seul point de sanitization, sur la bonne fonction.
            $jours[$jour] = isset($_POST[$jour]) ? wp_unslash($_POST[$jour]) : '';
        }

        $result = Psc_Menus::save($id, $semaine, $jours);
        if (is_wp_error($result)) {
            self::redirect('psc_menus', 'invalid');
        }
        self::redirect_to_menu($result, 'saved');
    }

    public static function handle_send_menu() {
        self::guard('psc_send_menu');

        $id   = psc_post_int('id');
        $menu = Psc_Menus::get($id);
        if (!$menu) self::redirect('psc_menus', 'invalid');

        $count = Psc_Menus::send($menu);
        self::redirect_to_menu($id, $count > 0 ? 'sent' : 'sent_zero');
    }

    public static function handle_delete_menu() {
        self::guard('psc_delete_menu');
        $id = psc_post_int('id');
        if ($id) Psc_Menus::delete($id);
        self::redirect('psc_menus', 'deleted');
    }

    private static function redirect_to_menu($id, $msg) {
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_menus', 'edit' => $id, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function page_menus() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $edit_id = psc_get_int('edit');
        $editing = $edit_id ? Psc_Menus::get($edit_id) : null;

        // Par défaut, semaine prochaine : ce formulaire sert à préparer le
        // menu à venir, pas à consulter l'historique (la liste ci-dessous
        // s'en charge).
        $default_week = $editing ? $editing->semaine_debut : psc_week_start(gmdate('Y-m-d', strtotime('+7 days')));

        $recent  = Psc_Menus::recent(12);
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        include PSC_PATH . 'templates/admin-menus.php';
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
}
