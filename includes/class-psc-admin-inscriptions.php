<?php
if (!defined('ABSPATH')) exit;

/**
 * Présences déclarées : consultation, correction par la mairie, export.
 */
class Psc_Admin_Inscriptions extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_admin_update_registrations', array(__CLASS__, 'handle_admin_update_registrations'));
        add_action('admin_post_psc_export_csv', array(__CLASS__, 'handle_export_csv'));
    }

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
            $t_cy     = psc_table('child_school_years');
            $year_id  = $trimestre ? (int) $trimestre->school_year_id : Psc_School_Years::active_id();
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, cy.classe AS classe
                 FROM $t_child c
                 LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
                 WHERE c.parent_id = %d ORDER BY c.nom",
                $year_id, $parent_id
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
                    if (!psc_is_valid_service($svc)) continue;
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

        $year_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT school_year_id FROM ' . psc_table('trimestres') . ' WHERE id = %d', $trimestre_id
        ));

        $t_reg = psc_table('registrations');
        $t_child = psc_table('children');
        $t_cy = psc_table('child_school_years');
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT r.jour_date, r.service, c.nom, c.prenom, cy.classe, p.email AS parent_email
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             LEFT JOIN " . psc_table('parents') . " p ON p.id = c.parent_id
             WHERE r.trimestre_id = %d ORDER BY c.nom, r.jour_date", $year_id, $trimestre_id
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
}
