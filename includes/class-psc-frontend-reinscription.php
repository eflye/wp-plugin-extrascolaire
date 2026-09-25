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

        $files = array();
        foreach ($children as $child) {
            if (empty($_POST['confirm_' . $child->id])) continue; // enfant retiré pour la nouvelle année
            $files[(int) $child->id] = isset($_FILES['assurance_' . $child->id]) ? $_FILES['assurance_' . $child->id] : null;
        }

        $retires = array();
        $result = self::apply_reinscription($children, $files, $target_year->id, current_time('mysql'), $retires);

        // Enfant décoché après une réinscription déjà envoyée : il perd son
        // inscription à l'année cible (décision de la mairie), c'est tracé
        // et la mairie en est informée.
        if ($retires) {
            $names = array();
            foreach ($retires as $child) {
                $names[] = trim($child->prenom . ' ' . $child->nom);
                Psc_Audit::log('enfant.reinscription_retiree', array(
                    'objet_type' => 'enfant', 'objet_id' => (int) $child->id, 'enfant_id' => (int) $child->id,
                    'famille_id' => (int) $parent->id, 'meta' => array('annee' => $target_year->year_key),
                    'resume' => sprintf(
                        /* translators: 1: prénom et nom de l'enfant, 2: année, ex. 2026-2027 */
                        __('Réinscription de %1$s pour %2$s retirée par la famille.', 'periscolaire-registration'),
                        trim($child->prenom . ' ' . $child->nom), $target_year->year_key
                    ),
                ));
            }
            Psc_Mailer::notify_reinscription_retrait($parent, $names, $target_year->year_key);
        }

        $codes = array('ok' => 'reinscription_confirmee', 'retire' => 'reinscription_retiree', 'required' => 'reinscription_required', 'failed' => 'reinscription_failed');
        self::parent_form_redirect($codes[$result]);
    }

    /**
     * Réinscrit les enfants confirmés ($files : child_id => fichier reçu).
     * Tous les justificatifs sont contrôlés AVANT la première écriture :
     * un fichier invalide sur le deuxième enfant ne laisse plus le premier
     * réinscrit à moitié (P1-13). Une panne pendant les écritures reste
     * rattrapable en renvoyant le formulaire : inscription et dépôt sont
     * des remplacements, jamais des ajouts.
     *
     * Un enfant décoché qui était déjà réinscrit à l'année cible perd
     * cette inscription (ligne et justificatif de l'année), une fois tous
     * les fichiers contrôlés ; il est renvoyé dans $retires.
     *
     * @return string 'ok' | 'retire' (seulement des retraits) | 'required' | 'failed'
     */
    public static function apply_reinscription(array $children, array $files, $target_year_id, $reglement_accepted_at, &$retires = array()) {
        $retires = array();
        $plan = array();
        $to_remove = array();
        foreach ($children as $child) {
            if (!array_key_exists((int) $child->id, $files)) {
                if (Psc_School_Years::enrollment($child->id, $target_year_id)) $to_remove[] = $child;
                continue;
            }

            $classe_actuelle = Psc_School_Years::classe_for($child->id); // année en cours (active)
            $classe_proposee = $classe_actuelle !== '' ? Psc_School_Years::classe_superieure($classe_actuelle) : null;
            if (!$classe_proposee || $classe_proposee === 'sortie') continue; // fin de cycle : rien à réinscrire

            $file = $files[(int) $child->id];
            if (Psc_Assurances::validate_upload($file) !== true) return 'required';
            $plan[] = array((int) $child->id, $classe_proposee, $file);
        }
        if (!$plan && !$to_remove) return 'required';

        foreach ($to_remove as $child) {
            if (!Psc_School_Years::unenroll($child->id, $target_year_id)) return 'failed';
            $retires[] = $child;
        }
        if (!$plan) return 'retire';

        foreach ($plan as $p) {
            if (!Psc_School_Years::enroll($p[0], $target_year_id, $p[1], 'inscrit', $reglement_accepted_at)
                || Psc_Assurances::store_upload($p[0], $p[2], $target_year_id) !== true) {
                return 'failed';
            }
        }
        return 'ok';
    }
}
