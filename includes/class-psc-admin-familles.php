<?php
if (!defined('ABSPATH')) exit;

/**
 * Familles, enfants et personnes autorisées à les récupérer.
 */
class Psc_Admin_Familles extends Psc_Admin_Base {

    public static function init() {
        add_action('admin_post_psc_add_child', array(__CLASS__, 'handle_add_child'));
        add_action('admin_post_psc_delete_child', array(__CLASS__, 'handle_delete_child'));
        add_action('admin_post_psc_delete_family', array(__CLASS__, 'handle_delete_family'));
        add_action('admin_post_psc_mark_child_sorti', array(__CLASS__, 'handle_mark_child_sorti'));
        add_action('admin_post_psc_mark_child_actif', array(__CLASS__, 'handle_mark_child_actif'));
        add_action('admin_post_psc_add_parent', array(__CLASS__, 'handle_add_parent'));
        add_action('admin_post_psc_toggle_parent', array(__CLASS__, 'handle_toggle_parent'));
        add_action('admin_post_psc_send_link', array(__CLASS__, 'handle_send_link'));
        add_action('admin_post_psc_edit_parent', array(__CLASS__, 'handle_edit_parent'));
        add_action('admin_post_psc_download_assurance', array(__CLASS__, 'handle_download_assurance'));
    }

