<?php
if (!defined('ABSPATH')) exit;

/**
 * Configuration administrable de l'année scolaire (table psc_school_year)
 * et calcul des jours d'école.
 *
 * L'unité du portail est l'ANNÉE scolaire — plus aucune notion de trimestre.
 * Une ligne de psc_school_year porte :
 *  - year_key         '2026-2027' (clé unique, format rentrée-année)
 *  - date_start       2026-09-01
 *  - date_end         2027-07-06
 *  - vacation_ranges  JSON [[start, end], …] — vacances de la mairie
 *  - lock_hours       délai de prévenance (48 h par défaut)
 *
 * psc_holidays porte les jours fériés à exclure, année par année
 * (pré-rempli avec les fériés métropole, complété à la main pour les ponts).
 *
 * Les jours d'école sont CALCULÉS, jamais stockés : jours de semaine
 * 1, 2, 4, 5 (pas de mercredi), moins vacances et fériés, moins les
 * fermetures manuelles posées par la mairie (table school_calendar,
 * source 'manual').
 *
 * Deux entités coexistent volontairement :
 *  - wp_psc_school_years (pluriel, historique) : le dossier d'inscription
 *    par année (classe, assurance, règlement de chaque enfant) ;
 *  - wp_psc_school_year (singulier, celle-ci) : la configuration du
 *    planning — dates, vacances, fériés, verrou.
 */
class Psc_School_Year {

    /** Cache par requête : état d'un jour, plages de vacances, fériés. */
    private static $day_cache = array();
    private static $vacation_cache = array();
    private static $holiday_cache = array();

    /**
     * Cache par requête des LECTURES de configuration. La résolution du
     * planning interroge la clé d'année (donc la configuration) pour CHAQUE
     * (enfant × date × prestation) : sans ce cache, un simple clic dans la
     * grille lançait plusieurs centaines de requêtes identiques — la table
     * compte une à trois lignes, tout tient en mémoire.
     */
    private static $all_cache = null;
    private static $vacation_decoded = array();
    private static $school_days_month_cache = array();

    /* ---------------- Lectures ---------------- */

    public static function all() {
        if (self::$all_cache === null) {
            global $wpdb;
            $rows = $wpdb->get_results('SELECT * FROM ' . psc_table('school_year') . ' ORDER BY date_start DESC');
            self::$all_cache = is_array($rows) ? $rows : array();
        }
        return self::$all_cache;
    }

    /** Configuration d'une année par sa clé ('2026-2027'), ou null. */
    public static function get($year_key) {
        $year_key = self::sanitize_key($year_key);
        if ($year_key === '') return null;
        foreach (self::all() as $row) {
            if ($row->year_key === $year_key) return $row;
        }
        return null;
    }

    /**
     * Configuration de l'année courante : celle qui couvre aujourd'hui,
     * sinon la plus récente (été compris — une famille qui déclare son
     * rythme en juillet pour la rentrée doit retomber sur la bonne année).
     * Résolue depuis le cache all() : même sémantique que les requêtes
     * d'origine (couvrante d'abord, sinon la plus récente par date_start).
     */
    public static function active() {
        $today = current_time('Y-m-d');
        $rows = self::all();
        foreach ($rows as $row) {
            if ($row->date_start <= $today && $row->date_end >= $today) return $row;
        }
        return $rows ? $rows[0] : null;
    }

    /** Configuration couvrant une date (date_start <= date <= date_end), sinon l'année active. */
    public static function for_date($date) {
        $date = psc_valid_date($date);
        if (!$date) return self::active();
        foreach (self::all() as $row) {
            if ($row->date_start <= $date && $row->date_end >= $date) return $row;
        }
        return self::active();
    }

    /**
     * Clé d'année d'une date : la config couvrante si elle existe, sinon la
     * clé déduite de l'année de rentrée (une date de mars 2027 → '2026-2027').
     * La dérivation garantit qu'une date hors config retrouve quand même
     * ses patterns (la migration crée une config par année historisée).
     */
    public static function year_key_for_date($date) {
        $row = self::for_date($date);
        if ($row) return $row->year_key;
        $d = psc_valid_date($date);
        if (!$d) return '';
        $y = (int) substr($d, 0, 4);
        if ((int) substr($d, 5, 2) < 8) $y--;
        return $y . '-' . ($y + 1);
    }

    /** Normalise une clé d'année ('2026-2027') ou retourne ''. */
    public static function sanitize_key($key) {
        $key = trim((string) $key);
        return preg_match('/^\d{4}-\d{4}$/', $key) ? $key : '';
    }

    /** Clé d'année courante (année de rentrée du jour), sans requête. */
    public static function current_key() {
        $y = psc_rentree_year();
        return $y . '-' . ($y + 1);
    }

