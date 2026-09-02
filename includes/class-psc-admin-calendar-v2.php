<?php
if (!defined('ABSPATH')) exit;

/**
 * "Calendrier scolaire en cours" (slug psc_school_calendar_v2, inchangé)
 * — vue visuelle mois/semaine (façon Google Calendar) du calendrier
 * scolaire, avec le statut de chaque jour ET de chacune des 3 prestations
 * du jour (garderie matin, cantine, garderie soir), fermables/réouvrables
 * individuellement en plus de la fermeture du jour entier déjà proposée
 * par la page "Années scolaires". Classe volontairement isolée (page,
 * assets, endpoints AJAX propres) pour limiter les risques de régression
 * sur Psc_Admin — seul l'enregistrement du menu vit dans Psc_Admin::menu()
 * pour pouvoir contrôler sa position dans le sous-menu Périscolaire.
 */
class Psc_Admin_Calendar_V2 {

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));

        add_action('wp_ajax_psc_cal_v2_preview_close_day', array(__CLASS__, 'ajax_preview_close_day'));
        add_action('wp_ajax_psc_cal_v2_close_day', array(__CLASS__, 'ajax_close_day'));
        add_action('wp_ajax_psc_cal_v2_open_day', array(__CLASS__, 'ajax_open_day'));
        add_action('wp_ajax_psc_cal_v2_preview_close_service', array(__CLASS__, 'ajax_preview_close_service'));
        add_action('wp_ajax_psc_cal_v2_close_service', array(__CLASS__, 'ajax_close_service'));
        add_action('wp_ajax_psc_cal_v2_open_service', array(__CLASS__, 'ajax_open_service'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_school_calendar_v2') === false) return;
        wp_enqueue_style('psc-admin', PSC_URL . 'assets/css/admin.css', array(), PSC_VERSION);
        wp_enqueue_style('psc-admin-calendar-v2', PSC_URL . 'assets/css/admin-calendar-v2.css', array('psc-admin'), PSC_VERSION);
        // Mécanique AJAX commune (assets/js/psc-ajax.js) : déclarée en
        // dépendance pour que WordPress garantisse l'ordre de chargement.
        wp_enqueue_script('psc-ajax', PSC_URL . 'assets/js/psc-ajax.js', array(), PSC_VERSION, true);
        wp_enqueue_script('psc-admin-calendar-v2', PSC_URL . 'assets/js/admin-calendar-v2.js', array('psc-ajax'), PSC_VERSION, true);
        wp_localize_script('psc-admin-calendar-v2', 'PSC_CAL_V2', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_calendar_v2'),
            'services' => psc_services(),
            // Idem : la liste des prestations élémentaires vient du serveur.
            'unit_services' => psc_unit_services(),
            // Libellés du menu contextuel et des popins de confirmation,
            // traduits côté serveur (%s remplacés côté navigateur).
            'i18n' => array(
                'ajax_error'            => __('Une erreur est survenue, merci de réessayer.', 'periscolaire-registration'),
                'reopen_day'            => __('Réouvrir ce jour', 'periscolaire-registration'),
                'reopen_date'           => __('Réouvrir le %s ?', 'periscolaire-registration'),
                'close_all_day'         => __('Fermer tout le jour', 'periscolaire-registration'),
                'reopen_service'        => __('Réouvrir %s', 'periscolaire-registration'),
                'reopen_service_date'   => __('Réouvrir %s le %s ?', 'periscolaire-registration'),
                'close_service'         => __('Fermer %s', 'periscolaire-registration'),
                'close_date'            => __('Fermer le %s', 'periscolaire-registration'),
                'close_service_date'    => __('Fermer %s le %s ?', 'periscolaire-registration'),
                // Le planning étant calculé (rythme + exceptions), rien
                // n'est supprimé en base : la fermeture soustrait le jour
                // au calcul — non facturé, absent des listes.
                'preview_day'           => __('%s déclaration(s) de %s famille(s) passeront à « non déclaré » ce jour-là. Ces prestations ne seront pas facturées, et chaque famille recevra un e-mail.', 'periscolaire-registration'),
                'no_registrations'      => __('Aucune déclaration ce jour-là.', 'periscolaire-registration'),
                'preview_service_direct' => __('%s déclaration(s) de %s passeront à « non déclaré » (%s famille(s), non facturées).', 'periscolaire-registration'),
                'preview_service_forf'  => __('%s enfant(s) en forfait journée (%s famille(s)) seront déclassés vers les prestations restantes ce jour-là.', 'periscolaire-registration'),
            ),
        ));
    }

    /* ------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------ */

    public static function page_calendar_v2() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $view = (isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'week') ? 'week' : 'month';

        if ($view === 'week') {
            $week_param = isset($_GET['week']) ? psc_valid_date(sanitize_text_field(wp_unslash($_GET['week']))) : false;
            $week_start = $week_param ?: gmdate('Y-m-d');
            $dates      = self::week_dates($week_start);
            $month      = null;
        } else {
            $month_raw = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : '';
            $month     = preg_match('/^\d{4}-\d{2}$/', $month_raw) ? $month_raw : gmdate('Y-m');
            $dates     = self::month_dates($month);
            $week_start = null;
        }

        $days          = self::build_days($dates);
        $services_meta = psc_services();

        include PSC_PATH . 'templates/admin-calendar-v2.php';
    }

    /* ------------------------------------------------------------------
     * Construction de la grille
     * ------------------------------------------------------------------ */

    /** Toutes les dates (Y-m-d) de la grille mois (semaines complètes lundi-dimanche encadrant le mois). */
    public static function month_dates($month) {
        $first = DateTime::createFromFormat('Y-m-d', $month . '-01');
        $first->setTime(0, 0);

        $grid_start = clone $first;
        $dow = (int) $grid_start->format('N');
        $grid_start->modify('-' . ($dow - 1) . ' days');

        $last = clone $first;
        $last->modify('last day of this month');
        $grid_end = clone $last;
        $dow_end = (int) $grid_end->format('N');
        $grid_end->modify('+' . (7 - $dow_end) . ' days');

        $dates = array();
        $cursor = clone $grid_start;
        while ($cursor <= $grid_end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        return $dates;
    }

    /** Les 7 dates (Y-m-d) de la semaine lundi-dimanche contenant $date. */
    public static function week_dates($date) {
        $start = new DateTime($date);
        $dow = (int) $start->format('N');
        $start->modify('-' . ($dow - 1) . ' days');

        $dates = array();
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $start->format('Y-m-d');
            $start->modify('+1 day');
        }
        return $dates;
    }

    /**
     * Catégorisation intégrée à build_days() : week-end > mercredi >
     * vacances (configurées ou importées) > férié > fermeture manuelle —
     * cf. Psc_School_Year::day_status(), qui porte maintenant la règle.
     */

    /**
     * Construit les données de chaque jour de la grille : statut du jour
     * (hors année scolaire / fermé avec sa catégorie / ouvert), et pour un
     * jour ouvert, le statut de chacune des prestations (fermée ou non,
     * effectif déclaré). Les jours d'école sont CALCULÉS (lundi, mardi,
     * jeudi, vendredi, moins vacances et fériés) — plus aucune ligne de
     * calendrier stockée ; les effectifs viennent de la source de vérité
     * unique (psc_is_declared), en un lot pour toute la grille.
     */
    private static function build_days(array $dates) {
        global $wpdb;
        $start = $dates[0];
        $end   = end($dates);

        $t_svc = psc_table('service_closures');
        $closure_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, service, label FROM $t_svc WHERE jour_date BETWEEN %s AND %s",
            $start, $end
        ));
        $closures = array();
        foreach ($closure_rows as $r) {
            $closures[$r->jour_date][$r->service] = $r->label;
        }

        // Calendrier scolaire de la période (vacances importées, fermetures
        // manuelles) : lu en une fois, pour les libellés affichés.
        $t_cal = psc_table('school_calendar');
        $cal_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, is_closed, source, label FROM $t_cal WHERE jour_date BETWEEN %s AND %s",
            $start, $end
        ));
        $school_calendar = array();
        foreach ($cal_rows as $r) {
            $school_calendar[$r->jour_date] = $r;
        }

        // Effectifs déclarés de la période : un seul lot de résolution.
        $children = $wpdb->get_results(
            "SELECT id FROM " . psc_table('children') . " WHERE statut = 'actif'"
        );
        $child_ids = $children ? array_map(function ($c) { return (int) $c->id; }, $children) : array();
        $declared = $child_ids ? Psc_Planning::declared_map($child_ids, $dates) : array();
        $forf = psc_forfait_code();

        $counts = array();
        foreach ($dates as $date) {
            $counts[$date] = array('GM' => 0, 'CANT' => 0, 'GS' => 0, 'FORF' => 0);
            foreach ($child_ids as $cid) {
                $day = isset($declared[$cid][$date]) ? $declared[$cid][$date] : array();
                foreach (psc_billing_services($day) as $svc) {
                    $counts[$date][$svc] = ($counts[$date][$svc] ?? 0) + 1;
                }
            }
        }

        $days = array();

        foreach ($dates as $date) {
            // Hors année scolaire (été, années non configurées) : aucune
            // déclaration possible, affiché comme tel.
            $in_year = false;
            foreach (Psc_School_Year::all() as $y) {
                if ($date >= $y->date_start && $date <= $y->date_end) { $in_year = true; break; }
            }
            if (!$in_year) {
                $days[$date] = array('date' => $date, 'status' => 'out_of_term');
                continue;
            }

            // Week-end, mercredi, vacances (configurées ou importées), jour
            // férié ou fermeture manuelle : affiché comme tel.
            $status = Psc_School_Year::day_status($date);
            if ($status !== 'school') {
                $label = '';
                $category = $status;
                if ($status === 'weekend') {
                    $label = __('Week-end', 'periscolaire-registration');
                } elseif ($status === 'wednesday') {
                    $label = __('Mercredi', 'periscolaire-registration');
                } elseif ($status === 'holiday') {
                    $label = __('Férié', 'periscolaire-registration');
                } elseif ($status === 'vacation') {
                    $label = __('Vacances', 'periscolaire-registration');
                } elseif ($status === 'closed') {
                    // Libellé de la fermeture manuelle, si elle en porte un.
                    $row = isset($school_calendar[$date]) ? $school_calendar[$date] : null;
                    $label = $row && $row->label ? $row->label : __('Fermeture exceptionnelle', 'periscolaire-registration');
                }
                $days[$date] = array(
                    'date'     => $date,
                    'status'   => 'closed_day',
                    'category' => $category,
                    'label'    => $label,
                );
                continue;
            }

            $services = array();
            foreach (psc_unit_services() as $code) {
                $services[$code] = array(
                    'closed' => isset($closures[$date][$code]),
                    'label'  => isset($closures[$date][$code]) ? $closures[$date][$code] : null,
                    'count'  => $counts[$date][$code],
                );
            }

            $days[$date] = array(
                'date'       => $date,
                'status'     => 'open',
                'services'   => $services,
                'forf_count' => $counts[$date][$forf],
            );
        }

        return $days;
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    private static function ajax_guard() {
        if (!psc_user_can_manage()) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }
        check_ajax_referer('psc_calendar_v2', 'nonce');
    }

    public static function ajax_preview_close_day() {
        self::ajax_guard();
        $date = psc_valid_date(psc_post('date'));
        if (!$date) wp_send_json_error(array('code' => 'invalid_date'), 400);

        $affected = Psc_School_Calendar::affected_families($date);
        wp_send_json_success(array(
            'registrations' => $affected['registrations'],
            'families'      => count($affected['families']),
        ));
    }

    public static function ajax_close_day() {
        self::ajax_guard();
        $date  = psc_valid_date(psc_post('date'));
        $label = psc_post('label');
        if (!$date) wp_send_json_error(array('code' => 'invalid_date'), 400);

        $result = Psc_School_Calendar::close_day($date, $label);
        if (is_wp_error($result)) wp_send_json_error(array('code' => $result->get_error_code()), 400);

        wp_send_json_success();
    }

    public static function ajax_open_day() {
        self::ajax_guard();
        $date = psc_valid_date(psc_post('date'));
        if (!$date) wp_send_json_error(array('code' => 'invalid_date'), 400);

        Psc_School_Calendar::open_day($date);
        wp_send_json_success();
    }

    public static function ajax_preview_close_service() {
        self::ajax_guard();
        $date    = psc_valid_date(psc_post('date'));
        $service = psc_post('service');
        if (!$date || !in_array($service, psc_unit_services(), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $affected = Psc_School_Calendar::affected_families_for_service($date, $service);
        wp_send_json_success(array(
            'direct_registrations' => $affected['direct']['registrations'],
            'direct_families'      => count($affected['direct']['families']),
            'forf_registrations'   => $affected['forf']['registrations'],
            'forf_families'        => count($affected['forf']['families']),
        ));
    }

    public static function ajax_close_service() {
        self::ajax_guard();
        $date    = psc_valid_date(psc_post('date'));
        $service = psc_post('service');
        $label   = psc_post('label');
        if (!$date || !in_array($service, psc_unit_services(), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $result = Psc_School_Calendar::close_service($date, $service, $label);
        if (is_wp_error($result)) wp_send_json_error(array('code' => $result->get_error_code()), 400);

        wp_send_json_success();
    }

    public static function ajax_open_service() {
        self::ajax_guard();
        $date    = psc_valid_date(psc_post('date'));
        $service = psc_post('service');
        if (!$date || !in_array($service, psc_unit_services(), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        Psc_School_Calendar::open_service($date, $service);
        wp_send_json_success();
    }
}
