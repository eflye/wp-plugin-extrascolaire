<?php
if (!defined('ABSPATH')) exit;

/**
 * Périscolaire › Maintenance : la mise à jour du plugin conduite depuis le
 * backoffice, étape par étape, sans ligne de commande — un WordPress en
 * conteneur n'a souvent ni WP-CLI ni accès commode à wp-config.php.
 *
 * Cinq étapes, chacune avec un état calculé (jamais une case cochée « pour
 * faire joli » quand l'état se constate) :
 *  1. sauvegarde confirmée pour cette version (déclaration de la mairie) ;
 *  2. base de données : schéma, montée en échec, documents non déplacés,
 *     années en double ;
 *  3. clé de chiffrement des IBAN : hors de la base, valeurs rechiffrées ;
 *  4. nouveaux réglages : confidentialité, année active, mode debug,
 *     modèles d'e-mails relus ;
 *  5. recette : points cochés par la personne qui les a constatés.
 *
 * L'état déclaratif (sauvegarde, modèles relus, recette) vit dans l'option
 * psc_maintenance, remise à zéro à chaque nouvelle version. Aucune clé de
 * chiffrement n'est jamais enregistrée : générée, elle est affichée une
 * seule fois, dans la réponse à la demande.
 */
class Psc_Admin_Maintenance extends Psc_Admin_Base {

    const OPTION = 'psc_maintenance';

    public static function init() {
        add_action('admin_post_psc_config_maintenance_sauvegarde', array(__CLASS__, 'handle_sauvegarde'));
        add_action('admin_post_psc_config_maintenance_relancer', array(__CLASS__, 'handle_relancer'));
        add_action('admin_post_psc_config_maintenance_rechiffrer', array(__CLASS__, 'handle_rechiffrer'));
        add_action('admin_post_psc_config_maintenance_modeles', array(__CLASS__, 'handle_modeles'));
        add_action('admin_post_psc_config_maintenance_recette', array(__CLASS__, 'handle_recette'));
        add_action('admin_notices', array(__CLASS__, 'notice_dashboard'));
    }

    /** Points de recette, dans l'ordre de la procédure. */
    public static function recette_items() {
        return array(
            'tableau_de_bord' => __('Le tableau de bord s’affiche, sans alerte nouvelle.', 'periscolaire-registration'),
            'connexion'       => __('Un lien de connexion arrive par e-mail et ouvre le portail d’une famille de test.', 'periscolaire-registration'),
            'documents'       => __('Un justificatif d’assurance et une facture existants se téléchargent.', 'periscolaire-registration'),
            'planning'        => __('Une case du planning d’un enfant de test se coche puis se décoche.', 'periscolaire-registration'),
            'iban'            => __('Dans Familles, l’IBAN masqué d’une famille en prélèvement s’affiche.', 'periscolaire-registration'),
            'journal'         => __('Le journal d’audit montre la mise à jour du schéma et, s’il a eu lieu, le rechiffrement.', 'periscolaire-registration'),
        );
    }

    /** État déclaratif pour la version installée (remis à zéro à chaque version). */
    public static function state() {
        $state = get_option(self::OPTION, array());
        if (!is_array($state) || ($state['version'] ?? '') !== PSC_VERSION) {
            $state = array('version' => PSC_VERSION, 'sauvegarde' => null, 'modeles' => null, 'recette' => array());
        }
        return $state;
    }

    protected static function save_state(array $state) {
        $state['version'] = PSC_VERSION;
        update_option(self::OPTION, $state, false);
    }

    protected static function stamp() {
        return array('user' => get_current_user_id(), 'at' => current_time('mysql'));
    }

    /**
     * Années scolaires dont la clé est portée par plusieurs lignes (montée
     * 4.15.0 arrêtée) : le seul conflit que la mise à jour ne tranche pas.
     */
    public static function duplicate_years() {
        global $wpdb;
        $t = psc_table('school_years');
        $cy = psc_table('child_school_years');
        $has_key = (bool) $wpdb->get_var("SHOW COLUMNS FROM $t LIKE 'year_key'");
        if (!$has_key) return array();
        return (array) $wpdb->get_results(
            "SELECT y.id, y.year_key, y.date_debut, y.date_fin, y.statut,
                    (SELECT COUNT(*) FROM $cy c WHERE c.school_year_id = y.id) AS inscriptions
             FROM $t y
             WHERE y.year_key IN (SELECT year_key FROM $t GROUP BY year_key HAVING COUNT(*) > 1)
             ORDER BY y.year_key, y.date_debut"
        );
    }

