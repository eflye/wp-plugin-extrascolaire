<?php
if (!defined('ABSPATH')) exit;

/**
 * Réinscription annuelle d'une famille pour l'année scolaire en
 * préparation. L'onglet n'existe au portail que pendant la fenêtre
 * ouverte par la mairie (Réglages) : reinscription_window_open() est
 * donc public, lue aussi par le noyau du portail pour afficher l'onglet.
 */
class Psc_Frontend_Reinscription extends Psc_Frontend_Base {

    public static function init() {
        add_action('admin_post_nopriv_psc_parent_reinscription', array(__CLASS__, 'handle_parent_reinscription'));
        add_action('admin_post_psc_parent_reinscription', array(__CLASS__, 'handle_parent_reinscription'));
    }

    /** Année scolaire "en préparation" la plus récente — cible de la réinscription. */
    public static function reinscription_target_year() {
        global $wpdb;
        return $wpdb->get_row(
            "SELECT * FROM " . psc_table('school_years') . " WHERE statut = 'preparation' ORDER BY id DESC LIMIT 1"
        );
    }

    /** Fenêtre de réinscription (Réglages) : ouverte aujourd'hui ? */
    public static function reinscription_window_open() {
        $debut = get_option('psc_reinscription_debut', '');
        $fin   = get_option('psc_reinscription_fin', '');
        if (!$debut || !$fin) return false;
        $today = current_time('Y-m-d');
        return $today >= $debut && $today <= $fin;
    }

    /**
     * Réinscription d'une famille pour l'année en préparation : par
     * enfant actif, confirmation ou retrait, avec règlement intérieur
     * (accepté une fois pour la famille) et nouveau justificatif
     * d'assurance obligatoires pour chaque enfant confirmé. Un enfant
     * décoché n'est pas sorti pour autant — cf. absence de ligne
     * child_school_years pour l'année cible, lu comme "non_reinscrit" par
     * le backoffice (Psc_Admin) une fois la fenêtre refermée.
     */
    public static function handle_parent_reinscription() {
        $parent = self::authed_parent('psc_parent_reinscription');
        if (!$parent) self::parent_form_redirect('auth');

        if (!self::reinscription_window_open()) self::parent_form_redirect('reinscription_invalid');

        $target_year = self::reinscription_target_year();
        if (!$target_year) self::parent_form_redirect('reinscription_invalid');

        if (empty($_POST['reglement_accepted'])) self::parent_form_redirect('reinscription_required');

        $children = self::children_of($parent->id, true);
        if (!$children) self::parent_form_redirect('reinscription_invalid');

        $reglement_accepted_at = current_time('mysql');
        $confirmed_count = 0;

        foreach ($children as $child) {
            if (empty($_POST['confirm_' . $child->id])) continue; // enfant retiré pour la nouvelle année

            $classe_actuelle = Psc_School_Years::classe_for($child->id); // année en cours (active)
            $classe_proposee = $classe_actuelle !== '' ? Psc_School_Years::classe_superieure($classe_actuelle) : null;
            if (!$classe_proposee || $classe_proposee === 'sortie') continue; // fin de cycle : rien à réinscrire

            $file = isset($_FILES['assurance_' . $child->id]) ? $_FILES['assurance_' . $child->id] : null;
            $file_check = Psc_Assurances::validate_upload($file);
            if ($file_check !== true) {
                self::parent_form_redirect('reinscription_required');
            }

            Psc_School_Years::enroll($child->id, $target_year->id, $classe_proposee, 'inscrit', $reglement_accepted_at);
            Psc_Assurances::store_upload($child->id, $file, $target_year->id);
            $confirmed_count++;
        }

        if (!$confirmed_count) self::parent_form_redirect('reinscription_required');

        self::parent_form_redirect('reinscription_confirmee');
    }
}
