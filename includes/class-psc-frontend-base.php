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
     * Vérifie que l'enfant appartient bien au foyer connecté — contrôle
     * serveur exigé sur chaque point d'entrée d'écriture du planning.
     */
    protected static function owned_child($child_id, $parent_id) {
        global $wpdb;
        $child_id = (int) $child_id;
        if (!$child_id) return null;
        $child = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('children') . ' WHERE id = %d', $child_id
        ));
        if (!$child || (int) $child->parent_id !== (int) $parent_id) return null;
        return $child;
    }
}
