<?php
if (!defined('ABSPATH')) exit;

/**
 * Calendrier déclaré par la famille : cases cochées à l'unité ou par lot,
 * récapitulatif par e-mail, annulation depuis le tableau de bord.
 *
 * Les parents ne sont PAS des utilisateurs WordPress : les actions AJAX
 * doivent donc être exposées en "nopriv". L'autorisation est vérifiée dans
 * chaque handler via la session du plugin.
 */
class Psc_Frontend_Inscriptions extends Psc_Frontend_Base {

    public static function init() {
        add_action('wp_ajax_nopriv_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_psc_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_nopriv_psc_toggle_bulk', array(__CLASS__, 'ajax_toggle_bulk'));
        add_action('wp_ajax_psc_toggle_bulk', array(__CLASS__, 'ajax_toggle_bulk'));
        add_action('wp_ajax_nopriv_psc_confirm', array(__CLASS__, 'ajax_confirm'));
        add_action('wp_ajax_psc_confirm', array(__CLASS__, 'ajax_confirm'));

        // "Annulation prestations" : popin du tableau de bord, POST classique.
        add_action('admin_post_nopriv_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));
        add_action('admin_post_psc_cancel_absence', array(__CLASS__, 'handle_cancel_absence'));
    }

    /**
     * Cette prestation est-elle fermée ce jour-là par la mairie ?
     *
     * Le forfait est indivisible : il est bloqué dès qu'une seule de ses
     * composantes l'est, puisqu'on ne peut pas en facturer une partie.
     */
    protected static function service_closed_on($date, $service) {
        $closed = Psc_School_Calendar::closed_services_for_date($date);

        return $service === psc_forfait_code()
            ? (bool) array_intersect($closed, psc_unit_services())
            : in_array($service, $closed, true);
    }

    /**
     * Écrit ou retire une présence déclarée, en maintenant l'exclusivité
     * entre le forfait et ses composantes.
     *
     * Ne décide de rien : les contrôles (appartenance de l'enfant, jour
     * ouvert, délai de prévenance, prestation fermée) restent à la charge
     * de l'appelant, qui seul sait comment signaler un refus — en JSON
     * pour une case cochée à l'unité, en passant au jour suivant pour un
     * envoi par lot.
     *
     * Cette routine existait en double, à vingt lignes identiques sur
     * vingt-huit, l'invariant du forfait compris. Corriger un défaut dans
     * l'une sans l'autre était une erreur silencieuse et probable.
     */
    protected static function apply_registration($child_id, $trimestre_id, $date, $service, $checked) {
        global $wpdb;
        $t_reg = psc_table('registrations');

        if (!$checked) {
            $wpdb->delete(
                $t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                array('%d', '%s', '%s')
            );
            return;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $t_reg (child_id, trimestre_id, jour_date, service, updated_at)
             VALUES (%d, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
            $child_id, $trimestre_id, $date, $service, current_time('mysql')
        ));

        foreach (psc_conflicting_services($service) as $svc) {
            $wpdb->delete(
                $t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $svc),
                array('%d', '%s', '%s')
            );
        }
    }

