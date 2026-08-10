<?php
if (!defined('ABSPATH')) exit;

class Psc_Frontend {

    public static function init() {
        add_shortcode('periscolaire_form', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

        // Une fois connectée, la famille n'a plus besoin du texte de
        // présentation rédigé autour du shortcode dans l'éditeur (utile
        // uniquement avant connexion, pour donner envie/expliquer) : le
        // portail occupe alors toute la page, dès le haut.
        add_filter('the_content', array(__CLASS__, 'hide_page_chrome_when_connected'), 20);

        // Le titre de la page (H1 "wp-block-post-title") vient du modèle
        // de page, pas de post_content : le filtre the_content ci-dessus
        // ne peut pas l'atteindre. On ajoute une classe sur <body> pour le
        // masquer en CSS uniquement quand le portail est affiché.
        add_filter('body_class', array(__CLASS__, 'add_portal_body_class'));

        // Les parents ne sont PAS des utilisateurs WordPress : les actions
        // AJAX doivent donc être exposées en "nopriv". L'autorisation est
        // vérifiée dans chaque handler via la session du plugin.
        add_action('wp_ajax_nopriv_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_nopriv_psc_confirm', array(__CLASS__, 'ajax_confirm'));
        add_action('wp_ajax_psc_confirm', array(__CLASS__, 'ajax_confirm'));

        // Gestion des enfants par le parent (formulaires POST classiques).
        add_action('admin_post_nopriv_psc_parent_update_child', array(__CLASS__, 'handle_parent_update_child'));
        add_action('admin_post_psc_parent_update_child', array(__CLASS__, 'handle_parent_update_child'));
        add_action('admin_post_nopriv_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));
        add_action('admin_post_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));

        add_action('admin_post_nopriv_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));
        add_action('admin_post_psc_parent_download_invoice', array(__CLASS__, 'handle_parent_download_invoice'));
    }

    /**
     * Remplace tout le contenu de la page par le seul rendu du shortcode
     * quand une famille est connectée — le texte de présentation autour
     * (rédigé dans l'éditeur) n'a de sens qu'avant connexion. On ignore
     * volontairement $content : on ré-exécute le shortcode nous-mêmes
     * plutôt que d'essayer d'en extraire le rendu déjà mélangé au reste
     * de la page, ce qui évite toute dépendance à l'ordre des filtres
     * `the_content`.
     */
    public static function hide_page_chrome_when_connected($content) {
        if (!self::portal_takes_over_page()) return $content;
        return self::shortcode(array());
    }

    /** Ajoute une classe CSS sur <body> pour masquer le titre de page (venu
     *  du modèle, hors de portée de the_content) quand le portail s'affiche. */
    public static function add_portal_body_class($classes) {
        if (self::portal_takes_over_page()) {
            $classes[] = 'psc-portal-active';
        }
        return $classes;
    }

    /** Vrai si la page courante affiche le portail famille connecté (donc
     *  doit céder toute la page — titre et texte d'intro compris). */
    protected static function portal_takes_over_page() {
        if (!is_singular() || !Psc_Parents::current()) return false;
        $post = get_post();
        return $post && has_shortcode($post->post_content, 'periscolaire_form');
    }

    public static function assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_form')) return;

        wp_enqueue_style('psc-frontend', PSC_URL . 'assets/css/frontend.css', array(), PSC_VERSION);
        wp_enqueue_script('psc-frontend', PSC_URL . 'assets/js/frontend.js', array(), PSC_VERSION, true);
        wp_localize_script('psc-frontend', 'PSC', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_front'),
        ));

        // Design v2 : commun au portail connecté et à la vue invité —
        // psc-frontend reste le socle partagé par les deux (bascule de
        // connexion, cases à cocher du calendrier, popins, sauvegarde de
        // défilement).
        wp_enqueue_style('psc-portal', PSC_URL . 'assets/css/portal.css', array('psc-frontend'), PSC_VERSION);

        if (Psc_Parents::current()) {
            wp_enqueue_script('psc-portal', PSC_URL . 'assets/js/portal.js', array(), PSC_VERSION, true);
        } else {
            wp_enqueue_script('psc-guest', PSC_URL . 'assets/js/guest.js', array(), PSC_VERSION, true);
        }
    }

    protected static function active_trimestre() {
        global $wpdb;
        $t_trim = psc_table('trimestres');
        return $wpdb->get_row("SELECT * FROM $t_trim WHERE active = 1 ORDER BY id DESC LIMIT 1");
    }

    protected static function children_of($parent_id, $active_only = false) {
        global $wpdb;
        $t_child = psc_table('children');
        $where   = $active_only ? 'AND active = 1' : '';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_child WHERE parent_id = %d $where ORDER BY prenom", $parent_id
        ));
    }