    /**
     * État calculé de chaque étape : 'ok', 'a_faire' ou 'bloquant', et les
     * éléments d'affichage utiles.
     */
    public static function steps() {
        $state = self::state();
        $steps = array();

        $steps['sauvegarde'] = array('status' => $state['sauvegarde'] ? 'ok' : 'a_faire', 'stamp' => $state['sauvegarde']);

        $db_version = (string) get_option('psc_db_version');
        $failed = get_option('psc_migration_failed');
        $storage = get_option('psc_storage_move_failed');
        $dupes = self::duplicate_years();
        $db_ok = $db_version === Psc_Installer::DB_VERSION && !is_array($failed) && empty($storage);
        $steps['base'] = array(
            'status'   => $db_ok ? 'ok' : (is_array($failed) || $storage ? 'bloquant' : 'a_faire'),
            'version'  => $db_version,
            'attendue' => Psc_Installer::DB_VERSION,
            'echec'    => is_array($failed) ? $failed : null,
            'stockage' => is_array($storage) ? $storage : array(),
            'doublons' => $dupes,
        );

        $report = Psc_Key_Rotation::run(true);
        $source = psc_encryption_key_source();
        $pending = $report['ancienne_cle'] + $report['clair'];
        $steps['cle'] = array(
            'status'  => ($source !== 'base' && !$pending && !$report['illisible']) ? 'ok' : 'a_faire',
            'source'  => $source,
            'rapport' => $report,
        );

        $checks = array(
            'confidentialite' => trim((string) get_option('psc_privacy_municipality', '')) !== '',
            'annee_active'    => Psc_School_Years::active_id() > 0,
            'debug_inactif'   => !get_option('psc_invoice_debug_delete'),
            'modeles'         => (bool) $state['modeles'],
        );
        $steps['reglages'] = array('status' => in_array(false, $checks, true) ? 'a_faire' : 'ok', 'checks' => $checks, 'modeles' => $state['modeles']);

        $done = array_intersect_key((array) $state['recette'], self::recette_items());
        $steps['recette'] = array('status' => count($done) === count(self::recette_items()) ? 'ok' : 'a_faire', 'faits' => $done);

        return $steps;
    }

    public static function page() {
        if (!current_user_can('psc_manage_config')) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        // Clé générée : affichée une seule fois, dans cette réponse, jamais
        // enregistrée (ni option, ni transient, ni journal).
        $generated_key = '';
        if (isset($_POST['psc_generer_cle'])) {
            check_admin_referer('psc_config_maintenance_cle');
            $generated_key = bin2hex(random_bytes(32));
            nocache_headers();
        }
        $steps = self::steps();
        $recette_items = self::recette_items();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $rechiffre = array(
            'n'  => isset($_GET['n']) ? absint($_GET['n']) : 0,
            'il' => isset($_GET['il']) ? absint($_GET['il']) : 0,
            'er' => isset($_GET['er']) ? absint($_GET['er']) : 0,
        );
        include PSC_PATH . 'templates/admin-maintenance.php';
    }

    public static function handle_sauvegarde() {
        self::guard('psc_config_maintenance_sauvegarde');
        if (empty($_POST['sauvegarde_ok'])) self::redirect('psc_maintenance', 'sauvegarde_requise');
        $state = self::state();
        $state['sauvegarde'] = self::stamp();
        self::save_state($state);
        self::redirect('psc_maintenance', 'sauvegarde_ok');
    }

    public static function handle_relancer() {
        self::guard('psc_config_maintenance_relancer');
        Psc_Installer::maybe_upgrade();
        $ok = get_option('psc_db_version') === Psc_Installer::DB_VERSION && !get_option('psc_migration_failed');
        self::redirect('psc_maintenance', $ok ? 'base_ok' : 'base_echec');
    }

    public static function handle_rechiffrer() {
        self::guard('psc_config_maintenance_rechiffrer');
        // Contrôles serveur, quel que soit l'état des boutons à l'écran.
        if (!psc_encryption_key_outside_db()) self::redirect('psc_maintenance', 'cle_en_base');
        if (!self::state()['sauvegarde']) self::redirect('psc_maintenance', 'sauvegarde_requise');
        $r = Psc_Key_Rotation::run(false);
        self::redirect('psc_maintenance', $r['erreur'] ? 'rechiffrement_incomplet' : 'rechiffrement_ok', array(
            'n' => $r['rechiffre'], 'il' => $r['illisible'], 'er' => $r['erreur'],
        ));
    }

    public static function handle_modeles() {
        self::guard('psc_config_maintenance_modeles');
        $state = self::state();
        $state['modeles'] = self::stamp();
        self::save_state($state);
        self::redirect('psc_maintenance', 'modeles_ok');
    }

    public static function handle_recette() {
        self::guard('psc_config_maintenance_recette');
        $state = self::state();
        $checked = isset($_POST['recette']) && is_array($_POST['recette']) ? array_map('sanitize_key', wp_unslash($_POST['recette'])) : array();
        $recette = array();
        foreach (array_keys(self::recette_items()) as $key) {
            if (!in_array($key, $checked, true)) continue;
            // Un point déjà constaté garde son auteur et sa date.
            $recette[$key] = $state['recette'][$key] ?? self::stamp();
        }
        $state['recette'] = $recette;
        self::save_state($state);
        self::redirect('psc_maintenance', 'recette_ok');
    }

    /**
     * Rappel sur le tableau de bord tant que des étapes restent à faire
     * pour la version installée.
     */
    public static function notice_dashboard() {
        if (!current_user_can('psc_manage_config')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'toplevel_page_psc_dashboard') return;

        $steps = self::steps();
        $left = count(array_filter($steps, function ($s) { return $s['status'] !== 'ok'; }));
        if (!$left) return;
        ?>
        <div class="notice notice-warning" data-testid="notice-maintenance">
            <p>
                <?php
                printf(
                    /* translators: 1: version du plugin, 2: nombre d'étapes restantes */
                    esc_html(_n('Version %1$s : %2$d étape de maintenance reste à faire.', 'Version %1$s : %2$d étapes de maintenance restent à faire.', $left, 'periscolaire-registration')),
                    esc_html(PSC_VERSION),
                    (int) $left
                );
                ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=psc_maintenance')); ?>"><?php esc_html_e('Ouvrir Périscolaire › Maintenance', 'periscolaire-registration'); ?></a>
            </p>
        </div>
        <?php
    }
}
