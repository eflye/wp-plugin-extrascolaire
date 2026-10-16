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
        add_action('init', array(__CLASS__, 'maybe_handle_unsubscribe'), 5);
    }

    /**
     * Lien « Ne plus recevoir les menus par e-mail » (?psc_menu_unsub=ID&t=JETON).
     *
     * GET n'écrit rien (les passerelles de sécurité des messageries suivent
     * les liens) : il affiche une page de confirmation dont le bouton renvoie
     * en POST vers la même adresse. Un POST valide désinscrit : c'est aussi
     * la désinscription en un clic des messageries (RFC 8058, en-tête
     * List-Unsubscribe-Post), qui ne peut pas porter de nonce — le jeton
     * signé fait office d'autorisation (cf. Psc_Menus::unsubscribe_token()).
     */
    public static function maybe_handle_unsubscribe() {
        if (!isset($_GET['psc_menu_unsub'])) return;
        $title = __('Menus de la cantine par e-mail', 'periscolaire-registration');
        $portal = '<p><a href="' . esc_url(add_query_arg('psc_tab', 'profil', Psc_Mailer::form_page_url())) . '">' . esc_html__('Aller à « Mon profil » dans l’espace famille', 'periscolaire-registration') . '</a></p>';

        if (!psc_rate_limit_by_ip('menu_unsub_', 30, HOUR_IN_SECONDS)) {
            self::unsubscribe_page($title, '<p>' . esc_html__('Trop de tentatives. Réessayez plus tard.', 'periscolaire-registration') . '</p>', 429);
        }

        $token  = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
        $parent = Psc_Menus::parent_from_unsubscribe(absint($_GET['psc_menu_unsub']), $token);
        if (!$parent) {
            self::unsubscribe_page($title, '<p>' . esc_html__('Ce lien de désinscription n’est plus valable (l’adresse e-mail du compte a peut-être changé). Vous pouvez choisir de recevoir ou non les menus depuis « Mon profil » dans votre espace famille.', 'periscolaire-registration') . '</p>' . $portal, 404);
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method === 'POST') {
            if (!Psc_Menus::unsubscribe($parent)) {
                self::unsubscribe_page($title, '<p>' . esc_html__('L’enregistrement a échoué. Merci de réessayer dans quelques instants.', 'periscolaire-registration') . '</p>', 500);
            }
            self::unsubscribe_page($title, '<p role="status" data-testid="menu-unsub-done">' . esc_html__('C’est noté : vous ne recevrez plus le menu de la cantine par e-mail. Il reste consultable dans l’onglet « Menu » de votre espace famille, et vous pouvez réactiver l’envoi à tout moment depuis « Mon profil ».', 'periscolaire-registration') . '</p>' . $portal);
        }

        if ((int) ($parent->menu_emails ?? 1) === 0) {
            self::unsubscribe_page($title, '<p data-testid="menu-unsub-already">' . esc_html__('Vous ne recevez déjà plus le menu de la cantine par e-mail. Vous pouvez réactiver l’envoi depuis « Mon profil » dans votre espace famille.', 'periscolaire-registration') . '</p>' . $portal);
        }

        $action = add_query_arg(array('psc_menu_unsub' => (int) $parent->id, 't' => $token), home_url('/'));
        $form = '<p>' . esc_html__('Vous ne recevrez plus le menu de la cantine par e-mail chaque semaine. Il restera consultable dans l’onglet « Menu » de votre espace famille.', 'periscolaire-registration') . '</p>'
            . '<form method="post" action="' . esc_url($action) . '">'
            . '<p><button type="submit" class="button button-primary" data-testid="menu-unsub-confirm">' . esc_html__('Ne plus recevoir les menus par e-mail', 'periscolaire-registration') . '</button></p>'
            . '</form>';
        self::unsubscribe_page($title, $form);
    }

    /** Page autonome, sans cache ni Referer (le jeton est dans l'URL). */
    private static function unsubscribe_page($title, $html, $status = 200) {
        nocache_headers();
        if (!headers_sent()) header('Referrer-Policy: no-referrer');
        wp_die(
            '<h1>' . esc_html($title) . '</h1>' . wp_kses($html, array(
                'p' => array('role' => array(), 'data-testid' => array()), 'a' => array('href' => array()),
                'form' => array('method' => array(), 'action' => array()),
                'button' => array('type' => array(), 'class' => array(), 'data-testid' => array()),
            )),
            esc_html($title),
            array('response' => (int) $status)
        );
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
            'origine_viande'  => Psc_Menus::meat_origin(Psc_Menus::get_by_week($menu_week)),
            'prev_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $prev_week)), $base),
            'next_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $next_week)), $base),
            'reset_url'       => $extra_args ? add_query_arg($extra_args, $base) : $base,
        );
    }

    public static function portal_menu_data($week_override = null, $base_url_override = null) {
        return self::menu_nav_data(array('psc_tab' => 'menu'), $week_override, $base_url_override);
    }

    public static function guest_menu_data($week_override = null, $base_url_override = null) {
        return self::menu_nav_data(array(), $week_override, $base_url_override);
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