    /**
     * Construit la table des inscriptions existantes pour une liste d'enfants.
     * Clé : childId|date|service
     */
    protected static function reg_map($trimestre_id, $children) {
        global $wpdb;
        if (empty($children)) return array();

        $ids = array_map('intval', wp_list_pluck($children, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $t_reg = psc_table('registrations');
        $params = array_merge(array($trimestre_id), $ids);

        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service FROM $t_reg
             WHERE trimestre_id = %d AND child_id IN ($placeholders)",
            $params
        ));

        $map = array();
        foreach ($regs as $r) {
            $map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = 1;
        }
        return $map;
    }

    /* ---------------- AJAX : cocher / décocher ---------------- */

    public static function ajax_toggle() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        global $wpdb;

        $child_id = psc_post_int('child_id');
        $service  = psc_post('service');
        $date     = psc_valid_date(psc_post('date'));
        $checked  = psc_post('checked') === '1';

        if (!$child_id || !$date) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        if (!in_array($service, psc_allowed_services(), true)) {
            wp_send_json_error(array('code' => 'service'), 400);
        }

        // L'enfant doit appartenir au parent de la session en cours.
        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        if (!$child) {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ((int) $child->parent_id !== (int) $parent->id) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }

        $trimestre = self::active_trimestre();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $t_days = psc_table('calendar_days');
        $day = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
            $trimestre->id, $date
        ));
        if (!$day) {
            wp_send_json_error(array('code' => 'day_closed'), 403);
        }

        // Délai de prévenance : vérifié CÔTÉ SERVEUR. Désactiver la case
        // dans le navigateur ne suffit pas, un utilisateur peut réactiver
        // le champ ou rejouer la requête.
        if (psc_is_locked($date)) {
            wp_send_json_error(array(
                'code'    => 'locked',
                'message' => sprintf(
                    'Ce jour n\'est plus modifiable en ligne (délai de %d h dépassé). Contactez la mairie.',
                    psc_lock_hours()
                ),
            ), 403);
        }

        $t_reg = psc_table('registrations');
        if ($checked) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                $child_id, $trimestre->id, $date, $service, current_time('mysql')
            ));
            // FORF inclut GM+CANT+GS : retirer les prestations individuelles si elles existent
            if ($service === 'FORF') {
                foreach (array('GM', 'CANT', 'GS') as $svc) {
                    $wpdb->delete($t_reg,
                        array('child_id' => $child_id, 'jour_date' => $date, 'service' => $svc),
                        array('%d', '%s', '%s')
                    );
                }
            }
            // Une prestation individuelle est incompatible avec FORF
            if (in_array($service, array('GM', 'CANT', 'GS'), true)) {
                $wpdb->delete($t_reg,
                    array('child_id' => $child_id, 'jour_date' => $date, 'service' => 'FORF'),
                    array('%d', '%s', '%s')
                );
            }
        } else {
            $wpdb->delete(
                $t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                array('%d', '%s', '%s')
            );
        }

        wp_send_json_success();
    }

    /* ---------------- AJAX : valider et recevoir le récapitulatif ---------------- */

    public static function ajax_confirm() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        // Évite l'envoi répété de récapitulatifs (clics multiples).
        if (!psc_rate_limit('recap_' . $parent->id, 5, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array(
                'code'    => 'rate',
                'message' => 'Plusieurs récapitulatifs viennent d\'être envoyés. Merci de patienter quelques minutes.',
            ), 429);
        }

        $trimestre = self::active_trimestre();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $children = self::children_of($parent->id);
        if (empty($children)) {
            wp_send_json_error(array('code' => 'nochild'), 400);
        }

        $reg_map = self::reg_map($trimestre->id, $children);

        // Calcul du diff par rapport au dernier récapitulatif envoyé
        $snapshot_key = 'psc_recap_snap_' . $parent->id . '_' . $trimestre->id;
        $prev_map     = get_transient($snapshot_key);
        if (!is_array($prev_map)) $prev_map = array();

        $diff_added   = array_keys(array_diff_key($reg_map, $prev_map));
        $diff_removed = array_keys(array_diff_key($prev_map, $reg_map));

        set_transient($snapshot_key, $reg_map, 180 * DAY_IN_SECONDS);

        $sent = Psc_Mailer::send_recap($parent, $trimestre, $children, $reg_map, psc_services(), $diff_added, $diff_removed);

        if (!$sent) {
            wp_send_json_error(array(
                'code'    => 'mail',
                'message' => 'L\'envoi de l\'e-mail a échoué. Vos inscriptions sont bien enregistrées ; contactez la mairie si besoin.',
            ), 500);
        }

        wp_send_json_success(array(
            'message' => sprintf('Récapitulatif envoyé à %s.', $parent->email),
        ));
    }

    /* ---------------- Gestion des enfants par le parent ---------------- */

    protected static function parent_form_redirect($msg) {
        wp_safe_redirect(add_query_arg('psc_msg', $msg, Psc_Mailer::form_page_url()));
        exit;
    }

    public static function handle_parent_update_child() {
        check_admin_referer('psc_parent_update_child');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $child_id  = psc_post_int('child_id');
        $classe    = psc_post('classe');
        $active    = isset($_POST['active']) ? 1 : 0;
        $sans_porc = isset($_POST['sans_porc']) ? 1 : 0;
        $vegan     = isset($_POST['vegan']) ? 1 : 0;

        $allowed = array_keys(psc_classe_options());
        if (!in_array($classe, $allowed, true)) $classe = '';

        global $wpdb;
        $t_child = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('invalid');

        $wpdb->update(
            $t_child,
            array('classe' => $classe, 'active' => $active, 'sans_porc' => $sans_porc, 'vegan' => $vegan),
            array('id' => $child_id),
            array('%s', '%d', '%d', '%d'),
            array('%d')
        );
        self::parent_form_redirect('child_updated');
    }

    public static function handle_parent_add_child() {
        check_admin_referer('psc_parent_add_child');

        $parent = Psc_Parents::current();
        if (!$parent) self::parent_form_redirect('auth');

        $prenom    = psc_post('new_prenom');
        $nom       = psc_post('new_nom');
        $classe    = psc_post('new_classe');
        $sans_porc = isset($_POST['new_sans_porc']) ? 1 : 0;
        $vegan     = isset($_POST['new_vegan']) ? 1 : 0;

        if ($prenom === '' || $nom === '') self::parent_form_redirect('child_invalid');

        $allowed = array_keys(psc_classe_options());
        if (!in_array($classe, $allowed, true)) $classe = '';

        global $wpdb;
        $t_child = psc_table('children');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_child WHERE parent_id = %d", $parent->id
        ));
        if ($count >= psc_max_children_per_user()) self::parent_form_redirect('child_limit');

        $wpdb->insert($t_child, array(
            'parent_id'  => $parent->id,
            'nom'        => mb_substr($nom, 0, 190),
            'prenom'     => mb_substr($prenom, 0, 190),
            'classe'     => mb_substr($classe, 0, 100),
            'sans_porc'  => $sans_porc,
            'vegan'      => $vegan,
            'active'     => 1,
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s'));

        self::parent_form_redirect('child_added');
    }

    /* ---------------- Factures (espace famille) ---------------- */

    /**
     * Téléchargement d'une facture par la famille elle-même. Le nonce
     * prouve l'intention, mais le contrôle qui compte vraiment est la
     * vérification d'appartenance ci-dessous : sans elle, une famille
     * connectée pourrait télécharger la facture d'une autre en devinant
     * un identifiant dans l'URL.
     */
    public static function handle_parent_download_invoice() {
        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_die(esc_html__('Vous devez être connecté pour accéder à cette facture.', 'periscolaire-registration'), '', array('response' => 403));
        }

        $invoice_id = psc_get_int('invoice_id');
        check_admin_referer('psc_parent_download_invoice_' . $invoice_id);

        $invoice = Psc_Invoices::get($invoice_id);
        if (!$invoice || (int) $invoice->parent_id !== (int) $parent->id) {
            wp_die(esc_html__('Facture introuvable.', 'periscolaire-registration'), '', array('response' => 404));
        }

        Psc_Invoices::download($invoice_id);
    }

    /* ---------------- Espace famille v2 ("Family Portal") ---------------- */

    protected static function portal_tab_defs() {
        return array(
            'dashboard' => array(
                'label' => 'Tableau de bord',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9h13v-9"/><path d="M10 19v-6h4v6"/></svg>',
            ),
            'cantine' => array(
                'label' => 'Cantine & Garderie',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.5" y="5" width="17" height="15" rx="1"/><path d="M3.5 9.5h17"/><path d="M8 3v3M16 3v3"/></svg>',
            ),
            'menu' => array(
                'label' => 'Menu de la semaine',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3v8a2 2 0 0 0 4 0V3M6 6h4"/><path d="M14 3c-1.2 1.3-1.2 6 0 8M14 3v18M17 3v6a2 2 0 0 0 2 2v10"/></svg>',
            ),
            'enfants' => array(
                'label' => 'Mes enfants',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="3.2"/><path d="M5 20c0-3.5 3.2-6 7-6s7 2.5 7 6"/></svg>',
            ),
            'factures' => array(
                'label' => 'Mes factures',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
            ),
        );
    }

    /**
     * Onglet actif : ?psc_tab= si connu, sinon le tableau de bord. Un
     * message lié à la gestion des enfants (retour d'un POST classique,
     * cf. handle_parent_*) ramène toujours sur l'onglet "Mes enfants",
     * quel que soit l'onglet d'où le formulaire a été soumis.
     */
    protected static function resolve_active_tab($psc_msg) {
        $known = array_keys(self::portal_tab_defs());
        $requested = isset($_GET['psc_tab']) ? sanitize_key(wp_unslash($_GET['psc_tab'])) : '';
        $tab = in_array($requested, $known, true) ? $requested : 'dashboard';

        if (in_array($psc_msg, array('child_updated', 'child_added', 'child_invalid', 'child_limit'), true)) {
            $tab = 'enfants';
        }
        return $tab;
    }

    protected static function portal_tabs_data() {
        $base = remove_query_arg(array('psc_tab', 'psc_semaine', 'psc_msg'));
        $tabs = array();
        foreach (self::portal_tab_defs() as $key => $def) {
            $tabs[$key] = array(
                'label' => $def['label'],
                'icon'  => $def['icon'],
                'url'   => add_query_arg('psc_tab', $key, $base),
            );
        }
        return $tabs;
    }

    /**
     * Jours du menu (parmi lundi/mardi/jeudi/vendredi) pour la semaine
     * donnée, uniquement s'il s'agit d'une semaine d'école avec du
     * contenu — sinon tableau vide (état "non renseigné" uniformisé côté
     * portail, cf. handoff : pas de distinction visuelle entre "vacances"
     * et "pas encore saisi").
     */
    protected static function menu_days_for_week($monday) {
        foreach (Psc_Menus::JOUR_OFFSETS as $offset) {
            $day_date = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
            if (psc_is_school_day($day_date)) {
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
        }
        return array(); // aucun jour d'école cette semaine-là
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
            return sprintf('Semaine du %s au %s %s %s', $start_day, $end_day, $end_month, $end_year);
        }
        $start_month = date_i18n('F', strtotime($monday));
        $start_year  = date_i18n('Y', strtotime($monday));
        if ($start_year !== $end_year) {
            return sprintf('Semaine du %s %s %s au %s %s %s', $start_day, $start_month, $start_year, $end_day, $end_month, $end_year);
        }
        return sprintf('Semaine du %s %s au %s %s %s', $start_day, $start_month, $end_day, $end_month, $end_year);
    }

    /**
     * Données de navigation du menu de cantine par semaine — partagées
     * entre l'onglet "Menu de la semaine" du portail connecté ($extra_args
     * = psc_tab=menu, pour revenir sur le bon onglet après un rechargement
     * complet déclenché par ←/→) et le widget public de la vue invité
     * (aucun argument supplémentaire).
     */
    protected static function menu_nav_data($extra_args = array()) {
        $requested = isset($_GET['psc_semaine']) ? sanitize_text_field(wp_unslash($_GET['psc_semaine'])) : '';
        $menu_week = $requested ? psc_week_start($requested) : false;
        if (!$menu_week) {
            $menu_week = psc_week_start(current_time('Y-m-d'));
        }

        $days = self::menu_days_for_week($menu_week);

        $prev_week = gmdate('Y-m-d', strtotime($menu_week . ' -7 days'));
        $next_week = gmdate('Y-m-d', strtotime($menu_week . ' +7 days'));
        $base = remove_query_arg(array('psc_semaine', 'psc_msg'));

        return array(
            'week_label'      => self::week_range_label($menu_week),
            'is_current_week' => ($menu_week === psc_week_start(current_time('Y-m-d'))),
            'has_content'     => !empty($days),
            'days'            => $days,
            'prev_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $prev_week)), $base),
            'next_url'        => add_query_arg(array_merge($extra_args, array('psc_semaine' => $next_week)), $base),
            'reset_url'       => $extra_args ? add_query_arg($extra_args, $base) : $base,
        );
    }

    protected static function portal_menu_data() {
        return self::menu_nav_data(array('psc_tab' => 'menu'));
    }

    protected static function guest_menu_data() {
        return self::menu_nav_data();
    }

    /**
     * Statistiques du tableau de bord : cumul période (tous enfants
     * actifs confondus), prochaine facture non envoyée, menu de la
     * semaine réelle en cours, résumé par enfant.
     */
    protected static function dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices) {
        $days_count = 0;
        $amount = 0.0;
        $children_summaries = array();

        foreach ($children as $child) {
            $child_days = 0;
            $child_amount = 0.0;
            foreach ($days_by_month as $days) {
                foreach ($days as $d) {
                    $has_reg = false;
                    foreach (psc_allowed_services() as $s) {
                        if (isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s])) {
                            $has_reg = true;
                            $child_amount += (float) $services[$s]['price'];
                        }
                    }
                    if ($has_reg) $child_days++;
                }
            }
            $days_count += $child_days;
            $amount += $child_amount;

            $diet_bits = array();
            if ((int) $child->sans_porc) $diet_bits[] = 'Sans porc';
            if ((int) $child->vegan) $diet_bits[] = 'Sans viande';
            $meta = $child->classe ? $child->classe : '';
            if ($diet_bits) $meta .= ($meta !== '' ? ' · ' : '') . implode(', ', $diet_bits);
            if ($meta === '') $meta = '—';

            $children_summaries[] = array(
                'name'    => trim($child->prenom . ' ' . $child->nom),
                'meta'    => $meta,
                'summary' => sprintf(
                    '%d jour%s déclaré%s · %s €',
                    $child_days, $child_days > 1 ? 's' : '', $child_days > 1 ? 's' : '',
                    number_format_i18n($child_amount, 2)
                ),
            );
        }

        $next_invoice = null;
        $pending = array_values(array_filter($invoices, function ($i) { return empty($i->sent_at); }));
        if ($pending) {
            usort($pending, function ($a, $b) { return strcmp($a->mois, $b->mois); });
            $next_invoice = array(
                'mois_label'   => Psc_Invoices::month_label($pending[0]->mois),
                'status_label' => 'En attente',
            );
        }

        $current_week = psc_week_start(current_time('Y-m-d'));

        return array(
            'title'        => $parent->nom !== '' ? 'Famille ' . $parent->nom : 'Bienvenue',
            'days_label'   => $days_count . ($days_count > 1 ? ' jours' : ' jour'),
            'amount_label' => number_format_i18n($amount, 2),
            'next_invoice' => $next_invoice,
            'menu'         => self::menu_days_for_week($current_week),
            'children'     => $children_summaries,
        );
    }

    /* ---------------- Vue invité v2 ("Vue visiteur") ---------------- */

    /**
     * Un message d'erreur retourné après une soumission de demande ratée
     * (validation serveur inchangée, cf. Psc_Requests::handle_submit) doit
     * rouvrir le stepper sur l'étape concernée — sinon l'erreur affichée
     * ne correspond à aucun champ visible. Le rechargement complet de la
     * page (redirection) efface aussi tout ce que le parent avait saisi,
     * y compris le mode de paiement choisi : pour une erreur liée au
     * prélèvement, on rouvre donc le panneau SEPA plutôt que de retomber
     * sur "Chèque ou espèces" (qui masquerait le champ à corriger).
     */
    protected static function wizard_error_context($psc_msg) {
        $map = array(
            'need_child'               => array('step' => 1, 'sepa' => false),
            'sepa_reglement_required'  => array('step' => 2, 'sepa' => true),
            'sepa_missing'             => array('step' => 2, 'sepa' => true),
            'bad_iban'                 => array('step' => 2, 'sepa' => true),
            'bad_bic'                  => array('step' => 2, 'sepa' => true),
            'reglement_required'       => array('step' => 3, 'sepa' => false),
        );
        if (isset($map[$psc_msg])) {
            return array(
                'step'         => $map[$psc_msg]['step'],
                'payment_mode' => $map[$psc_msg]['sepa'] ? 'prelevement' : 'autre',
                'has_error'    => true,
            );
        }
        return array('step' => 0, 'payment_mode' => 'autre', 'has_error' => false);
    }

    /* ---------------- Affichage ---------------- */

    public static function shortcode($atts) {
        ob_start();

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $parent = Psc_Parents::current();

        if (!$parent) {
            $psc_guest_menu = self::guest_menu_data();
            $psc_wizard = self::wizard_error_context($psc_msg);
            include PSC_PATH . 'templates/frontend-guest.php';
            return ob_get_clean();
        }

        $trimestre    = self::active_trimestre();
        $all_children = self::children_of($parent->id);               // pour la section "Mes enfants"
        $children     = self::children_of($parent->id, true);         // uniquement actifs → calendrier
        $days_by_month = array();
        $reg_map = array();

        if ($trimestre) {
            global $wpdb;
            $t_days = psc_table('calendar_days');
            $days = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date",
                $trimestre->id
            ));
            foreach ($days as $d) {
                $days_by_month[date_i18n('F Y', strtotime($d->jour_date))][] = $d;
            }
            $reg_map = self::reg_map($trimestre->id, $children);
        }

        $services = psc_services();
        $invoices = Psc_Invoices::get_for_parent($parent->id);

        $active_tab       = self::resolve_active_tab($psc_msg);
        $psc_portal_tabs  = self::portal_tabs_data();
        $psc_portal_menu  = self::portal_menu_data();
        $psc_portal_dashboard = self::dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices);

        include PSC_PATH . 'templates/frontend-portal.php';
        return ob_get_clean();
    }
}