    /* ---------------- Vacances / fériés / jours d'école ---------------- */

    /**
     * Plages de vacances configurées [[start, end], …]. Si la mairie n'a
     * rien configuré (JSON vide), on retombe sur le calendrier scolaire
     * importé (iCal officiel zone C + corrections), pour qu'un site qui
     * n'a pas encore resaisi ses vacances continue de fermer les bons jours.
     */
    public static function vacation_ranges($year_key = null) {
        $row = $year_key !== null ? self::get($year_key) : self::active();
        if (!$row) return array();

        // Décodage une seule fois par année et par requête : is_vacation()
        // est appelé pour chaque date de chaque résolution.
        if (isset(self::$vacation_decoded[$row->year_key])) {
            return self::$vacation_decoded[$row->year_key];
        }

        $raw = trim((string) $row->vacation_ranges);
        $ranges = array();
        if ($raw !== '' && $raw !== '[]' && $raw !== 'null') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $r) {
                    if (!is_array($r) || count($r) < 2) continue;
                    $start = psc_valid_date($r[0]);
                    $end   = psc_valid_date($r[1]);
                    if ($start && $end && strtotime($end) >= strtotime($start)) {
                        $ranges[] = array($start, $end);
                    }
                }
            }
        }

        self::$vacation_decoded[$row->year_key] = $ranges;
        return $ranges;
    }

    /** La mairie a-t-elle configuré ses propres plages de vacances ? */
    protected static function has_custom_vacations($year_key = null) {
        return !empty(self::vacation_ranges($year_key));
    }

    /** Un jour est-il en vacances (plages configurées, sinon calendrier importé) ? */
    public static function is_vacation($date) {
        $ranges = self::vacation_ranges(self::year_key_for_date($date));
        if ($ranges) {
            foreach ($ranges as list($start, $end)) {
                if ($date >= $start && $date <= $end) return true;
            }
            return false;
        }
        // Repli : calendrier scolaire importé (iCal + corrections mairie).
        return psc_is_school_vacation($date);
    }

    /** Jours fériés à exclure pour l'année couvrant la date (table psc_holidays). */
    public static function holidays($year_key) {
        $year_key = self::sanitize_key($year_key);
        if ($year_key === '') return array();
        if (isset(self::$holiday_cache[$year_key])) return self::$holiday_cache[$year_key];

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT jour_date FROM ' . psc_table('holidays') . ' WHERE year_key = %s ORDER BY jour_date',
            $year_key
        ));
        $dates = $rows ? wp_list_pluck($rows, 'jour_date') : array();
        self::$holiday_cache[$year_key] = $dates;
        return $dates;
    }

    /** Jours fériés avec libellé (pour l'écran mairie) : [date => label|null]. */
    public static function holidays_with_labels($year_key) {
        $year_key = self::sanitize_key($year_key);
        if ($year_key === '') return array();

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT jour_date, label FROM ' . psc_table('holidays') . ' WHERE year_key = %s ORDER BY jour_date',
            $year_key
        ));
        $out = array();
        if ($rows) {
            foreach ($rows as $r) $out[$r->jour_date] = $r->label;
        }
        return $out;
    }

    /** Un jour est-il férié ? Repli sur les fériés métropole calculés si l'année n'a aucun férié enregistré. */
    public static function is_holiday($date) {
        $year_key = self::year_key_for_date($date);
        $holidays = self::holidays($year_key);
        if (empty($holidays)) {
            return psc_is_holiday($date);
        }
        return in_array($date, $holidays, true);
    }

    /**
     * Fermeture manuelle posée par la mairie (school_calendar, source
     * 'manual'). Toujours soustraite, même quand les plages de vacances
     * sont configurées : une fermeture exceptionnelle (formation,
     * dégât des eaux) n'est pas une vacation.
     */
    public static function is_manually_closed($date) {
        return Psc_School_Calendar::is_manually_closed($date);
    }

    /**
     * État d'un jour : 'weekend' | 'wednesday' | 'vacation' | 'holiday'
     * | 'closed' (fermeture manuelle) | 'school'.
     */
    public static function day_status($date) {
        $date = psc_valid_date($date);
        if (!$date) return 'closed';
        if (isset(self::$day_cache[$date])) return self::$day_cache[$date];

        $dow = (int) date('N', strtotime($date)); // 1 (lundi) .. 7 (dimanche)
        $status = 'school';
        if ($dow >= 6) {
            $status = 'weekend';
        } elseif ($dow === 3) {
            $status = 'wednesday';
        } elseif (self::is_vacation($date)) {
            $status = 'vacation';
        } elseif (self::is_holiday($date)) {
            $status = 'holiday';
        } elseif (self::is_manually_closed($date)) {
            $status = 'closed';
        }

        self::$day_cache[$date] = $status;
        return $status;
    }

    /** Un jour est-il un jour d'école (donc de service potentiel) ? */
    public static function is_school_day($date) {
        return self::day_status($date) === 'school';
    }

    /** Jours d'école d'une plage inclusive, triés. */
    public static function school_days($date_start, $date_end) {
        $date_start = psc_valid_date($date_start);
        $date_end   = psc_valid_date($date_end);
        if (!$date_start || !$date_end || strtotime($date_end) < strtotime($date_start)) return array();

        // Liste d'abord, PRÉCHARGE ensuite (fermetures importées/manuelles en
        // 2 requêtes pour toute la plage), puis filtre : sans préchargement,
        // chaque date interrogeait le calendrier deux fois — des centaines
        // de requêtes par résolution (frise, récapitulatifs, clics).
        $all = array();
        $cursor = new DateTime($date_start);
        $end = new DateTime($date_end);
        $guard = 0;
        while ($cursor <= $end && $guard++ < psc_max_school_days()) {
            $all[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        Psc_School_Calendar::preload_closed($all);
        Psc_School_Calendar::preload_manual_closed($all);

        return array_values(array_filter($all, array(__CLASS__, 'is_school_day')));
    }

    /** Jours d'école d'un mois (YYYY-MM), triés — une seule résolution par mois et par requête (frise, récapitulatifs). */
    public static function school_days_in_month($ym) {
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return array();
        if (isset(self::$school_days_month_cache[$ym])) return self::$school_days_month_cache[$ym];
        $start = $ym . '-01';
        $end = gmdate('Y-m-t', strtotime($start));
        $days = self::school_days($start, $end);
        self::$school_days_month_cache[$ym] = $days;
        return $days;
    }

    /**
     * Mois de l'année scolaire (configurée ou active), du plus ancien au
     * plus récent : [['key' => '2026-09', 'label' => 'Septembre 2026'], …].
     * La frise de Planning - 2 et la navigation mois par mois s'appuient
     * sur cette liste — onze boutons de septembre à juillet.
     */
    public static function months($year_key = null) {
        $row = $year_key !== null ? self::get($year_key) : self::active();
        if (!$row) return array();

        $months = array();
        $cursor = new DateTime($row->date_start);
        $end = new DateTime($row->date_end);
        $guard = 0;
        while ($cursor <= $end && $guard++ < 24) {
            $key = $cursor->format('Y-m');
            $months[] = array(
                'key'   => $key,
                'label' => date_i18n('F Y', $cursor->getTimestamp()),
                'short' => date_i18n('M', $cursor->getTimestamp()),
            );
            $cursor->modify('first day of next month');
        }
        return $months;
    }

    /** Délai de modification configuré pour l'année (heures), repli sur l'option historique. */
    public static function lock_hours($year_key = null) {
        $row = $year_key !== null ? self::get($year_key) : self::active();
        if ($row && $row->lock_hours !== null && (int) $row->lock_hours >= 0) {
            return min(720, (int) $row->lock_hours);
        }
        $h = (int) get_option('psc_lock_hours', 48);
        return max(0, min(720, $h));
    }

    public static function flush_cache() {
        self::$day_cache = array();
        self::$vacation_cache = array();
        self::$vacation_decoded = array();
        self::$holiday_cache = array();
        self::$all_cache = null;
        self::$school_days_month_cache = array();
    }

    /* ---------------- Écritures (écran mairie) ---------------- */

    /**
     * Crée (ou remplace) la configuration d'une année. Les fériés de
     * l'année sont pré-remplis avec les fériés métropole couvrant la
     * période — la mairie les complète (ponts) ou les retire ensuite.
     */
    public static function save($year_key, $date_start, $date_end, $vacation_ranges_json, $lock_hours) {
        global $wpdb;
        $year_key = self::sanitize_key($year_key);
        $date_start = psc_valid_date($date_start);
        $date_end   = psc_valid_date($date_end);
        if ($year_key === '' || !$date_start || !$date_end) {
            return new WP_Error('invalid', __('Clé d\'année ou dates invalides.', 'periscolaire-registration'));
        }
        if (strtotime($date_end) < strtotime($date_start)) {
            return new WP_Error('order_dates', __('La date de fin doit être après la date de début.', 'periscolaire-registration'));
        }

        // Plages : JSON strict [[start, end], …], revalidé entrée par entrée.
        $decoded = json_decode((string) $vacation_ranges_json, true);
        $clean = array();
        if (is_array($decoded)) {
            foreach ($decoded as $r) {
                if (!is_array($r) || count($r) < 2) continue;
                $s = psc_valid_date($r[0]);
                $e = psc_valid_date($r[1]);
                if ($s && $e && strtotime($e) >= strtotime($s)) $clean[] = array($s, $e);
            }
        }

        $lock_hours = max(0, min(720, (int) $lock_hours));
        $now = current_time('mysql');
        $t = psc_table('school_year');

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE year_key = %s", $year_key));
        if ($exists) {
            $wpdb->update($t, array(
                'date_start'      => $date_start,
                'date_end'        => $date_end,
                'vacation_ranges' => wp_json_encode($clean),
                'lock_hours'      => $lock_hours,
                'updated_at'      => $now,
            ), array('id' => (int) $exists), array('%s', '%s', '%s', '%d', '%s'), array('%d'));
        } else {
            $wpdb->insert($t, array(
                'year_key'        => $year_key,
                'date_start'      => $date_start,
                'date_end'        => $date_end,
                'vacation_ranges' => wp_json_encode($clean),
                'lock_hours'      => $lock_hours,
                'created_at'      => $now,
                'updated_at'      => $now,
            ), array('%s', '%s', '%s', '%s', '%d', '%s', '%s'));
        }

        self::seed_holidays($year_key, $date_start, $date_end);
        self::flush_cache();
        return true;
    }

    /** Pré-remplit psc_holidays avec les fériés métropole couvrant la période. Idempotent. */
    public static function seed_holidays($year_key, $date_start, $date_end) {
        global $wpdb;
        $year_key = self::sanitize_key($year_key);
        $date_start = psc_valid_date($date_start);
        $date_end   = psc_valid_date($date_end);
        if ($year_key === '' || !$date_start || !$date_end) return 0;

        $y1 = (int) substr($date_start, 0, 4);
        $y2 = (int) substr($date_end, 0, 4);
        $candidates = array();
        for ($y = $y1; $y <= $y2; $y++) {
            foreach (psc_french_holidays($y) as $h) {
                if ($h >= $date_start && $h <= $date_end) $candidates[$h] = true;
            }
        }
        if (!$candidates) return 0;

        $t = psc_table('holidays');
        $count = 0;
        foreach (array_keys($candidates) as $h) {
            $count += (int) $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (year_key, jour_date) VALUES (%s, %s)",
                $year_key, $h
            ));
        }
        self::flush_cache();
        return $count;
    }

    public static function add_holiday($year_key, $date, $label = '') {
        global $wpdb;
        $year_key = self::sanitize_key($year_key);
        $date = psc_valid_date($date);
        if ($year_key === '' || !$date) return false;
        $t = psc_table('holidays');
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (year_key, jour_date, label) VALUES (%s, %s, %s)",
            $year_key, $date, $label !== '' ? sanitize_text_field($label) : null
        ));
        self::flush_cache();
        return true;
    }

    public static function remove_holiday($year_key, $date) {
        global $wpdb;
        $year_key = self::sanitize_key($year_key);
        $date = psc_valid_date($date);
        if ($year_key === '' || !$date) return false;
        $wpdb->delete(psc_table('holidays'), array('year_key' => $year_key, 'jour_date' => $date), array('%s', '%s'));
        self::flush_cache();
        return true;
    }

    /**
     * Garantit qu'une configuration existe pour l'année courante (et la
     * crée par dérivation sinon) : appelée à la montée de version et en
     * ceinture de sécurité du portail, pour qu'un planning reste
     * affichable même si la mairie n'a rien configuré.
     */
    public static function ensure_default() {
        global $wpdb;
        $t = psc_table('school_year');
        if (!$wpdb->get_var("SHOW TABLES LIKE '$t'")) return null;

        $today = current_time('Y-m-d');
        $covering = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE date_start <= %s AND date_end >= %s", $today, $today
        ));
        if ($covering) return self::get_by_id((int) $covering);

        $y = psc_rentree_year();
        $year_key = $y . '-' . ($y + 1);
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE year_key = %s", $year_key));
        if (!$exists) {
            // Dérive les bornes de l'année d'inscription (dossier) si elle
            // existe : la configuration du planning suit le dossier.
            $enrolled = $wpdb->get_row($wpdb->prepare(
                'SELECT date_debut, date_fin FROM ' . psc_table('school_years') . ' WHERE label = %s ORDER BY id DESC LIMIT 1',
                $year_key
            ));
            $start = $enrolled && $enrolled->date_debut ? $enrolled->date_debut : sprintf('%d-09-01', $y);
            $end   = $enrolled && $enrolled->date_fin   ? $enrolled->date_fin   : sprintf('%d-07-06', $y + 1);
            self::save($year_key, $start, $end, '[]', psc_lock_hours());
        }
        return self::get($year_key);
    }

    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('school_year') . ' WHERE id = %d', $id));
    }
}