    public static function handle_add_child() {
        self::guard('psc_add_child');
        global $wpdb;

        $parent_id = psc_post_int('parent_id');
        $nom       = psc_post('nom');
        $prenom    = psc_post('prenom');
        $classe    = psc_post('classe');
        $naissance = psc_valid_date(psc_post('naissance'));

        if (!$parent_id || $nom === '' || $prenom === '') {
            self::redirect('psc_children', 'invalid');
        }
        // Date bien formée mais incohérente : refusée, cf.
        // psc_valid_child_birthdate() (futur, moins de 3 ans au 1er
        // septembre de l'année en cours).
        if ($naissance && !psc_valid_child_birthdate($naissance)) {
            self::redirect('psc_children', 'child_bad_birthdate');
        }

        if (!Psc_Parents::get_by_id($parent_id)) {
            self::redirect('psc_children', 'nouser');
        }

        $allowed = array_keys(Psc_School_Years::classe_options());
        if (!in_array($classe, $allowed, true)) $classe = '';

        $wpdb->insert(psc_table('children'), array(
            'parent_id'      => $parent_id,
            'nom'            => mb_substr($nom, 0, 190),
            'prenom'         => mb_substr($prenom, 0, 190),
            'date_naissance' => $naissance ?: null,
            'statut'         => 'actif',
            'created_at'     => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;

        $year_id = Psc_School_Years::active_id();
        if ($year_id && $classe !== '') {
            Psc_School_Years::enroll($child_id, $year_id, $classe, 'inscrit');
        }

        self::redirect('psc_children', 'added');
    }

    public static function handle_delete_child() {
        self::guard('psc_delete_child');
        $id = psc_post_int('id');
        if (!$id) self::redirect('psc_children', 'invalid');
        self::purge_child($id);
        self::redirect('psc_children', 'deleted');
    }

    /**
     * Purge complète d'un enfant : justificatifs d'assurance sur disque,
     * puis toutes les lignes le concernant (inscriptions, pointage SIDSCM,
     * historique des personnes autorisées). Utilisé à la fois par la
     * suppression d'un enfant seul et par la suppression complète d'une
     * famille (cf. handle_delete_family) — un seul endroit qui sait purger
     * un enfant, pour ne jamais oublier une table dans l'un des deux chemins.
     */
    protected static function purge_child($child_id) {
        global $wpdb;
        $t_cy = psc_table('child_school_years');

        $paths = $wpdb->get_col($wpdb->prepare(
            "SELECT assurance_file_path FROM $t_cy WHERE child_id = %d AND assurance_file_path IS NOT NULL",
            $child_id
        ));
        foreach ($paths as $rel_path) {
            $abs = psc_private_path($rel_path);
            if (file_exists($abs)) {
                @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        $wpdb->delete(psc_table('attendance'), array('child_id' => $child_id), array('%d'));
        $wpdb->delete(psc_table('registrations'), array('child_id' => $child_id), array('%d'));
        $wpdb->delete(psc_table('child_school_years'), array('child_id' => $child_id), array('%d'));
        // RGPD : les coordonnées de tiers (personnes autorisées) suivent la
        // même durée de conservation que la fiche enfant, historique compris
        // — cf. README.
        $wpdb->delete(psc_table('pickup_history'), array('child_id' => $child_id), array('%d'));
        $wpdb->delete(psc_table('pickup_persons'), array('child_id' => $child_id), array('%d'));
        $wpdb->delete(psc_table('children'), array('id' => $child_id), array('%d'));
    }

    /**
     * Suppression complète d'une famille : purge chacun de ses enfants
     * (cf. purge_child), ses factures (PDF sur disque compris), puis la
     * fiche famille elle-même. Les demandes d'inscription historiques
     * (wp_psc_requests) ne référencent pas parent_id — elles documentent
     * la candidature d'origine, pas le compte, et ne sont donc pas purgées
     * ici.
     */
    public static function handle_delete_family() {
        self::guard('psc_delete_family');
        global $wpdb;

        $id = psc_post_int('id');
        $t_parent = psc_table('parents');
        $exists = $id ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE id = %d", $id)) : null;
        if (!$exists) self::redirect('psc_parents', 'invalid');

        $t_child = psc_table('children');
        $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $id));
        foreach ($child_ids as $child_id) {
            self::purge_child($child_id);
        }

        $t_inv = psc_table('invoices');
        $pdf_paths = $wpdb->get_col($wpdb->prepare(
            "SELECT pdf_path FROM $t_inv WHERE parent_id = %d AND pdf_path IS NOT NULL", $id
        ));
        foreach ($pdf_paths as $rel_path) {
            $abs = psc_private_path($rel_path);
            if (file_exists($abs)) {
                @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        $wpdb->delete($t_inv, array('parent_id' => $id), array('%d'));

        $wpdb->delete($t_parent, array('id' => $id), array('%d'));

        self::redirect('psc_parents', 'family_deleted');
    }

    public static function handle_mark_child_sorti() {
        self::guard('psc_mark_child_sorti');
        if (!Psc_School_Years::mark_sorti(psc_post_int('id'))) self::redirect('psc_children', 'invalid');
        self::redirect('psc_children', 'marked_sorti');
    }

    public static function handle_mark_child_actif() {
        self::guard('psc_mark_child_actif');
        if (!Psc_School_Years::mark_actif(psc_post_int('id'))) self::redirect('psc_children', 'invalid');
        self::redirect('psc_children', 'marked_actif');
    }

    public static function page_children() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $t_child = psc_table('children');
        $t_parent = psc_table('parents');
        $t_cy = psc_table('child_school_years');

        $years = Psc_School_Years::all();
        $selected_year_id = psc_get_int('school_year_id') ?: Psc_School_Years::active_id();
        $show_sortis = !empty($_GET['show_sortis']);

        $where = $show_sortis ? '' : "AND c.statut = 'actif'";
        $children = $selected_year_id ? $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, p.nom AS parent_nom, p.email AS parent_email,
                    cy.classe AS classe, cy.statut AS statut_annee,
                    cy.assurance_original_filename AS assurance_filename,
                    cy.assurance_uploaded_at AS assurance_uploaded_at
             FROM $t_child c
             LEFT JOIN $t_parent p ON p.id = c.parent_id
             LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             WHERE 1=1 $where
             ORDER BY c.nom",
            $selected_year_id
        )) : array();

        $parents = Psc_Parents::all();
        $psc_classe_labels = Psc_School_Years::classe_options();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-children.php';
    }

    /**
     * Fiche "Personnes autorisées" d'un enfant — consultation seule côté
     * mairie (liste courante + historique complet). Aucune écriture :
     * seule la famille édite cette liste, cf. Psc_Frontend.
     */
    public static function page_pickup_persons() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;

        $child_id = psc_get_int('child_id');
        $child = $child_id ? $wpdb->get_row($wpdb->prepare(
            'SELECT c.*, p.nom AS parent_nom, p.email AS parent_email
             FROM ' . psc_table('children') . ' c
             LEFT JOIN ' . psc_table('parents') . ' p ON p.id = c.parent_id
             WHERE c.id = %d', $child_id
        )) : null;

        if (!$child) {
            wp_die(esc_html__('Enfant introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        $pickup_persons = Psc_Pickup_Persons::for_child($child_id);
        $pickup_history = Psc_Pickup_Persons::history_for_child($child_id);
        // Les parents (titulaire + éventuel second parent) figurent toujours
        // dans la liste courante, au même titre que sur "Mes enfants" et
        // l'écran SIDSCM — jamais des lignes wp_psc_pickup_persons, donc
        // absents de l'historique, cohérent avec le reste du plugin.
        $parent_row = $child->parent_id ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $child->parent_id
        )) : null;
        $pickup_parent_rows = $parent_row ? Psc_Pickup_Persons::parent_entries($parent_row) : array();
        include PSC_PATH . 'templates/admin-pickup-persons.php';
    }

    public static function handle_add_parent() {
        self::guard('psc_add_parent');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $nom   = psc_post('nom');

        $result = Psc_Parents::create($email, $nom);
        if (is_wp_error($result)) {
            self::redirect('psc_parents', $result->get_error_code() === 'psc_exists' ? 'exists' : 'invalid');
        }
        self::redirect('psc_parents', 'added');
    }

    public static function handle_toggle_parent() {
        self::guard('psc_toggle_parent');
        global $wpdb;

        $id = psc_post_int('id');
        $parent = $id ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $id
        )) : null;
        if (!$parent) self::redirect('psc_parents', 'invalid');

