<?php
if (!defined('ABSPATH')) exit;

/**
 * Socle commun du portail famille : identification de la famille connectée,
 * redirections de formulaires et lectures partagées entre domaines.
 *
 * Psc_Frontend réunissait quarante-neuf méthodes couvrant sept domaines
 * métier. Chacun a désormais sa classe ; celle-ci ne porte que ce qu'ils
 * partagent réellement.
 */
abstract class Psc_Frontend_Base {

    /**
     * Point d'entrée unique des actions du portail : identifie la famille et
     * valide le jeton anti-CSRF qui lui est propre. Retourne la famille, ou
     * null (l'appelant redirige alors vers l'écran de connexion).
     *
     * Deux couches, volontairement : le nonce WordPress vérifie aussi le
     * référent, mais il ne distingue pas les visiteurs non connectés entre
     * eux — c'est psc_verify_parent_nonce() qui garantit que le jeton a bien
     * été émis pour CETTE famille, et non lu sur une page publique par un
     * tiers (cf. psc_parent_nonce()).
     */
    protected static function authed_parent($action) {
        check_admin_referer($action);

        $parent = Psc_Parents::current();
        if (!$parent) return null;

        $nonce = isset($_POST['psc_nonce']) ? sanitize_text_field(wp_unslash($_POST['psc_nonce'])) : '';
        if (!psc_verify_parent_nonce($action, $parent->id, $nonce)) return null;

        return $parent;
    }

    protected static function parent_form_redirect($msg) {
        wp_safe_redirect(add_query_arg('psc_msg', $msg, Psc_Mailer::form_page_url()));
        exit;
    }

    protected static function children_of($parent_id, $active_only = false) {
        global $wpdb;
        $t_child = psc_table('children');
        $where   = $active_only ? "AND statut = 'actif'" : '';
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
}
