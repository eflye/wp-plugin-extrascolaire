<?php
if (!defined('ABSPATH')) exit;

/**
 * Années scolaires, calendrier officiel et passage d'année.
 *
 * Une seule page les réunit (psc_school_years) : créer une année, en
 * importer le calendrier et faire monter les enfants de classe sont trois
 * moments du même geste annuel.
 */
class Psc_Admin_School_Years extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_add_school_year', array(__CLASS__, 'handle_add_school_year'));
        add_action('admin_post_psc_activate_school_year', array(__CLASS__, 'handle_activate_school_year'));
        add_action('admin_post_psc_archive_school_year', array(__CLASS__, 'handle_archive_school_year'));
        add_action('admin_post_psc_update_school_year', array(__CLASS__, 'handle_update_school_year'));
        add_action('admin_post_psc_delete_school_year', array(__CLASS__, 'handle_delete_school_year'));
        add_action('admin_post_psc_stage_promotion', array(__CLASS__, 'handle_stage_promotion'));
        add_action('admin_post_psc_confirm_promotion', array(__CLASS__, 'handle_confirm_promotion'));
        add_action('admin_post_psc_cancel_promotion', array(__CLASS__, 'handle_cancel_promotion'));
        add_action('admin_post_psc_import_school_calendar', array(__CLASS__, 'handle_import_school_calendar'));
        add_action('admin_post_psc_upload_school_calendar', array(__CLASS__, 'handle_upload_school_calendar'));
        add_action('admin_post_psc_close_school_day', array(__CLASS__, 'handle_close_school_day'));
        add_action('admin_post_psc_cancel_school_day_close', array(__CLASS__, 'handle_cancel_school_day_close'));
        add_action('admin_post_psc_open_school_day', array(__CLASS__, 'handle_open_school_day'));
    }

    public static function handle_add_school_year() {
        self::guard('psc_add_school_year');
        $result = Psc_School_Years::create(psc_post('label'), psc_post('date_debut'), psc_post('date_fin'));
        if (is_wp_error($result)) self::redirect('psc_school_years', $result->get_error_code());
        self::redirect('psc_school_years', 'created');
    }

    public static function handle_activate_school_year() {
        self::guard('psc_activate_school_year');
        if (!Psc_School_Years::activate(psc_post_int('id'))) self::redirect('psc_school_years', 'invalid');
        self::redirect('psc_school_years', 'activated');
    }

    public static function handle_archive_school_year() {
        self::guard('psc_archive_school_year');
        if (!Psc_School_Years::archive(psc_post_int('id'))) self::redirect('psc_school_years', 'invalid');
        self::redirect('psc_school_years', 'archived');
    }

    public static function handle_update_school_year() {
        self::guard('psc_update_school_year');
        $result = Psc_School_Years::update(psc_post_int('id'), psc_post('label'), psc_post('date_debut'), psc_post('date_fin'));
        if (is_wp_error($result)) self::redirect('psc_school_years', $result->get_error_code());
        self::redirect('psc_school_years', 'updated');
    }

    public static function handle_delete_school_year() {
        self::guard('psc_delete_school_year');
        $result = Psc_School_Years::delete(psc_post_int('id'));
        if (is_wp_error($result)) self::redirect('psc_school_years', $result->get_error_code());
        self::redirect('psc_school_years', 'year_deleted');
    }

    /**
     * Étape 1 du passage d'année : calcule le plan de montée de classe et
     * le met en attente (transient), sans rien écrire — l'admin voit un
     * récapitulatif et peut corriger des lignes avant de confirmer. Même
     * principe que la fermeture d'un jour de calendrier avec inscriptions
     * existantes (handle_close_school_day()).
     */
    public static function handle_stage_promotion() {
        self::guard('psc_stage_promotion');

        $from_year_id = psc_post_int('from_year_id');
        $to_year_id   = psc_post_int('to_year_id');
        if (!$from_year_id || !$to_year_id || $from_year_id === $to_year_id) {
            self::redirect('psc_school_years', 'invalid');
        }

        $plan = Psc_School_Years::build_promotion_plan($from_year_id, $to_year_id);
        Psc_School_Years::stage_promotion($from_year_id, $to_year_id, $plan);

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_passage_annee'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Étape 2 : écrit le plan (avec corrections éventuelles ligne par
     * ligne, envoyées comme classe_{child_id}) une fois confirmé
     * explicitement par l'admin.
     */
    public static function handle_confirm_promotion() {
        self::guard('psc_confirm_promotion');

        $staged = Psc_School_Years::staged_promotion();
        if (!$staged) self::redirect('psc_school_years', 'invalid');

        $overrides = array();
        foreach ($staged['plan'] as $row) {
            $key = 'classe_' . $row['child_id'];
            if (isset($_POST[$key])) {
                $overrides[$row['child_id']] = sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }

        Psc_School_Years::apply_promotion($staged['to_year_id'], $staged['plan'], $overrides);
        Psc_School_Years::clear_staged_promotion();
        self::redirect('psc_school_years', 'promoted');
    }

    public static function handle_cancel_promotion() {
        self::guard('psc_cancel_promotion');
        Psc_School_Years::clear_staged_promotion();
        self::redirect('psc_school_years', 'promotion_cancelled');
    }

    public static function page_school_years() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $years = Psc_School_Years::all();

        $existing_ranges = array();
        foreach ($years as $y) {
            $existing_ranges[$y->date_debut . '|' . $y->date_fin] = true;
        }
        $candidates = Psc_School_Calendar::candidate_school_years();
        foreach ($candidates as &$c) {
            $c['exists'] = isset($existing_ranges[$c['date_debut'] . '|' . $c['date_fin']]);
        }
        unset($c);

        $pending = get_transient(self::pending_close_key());
        $pending_affected = $pending ? Psc_School_Calendar::affected_families_range($pending['date_debut'], $pending['date_fin']) : null;

        $imported_at = get_option('psc_school_calendar_imported_at', '');
        $imported_n  = psc_get_int('n');
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        // "Jours fermés" n'a de valeur qu'en sortie immédiate d'un import
        // (log de ce qui vient d'être chargé) : afficher en permanence la
        // liste complète (potentiellement des milliers de lignes) n'apporte
        // rien au quotidien et coûte une requête à chaque chargement de page.
        $groups = array();
        if (in_array($psc_msg, array('imported', 'uploaded'), true)) {
            $groups = self::group_closed_days(Psc_School_Calendar::all());
        }

        include PSC_PATH . 'templates/admin-annees.php';
    }

    public static function page_passage_annee() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $staged = Psc_School_Years::staged_promotion();
        $from_year = $staged ? Psc_School_Years::get($staged['from_year_id']) : null;
        $to_year   = $staged ? Psc_School_Years::get($staged['to_year_id']) : null;
        $plan      = $staged ? $staged['plan'] : array();
        $classe_options = Psc_School_Years::classe_options();
        include PSC_PATH . 'templates/admin-passage-annee.php';
    }

    protected static function pending_close_key() {
        return 'psc_pending_close_' . get_current_user_id();
    }

    public static function handle_import_school_calendar() {
        self::guard('psc_import_school_calendar');

        $result = Psc_School_Calendar::import();
        if (is_wp_error($result)) {
            self::redirect('psc_school_years', 'import_failed');
        }
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_school_years', 'psc_msg' => 'imported', 'n' => (int) $result),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Import manuel d'un fichier .ics fourni par l'admin — palliatif quand
     * le serveur n'a pas d'accès sortant vers le flux du ministère.
     */
    public static function handle_upload_school_calendar() {
        self::guard('psc_upload_school_calendar');

        if (empty($_FILES['ics_file']) || !isset($_FILES['ics_file']['error']) || $_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
            self::redirect('psc_school_years', 'upload_failed');
        }

        $file     = $_FILES['ics_file'];
        $filetype = wp_check_filetype($file['name'], array('ics' => 'text/calendar'));
        if ($filetype['ext'] !== 'ics') {
            self::redirect('psc_school_years', 'upload_invalid_type');
        }
        if ($file['size'] > 2 * MB_IN_BYTES) {
            self::redirect('psc_school_years', 'upload_too_large');
        }

        $body = file_get_contents($file['tmp_name']);
        $result = Psc_School_Calendar::import_from_upload($body);
        if (is_wp_error($result)) {
            self::redirect('psc_school_years', 'upload_failed');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_school_years', 'psc_msg' => 'uploaded', 'n' => (int) $result),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Fermeture manuelle d'un jour ou d'une plage de dates (formation des
     * enseignants, vacances scolaires, fermeture exceptionnelle...). Si des
     * inscriptions existent déjà sur la période, on n'exécute rien au
     * premier passage : on stocke la demande et on redirige vers un écran
     * d'avertissement. Ce n'est qu'après confirmation explicite (confirm=1)
     * que la fermeture, la suppression des inscriptions et l'e-mail aux
     * familles ont lieu.
     */
    public static function handle_close_school_day() {
        self::guard('psc_close_school_day');

        $date_debut = psc_valid_date(psc_post('date_debut'));
        $date_fin   = psc_valid_date(psc_post('date_fin')) ?: $date_debut;
        $label      = psc_post('label');
        $confirm    = psc_post_int('confirm');

        if (!$date_debut || !$date_fin || strtotime($date_fin) < strtotime($date_debut)) {
            self::redirect('psc_school_years', 'invalid_date');
        }
        $span = (strtotime($date_fin) - strtotime($date_debut)) / DAY_IN_SECONDS;
        if ($span > psc_max_trimestre_days()) {
            self::redirect('psc_school_years', 'invalid_date');
        }

        $affected = Psc_School_Calendar::affected_families_range($date_debut, $date_fin);

        if ($affected['registrations'] > 0 && !$confirm) {
            set_transient(self::pending_close_key(), array('date_debut' => $date_debut, 'date_fin' => $date_fin, 'label' => $label), 10 * MINUTE_IN_SECONDS);
            self::redirect('psc_school_years', 'confirm_needed');
        }

        delete_transient(self::pending_close_key());
        Psc_School_Calendar::close_range($date_debut, $date_fin, $label);
        self::redirect('psc_school_years', 'closed');
    }

    public static function handle_cancel_school_day_close() {
        self::guard('psc_cancel_school_day_close');
        delete_transient(self::pending_close_key());
        self::redirect('psc_school_years', 'cancelled');
    }

    public static function handle_open_school_day() {
        self::guard('psc_open_school_day');

        $date = psc_valid_date(psc_post('date'));
        if (!$date) {
            self::redirect('psc_school_years', 'invalid_date');
        }

        Psc_School_Calendar::open_day($date);
        self::redirect('psc_school_years', 'opened');
    }

    /**
     * Regroupe les jours fermés consécutifs (même libellé) en périodes,
     * pour un affichage lisible plutôt qu'une liste de ~150 lignes.
     */
    protected static function group_closed_days($rows) {
        $groups = array();
        $current = null;

        foreach ($rows as $row) {
            if (!$row->is_closed) continue;

            $is_next_day = $current && (strtotime($row->jour_date) - strtotime($current['end'])) === DAY_IN_SECONDS;
            if ($current && $is_next_day && $row->label === $current['label'] && $row->source === $current['source']) {
                $current['end'] = $row->jour_date;
                $current['count']++;
            } else {
                if ($current) $groups[] = $current;
                $current = array(
                    'start' => $row->jour_date, 'end' => $row->jour_date,
                    'label' => $row->label, 'source' => $row->source, 'count' => 1,
                );
            }
        }
        if ($current) $groups[] = $current;

        return $groups;
    }
}
