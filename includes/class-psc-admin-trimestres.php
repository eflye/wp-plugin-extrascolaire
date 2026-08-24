<?php
if (!defined('ABSPATH')) exit;

/**
 * Trimestres : création, activation, modification des dates, suppression.
 */
class Psc_Admin_Trimestres extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_add_trimestre', array(__CLASS__, 'handle_add_trimestre'));
        add_action('admin_post_psc_activate_trimestre', array(__CLASS__, 'handle_activate_trimestre'));
        add_action('admin_post_psc_update_trimestre', array(__CLASS__, 'handle_update_trimestre'));
        add_action('admin_post_psc_cancel_trimestre_update', array(__CLASS__, 'handle_cancel_trimestre_update'));
        add_action('admin_post_psc_delete_trimestre', array(__CLASS__, 'handle_delete_trimestre'));
    }

    public static function handle_add_trimestre() {
        self::guard('psc_add_trimestre');
        global $wpdb;

        $label = psc_post('label');
        $debut = psc_valid_date(psc_post('date_debut'));
        $fin   = psc_valid_date(psc_post('date_fin'));
        $school_year_id = psc_post_int('school_year_id') ?: null;

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
            'label'          => mb_substr($label, 0, 190),
            'date_debut'     => $debut,
            'date_fin'       => $fin,
            'active'         => 0,
            'school_year_id' => $school_year_id,
        ), array('%s', '%s', '%s', '%d', '%d'));

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

    /**
     * Corrige le libellé, les dates ou l'année scolaire de rattachement
     * d'un trimestre existant — mêmes règles de validation qu'à la
     * création. Si les dates changent, le calendrier est régénéré sur la
     * nouvelle période (Psc_Installer::generate_calendar_days(), idempotent
     * via ON DUPLICATE KEY UPDATE) : les jours déjà couverts retrouvent
     * leur statut ouvert/fermé recalculé automatiquement (week-end,
     * mercredi, vacances, férié) — une fermeture ponctuelle ajoutée à la
     * main sur un jour resté dans la période peut donc être réinitialisée,
     * ce que l'écran signale avant enregistrement.
     */
    public static function handle_update_trimestre() {
        self::guard('psc_update_trimestre');
        global $wpdb;

        $id = psc_post_int('id');
        $t_trim = psc_table('trimestres');
        $exists = $id ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_trim WHERE id = %d", $id)) : null;
        if (!$exists) self::redirect('psc_trimestres', 'invalid');

        $label = psc_post('label');
        $debut = psc_valid_date(psc_post('date_debut'));
        $fin   = psc_valid_date(psc_post('date_fin'));
        $school_year_id = psc_post_int('school_year_id') ?: null;

        if ($label === '' || !$debut || !$fin) {
            self::redirect('psc_trimestres', 'invalid_dates');
        }
        if (strtotime($fin) < strtotime($debut)) {
            self::redirect('psc_trimestres', 'order_dates');
        }
        $span = (strtotime($fin) - strtotime($debut)) / DAY_IN_SECONDS;
        if ($span > psc_max_trimestre_days()) {
            self::redirect('psc_trimestres', 'too_long');
        }

        // Rétrécir un trimestre laissait derrière lui des jours de
        // calendrier et des inscriptions désormais hors de ses bornes :
        // generate_calendar_days() ajoute des jours, n'en retire jamais.
        // Ces présences restaient rattachées au trimestre et donc
        // facturées, alors qu'elles ne tombaient plus dans aucune période
        // valide. On mesure l'impact avant d'écrire quoi que ce soit.
        $orphaned = self::registrations_outside_range($id, $debut, $fin);

        if ($orphaned > 0 && !psc_post_int('confirm')) {
            set_transient(
                self::pending_trimestre_key(),
                array('id' => $id, 'label' => $label, 'date_debut' => $debut,
                      'date_fin' => $fin, 'school_year_id' => $school_year_id,
                      'orphaned' => $orphaned),
                10 * MINUTE_IN_SECONDS
            );
            self::redirect('psc_trimestres', 'trim_confirm_needed');
        }

        delete_transient(self::pending_trimestre_key());

        $wpdb->update($t_trim, array(
            'label'          => mb_substr($label, 0, 190),
            'date_debut'     => $debut,
            'date_fin'       => $fin,
            'school_year_id' => $school_year_id,
        ), array('id' => $id), array('%s', '%s', '%s', '%d'), array('%d'));

        Psc_Installer::generate_calendar_days($id, $debut, $fin);

        // Puis on retire ce qui est sorti de la période. L'ordre compte :
        // le calendrier est régénéré d'abord, sans quoi la purge porterait
        // sur les anciennes bornes.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . psc_table('registrations') . "
             WHERE trimestre_id = %d AND (jour_date < %s OR jour_date > %s)",
            $id, $debut, $fin
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . psc_table('calendar_days') . "
             WHERE trimestre_id = %d AND (jour_date < %s OR jour_date > %s)",
            $id, $debut, $fin
        ));

        self::redirect('psc_trimestres', $orphaned > 0 ? 'trim_updated_purged' : 'updated');
    }

    /** Présences déclarées qui tomberaient hors des nouvelles bornes. */
    protected static function registrations_outside_range($trimestre_id, $debut, $fin) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . psc_table('registrations') . "
             WHERE trimestre_id = %d AND (jour_date < %s OR jour_date > %s)",
            $trimestre_id, $debut, $fin
        ));
    }

    protected static function pending_trimestre_key() {
        return 'psc_pending_trimestre_' . get_current_user_id();
    }

    public static function handle_cancel_trimestre_update() {
        self::guard('psc_cancel_trimestre_update');
        delete_transient(self::pending_trimestre_key());
        self::redirect('psc_trimestres', 'cancelled');
    }

    /**
     * Supprime un trimestre.
     *
     * Jamais le trimestre actif (même principe que l'année active) : il
     * faut d'abord en activer un autre.
     *
     * Autorisé en revanche même si des familles ont déjà déclaré des
     * présences dessus — le comportement précédent bloquait purement et
     * simplement. La perte de ces inscriptions est couverte par la
     * confirmation exigée ci-dessous, saisie dans la popin (champ
     * 'confirm_text', revalidé côté serveur : jamais de confiance dans la
     * seule validation JS du bouton).
     */
    public static function handle_delete_trimestre() {
        self::guard('psc_delete_trimestre');
        global $wpdb;

        $id = psc_post_int('id');
        $t_trim = psc_table('trimestres');
        $trimestre = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_trim WHERE id = %d", $id)) : null;
        if (!$trimestre) self::redirect('psc_trimestres', 'invalid');
        if ($trimestre->active) self::redirect('psc_trimestres', 'active_trimestre');

        if (psc_post('confirm_text') !== 'CONFIRMER') {
            self::redirect('psc_trimestres', 'confirm_mismatch');
        }

        $wpdb->delete(psc_table('registrations'), array('trimestre_id' => $id), array('%d'));
        $wpdb->delete(psc_table('calendar_days'), array('trimestre_id' => $id), array('%d'));
        $wpdb->delete($t_trim, array('id' => $id), array('%d'));
        self::redirect('psc_trimestres', 'trimestre_deleted');
    }

    public static function page_trimestres() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');

        $reg_count_rows = $wpdb->get_results(
            'SELECT trimestre_id, COUNT(*) AS n FROM ' . psc_table('registrations') . ' GROUP BY trimestre_id'
        );
        $trimestre_reg_counts = array();
        foreach ($reg_count_rows as $r) {
            $trimestre_reg_counts[(int) $r->trimestre_id] = (int) $r->n;
        }

        $pending_trimestre = get_transient(self::pending_trimestre_key());

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-trimestres.php';
    }
}
