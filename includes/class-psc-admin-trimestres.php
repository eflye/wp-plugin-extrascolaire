<?php
if (!defined('ABSPATH')) exit;

/**
 * Écran des trimestres.
 *
 * Ne porte plus que la couche HTTP : lire la saisie, demander
 * confirmation quand une modification détruit des données, relayer le
 * résultat. Les règles vivent dans Psc_Trimestres.
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

        $result = Psc_Trimestres::create(
            psc_post('label'),
            psc_post_int('school_year_id'),
            psc_post('date_debut'),
            psc_post('date_fin')
        );
        if (is_wp_error($result)) self::redirect('psc_trimestres', $result->get_error_code());

        self::redirect('psc_trimestres', 'created');
    }

    public static function handle_activate_trimestre() {
        self::guard('psc_activate_trimestre');

        if (!Psc_Trimestres::activate(psc_post_int('id'))) {
            self::redirect('psc_trimestres', 'invalid');
        }
        self::redirect('psc_trimestres', 'activated');
    }

    /**
     * Corrige un trimestre existant.
     *
     * Le calendrier est régénéré sur la nouvelle période : les jours
     * restés dedans retrouvent leur statut recalculé (week-end, mercredi,
     * vacances, férié), donc une fermeture ponctuelle ajoutée à la main
     * peut être réinitialisée — l'écran le signale avant enregistrement.
     *
     * Rétrécir la période supprime en outre les présences qui en sortent.
     * On mesure l'impact avant d'écrire quoi que ce soit, et l'on
     * s'interrompt pour le faire confirmer, en annonçant le nombre exact.
     */
    public static function handle_update_trimestre() {
        self::guard('psc_update_trimestre');

        $id             = psc_post_int('id');
        $label          = psc_post('label');
        $school_year_id = psc_post_int('school_year_id');
        $debut          = psc_valid_date(psc_post('date_debut'));
        $fin            = psc_valid_date(psc_post('date_fin'));

        // Le nombre de présences menacées ne peut se calculer que sur des
        // bornes valides : la validation complète a lieu plus bas, dans le
        // domaine, mais il faut au moins deux dates lisibles pour compter.
        $orphaned = ($id && $debut && $fin)
            ? Psc_Trimestres::registrations_outside_range($id, $debut, $fin)
            : 0;

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

        $result = Psc_Trimestres::update($id, $label, $school_year_id, psc_post('date_debut'), psc_post('date_fin'));
        if (is_wp_error($result)) self::redirect('psc_trimestres', $result->get_error_code());

        delete_transient(self::pending_trimestre_key());
        self::redirect('psc_trimestres', $orphaned > 0 ? 'trim_updated_purged' : 'updated');
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
     * Supprime un trimestre, avec les présences déclarées dessus.
     *
     * La confirmation est saisie dans la popin ('confirm_text') et
     * revalidée ici : jamais de confiance dans la seule validation
     * JavaScript du bouton.
     */
    public static function handle_delete_trimestre() {
        self::guard('psc_delete_trimestre');

        $id = psc_post_int('id');
        if (!Psc_Trimestres::get($id)) self::redirect('psc_trimestres', 'invalid');

        if (psc_post('confirm_text') !== 'CONFIRMER') {
            self::redirect('psc_trimestres', 'confirm_mismatch');
        }

        $result = Psc_Trimestres::delete($id);
        if (is_wp_error($result)) self::redirect('psc_trimestres', $result->get_error_code());

        self::redirect('psc_trimestres', 'trimestre_deleted');
    }

    public static function page_trimestres() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $trimestres           = Psc_Trimestres::all();
        $trimestre_reg_counts = Psc_Trimestres::registration_counts();
        $pending_trimestre    = get_transient(self::pending_trimestre_key());

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-trimestres.php';
    }
}