    public static function ajax_toggle() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        // Jeton propre à cette famille : le nonce ci-dessus ne distingue pas
        // les visiteurs non connectés entre eux (cf. psc_parent_nonce()).
        if (!psc_verify_parent_nonce('psc_front', $parent->id, psc_post('parent_nonce'))) {
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
        if (!psc_is_valid_service($service)) {
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

        // L'assurance scolaire de l'année en cours ne bloque que l'AJOUT
        // d'un jour : un enfant déjà déclaré peut toujours être décoché
        // même sans document à jour (pas de blocage rétroactif).
        if ($checked && !Psc_Assurances::has_valid($child_id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => __('L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.', 'periscolaire-registration'),
            ), 403);
        }

        $trimestre = Psc_Trimestres::active();
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
                    __('Ce jour n\'est plus modifiable en ligne (délai de %d h dépassé). Contactez la mairie.', 'periscolaire-registration'),
                    psc_lock_hours()
                ),
            ), 403);
        }

        // Fermeture par prestation (calendrier scolaire v2) : vérifiée
        // CÔTÉ SERVEUR, comme le délai de prévenance ci-dessus — griser la
        // case dans le navigateur ne suffit pas. Un enfant déjà déclaré
        // peut toujours être décoché (pas de blocage rétroactif), donc ce
        // contrôle ne s'applique qu'à une nouvelle déclaration ($checked).
        if ($checked && self::service_closed_on($date, $service)) {
            wp_send_json_error(array(
                'code'    => 'service_closed',
                'message' => __('Cette prestation est fermée ce jour-là. Contactez la mairie.', 'periscolaire-registration'),
            ), 403);
        }

        self::apply_registration($child_id, $trimestre->id, $date, $service, $checked);

        wp_send_json_success();
    }

    /**
     * Bouton "Tout" par colonne de service (Planning cantine & garderie) : coche ou
     * décoche en une fois tous les jours déclarables d'un mois pour un
     * enfant/service donnés. Reçoit la liste exacte des dates depuis le
     * client (celles rendues comme déclarables — non verrouillées — au
     * chargement de la page), mais revalide chacune côté serveur (jour
     * ouvert, non verrouillé) plutôt que de faire confiance à cette liste :
     * son état a pu changer depuis le rendu de la page. Les dates rejetées
     * sont ignorées silencieusement plutôt que d'échouer tout le lot — même
     * principe de résilience que handle_cancel_absence(). Ne réutilise pas
     * ajax_toggle() (même logique dupliquée par date) pour ne rien changer
     * au comportement déjà en place du pointage case par case.
     */
    public static function ajax_toggle_bulk() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        // Jeton propre à cette famille : le nonce ci-dessus ne distingue pas
        // les visiteurs non connectés entre eux (cf. psc_parent_nonce()).
        if (!psc_verify_parent_nonce('psc_front', $parent->id, psc_post('parent_nonce'))) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        global $wpdb;

        $child_id  = psc_post_int('child_id');
        $service   = psc_post('service');
        $checked   = psc_post('checked') === '1';
        $raw_dates = isset($_POST['dates']) ? wp_unslash($_POST['dates']) : '';
        $raw_dates = is_array($raw_dates) ? $raw_dates : explode(',', (string) $raw_dates);

        if (!$child_id || !psc_is_valid_service($service)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $dates = array();
        foreach ($raw_dates as $raw) {
            $d = psc_valid_date($raw);
            if ($d) $dates[$d] = $d; // dédoublonnage
        }
        if (empty($dates)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        $t_child = psc_table('children');
        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        if (!$child) {
            wp_send_json_error(array('code' => 'notfound'), 404);
        }
        if ((int) $child->parent_id !== (int) $parent->id) {
            wp_send_json_error(array('code' => 'forbidden'), 403);
        }

        if ($checked && !Psc_Assurances::has_valid($child_id)) {
            wp_send_json_error(array(
                'code'    => 'assurance_missing',
                'message' => __('L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants » pour pouvoir déclarer des jours.', 'periscolaire-registration'),
            ), 403);
        }

        $trimestre = Psc_Trimestres::active();
        if (!$trimestre) {
            wp_send_json_error(array('code' => 'closed'), 403);
        }

        $t_days = psc_table('calendar_days');
        $t_reg  = psc_table('registrations');
        $applied = array();

        foreach ($dates as $date) {
            $day = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
                $trimestre->id, $date
            ));
            if (!$day) continue;

            // Délai de prévenance revérifié par date : l'état a pu changer
            // depuis le chargement de la page, on ignore silencieusement
            // plutôt que d'échouer tout le lot.
            if (psc_is_locked($date)) continue;

            // Fermeture par prestation (calendrier scolaire v2), même
            // revérification par date que le délai ci-dessus. Un jour
            // refusé est ignoré silencieusement plutôt que d'échouer tout
            // le lot — c'est la seule différence avec la case à l'unité.
            if ($checked && self::service_closed_on($date, $service)) continue;

            self::apply_registration($child_id, $trimestre->id, $date, $service, $checked);

            $applied[] = $date;
        }

        wp_send_json_success(array('dates' => $applied));
    }

    public static function ajax_confirm() {
        check_ajax_referer('psc_front', 'nonce');

        $parent = Psc_Parents::current();
        if (!$parent) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }
        // Jeton propre à cette famille : le nonce ci-dessus ne distingue pas
        // les visiteurs non connectés entre eux (cf. psc_parent_nonce()).
        if (!psc_verify_parent_nonce('psc_front', $parent->id, psc_post('parent_nonce'))) {
            wp_send_json_error(array('code' => 'auth'), 403);
        }

        // Évite l'envoi répété de récapitulatifs (clics multiples).
        if (!psc_rate_limit('recap_' . $parent->id, 5, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array(
                'code'    => 'rate',
                'message' => __('Plusieurs récapitulatifs viennent d\'être envoyés. Merci de patienter quelques minutes.', 'periscolaire-registration'),
            ), 429);
        }

        $trimestre = Psc_Trimestres::active();
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
                'message' => __('L\'envoi de l\'e-mail a échoué. Vos inscriptions sont bien enregistrées ; contactez la mairie si besoin.', 'periscolaire-registration'),
            ), 500);
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Récapitulatif envoyé à %s.', 'periscolaire-registration'), $parent->email),
        ));
    }

    /**
     * "Annulation prestations" depuis le tableau de bord : annule, pour un
     * enfant donné, une sélection de prestations individuelles (pas
     * forcément toute une journée). $_POST['items'] est un tableau de
     * chaînes "YYYY-MM-DD|SERVICE" (une par case cochée) — cf.
     * Psc_Frontend::absence_candidates() pour la construction de la liste
     * proposée et
     * assets/js/portal.js pour le remplissage du formulaire. Un forfait
     * (FORF) est indivisible : les 3 lignes GM/CANT/GS qui le représentent
     * dans l'UI portent toutes service=FORF, donc cocher n'importe laquelle
     * (ou plusieurs) revient à annuler le même unique forfait — dédoublonné
     * ci-dessous par date+service avant toute suppression. Même ordre de
     * vérification que ajax_toggle() par prestation : appartenance de
     * l'enfant, trimestre actif, jour ouvert, délai de préavis
     * (psc_lock_hours) non dépassé — une prestation qui ne passe plus ces
     * contrôles (entre le chargement de la popin et la soumission) est
     * silencieusement ignorée plutôt que de faire échouer tout le lot.
     */
    public static function handle_cancel_absence() {
        $parent = self::authed_parent('psc_cancel_absence');
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id  = psc_post_int('child_id');
        $raw_items = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : array();

        $pairs = array();
        foreach ($raw_items as $raw) {
            $parts = explode('|', (string) $raw, 2);
            if (count($parts) !== 2) continue;
            $date    = psc_valid_date($parts[0]);
            $service = $parts[1];
            if (!$date || !psc_is_valid_service($service)) continue;
            $pairs[$date . '|' . $service] = array('date' => $date, 'service' => $service);
        }
        if (!$child_id || !$pairs) self::parent_form_redirect('absence_invalid');

        $t_child = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('absence_invalid');

        $trimestre = Psc_Trimestres::active();
        if (!$trimestre) self::parent_form_redirect('absence_invalid');

        $t_days = psc_table('calendar_days');
        $t_reg  = psc_table('registrations');

        $cancelled_by_date = array(); // date => [services annulés]
        foreach ($pairs as $pair) {
            $date    = $pair['date'];
            $service = $pair['service'];

            $day = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND jour_date = %s AND is_open = 1",
                $trimestre->id, $date
            ));
            if (!$day || psc_is_locked($date)) continue;

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $t_reg WHERE child_id = %d AND jour_date = %s AND service = %s",
                $child_id, $date, $service
            ));
            if (!$exists) continue; // déjà annulé entre-temps

            $wpdb->delete($t_reg,
                array('child_id' => $child_id, 'jour_date' => $date, 'service' => $service),
                array('%d', '%s', '%s')
            );
            $cancelled_by_date[$date][] = $service;
        }

        if (!$cancelled_by_date) self::parent_form_redirect('absence_invalid');

        $child = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_child WHERE id = %d", $child_id));
        foreach ($cancelled_by_date as $date => $services) {
            Psc_Mailer::notify_absence_cancelled($parent, $child, $date, $services);
        }

        self::parent_form_redirect('absence_cancelled');
    }
}
