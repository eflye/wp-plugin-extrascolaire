<?php
if (!defined('ABSPATH')) exit;

/**
 * Présences déclarées : consultation, correction par la mairie, export.
 *
 * L'unité est le MOIS de l'année scolaire (navigation mois par mois) — la
 * correction de la mairie écrit des exceptions (ajout / retrait), sans être
 * soumise au verrou de 48 h : elle corrige au dernier moment, comme elle
 * pouvait supprimer une ligne de l'ancienne table. La source de vérité est
 * unique (psc_is_declared) : ce que la mairie voit est exactement ce que la
 * facturation comptera.
 */
class Psc_Admin_Inscriptions extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_admin_update_registrations', array(__CLASS__, 'handle_admin_update_registrations'));
        add_action('admin_post_psc_export_csv', array(__CLASS__, 'handle_export_csv'));
    }

    public static function page_inscriptions() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;

        Psc_School_Year::ensure_default();
        $annee = Psc_School_Year::active();

        // Navigation mois par mois sur l'année scolaire.
        $months = Psc_School_Year::months();
        $month_keys = wp_list_pluck($months, 'key');
        $mois = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois) || !in_array($mois, $month_keys, true)) {
            $today = current_time('Y-m');
            $mois = in_array($today, $month_keys, true) ? $today : ($month_keys ? $month_keys[0] : '');
        }

        $parents   = Psc_Parents::all();
        $parent_id = psc_get_int('parent_id');
        $selected_parent = $parent_id ? Psc_Parents::get_by_id($parent_id) : null;

        $children = array();
        $month_dates = array();
        $declared    = array();
        $explicit    = array();
        $services    = psc_services();

        if ($selected_parent && $mois && $annee) {
            $t_child  = psc_table('children');
            $t_cy     = psc_table('child_school_years');
            $year_id  = Psc_School_Years::active_id();
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, cy.classe AS classe
                 FROM $t_child c
                 LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
                 WHERE c.parent_id = %d ORDER BY c.nom", $year_id, $parent_id
            ));

            $month_dates = Psc_School_Year::school_days_in_month($mois);
            Psc_School_Calendar::preload_closed($month_dates);
            if (!empty($children) && $month_dates) {
                $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
                $declared = Psc_Planning::declared_map($child_ids, $month_dates);
                $explicit = Psc_Planning::month_explicit_map($child_ids, $mois);
            }
        }

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-inscriptions.php';
    }

    /**
     * Correction de la mairie : le POST porte l'état complet des cases du
     * mois (regs[enfant][date][service]) pour la famille affichée. Le diff
     * avec l'état effectif (psc_is_declared) est appliqué en exceptions —
     * l'invariant d'écriture fait le reste — puis notifié à la famille.
     */
    public static function handle_admin_update_registrations() {
        self::guard('psc_admin_update_registrations');

        $parent_id = psc_post_int('parent_id');
        $mois      = psc_post('mois');
        if (!$parent_id || !preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_inscriptions', 'invalid');
        }

        $parent = Psc_Parents::get_by_id($parent_id);
        if (!$parent) self::redirect('psc_inscriptions', 'invalid');

        $dates = Psc_School_Year::school_days_in_month($mois);
        if (!$dates) self::redirect_to_inscriptions($parent_id, $mois, 'invalid');

        $children = array();
        global $wpdb;
        $t_child  = psc_table('children');
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom", $parent_id
        ));
        if (empty($children)) {
            self::redirect_to_inscriptions($parent_id, $mois, 'saved');
        }

        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $child_set = array_flip($child_ids);
        $dates_set = array_flip($dates);

        // État soumis (cases cochées du formulaire).
        $submitted = array();
        $regs_post = isset($_POST['regs']) && is_array($_POST['regs']) ? wp_unslash($_POST['regs']) : array();
        foreach ($regs_post as $cid => $by_date) {
            $cid = (int) $cid;
            if (!isset($child_set[$cid]) || !is_array($by_date)) continue;
            foreach ($by_date as $date => $svcs) {
                $date = sanitize_text_field((string) $date);
                if (!isset($dates_set[$date]) || !is_array($svcs)) continue;
                foreach ($svcs as $svc => $on) {
                    $svc = strtoupper(sanitize_key((string) $svc));
                    if (!psc_is_valid_service($svc)) continue;
                    $submitted[$cid . '|' . $date . '|' . $svc] = true;
                }
            }
        }

        // État effectif avant correction.
        $declared_before = Psc_Planning::declared_map($child_ids, $dates);
        $current = array();
        foreach ($declared_before as $cid => $by_date) {
            foreach ($by_date as $date => $by_svc) {
                foreach ($by_svc as $svc => $on) {
                    if ($on) $current[$cid . '|' . $date . '|' . $svc] = true;
                }
            }
        }

        $diff_added   = array_values(array_diff_key($submitted, $current));
        $diff_removed = array_values(array_diff_key($current, $submitted));

        // Application du diff : chaque triplet divergent reçoit une
        // exception — la mairie n'est pas soumise au verrou de 48 h.
        foreach ($diff_added as $key) {
            list($cid, $date, $svc) = explode('|', $key);
            Psc_Planning::toggle_exception((int) $cid, $date, $svc, true, true);
        }
        foreach ($diff_removed as $key) {
            list($cid, $date, $svc) = explode('|', $key);
            Psc_Planning::toggle_exception((int) $cid, $date, $svc, false, true);
        }

        if ($diff_added || $diff_removed) {
            Psc_Mailer::send_admin_correction($parent, Psc_School_Year::year_key_for_date($dates[0]), $children, psc_services(), $diff_added, $diff_removed);
        }

        self::redirect_to_inscriptions($parent_id, $mois, 'saved');
    }

    private static function redirect_to_inscriptions($parent_id, $mois, $msg) {
        wp_safe_redirect(add_query_arg(array(
            'page'         => 'psc_inscriptions',
            'parent_id'    => $parent_id,
            'mois'         => $mois,
            'psc_msg'      => $msg,
        ), admin_url('admin.php')));
        exit;
    }

    /**
     * Export CSV des présences déclarées : mois de l'année scolaire (toutes
     * familles), via la source de vérité unique. La mairie vise l'export
     * des effectifs prévisionnels par jour et par service — ce fichier est
     * la base révisable ; l'export dédié prestataire (commande cantine)
     * vit dans Psc_Supplier_Orders.
     */
    public static function handle_export_csv() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_export_csv');

        $mois = sanitize_text_field(wp_unslash($_GET['mois'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            wp_die(esc_html__('Mois invalide.', 'periscolaire-registration'));
        }

        global $wpdb;
        $dates = Psc_School_Year::school_days_in_month($mois);
        if (!$dates) {
            wp_die(esc_html__('Aucun jour d\'école ce mois-ci.', 'periscolaire-registration'));
        }

        $t_child = psc_table('children');
        $t_cy = psc_table('child_school_years');
        $year_id = Psc_School_Years::active_id();
        $children = $wpdb->get_results(
            "SELECT c.*, cy.classe, p.email AS parent_email
             FROM $t_child c
             LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = $year_id
             LEFT JOIN " . psc_table('parents') . " p ON p.id = c.parent_id
             WHERE c.statut = 'actif' AND p.active = 1
             ORDER BY c.nom, c.prenom"
        );
        if (!$children) {
            wp_die(esc_html__('Aucun enfant actif.', 'periscolaire-registration'));
        }

        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $declared = Psc_Planning::declared_map($child_ids, $dates);

        $filename = 'inscriptions-periscolaire-' . $mois . '-' . gmdate('Ymd') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, array(
            __('Nom', 'periscolaire-registration'),
            __('Prénom', 'periscolaire-registration'),
            __('Classe', 'periscolaire-registration'),
            __('Contact parent', 'periscolaire-registration'),
            __('Date', 'periscolaire-registration'),
            __('Jour', 'periscolaire-registration'),
            __('Service', 'periscolaire-registration'),
        ), ';');
        foreach ($children as $row) {
            foreach ($dates as $date) {
                foreach (psc_billing_services(isset($declared[$row->id][$date]) ? $declared[$row->id][$date] : array()) as $svc) {
                    // psc_csv_escape() neutralise les formules Excel : les noms
                    // proviennent d'une saisie parent, donc de données non fiables.
                    fputcsv($out, array(
                        psc_csv_escape($row->nom),
                        psc_csv_escape($row->prenom),
                        psc_csv_escape($row->classe),
                        psc_csv_escape($row->parent_email),
                        $date,
                        psc_day_label($date),
                        $svc,
                    ), ';');
                }
            }
        }
        fclose($out);
        exit;
    }
}
