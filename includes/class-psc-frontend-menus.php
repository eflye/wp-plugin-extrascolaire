<?php
if (!defined('ABSPATH')) exit;

/**
 * Menus de la cantine côté famille : onglet « Menu de la semaine » du
 * portail connecté et widget de la vue invité partagent les mêmes
 * données et la même navigation par semaine. Les lecteurs de données
 * (menu_days_for_week, week_has_school_day) sont publics : le noyau du
 * portail les réutilise pour le résumé du tableau de bord.
 */
class Psc_Frontend_Menus extends Psc_Frontend_Base {

    public static function init() {
        add_action('wp_ajax_nopriv_psc_menu_week', array(__CLASS__, 'ajax_menu_week'));
        add_action('wp_ajax_psc_menu_week', array(__CLASS__, 'ajax_menu_week'));
    }

    /**
     * Vrai si la semaine (lundi donné) contient au moins un jour d'école
     * réel — distingue "vacances scolaires" de "semaine d'école dont le
     * menu n'a pas encore été saisi", deux états à ne pas confondre dans
     * l'affichage (une famille ne doit pas croire qu'un menu manque alors
     * que l'école est simplement fermée).
     */
    public static function week_has_school_day($monday) {
        foreach (Psc_Menus::JOUR_OFFSETS as $offset) {
            $day_date = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
            if (psc_is_school_day($day_date)) return true;
        }
        return false;
    }

    /**
     * Jours du menu (parmi lundi/mardi/jeudi/vendredi) pour la semaine
     * donnée. Tableau vide si l'école est fermée toute la semaine, ou si
     * la semaine est scolaire mais que le menu n'a pas encore été saisi
     * (cf. week_has_school_day() pour distinguer les deux côté affichage).
     */
    public static function menu_days_for_week($monday) {
        if (!self::week_has_school_day($monday)) return array();

        $menu = Psc_Menus::get_by_week($monday);
        if (!$menu) return array();

        $days_out = array();
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            $d_offset = Psc_Menus::JOUR_OFFSETS[$key];
            $d_date = gmdate('Y-m-d', strtotime($monday . " +{$d_offset} days"));
            if (!psc_is_school_day($d_date)) continue;
            $content = trim((string) $menu->$key);
            if ($content === '') continue;
            $days_out[] = array('day' => $label, 'dish' => $content);
        }
        return $days_out;
    }

    /**
     * Libellé "Semaine du 8 au 12 juin 2026" (jours d'école réels,
     * lundi à vendredi) — distinct du format du widget public (une seule
     * date), copie exacte de la formulation du handoff.
     */
    protected static function week_range_label($monday) {
        $friday = gmdate('Y-m-d', strtotime($monday . ' +4 days'));
        $start_day = date_i18n('j', strtotime($monday));
        $end_day   = date_i18n('j', strtotime($friday));
        $end_month = date_i18n('F', strtotime($friday));
        $end_year  = date_i18n('Y', strtotime($friday));

        if (date('Y-m', strtotime($monday)) === date('Y-m', strtotime($friday))) {
            return sprintf(__('Semaine du %s au %s %s %s', 'periscolaire-registration'), $start_day, $end_day, $end_month, $end_year);
        }
        $start_month = date_i18n('F', strtotime($monday));
        $start_year  = date_i18n('Y', strtotime($monday));
        if ($start_year !== $end_year) {
            return sprintf(__('Semaine du %s %s %s au %s %s %s', 'periscolaire-registration'), $start_day, $start_month, $start_year, $end_day, $end_month, $end_year);
        }
        return sprintf(__('Semaine du %s %s au %s %s %s', 'periscolaire-registration'), $start_day, $start_month, $end_day, $end_month, $end_year);
    }

    /**
     * Données de navigation du menu de cantine par semaine — partagées
     * entre l'onglet "Menu de la semaine" du portail connecté ($extra_args
     * = psc_tab=menu) et le widget public de la vue invité (aucun argument
     * supplémentaire). $week_override permet à l'appel AJAX (ajax_menu_week)
     * de demander une semaine précise sans passer par $_GET — la requête
     * initiale (page complète) continue de lire ?psc_semaine dans l'URL.
     * $base_url_override : nécessaire pour ce même appel AJAX — sans lui,
     * add_query_arg()/remove_query_arg() prendraient par défaut l'URL de la
     * requête en cours (admin-ajax.php), et les liens ←/→ renvoyés
     * pointeraient vers admin-ajax.php au lieu de la page famille.
     */
    protected static function menu_nav_data($extra_args = array(), $week_override = null, $base_url_override = null) {
        if ($week_override !== null) {
            $requested = $week_override;
        } else {
            $requested = isset($_GET['psc_semaine']) ? sanitize_text_field(wp_unslash($_GET['psc_semaine'])) : '';
        }
        $menu_week = $requested ? psc_week_start($requested) : false;
        if (!$menu_week) {
            $menu_week = psc_week_start(current_time('Y-m-d'));
        }

        $days = self::menu_days_for_week($menu_week);

        $prev_week = gmdate('Y-m-d', strtotime($menu_week . ' -7 days'));
        $next_week = gmdate('Y-m-d', strtotime($menu_week . ' +7 days'));
        $base = $base_url_override !== null
            ? remove_query_arg(array('psc_semaine', 'psc_msg'), $base_url_override)
            : remove_query_arg(array('psc_semaine', 'psc_msg'));

        return array(
            'week_label'      => self::week_range_label($menu_week),
            'is_current_week' => ($menu_week === psc_week_start(current_time('Y-m-d'))),
            'has_content'     => !empty($days),
            'no_school_week'  => !self::week_has_school_day($menu_week),
            'days'            => $days,
            'prev_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $prev_week)), $base),
            'next_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $next_week)), $base),
            'reset_url'       => $extra_args ? add_query_arg($extra_args, $base) : $base,
        );
    }

    public static function portal_menu_data($week_override = null, $base_url_override = null) {
        return self::menu_nav_data(array('psc_tab' => 'menu'), $week_override, $base_url_override);
    }

    public static function guest_menu_data() {
        return self::menu_nav_data();
    }

    /**
     * Navigation par semaine du menu (onglet "Menu de la semaine", portail
     * connecté) sans rechargement de page : ne renvoie que le HTML du bloc
     * (nav + tableau/message), identique à templates/portal-menu-block.php
     * inclus lors du rendu complet de la page — même données, même charte,
     * seul le mécanisme de chargement change.
     */
    public static function ajax_menu_week() {
        check_ajax_referer('psc_front', 'nonce');

        $semaine = psc_post('semaine');
        $psc_portal_menu = self::portal_menu_data($semaine !== '' ? $semaine : false, Psc_Mailer::form_page_url());

        ob_start();
        include PSC_PATH . 'templates/portal-menu-block.php';
        $html = ob_get_clean();

        wp_send_json_success(array('html' => $html));
    }
}
