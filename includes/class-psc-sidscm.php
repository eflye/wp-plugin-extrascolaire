<?php
if (!defined('ABSPATH')) exit;

/**
 * "Listes intervenantes SIDSCM" — page publique dédiée (aucun compte
 * WordPress) pour les intervenants sur le terrain (garderie/cantine) :
 * qui est attendu aujourd'hui/cette semaine, par service, avec pointage
 * de présence réelle. Protégée par un code d'accès unique configuré en
 * Réglages (psc_sidscm_access_code) — pas d'authentification WordPress,
 * volontairement léger, mais le code est revérifié côté serveur à
 * chaque appel AJAX (rien n'est jamais envoyé au navigateur avant
 * vérification, contrairement à une simple bascule d'affichage
 * côté client).
 *
 * Les enfants attendus viennent des inscriptions réelles déjà
 * enregistrées (wp_psc_registrations) — aucune nouvelle saisie de
 * planning ici. Le pointage de présence est persisté dans une table
 * dédiée (wp_psc_attendance), horodaté, un enfant "présent" par défaut
 * tant qu'il n'a jamais été pointé explicitement (l'intervenant ne
 * décoche que les absents plutôt que de cocher tout le monde).
 */
class Psc_Sidscm {

    /** Services concernés par cet écran — le forfait (FORF) implique les trois. */
    const SERVICES = array('GM', 'CANT', 'GS');

