<?php
if (!defined('ABSPATH')) exit;

class Psc_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_psc_add_trimestre', array(__CLASS__, 'handle_add_trimestre'));
        add_action('admin_post_psc_activate_trimestre', array(__CLASS__, 'handle_activate_trimestre'));
        add_action('admin_post_psc_close_range', array(__CLASS__, 'handle_close_range'));
        add_action('admin_post_psc_add_school_year', array(__CLASS__, 'handle_add_school_year'));
        add_action('admin_post_psc_activate_school_year', array(__CLASS__, 'handle_activate_school_year'));
        add_action('admin_post_psc_archive_school_year', array(__CLASS__, 'handle_archive_school_year'));
        add_action('admin_post_psc_update_school_year', array(__CLASS__, 'handle_update_school_year'));
        add_action('admin_post_psc_delete_school_year', array(__CLASS__, 'handle_delete_school_year'));
        add_action('admin_post_psc_stage_promotion', array(__CLASS__, 'handle_stage_promotion'));
        add_action('admin_post_psc_confirm_promotion', array(__CLASS__, 'handle_confirm_promotion'));
        add_action('admin_post_psc_cancel_promotion', array(__CLASS__, 'handle_cancel_promotion'));
        add_action('admin_post_psc_add_child', array(__CLASS__, 'handle_add_child'));
        add_action('admin_post_psc_delete_child', array(__CLASS__, 'handle_delete_child'));
        add_action('admin_post_psc_mark_child_sorti', array(__CLASS__, 'handle_mark_child_sorti'));
        add_action('admin_post_psc_mark_child_actif', array(__CLASS__, 'handle_mark_child_actif'));
        add_action('admin_post_psc_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_psc_export_csv', array(__CLASS__, 'handle_export_csv'));
        add_action('admin_post_psc_add_parent', array(__CLASS__, 'handle_add_parent'));
        add_action('admin_post_psc_toggle_parent', array(__CLASS__, 'handle_toggle_parent'));
        add_action('admin_post_psc_send_link', array(__CLASS__, 'handle_send_link'));
        add_action('admin_post_psc_edit_parent', array(__CLASS__, 'handle_edit_parent'));
        add_action('admin_post_psc_delete_family', array(__CLASS__, 'handle_delete_family'));
        add_action('admin_post_psc_save_email_templates', array(__CLASS__, 'handle_save_email_templates'));
        add_action('admin_post_psc_reset_email_template', array(__CLASS__, 'handle_reset_email_template'));
        add_action('admin_post_psc_reset_email_templates', array(__CLASS__, 'handle_reset_email_templates'));
        add_action('admin_post_psc_generate_invoices', array(__CLASS__, 'handle_generate_invoices'));
        add_action('admin_post_psc_send_invoice', array(__CLASS__, 'handle_send_invoice'));
        add_action('admin_post_psc_send_all_invoices', array(__CLASS__, 'handle_send_all_invoices'));
        add_action('admin_post_psc_download_invoice', array(__CLASS__, 'handle_download_invoice'));
        add_action('admin_post_psc_admin_update_registrations', array(__CLASS__, 'handle_admin_update_registrations'));
        add_action('admin_post_psc_save_menu', array(__CLASS__, 'handle_save_menu'));
        add_action('admin_post_psc_send_menu', array(__CLASS__, 'handle_send_menu'));
        add_action('admin_post_psc_delete_menu', array(__CLASS__, 'handle_delete_menu'));
        add_action('admin_post_psc_send_supplier_order', array(__CLASS__, 'handle_send_supplier_order'));
        add_action('admin_post_psc_cancel_class_meals', array(__CLASS__, 'handle_cancel_class_meals'));
        add_action('admin_post_psc_dismiss_cancel_class_meals', array(__CLASS__, 'handle_dismiss_cancel_class_meals'));
        add_action('admin_post_psc_import_school_calendar', array(__CLASS__, 'handle_import_school_calendar'));
        add_action('admin_post_psc_upload_school_calendar', array(__CLASS__, 'handle_upload_school_calendar'));
        add_action('admin_post_psc_close_school_day', array(__CLASS__, 'handle_close_school_day'));
        add_action('admin_post_psc_open_school_day', array(__CLASS__, 'handle_open_school_day'));
        add_action('admin_post_psc_cancel_school_day_close', array(__CLASS__, 'handle_cancel_school_day_close'));
        add_action('admin_post_psc_download_assurance', array(__CLASS__, 'handle_download_assurance'));
        add_action('admin_post_psc_download_pending_assurance', array(__CLASS__, 'handle_download_pending_assurance'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    /**
     * Contrôle d'accès + nonce, appliqué en tête de chaque action.
     * Le nonce seul ne suffit pas (il prouve l'intention, pas le droit) ;
     * la capacité seule ne suffit pas non plus (elle n'empêche pas le CSRF).
     */
    protected static function guard($nonce_action) {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Vous n\'avez pas les droits nécessaires.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer($nonce_action);
    }

    public static function redirect_public($page, $msg) {
        self::redirect($page, $msg);
    }

    protected static function redirect($page, $msg) {
        wp_safe_redirect(add_query_arg(
            array('page' => $page, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_') === false) return;
        wp_enqueue_style('psc-admin', PSC_URL . 'assets/css/admin.css', array(), PSC_VERSION);
        if (strpos($hook, 'psc_settings') !== false) {
            wp_enqueue_media();
        }
    }

    /**
     * Regroupement du menu (6 blocs, du plus structurel au plus
     * opérationnel) : Tableau de bord, Calendrier (année/trimestres/jours),
     * Demandes & suivi, Familles, Cantine, Facturation, Configuration. Le
     * slug du menu de premier niveau est 'psc_dashboard' (avant :
     * 'psc_inscriptions', qui reste une page valide — seule sa place dans
     * l'arborescence change, aucun lien existant vers
     * admin.php?page=psc_inscriptions n'est cassé). "Inscriptions" est
     * renommé "Présences déclarées" dans le menu : le libellé prêtait à
     * confusion avec "Demandes d'inscription" juste à côté, alors que ce
     * sont deux écrans très différents (vue calendrier des présences déjà
     * déclarées, vs file de modération des nouvelles familles). Les
     * séparateurs visuels entre blocs sont en CSS (assets/css/admin.css),
     * WordPress ne proposant pas de séparateur natif dans un sous-menu de
     * plugin.
     */
    public static function menu() {
        $cap = psc_manage_cap();
        add_menu_page('Périscolaire', 'Périscolaire', $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-groups', 58);
        add_submenu_page('psc_dashboard', 'Tableau de bord', 'Tableau de bord', $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'));

        // Calendrier (année scolaire ⊃ trimestres ⊃ jours ouverts/fermés)
        add_submenu_page('psc_dashboard', 'Années scolaires', 'Années scolaires', $cap, 'psc_school_years', array(__CLASS__, 'page_school_years'));
        // Écran intermédiaire du passage d'année (récapitulatif + confirmation) :
        // pas un lien de menu à part entière, seulement atteint depuis
        // "Années scolaires" — menu_title à null pour ne pas apparaître dans
        // la barre latérale.
        add_submenu_page('psc_dashboard', 'Passage d\'année', null, $cap, 'psc_passage_annee', array(__CLASS__, 'page_passage_annee'));
        add_submenu_page('psc_dashboard', 'Trimestres', 'Trimestres', $cap, 'psc_trimestres', array(__CLASS__, 'page_trimestres'));
        add_submenu_page('psc_dashboard', 'Calendrier scolaire', 'Calendrier scolaire', $cap, 'psc_school_calendar', array(__CLASS__, 'page_school_calendar'));

        // Demandes & suivi
        $pending = Psc_Requests::pending_count();
        $req_label = $pending
            ? sprintf('Demandes <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $pending)
            : 'Demandes';
        add_submenu_page('psc_dashboard', "Demandes d'inscription", $req_label, $cap, 'psc_requests', array(__CLASS__, 'page_requests'));
        add_submenu_page('psc_dashboard', 'Présences déclarées', 'Présences déclarées', $cap, 'psc_inscriptions', array(__CLASS__, 'page_inscriptions'));

        // Familles
        add_submenu_page('psc_dashboard', 'Familles', 'Familles', $cap, 'psc_parents', array(__CLASS__, 'page_parents'));
        add_submenu_page('psc_dashboard', 'Enfants', 'Enfants', $cap, 'psc_children', array(__CLASS__, 'page_children'));
        // Fiche "Personnes autorisées" d'un enfant — accessible uniquement
        // depuis la ligne de l'enfant dans Enfants, jamais dans le menu.
        add_submenu_page('psc_dashboard', 'Personnes autorisées', null, $cap, 'psc_pickup_persons', array(__CLASS__, 'page_pickup_persons'));

        // Cantine
        add_submenu_page('psc_dashboard', 'Menus cantine', 'Menus cantine', $cap, 'psc_menus', array(__CLASS__, 'page_menus'));
        add_submenu_page('psc_dashboard', 'Commande fournisseur', 'Commande fournisseur', $cap, 'psc_supplier_orders', array(__CLASS__, 'page_supplier_orders'));

        // Facturation
        add_submenu_page('psc_dashboard', 'Factures', 'Factures', $cap, 'psc_factures', array(__CLASS__, 'page_factures'));

        // Configuration
        add_submenu_page('psc_dashboard', 'Modèles e-mails', 'Modèles e-mails', $cap, 'psc_email_templates', array(__CLASS__, 'page_email_templates'));
        add_submenu_page('psc_dashboard', 'Réglages', 'Réglages', $cap, 'psc_settings', array(__CLASS__, 'page_settings'));
    }

    /* ---------------- Tableau de bord ---------------- */

    /**
     * Indicateurs globaux, sans notion d'urgence — la liste "à faire"
     * (dashboard_todos) porte les actions concrètes.
     */
    protected static function dashboard_stats() {
        global $wpdb;
        $trimestre = $wpdb->get_row(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE active = 1 ORDER BY id DESC LIMIT 1'
        );
        return array(
            'trimestre'        => $trimestre,
            'familles_actives' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . psc_table('parents') . ' WHERE active = 1'),
            'enfants_actifs'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . psc_table('children') . " WHERE statut = 'actif'"),
        );
    }

    /**
     * Actions concrètes à faire dans les jours/semaines à venir, dérivées
     * de données déjà existantes (aucune nouvelle table) : demandes en
     * attente, menu et commande fournisseur de la semaine prochaine pas
     * encore envoyés, trimestre actif proche de sa fin ou absent. Chaque
     * entrée : array('label'=>, 'done'=>bool, 'url'=>).
     */
    protected static function dashboard_todos() {
        global $wpdb;
        $todos = array();

        $pending = Psc_Requests::pending_count();
        $todos[] = array(
            'label' => $pending > 0
                ? sprintf('%d demande(s) d\'inscription en attente de traitement', $pending)
                : 'Aucune demande d\'inscription en attente',
            'done'  => $pending === 0,
            'url'   => admin_url('admin.php?page=psc_requests'),
        );

        // Semaine prochaine, ramenée à la prochaine semaine ayant au moins un
        // jour d'école ouvert : inutile de rappeler à l'admin de saisir un
        // menu ou une commande fournisseur pour une semaine de vacances.
        $next_week = psc_next_open_week(gmdate('Y-m-d', strtotime('+7 days')));
        $next_week_label = date_i18n('d/m', strtotime($next_week));

        $menu = Psc_Menus::get_by_week($next_week);
        $menu_has_content = false;
        if ($menu) {
            foreach (Psc_Menus::JOURS as $jour) {
                if (trim((string) $menu->$jour) !== '') { $menu_has_content = true; break; }
            }
        }
        $menu_sent = $menu && $menu->sent_at;
        $todos[] = array(
            'label' => sprintf(
                'Menu de cantine — semaine du %s : %s',
                $next_week_label,
                $menu_sent ? 'envoyé' : ($menu_has_content ? 'saisi, pas encore envoyé' : 'pas encore saisi')
            ),
            'done' => (bool) $menu_sent,
            'url'  => admin_url('admin.php?page=psc_menus'),
        );

        $order_sent = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . psc_table('supplier_orders') . ' WHERE semaine_debut = %s', $next_week
        ));
        $todos[] = array(
            'label' => sprintf(
                'Commande fournisseur — semaine du %s : %s',
                $next_week_label,
                $order_sent ? 'envoyée' : 'pas encore envoyée'
            ),
            'done' => (bool) $order_sent,
            'url'  => admin_url('admin.php?page=psc_supplier_orders&semaine_debut=' . $next_week),
        );

        $trimestre = $wpdb->get_row(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE active = 1 ORDER BY id DESC LIMIT 1'
        );
        if (!$trimestre) {
            $todos[] = array(
                'label' => 'Aucun trimestre actif — créez-en un pour ouvrir les inscriptions',
                'done'  => false,
                'url'   => admin_url('admin.php?page=psc_trimestres'),
            );
        } else {
            $days_left = (int) floor((strtotime($trimestre->date_fin) - strtotime(current_time('Y-m-d'))) / DAY_IN_SECONDS);
            if ($days_left <= 14) {
                $todos[] = array(
                    'label' => $days_left >= 0
                        ? sprintf('Le trimestre actif se termine dans %d jour(s) — pensez à préparer le suivant', $days_left)
                        : 'Le trimestre actif est terminé — pensez à en activer un nouveau',
                    'done' => false,
                    'url'  => admin_url('admin.php?page=psc_trimestres'),
                );
            }
        }

        return $todos;
    }

    public static function page_dashboard() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $stats = self::dashboard_stats();
        $todos = self::dashboard_todos();
        include PSC_PATH . 'templates/admin-dashboard.php';
    }

    /* ---------------- Trimestres ---------------- */

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

    /* ---------------- Années scolaires ---------------- */

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
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-annees.php';
    }

    public static function page_passage_annee() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $staged = Psc_School_Years::staged_promotion();
        $from_year = $staged ? Psc_School_Years::get($staged['from_year_id']) : null;
        $to_year   = $staged ? Psc_School_Years::get($staged['to_year_id']) : null;
        $plan      = $staged ? $staged['plan'] : array();
        $classe_options = psc_classe_options();
        include PSC_PATH . 'templates/admin-passage-annee.php';
    }

    public static function handle_close_range() {
        self::guard('psc_close_range');
        global $wpdb;

        $trimestre_id = psc_post_int('trimestre_id');
        $debut = psc_valid_date(psc_post('date_debut'));
        $fin   = psc_valid_date(psc_post('date_fin'));
        $label = psc_post('label');
        if ($label === '') $label = 'Vacances';

        if (!$trimestre_id || !$debut || !$fin || strtotime($fin) < strtotime($debut)) {
            self::redirect('psc_trimestres', 'invalid_dates');
        }
        $span = (strtotime($fin) - strtotime($debut)) / DAY_IN_SECONDS;
        if ($span > psc_max_trimestre_days()) {
            self::redirect('psc_trimestres', 'too_long');
        }

        $t_trim = psc_table('trimestres');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_trim WHERE id = %d", $trimestre_id));
        if (!$exists) self::redirect('psc_trimestres', 'invalid');

        Psc_Installer::set_range_closed($trimestre_id, $debut, $fin, mb_substr($label, 0, 100));
        self::redirect('psc_trimestres', 'closed');
    }

    public static function page_trimestres() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-trimestres.php';
    }

    /* ---------------- Enfants ---------------- */

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

        if (!Psc_Parents::get_by_id($parent_id)) {
            self::redirect('psc_children', 'nouser');
        }

        $allowed = array_keys(psc_classe_options());
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

        $upload_dir = wp_upload_dir();
        $paths = $wpdb->get_col($wpdb->prepare(
            "SELECT assurance_file_path FROM $t_cy WHERE child_id = %d AND assurance_file_path IS NOT NULL",
            $child_id
        ));
        foreach ($paths as $rel_path) {
            $abs = trailingslashit($upload_dir['basedir']) . $rel_path;
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
        $upload_dir = wp_upload_dir();
        $pdf_paths = $wpdb->get_col($wpdb->prepare(
            "SELECT pdf_path FROM $t_inv WHERE parent_id = %d AND pdf_path IS NOT NULL", $id
        ));
        foreach ($pdf_paths as $rel_path) {
            $abs = trailingslashit($upload_dir['basedir']) . $rel_path;
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
        $psc_classe_labels = psc_classe_options();
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

    /* ---------------- Réglages ---------------- */

    public static function handle_save_settings() {
        self::guard('psc_save_settings');

        $prices = array();
        foreach (psc_allowed_services() as $code) {
            $raw = isset($_POST['price_' . $code]) ? wp_unslash($_POST['price_' . $code]) : '0';
            $val = floatval(str_replace(',', '.', sanitize_text_field($raw)));
            $prices[$code] = max(0, min(1000, $val));
        }
        update_option('psc_service_prices', $prices);

        // Délai de prévenance, borné pour éviter une valeur absurde.
        $hours = psc_post_int('lock_hours', 48);
        update_option('psc_lock_hours', max(0, min(720, $hours)));

        update_option('psc_notify_mairie', isset($_POST['notify_mairie']) ? 1 : 0);
        update_option('psc_auto_approve_requests', isset($_POST['auto_approve_requests']) ? 1 : 0);

        // Durées de validité des liens envoyés par e-mail, bornées pour
        // éviter une valeur absurde (lien de connexion : entre 5 min et
        // 24h ; lien de confirmation : entre 1 et 30 jours).
        $login_ttl_minutes = psc_post_int('login_link_ttl_minutes', 30);
        update_option('psc_login_link_ttl_minutes', max(5, min(1440, $login_ttl_minutes)));
        $email_ttl_days = psc_post_int('email_confirmation_ttl_days', 3);
        update_option('psc_email_confirmation_ttl_days', max(1, min(30, $email_ttl_days)));

        $sidscm_code = isset($_POST['sidscm_access_code']) ? sanitize_text_field(wp_unslash($_POST['sidscm_access_code'])) : '';
        update_option('psc_sidscm_access_code', mb_substr(trim($sidscm_code), 0, 40));
        update_option('psc_sidscm_page_id', psc_post_int('sidscm_page_id', 0));

        $mairie_mail = isset($_POST['mairie_email']) ? sanitize_email(wp_unslash($_POST['mairie_email'])) : '';
        update_option('psc_mairie_email', is_email($mairie_mail) ? $mairie_mail : '');

        $supplier_mail = isset($_POST['supplier_email']) ? sanitize_email(wp_unslash($_POST['supplier_email'])) : '';
        update_option('psc_supplier_email', is_email($supplier_mail) ? $supplier_mail : '');

        $ics_url = isset($_POST['school_calendar_ics_url']) ? esc_url_raw(wp_unslash($_POST['school_calendar_ics_url'])) : '';
        update_option('psc_school_calendar_ics_url', $ics_url);

        // Billing / invoice settings
        $billing_fields = array(
            'psc_billing_org_intro'   => 'sanitize_text_field',
            'psc_billing_org_name'    => 'sanitize_text_field',
            'psc_billing_org_address' => 'sanitize_text_field',
            'psc_billing_org_phone'   => 'sanitize_text_field',
            'psc_billing_org_fax'     => 'sanitize_text_field',
            'psc_billing_org_email'   => 'sanitize_email',
            'psc_billing_org_city'    => 'sanitize_text_field',
            'psc_billing_org_ics'     => 'sanitize_text_field',
            'psc_billing_footer'      => 'sanitize_text_field',
        );
        foreach ($billing_fields as $option => $sanitizer) {
            $post_key = str_replace('psc_billing_', '', $option);
            $val = isset($_POST[$post_key]) ? call_user_func($sanitizer, wp_unslash($_POST[$post_key])) : '';
            update_option($option, $val);
        }
        update_option('psc_billing_logo_left_id',  absint(isset($_POST['logo_left_id'])  ? $_POST['logo_left_id']  : 0));
        update_option('psc_billing_logo_right_id', absint(isset($_POST['logo_right_id']) ? $_POST['logo_right_id'] : 0));

        update_option('psc_doc_reglement_interieur_id',   absint(isset($_POST['doc_reglement_interieur_id'])   ? $_POST['doc_reglement_interieur_id']   : 0));
        update_option('psc_doc_reglement_prelevement_id', absint(isset($_POST['doc_reglement_prelevement_id']) ? $_POST['doc_reglement_prelevement_id'] : 0));

        // Table de correspondance des classes (passage d'année) : un select
        // par classe existante vers sa classe suivante, ou "sortie".
        $progression = array();
        foreach (array_keys(psc_classe_options()) as $code) {
            if ($code === '') continue;
            $next = isset($_POST['progression_' . $code]) ? sanitize_text_field(wp_unslash($_POST['progression_' . $code])) : 'sortie';
            $progression[$code] = $next;
        }
        update_option('psc_classe_progression', $progression);

        $reins_debut = psc_valid_date(psc_post('reinscription_debut'));
        $reins_fin   = psc_valid_date(psc_post('reinscription_fin'));
        update_option('psc_reinscription_debut', $reins_debut ?: '');
        update_option('psc_reinscription_fin', $reins_fin ?: '');

        self::redirect('psc_settings', 'saved');
    }

    public static function page_settings() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $services = psc_services();
        $psc_classe_progression = psc_classe_progression();
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-settings.php';
    }

    /* ---------------- Inscriptions (édition admin) ---------------- */

    public static function page_inscriptions() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;

        $trimestres = $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
        $trimestre_id = psc_get_int('trimestre_id');
        if (!$trimestre_id && $trimestres) {
            foreach ($trimestres as $t) {
                if ($t->active) { $trimestre_id = (int) $t->id; break; }
            }
            if (!$trimestre_id && $trimestres) $trimestre_id = (int) $trimestres[0]->id;
        }
        $trimestre = null;
        foreach ($trimestres as $t) {
            if ((int) $t->id === $trimestre_id) { $trimestre = $t; break; }
        }

        $parents   = Psc_Parents::all();
        $parent_id = psc_get_int('parent_id');
        $selected_parent = $parent_id ? Psc_Parents::get_by_id($parent_id) : null;

        $children      = array();
        $days_by_month = array();
        $reg_map       = array();
        $services      = psc_services();

        if ($selected_parent && $trimestre_id) {
            $t_child  = psc_table('children');
            $t_cy     = psc_table('child_school_years');
            $year_id  = $trimestre ? (int) $trimestre->school_year_id : Psc_School_Years::active_id();
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT c.*, cy.classe AS classe
                 FROM $t_child c
                 LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
                 WHERE c.parent_id = %d ORDER BY c.nom",
                $year_id, $parent_id
            ));

            $t_days = psc_table('calendar_days');
            $days   = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date", $trimestre_id
            ));
            foreach ($days as $d) {
                $month_key = date_i18n('F Y', strtotime($d->jour_date));
                $days_by_month[$month_key][] = $d;
            }

            if (!empty($children)) {
                $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
                $ph        = implode(',', array_fill(0, count($child_ids), '%d'));
                $t_reg     = psc_table('registrations');
                $regs      = $wpdb->get_results($wpdb->prepare(
                    "SELECT child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
                    array_merge(array($trimestre_id), $child_ids)
                ));
                foreach ($regs as $r) {
                    $reg_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = true;
                }
            }
        }

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-inscriptions.php';
    }

    public static function handle_admin_update_registrations() {
        self::guard('psc_admin_update_registrations');
        global $wpdb;

        $parent_id    = psc_post_int('parent_id');
        $trimestre_id = psc_post_int('trimestre_id');
        if (!$parent_id || !$trimestre_id) self::redirect('psc_inscriptions', 'invalid');

        $parent = Psc_Parents::get_by_id($parent_id);
        if (!$parent) self::redirect('psc_inscriptions', 'invalid');

        $trimestre = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE id = %d', $trimestre_id
        ));
        if (!$trimestre) self::redirect('psc_inscriptions', 'invalid');

        $t_child  = psc_table('children');
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d ORDER BY nom", $parent_id
        ));
        if (empty($children)) {
            self::redirect_to_inscriptions($parent_id, $trimestre_id, 'saved');
        }

        $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
        $ph        = implode(',', array_fill(0, count($child_ids), '%d'));
        $t_days    = psc_table('calendar_days');
        $open_days   = $wpdb->get_col($wpdb->prepare(
            "SELECT jour_date FROM $t_days WHERE trimestre_id = %d AND is_open = 1", $trimestre_id
        ));
        $open_set = array_flip($open_days);

        // Build set of submitted registrations (only for valid children + open dates + allowed services).
        $submitted  = array();
        $regs_post  = isset($_POST['regs']) && is_array($_POST['regs']) ? $_POST['regs'] : array();
        foreach ($regs_post as $cid => $dates) {
            $cid = (int) $cid;
            if (!in_array($cid, $child_ids, true)) continue;
            if (!is_array($dates)) continue;
            foreach ($dates as $date => $svcs) {
                $date = sanitize_text_field(wp_unslash($date));
                if (!isset($open_set[$date])) continue;
                if (!is_array($svcs)) continue;
                foreach ($svcs as $svc => $on) {
                    $svc = strtoupper(sanitize_key($svc));
                    if (!in_array($svc, psc_allowed_services(), true)) continue;
                    $submitted[$cid . '|' . $date . '|' . $svc] = true;
                }
            }
        }

        // Current registrations for this family + trimestre.
        $t_reg      = psc_table('registrations');
        $current_rs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
            array_merge(array($trimestre_id), $child_ids)
        ));
        $current_map = array();
        foreach ($current_rs as $r) {
            $current_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = (int) $r->id;
        }

        // Compute diff before applying (for the email).
        $diff_added   = array_keys(array_diff_key($submitted, $current_map));
        $diff_removed = array_keys(array_diff_key($current_map, $submitted));

        // Apply diff.
        foreach (array_diff_key($current_map, $submitted) as $key => $reg_id) {
            $wpdb->delete($t_reg, array('id' => $reg_id), array('%d'));
        }
        foreach ($diff_added as $key) {
            list($cid, $date, $svc) = explode('|', $key);
            $wpdb->insert($t_reg, array(
                'trimestre_id' => $trimestre_id,
                'child_id'     => (int) $cid,
                'jour_date'    => $date,
                'service'      => $svc,
            ), array('%d', '%d', '%s', '%s'));
        }

        // Re-fetch final state for email.
        $new_regs = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service FROM $t_reg WHERE trimestre_id = %d AND child_id IN ($ph)",
            array_merge(array($trimestre_id), $child_ids)
        ));
        $reg_map = array();
        foreach ($new_regs as $r) {
            $reg_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = true;
        }

        Psc_Mailer::send_admin_correction($parent, $trimestre, $children, $reg_map, psc_services(), $diff_added, $diff_removed);

        self::redirect_to_inscriptions($parent_id, $trimestre_id, 'saved');
    }

    private static function redirect_to_inscriptions($parent_id, $trimestre_id, $msg) {
        wp_safe_redirect(add_query_arg(array(
            'page'         => 'psc_inscriptions',
            'parent_id'    => $parent_id,
            'trimestre_id' => $trimestre_id,
            'psc_msg'      => $msg,
        ), admin_url('admin.php')));
        exit;
    }

    public static function handle_export_csv() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_export_csv');

        global $wpdb;
        $trimestre_id = psc_get_int('trimestre_id');
        if (!$trimestre_id) {
            wp_die(esc_html__('Trimestre invalide.', 'periscolaire-registration'));
        }

        $year_id = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT school_year_id FROM ' . psc_table('trimestres') . ' WHERE id = %d', $trimestre_id
        ));

        $t_reg = psc_table('registrations');
        $t_child = psc_table('children');
        $t_cy = psc_table('child_school_years');
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT r.jour_date, r.service, c.nom, c.prenom, cy.classe, p.email AS parent_email
             FROM $t_reg r
             JOIN $t_child c ON c.id = r.child_id
             LEFT JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             LEFT JOIN " . psc_table('parents') . " p ON p.id = c.parent_id
             WHERE r.trimestre_id = %d ORDER BY c.nom, r.jour_date", $year_id, $trimestre_id
        ));

        $filename = 'inscriptions-periscolaire-' . $trimestre_id . '-' . gmdate('Ymd') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($out, array('Nom', 'Prénom', 'Classe', 'Contact parent', 'Date', 'Jour', 'Service'), ';');
        foreach ($data as $row) {
            // psc_csv_escape() neutralise les formules Excel : les noms
            // proviennent d'une saisie parent, donc de données non fiables.
            fputcsv($out, array(
                psc_csv_escape($row->nom),
                psc_csv_escape($row->prenom),
                psc_csv_escape($row->classe),
                psc_csv_escape($row->parent_email),
                $row->jour_date,
                psc_day_label($row->jour_date),
                $row->service,
            ), ';');
        }
        fclose($out);
        exit;
    }

    /* ---------------- Familles (comptes parents du plugin) ---------------- */

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

    /* ---------------- Modèles e-mails ---------------- */

    public static function handle_save_email_templates() {
        self::guard('psc_save_email_templates');
        $input = isset($_POST['templates']) ? wp_unslash($_POST['templates']) : array();
        Psc_Email_Templates::save(is_array($input) ? $input : array());
        self::redirect('psc_email_templates', 'saved');
    }

    public static function handle_reset_email_template() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $key = isset($_GET['key']) ? sanitize_key(wp_unslash($_GET['key'])) : '';
        check_admin_referer('psc_reset_email_template_' . $key);
        if ($key) {
            Psc_Email_Templates::reset($key);
        }
        self::redirect('psc_email_templates', 'reset_one');
    }

    public static function handle_reset_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        check_admin_referer('psc_reset_email_templates');
        Psc_Email_Templates::reset();
        self::redirect('psc_email_templates', 'reset_all');
    }

    public static function page_email_templates() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $templates = Psc_Email_Templates::get_all();
        $psc_msg   = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-email-templates.php';
    }

    /* ---------------- Factures ---------------- */

    public static function handle_generate_invoices() {
        self::guard('psc_generate_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $count = Psc_Invoices::generate_month($mois);
        if (is_wp_error($count)) {
            $code = $count->get_error_code();
            self::redirect('psc_factures', $code === 'month_not_finished' ? 'month_not_finished' : 'gen_error');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => ($count > 0 ? 'generated' : 'gen_zero')),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_send_invoice() {
        self::guard('psc_send_invoice');

        $invoice_id = psc_post_int('invoice_id');
        $mois       = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!$invoice_id) {
            self::redirect('psc_factures', 'invalid');
        }

        $result = Psc_Invoices::send($invoice_id);
        $msg    = is_wp_error($result) ? $result->get_error_code() : 'sent';
        if ($msg !== 'no_file' && is_wp_error($result)) {
            $msg = 'mail_failed';
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_send_all_invoices() {
        self::guard('psc_send_all_invoices');

        $mois = isset($_POST['mois']) ? sanitize_text_field(wp_unslash($_POST['mois'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            self::redirect('psc_factures', 'invalid');
        }

        $invoices = Psc_Invoices::get_for_month($mois);
        foreach ($invoices as $inv) {
            if (!$inv->sent_at) {
                Psc_Invoices::send((int) $inv->id);
            }
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_factures', 'mois' => $mois, 'psc_msg' => 'sent_all'),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_download_invoice() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $invoice_id = psc_get_int('invoice_id');
        check_admin_referer('psc_download_invoice_' . $invoice_id);
        Psc_Invoices::download($invoice_id);
    }

    /**
     * Consultation par la mairie d'un justificatif d'assurance scolaire.
     * Lecture seule : aucune validation/rejet n'existe pour l'instant
     * (auto-validation à l'upload, cf. Psc_Frontend::handle_parent_upload_assurance()).
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

        Psc_Frontend::stream_assurance_file($doc->assurance_file_path, $doc->assurance_original_filename);
    }

    /**
     * Consultation par la mairie d'un justificatif déposé avec une demande
     * d'inscription pas encore approuvée (zone d'attente, aucun child_id
     * n'existe encore). Le chemin n'est jamais pris depuis la requête
     * cliente : toujours re-dérivé de children_json par index, comme dans
     * Psc_Requests::handle_approve().
     */
    public static function handle_download_pending_assurance() {
        if (!psc_user_can_manage()) {
            wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        }
        $request_id = psc_get_int('request_id');
        $index      = psc_get_int('index');
        check_admin_referer('psc_download_pending_assurance_' . $request_id . '_' . $index);

        $req = Psc_Requests::get($request_id);
        if (!$req) {
            wp_die(esc_html__('Demande introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }
        $children = Psc_Requests::children_of($req);
        if (empty($children[$index]['assurance_rel_path'])) {
            wp_die(esc_html__('Aucun justificatif pour cet enfant.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Frontend::stream_assurance_file($children[$index]['assurance_rel_path'], $children[$index]['assurance_original_filename']);
    }

    public static function page_factures() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $all_months    = Psc_Invoices::months_with_data();
        $selected_mois = isset($_GET['mois']) ? sanitize_text_field(wp_unslash($_GET['mois'])) : '';
        if (!$selected_mois && !empty($all_months)) {
            $selected_mois = $all_months[0];
        }
        $invoices = $selected_mois ? Psc_Invoices::get_for_month($selected_mois) : array();
        $psc_msg  = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        include PSC_PATH . 'templates/admin-factures.php';
    }

    /* ---------------- Menus cantine ---------------- */

    public static function handle_save_menu() {
        self::guard('psc_save_menu');

        $id      = psc_post_int('id');
        $semaine = psc_post('semaine_debut');
        $jours   = array();
        foreach (Psc_Menus::JOURS as $jour) {
            // Pas psc_post() ici : sanitize_text_field() collapse les
            // retours à la ligne (conçu pour du texte sur une ligne). Ces
            // champs sont des textarea multi-lignes ; Psc_Menus::save()
            // applique déjà sanitize_textarea_field(), qui les préserve —
            // un seul point de sanitization, sur la bonne fonction.
            $jours[$jour] = isset($_POST[$jour]) ? wp_unslash($_POST[$jour]) : '';
        }

        $result = Psc_Menus::save($id, $semaine, $jours);
        if (is_wp_error($result)) {
            self::redirect('psc_menus', 'invalid');
        }
        self::redirect_to_menu(psc_week_start($semaine), 'saved');
    }

    public static function handle_send_menu() {
        self::guard('psc_send_menu');

        $id   = psc_post_int('id');
        $menu = Psc_Menus::get($id);
        if (!$menu) self::redirect('psc_menus', 'invalid');

        $count = Psc_Menus::send($menu);
        self::redirect_to_menu($menu->semaine_debut, $count > 0 ? 'sent' : 'sent_zero');
    }

    public static function handle_delete_menu() {
        self::guard('psc_delete_menu');
        $id = psc_post_int('id');
        if ($id) Psc_Menus::delete($id);
        self::redirect('psc_menus', 'deleted');
    }

    private static function redirect_to_menu($semaine, $msg) {
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_menus', 'semaine_debut' => $semaine, 'psc_msg' => $msg),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function page_menus() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        // Une semaine = un menu (Psc_Menus::save() les fusionne déjà par
        // semaine) : le formulaire est ancré sur la semaine, pas sur un id.
        // Par défaut, la prochaine semaine ayant au moins un jour d'école
        // ouvert — ce formulaire sert à préparer le menu à venir, pas à
        // consulter l'historique (la liste ci-dessous s'en charge).
        $requested   = isset($_GET['semaine_debut']) ? psc_valid_date(wp_unslash($_GET['semaine_debut'])) : false;
        $target_week = $requested
            ? psc_week_start($requested)
            : Psc_Menus::next_open_week(gmdate('Y-m-d', strtotime('+7 days')));

        $open_days = Psc_Menus::open_days($target_week);
        $editing   = Psc_Menus::get_by_week($target_week);

        $recent  = Psc_Menus::recent(12);
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';

        include PSC_PATH . 'templates/admin-menus.php';
    }

    /* ---------------- Commande fournisseur ---------------- */

    public static function handle_send_supplier_order() {
        self::guard('psc_send_supplier_order');

        $semaine = psc_post('semaine_debut');
        $result  = Psc_Supplier_Orders::send($semaine);

        if (is_wp_error($result)) {
            $known = array('psc_invalid_week', 'psc_no_supplier_email', 'psc_mail_failed');
            $msg   = in_array($result->get_error_code(), $known, true) ? $result->get_error_code() : 'error';
            wp_safe_redirect(add_query_arg(
                array('page' => 'psc_supplier_orders', 'semaine_debut' => $semaine, 'psc_msg' => $msg),
                admin_url('admin.php')
            ));
            exit;
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_supplier_orders', 'semaine_debut' => $semaine, 'psc_msg' => 'sent'),
            admin_url('admin.php')
        ));
        exit;
    }

    protected static function pending_cantine_key() {
        return 'psc_pending_cantine_' . get_current_user_id();
    }

    /**
     * Annulation de la cantine pour une classe entière un jour donné
     * (sortie scolaire...). Même logique avertissement -> confirmation
     * que Psc_Admin::handle_close_school_day() : rien n'est supprimé au
     * premier passage si des inscriptions existent, l'admin doit
     * confirmer explicitement après avoir vu qui est concerné.
     */
    public static function handle_cancel_class_meals() {
        self::guard('psc_cancel_class_meals');

        $date    = psc_valid_date(psc_post('date'));
        $classe  = psc_post('classe');
        $reason  = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
        $confirm = psc_post_int('confirm');

        if (!$date) {
            self::redirect('psc_supplier_orders', 'cantine_invalid');
        }
        if ($reason === '') {
            self::redirect('psc_supplier_orders', 'cantine_reason_required');
        }

        $affected = Psc_Supplier_Orders::cantine_registrations_for_class_day($date, $classe);
        if (empty($affected)) {
            self::redirect('psc_supplier_orders', 'cantine_none');
        }

        if (!$confirm) {
            set_transient(
                self::pending_cantine_key(),
                array('date' => $date, 'classe' => $classe, 'reason' => $reason),
                10 * MINUTE_IN_SECONDS
            );
            self::redirect('psc_supplier_orders', 'cantine_confirm_needed');
        }

        delete_transient(self::pending_cantine_key());
        $result = Psc_Supplier_Orders::cancel_class_meals($date, $classe, $reason);
        if (is_wp_error($result)) {
            self::redirect('psc_supplier_orders', 'cantine_invalid');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_supplier_orders', 'psc_msg' => 'cantine_cancelled', 'n' => (int) $result),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function handle_dismiss_cancel_class_meals() {
        self::guard('psc_dismiss_cancel_class_meals');
        delete_transient(self::pending_cantine_key());
        self::redirect('psc_supplier_orders', 'cantine_dismissed');
    }

    public static function page_supplier_orders() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $requested     = isset($_GET['semaine_debut']) ? sanitize_text_field(wp_unslash($_GET['semaine_debut'])) : '';
        $semaine_debut = psc_week_start($requested) ?: psc_next_open_week(gmdate('Y-m-d', strtotime('+7 days')));

        $preview = Psc_Supplier_Orders::compute_counts($semaine_debut);
        $recent  = Psc_Supplier_Orders::recent(20);
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $cantine_n = psc_get_int('n');

        $pending_cantine = get_transient(self::pending_cantine_key());
        $pending_cantine_affected = $pending_cantine
            ? Psc_Supplier_Orders::cantine_registrations_for_class_day($pending_cantine['date'], $pending_cantine['classe'])
            : array();

        include PSC_PATH . 'templates/admin-supplier-orders.php';
    }

    /* ---------------- Calendrier scolaire ---------------- */

    protected static function pending_close_key() {
        return 'psc_pending_close_' . get_current_user_id();
    }

    public static function handle_import_school_calendar() {
        self::guard('psc_import_school_calendar');

        $result = Psc_School_Calendar::import();
        if (is_wp_error($result)) {
            self::redirect('psc_school_calendar', 'import_failed');
        }
        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_school_calendar', 'psc_msg' => 'imported', 'n' => (int) $result),
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
            self::redirect('psc_school_calendar', 'upload_failed');
        }

        $file     = $_FILES['ics_file'];
        $filetype = wp_check_filetype($file['name'], array('ics' => 'text/calendar'));
        if ($filetype['ext'] !== 'ics') {
            self::redirect('psc_school_calendar', 'upload_invalid_type');
        }
        if ($file['size'] > 2 * MB_IN_BYTES) {
            self::redirect('psc_school_calendar', 'upload_too_large');
        }

        $body = file_get_contents($file['tmp_name']);
        $result = Psc_School_Calendar::import_from_upload($body);
        if (is_wp_error($result)) {
            self::redirect('psc_school_calendar', 'upload_failed');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_school_calendar', 'psc_msg' => 'uploaded', 'n' => (int) $result),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Fermeture manuelle d'un jour. Si des inscriptions existent déjà ce
     * jour-là, on n'exécute rien au premier passage : on stocke la
     * demande et on redirige vers un écran d'avertissement. Ce n'est
     * qu'après confirmation explicite (confirm=1) que la fermeture, la
     * suppression des inscriptions et l'e-mail aux familles ont lieu.
     */
    public static function handle_close_school_day() {
        self::guard('psc_close_school_day');

        $date  = psc_valid_date(psc_post('date'));
        $label = psc_post('label');
        $confirm = psc_post_int('confirm');

        if (!$date) {
            self::redirect('psc_school_calendar', 'invalid');
        }

        $affected = Psc_School_Calendar::affected_families($date);

        if ($affected['registrations'] > 0 && !$confirm) {
            set_transient(self::pending_close_key(), array('date' => $date, 'label' => $label), 10 * MINUTE_IN_SECONDS);
            self::redirect('psc_school_calendar', 'confirm_needed');
        }

        delete_transient(self::pending_close_key());
        Psc_School_Calendar::close_day($date, $label);
        self::redirect('psc_school_calendar', 'closed');
    }

    public static function handle_cancel_school_day_close() {
        self::guard('psc_cancel_school_day_close');
        delete_transient(self::pending_close_key());
        self::redirect('psc_school_calendar', 'cancelled');
    }

    public static function handle_open_school_day() {
        self::guard('psc_open_school_day');

        $date = psc_valid_date(psc_post('date'));
        if (!$date) {
            self::redirect('psc_school_calendar', 'invalid');
        }

        Psc_School_Calendar::open_day($date);
        self::redirect('psc_school_calendar', 'opened');
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

    public static function page_school_calendar() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));

        $rows   = Psc_School_Calendar::all();
        $groups = self::group_closed_days($rows);

        $pending = get_transient(self::pending_close_key());
        $pending_affected = $pending ? Psc_School_Calendar::affected_families($pending['date']) : null;

        $imported_at = get_option('psc_school_calendar_imported_at', '');
        $psc_msg     = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $imported_n  = psc_get_int('n');

        include PSC_PATH . 'templates/admin-school-calendar.php';
    }

    public static function page_requests() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        global $wpdb;
        $pending = Psc_Requests::by_status('pending');
        $t   = psc_table('requests');
        $t_p = psc_table('parents');
        $handled = $wpdb->get_results(
            "SELECT r.*, COALESCE(p.nom, r.nom) AS nom
             FROM $t r
             LEFT JOIN $t_p p ON p.email = r.email
             WHERE r.status IN ('approved','rejected')
             ORDER BY r.decided_at DESC LIMIT 100"
        );
        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        include PSC_PATH . 'templates/admin-requests.php';
    }
}
