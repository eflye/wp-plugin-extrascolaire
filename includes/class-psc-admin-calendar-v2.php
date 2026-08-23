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
        wp_enqueue_script('psc-admin-calendar-v2', PSC_URL . 'assets/js/admin-calendar-v2.js', array(), PSC_VERSION, true);
        wp_localize_script('psc-admin-calendar-v2', 'PSC_CAL_V2', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_calendar_v2'),
            'services' => psc_services(),
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
     * Trimestre couvrant $date, en priorisant le trimestre actif en cas de
     * chevauchement.
     *
     * Résolu en mémoire à partir des trimestres de la période, chargés en
     * une fois par build_days() : la grille en interroge trente-cinq, et
     * les poser un par un revenait à autant d'allers-retours pour une table
     * qui compte quelques lignes. L'ordre de la liste porte la priorité
     * (actif d'abord), comme le faisait le ORDER BY.
     */
    private static function trimestre_for_date($date, array $trimestres) {
        foreach ($trimestres as $t) {
            if ($t->date_debut <= $date && $t->date_fin >= $date) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Catégorise un jour intrinsèquement fermé (recalcule plutôt que de
     * parser le texte libre de calendar_days.label, qui peut être un motif
     * manuel quelconque) : week-end > mercredi > vacances/fermeture
     * manuelle > férié — même ordre de priorité que
     * Psc_Installer::generate_calendar_days(). Ne dépend d'aucun trimestre
     * ni de calendar_days : calculée uniquement à partir de la date et de
     * wp_psc_school_calendar, donc utilisable même pour un jour qui
     * n'appartient encore à aucun trimestre créé (vacances/week-ends visibles
     * dans la grille avant même l'ouverture du trimestre correspondant).
     * Retourne null si aucune de ces raisons ne s'applique (jour d'école
     * ordinaire potentiel).
     *
     * $school_calendar est la portion de wp_psc_school_calendar couvrant la
     * grille, indexée par date. Elle remplace trois requêtes par jour —
     * is_closed(), la lecture de `source`, puis label() — qui lisaient
     * chacune la même ligne.
     */
    private static function classify_closed_day($date, array $school_calendar) {
        if (psc_is_weekend($date)) {
            return array('category' => 'weekend', 'label' => 'Week-end');
        }
        if (psc_is_wednesday($date)) {
            return array('category' => 'wednesday', 'label' => 'Mercredi');
        }

        $row = isset($school_calendar[$date]) ? $school_calendar[$date] : null;
        if ($row && $row->is_closed) {
            return array(
                'category' => $row->source === 'manual' ? 'manual' : 'vacation',
                'label'    => $row->label ?: ($row->source === 'manual' ? 'Fermeture manuelle' : 'Vacances'),
            );
        }

        if (psc_is_holiday($date)) {
            return array('category' => 'holiday', 'label' => 'Férié');
        }
        return null;
    }

    /**
     * Construit les données de chaque jour de la grille : statut du jour
     * (hors trimestre / fermé avec sa catégorie / ouvert), et pour un jour
     * ouvert, le statut de chacune des 3 prestations (fermée ou non,
     * nombre d'inscriptions).
     */
    private static function build_days(array $dates) {
        global $wpdb;
        $start = $dates[0];
        $end   = end($dates);

        $t_reg = psc_table('registrations');
        $reg_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, service, COUNT(*) AS n FROM $t_reg WHERE jour_date BETWEEN %s AND %s GROUP BY jour_date, service",
            $start, $end
        ));
        $reg_counts = array();
        foreach ($reg_rows as $r) {
            $reg_counts[$r->jour_date][$r->service] = (int) $r->n;
        }

        $t_svc = psc_table('service_closures');
        $closure_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, service, label FROM $t_svc WHERE jour_date BETWEEN %s AND %s",
            $start, $end
        ));
        $closures = array();
        foreach ($closure_rows as $r) {
            $closures[$r->jour_date][$r->service] = $r->label;
        }

        // Calendrier scolaire de la période (vacances, fermetures
        // manuelles) : lu en une fois plutôt que trois fois par jour.
        $t_cal = psc_table('school_calendar');
        $cal_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, is_closed, source, label FROM $t_cal WHERE jour_date BETWEEN %s AND %s",
            $start, $end
        ));
        $school_calendar = array();
        foreach ($cal_rows as $r) {
            $school_calendar[$r->jour_date] = $r;
        }

        // Trimestres chevauchant la grille. Le tri porte la priorité
        // appliquée ensuite en mémoire : actif d'abord, puis le plus récent.
        $t_trim = psc_table('trimestres');
        $trimestres = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_trim WHERE date_debut <= %s AND date_fin >= %s
             ORDER BY active DESC, date_debut DESC",
            $end, $start
        ));

        // Jours de calendrier de la période, tous trimestres confondus.
        $t_days = psc_table('calendar_days');
        $day_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_days WHERE jour_date BETWEEN %s AND %s",
            $start, $end
        ));
        $calendar_days = array();
        foreach ($day_rows as $r) {
            $calendar_days[$r->trimestre_id . '|' . $r->jour_date] = $r;
        }

        $days = array();

        foreach ($dates as $date) {
            // Week-end, mercredi, vacances, jour férié ou fermeture manuelle
            // déjà enregistrée : affiché comme tel même si aucun trimestre
            // ne couvre encore cette date (l'admin voit les vacances/week-ends
            // à venir sans attendre la création du trimestre correspondant).
            $info = self::classify_closed_day($date, $school_calendar);
            if ($info !== null) {
                $days[$date] = array(
                    'date'     => $date,
                    'status'   => 'closed_day',
                    'category' => $info['category'],
                    'label'    => $info['label'],
                );
                continue;
            }

            $trimestre = self::trimestre_for_date($date, $trimestres);
            if (!$trimestre) {
                $days[$date] = array('date' => $date, 'status' => 'out_of_term');
                continue;
            }

            $key = $trimestre->id . '|' . $date;
            $cal = isset($calendar_days[$key]) ? $calendar_days[$key] : null;
            if (!$cal) {
                $days[$date] = array('date' => $date, 'status' => 'out_of_term');
                continue;
            }

            if (!$cal->is_open) {
                // Filet de sécurité : jour fermé dans calendar_days sans
                // raison détectée par classify_closed_day() (ne devrait pas
                // arriver en pratique, generate_calendar_days() suit les
                // mêmes règles), on retombe sur le libellé stocké.
                $days[$date] = array(
                    'date'         => $date,
                    'status'       => 'closed_day',
                    'category'     => 'manual',
                    'label'        => $cal->label ?: 'Fermé',
                    'trimestre_id' => $trimestre->id,
                );
                continue;
            }

            $services = array();
            foreach (Psc_School_Calendar::CLOSABLE_SERVICES as $code) {
                $services[$code] = array(
                    'closed' => isset($closures[$date][$code]),
                    'label'  => isset($closures[$date][$code]) ? $closures[$date][$code] : null,
                    'count'  => isset($reg_counts[$date][$code]) ? $reg_counts[$date][$code] : 0,
                );
            }

            $days[$date] = array(
                'date'         => $date,
                'status'       => 'open',
                'services'     => $services,
                'forf_count'   => isset($reg_counts[$date]['FORF']) ? $reg_counts[$date]['FORF'] : 0,
                'trimestre_id' => $trimestre->id,
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
        if (!$date || !in_array($service, Psc_School_Calendar::CLOSABLE_SERVICES, true)) {
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
        if (!$date || !in_array($service, Psc_School_Calendar::CLOSABLE_SERVICES, true)) {
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
        if (!$date || !in_array($service, Psc_School_Calendar::CLOSABLE_SERVICES, true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        Psc_School_Calendar::open_service($date, $service);
        wp_send_json_success();
    }
}
