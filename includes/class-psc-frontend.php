<?php
if (!defined('ABSPATH')) exit;

class Psc_Frontend {

    public static function init() {
        add_shortcode('periscolaire_form', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

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

    /* ---------------- Menu de cantine (accès libre) ---------------- */

    /**
     * Navigation "type calendrier" par semaine : ?psc_semaine=<n'importe
     * quelle date de la semaine visée>, toujours ramenée au lundi. Par
     * défaut, la semaine contenant aujourd'hui. Aucune limite de
     * navigation avant/après : une semaine sans menu saisi affiche
     * simplement l'état vide, comme n'importe quel calendrier.
     */
    protected static function render_menu_widget() {
        $requested = isset($_GET['psc_semaine']) ? sanitize_text_field(wp_unslash($_GET['psc_semaine'])) : '';
        $menu_week = $requested ? psc_week_start($requested) : false;
        if (!$menu_week) {
            $menu_week = psc_week_start(current_time('Y-m-d'));
        }

        $no_school_week = true;
        foreach (Psc_Menus::JOUR_OFFSETS as $offset) {
            $day_date = gmdate('Y-m-d', strtotime($menu_week . " +{$offset} days"));
            if (psc_is_school_day($day_date)) { $no_school_week = false; break; }
        }

        $menu = Psc_Menus::get_by_week($menu_week);
        $has_content = false;
        if ($menu && !$no_school_week) {
            foreach (Psc_Menus::JOUR_OFFSETS as $jour => $offset) {
                $day_date = gmdate('Y-m-d', strtotime($menu_week . " +{$offset} days"));
                if (!psc_is_school_day($day_date)) continue;
                if (trim((string) $menu->$jour) !== '') { $has_content = true; break; }
            }
        }

        $prev_week = gmdate('Y-m-d', strtotime($menu_week . ' -7 days'));
        $next_week = gmdate('Y-m-d', strtotime($menu_week . ' +7 days'));
        // psc_msg est un message transitoire (ex: "Vous êtes déconnecté") : il
        // ne doit pas survivre à la navigation dans le calendrier du menu.
        $prev_url  = add_query_arg('psc_semaine', $prev_week, remove_query_arg('psc_msg'));
        $next_url  = add_query_arg('psc_semaine', $next_week, remove_query_arg('psc_msg'));
        $is_current_week = ($menu_week === psc_week_start(current_time('Y-m-d')));

        include PSC_PATH . 'templates/frontend-menu.php';
    }

    /* ---------------- Affichage ---------------- */

    public static function shortcode($atts) {
        ob_start();

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $parent = Psc_Parents::current();

        // Menu de cantine : en accès libre, avant la connexion — affiché
        // que la famille soit identifiée ou non.
        self::render_menu_widget();

        if (!$parent) {
            include PSC_PATH . 'templates/frontend-login.php';
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
        include PSC_PATH . 'templates/frontend-form.php';
        return ob_get_clean();
    }
}