    public static function init() {
        add_shortcode('periscolaire_sidscm', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

        // Même mécanique que Psc_Frontend::hide_page_chrome_when_connected() /
        // add_portal_body_class() pour le portail famille : cet écran est
        // pensé plein écran ("outil de terrain"), le titre/gabarit de page
        // du thème n'a pas sa place ici, ni au tout premier écran de code.
        add_filter('the_content', array(__CLASS__, 'hide_page_chrome'), 20);
        add_filter('body_class', array(__CLASS__, 'add_body_class'));

        add_action('wp_ajax_nopriv_psc_sidscm_unlock', array(__CLASS__, 'ajax_unlock'));
        add_action('wp_ajax_psc_sidscm_unlock', array(__CLASS__, 'ajax_unlock'));
        add_action('wp_ajax_nopriv_psc_sidscm_data', array(__CLASS__, 'ajax_data'));
        add_action('wp_ajax_psc_sidscm_data', array(__CLASS__, 'ajax_data'));
        add_action('wp_ajax_nopriv_psc_sidscm_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_psc_sidscm_toggle', array(__CLASS__, 'ajax_toggle'));
    }

    protected static function page_has_shortcode() {
        if (!is_singular()) return false;
        $post = get_post();
        return $post && has_shortcode($post->post_content, 'periscolaire_sidscm');
    }

    public static function hide_page_chrome($content) {
        if (!self::page_has_shortcode()) return $content;
        return self::shortcode(array());
    }

    public static function add_body_class($classes) {
        if (self::page_has_shortcode()) {
            $classes[] = 'psc-sidscm-active';
        }
        return $classes;
    }

    public static function shortcode($atts) {
        ob_start();
        include PSC_PATH . 'templates/sidscm.php';
        return ob_get_clean();
    }

    public static function assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_sidscm')) return;

        // Dépend de psc-portal uniquement pour réutiliser ses @font-face
        // auto-hébergées (Fraunces/Work Sans/Cormorant Garamond) sans les
        // dupliquer — jamais de requête vers Google Fonts.
        wp_enqueue_style('psc-portal', PSC_URL . 'assets/css/portal.css', array(), PSC_VERSION);
        wp_enqueue_style('psc-sidscm', PSC_URL . 'assets/css/sidscm.css', array('psc-portal'), PSC_VERSION);
        wp_enqueue_script('psc-sidscm', PSC_URL . 'assets/js/sidscm.js', array(), PSC_VERSION, true);
        wp_localize_script('psc-sidscm', 'PSC_SIDSCM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_sidscm_front'),
        ));
    }

    /* ---------------- Code d'accès ---------------- */

    /**
     * Aucun code configuré = accès désactivé pour tout le monde (pas de
     * "code vide accepte tout" par défaut).
     */
    protected static function code_valid($submitted) {
        $configured = trim((string) get_option('psc_sidscm_access_code', ''));
        if ($configured === '') return false;
        return hash_equals(strtoupper($configured), strtoupper(trim((string) $submitted)));
    }

    public static function ajax_unlock() {
        check_ajax_referer('psc_sidscm_front', 'nonce');

        if (!psc_rate_limit('sidscm_unlock_' . psc_client_ip(), 20, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }

        if (!self::code_valid(psc_post('code'))) {
            wp_send_json_error(array('code' => 'bad_code'), 403);
        }

        wp_send_json_success();
    }

    /* ---------------- Données : semaine en cours ---------------- */

    /**
     * Enfants attendus pour la semaine réellement en cours (lundi de
     * aujourd'hui), tous services confondus — tout le filtrage jour/
     * service/vue se fait ensuite côté client, comme le reste de
     * l'interaction (bascule jour/semaine, onglets service) : un seul
     * aller-retour serveur pour charger la semaine, la présence se
     * pointe ensuite sans recharger la liste.
     */
    public static function ajax_data() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        if (!self::code_valid(psc_post('code'))) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        global $wpdb;
        $monday = psc_week_start(current_time('Y-m-d'));
        // Ne contient jamais le mercredi (psc_is_school_day()) et exclut déjà
        // vacances/jours fériés/fermetures ponctuelles : les seuls jours à
        // afficher sont ceux réellement en service cette semaine.
        $open_days = psc_open_days($monday);

        $out = array(
            'days'       => $open_days,
            'services'   => psc_services(),
            'children'   => array(),
            'attendance' => new stdClass(), // objet vide côté JSON si aucune ligne
        );

        if (empty($open_days)) {
            wp_send_json_success($out);
        }

        $t_child = psc_table('children');
        $children = $wpdb->get_results("SELECT * FROM $t_child WHERE statut = 'actif' ORDER BY nom, prenom");
        if (empty($children)) {
            wp_send_json_success($out);
        }

        $dates = array_values($open_days);
        $child_ids = wp_list_pluck($children, 'id');
        $ph_child = implode(',', array_fill(0, count($child_ids), '%d'));
        $ph_date  = implode(',', array_fill(0, count($dates), '%s'));

        $t_reg = psc_table('registrations');
        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service FROM $t_reg
             WHERE child_id IN ($ph_child) AND jour_date IN ($ph_date)",
            array_merge($child_ids, $dates)
        ));
        $reg_map = array();
        foreach ($regs as $r) {
            $reg_map[$r->child_id . '|' . $r->jour_date . '|' . $r->service] = true;
        }

        $t_att = psc_table('attendance');
        $att_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service, present FROM $t_att
             WHERE child_id IN ($ph_child) AND jour_date IN ($ph_date)",
            array_merge($child_ids, $dates)
        ));
        $attendance = array();
        foreach ($att_rows as $a) {
            $attendance[$a->child_id . '|' . $a->jour_date . '|' . $a->service] = (int) $a->present;
        }

        // Un forfait (FORF) couvre GM+CANT+GS d'un même coup : une ligne
        // service=FORF vaut inscription pour les trois, jamais stockée sous
        // les trois codes séparément (cf. ajax_toggle() de Psc_Frontend).
        $expected = function ($child_id, $date, $service) use ($reg_map) {
            return isset($reg_map[$child_id . '|' . $date . '|' . $service])
                || isset($reg_map[$child_id . '|' . $date . '|FORF']);
        };

        $out_children = array();
        foreach ($children as $c) {
            $classe = Psc_School_Years::classe_for($c->id);

            $diet_bits = array();
            if ((int) $c->sans_porc === 1) $diet_bits[] = 'Sans porc';
            if ((int) $c->vegan === 1) $diet_bits[] = 'Sans viande';

            $per_service = array();
            $has_any = false;
            foreach (self::SERVICES as $svc) {
                $jours = array();
                foreach ($open_days as $jour => $date) {
                    if ($expected($c->id, $date, $svc)) {
                        $jours[] = $jour;
                        $has_any = true;
                    }
                }
                $per_service[$svc] = $jours;
            }
            if (!$has_any) continue; // rien à afficher pour cet enfant cette semaine

            $out_children[] = array(
                'id'     => (int) $c->id,
                'prenom' => $c->prenom,
                'nom'    => $c->nom,
                'classe' => $classe,
                'diet'   => $diet_bits ? implode(', ', $diet_bits) : null,
                'GM'     => $per_service['GM'],
                'CANT'   => $per_service['CANT'],
                'GS'     => $per_service['GS'],
            );
        }

        $out['children'] = $out_children;
        $out['attendance'] = $attendance ?: new stdClass();

        wp_send_json_success($out);
    }

    /* ---------------- Pointage de présence ---------------- */

    public static function ajax_toggle() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        if (!self::code_valid(psc_post('code'))) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        $child_id = psc_post_int('child_id');
        $date     = psc_valid_date(psc_post('jour_date'));
        $service  = psc_post('service');
        $present  = psc_post('present') === '1' ? 1 : 0;

        if (!$child_id || !$date || !in_array($service, self::SERVICES, true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        global $wpdb;
        $t_att = psc_table('attendance');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_att WHERE child_id = %d AND jour_date = %s AND service = %s",
            $child_id, $date, $service
        ));

        $now = current_time('mysql');
        if ($existing) {
            $wpdb->update(
                $t_att,
                array('present' => $present, 'pointed_at' => $now),
                array('id' => $existing),
                array('%d', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert($t_att, array(
                'child_id'   => $child_id,
                'jour_date'  => $date,
                'service'    => $service,
                'present'    => $present,
                'pointed_at' => $now,
            ), array('%d', '%s', '%s', '%d', '%s'));
        }

        wp_send_json_success();
    }
}
