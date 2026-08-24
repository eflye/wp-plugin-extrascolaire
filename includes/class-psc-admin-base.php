<?php
if (!defined('ABSPATH')) exit;

/**
 * Socle commun des écrans d'administration : contrôle d'accès et
 * redirections.
 *
 * Psc_Admin réunissait quatre-vingt-deux méthodes couvrant dix domaines
 * métier. Chacun a désormais sa classe ; celle-ci ne porte que ce qu'ils
 * partagent réellement.
 */
abstract class Psc_Admin_Base {

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
}