        $wpdb->update(
            psc_table('parents'),
            array('active' => $parent->active ? 0 : 1),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
        self::redirect('psc_parents', $parent->active ? 'deactivated' : 'reactivated');
    }

    /**
     * Envoie manuellement un lien d'accès (première mise en service,
     * ou parent qui ne reçoit pas le message).
     */
    public static function handle_send_link() {
        self::guard('psc_send_link');

        $id = psc_post_int('id');
        $parent = Psc_Parents::get_by_id($id);
        if (!$parent) self::redirect('psc_parents', 'invalid');

        $ok = Psc_Parents::send_login_link($parent->email);
        self::redirect('psc_parents', $ok ? 'link_sent' : 'mail_failed');
    }

    public static function handle_edit_parent() {
        self::guard('psc_edit_parent');

        $id = psc_post_int('id');
        if (!$id) self::redirect('psc_parents', 'invalid');

        // Formats contrôlés ici et pas dans Psc_Parents::update() : la
        // même méthode écrit les coordonnées depuis l'approbation d'une
        // demande (déjà validée à la source) — la rejeter là rendrait des
        // demandes légitimes inapprovables. Facultatif : seules les valeurs
        // renseignées sont contrôlées.
        foreach (array(psc_post('code_postal'), psc_post('sepa_code_postal')) as $psc_edit_cp) {
            if ($psc_edit_cp !== '' && !psc_valid_postcode($psc_edit_cp)) {
                self::redirect('psc_parents', 'bad_code_postal');
            }
        }

        $result = Psc_Parents::update($id, array(
            'nom'              => psc_post('nom'),
            'adresse'          => psc_post('adresse'),
            'code_postal'      => psc_post('code_postal'),
            'ville'            => psc_post('ville'),
            'payment_mode'     => psc_post('payment_mode'),
            'sepa_titulaire'   => psc_post('sepa_titulaire'),
            'sepa_adresse'     => psc_post('sepa_adresse'),
            'sepa_code_postal' => psc_post('sepa_code_postal'),
            'sepa_ville'       => psc_post('sepa_ville'),
            'sepa_iban'        => psc_post('sepa_iban'),
            'sepa_bic'         => psc_post('sepa_bic'),
        ));
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(
                array('page' => 'psc_parents', 'edit' => $id, 'psc_msg' => $result->get_error_code() === 'psc_bad_iban' ? 'bad_iban' : 'bad_bic'),
                admin_url('admin.php')
            ));
            exit;
        }
        self::redirect('psc_parents', 'updated');
    }

    public static function page_parents() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $parents     = Psc_Parents::all();
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $edit_id     = psc_get_int('edit');
        $edit_parent = $edit_id ? Psc_Parents::get_by_id($edit_id) : null;
        include PSC_PATH . 'templates/admin-parents.php';
    }

    /**
     * Consultation par la mairie d'un justificatif d'assurance scolaire.
     * Lecture seule : aucune validation/rejet n'existe pour l'instant
     * (auto-validation à l'upload, cf. Psc_Frontend_Documents::handle_parent_upload_assurance()).
     */
    public static function handle_download_assurance() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $child_id = psc_get_int('child_id');
        check_admin_referer('psc_download_assurance_' . $child_id);

        $year_id = psc_get_int('school_year_id') ?: Psc_School_Years::active_id();
        $doc = $year_id ? Psc_School_Years::enrollment($child_id, $year_id) : null;
        if (!$doc || !$doc->assurance_file_path) {
            wp_die(esc_html__('Aucun document pour cette année.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Assurances::stream($doc->assurance_file_path, $doc->assurance_original_filename);
    }
}
