<?php
if (!defined('ABSPATH')) exit;

/**
 * Fiches enfants gérées par la famille depuis "Mes enfants" : correction
 * de l'état civil d'un enfant déjà onboardé et ajout d'un enfant (dont le
 * justificatif d'assurance scolaire, obligatoire dès la création).
 */
class Psc_Frontend_Enfants extends Psc_Frontend_Base {

    public static function init() {
        add_action('admin_post_nopriv_psc_parent_update_child_identity', array(__CLASS__, 'handle_parent_update_child_identity'));
        add_action('admin_post_psc_parent_update_child_identity', array(__CLASS__, 'handle_parent_update_child_identity'));
        add_action('admin_post_nopriv_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));
        add_action('admin_post_psc_parent_add_child', array(__CLASS__, 'handle_parent_add_child'));
    }

    /**
     * Correction par le parent d'une faute de frappe sur l'état civil
     * (prénom / nom / date de naissance) d'un enfant déjà onboardé. La
     * classe (désormais par année scolaire, cf. wp_psc_child_school_years)
     * et le statut actif/sorti ne sont plus modifiables par la famille :
     * la classe se pose à l'inscription / au passage d'année, le statut
     * relève de la mairie.
     */
    public static function handle_parent_update_child_identity() {
        $parent = self::authed_parent('psc_parent_update_child_identity');
        if (!$parent) self::parent_form_redirect('auth');

        $child_id  = psc_post_int('child_id');
        $prenom    = psc_post('prenom');
        $nom       = psc_post('nom');
        $naissance = psc_valid_date(psc_post('naissance'));

        if ($prenom === '' || $nom === '') self::parent_form_redirect('child_invalid');
        // Date bien formée mais incohérente (futur, moins de 3 ans au
        // 1er septembre) : refusée, cf. psc_valid_child_birthdate().
        if ($naissance && !psc_valid_child_birthdate($naissance)) {
            self::parent_form_redirect('child_bad_birthdate');
        }

        global $wpdb;
        $t_child = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('invalid');

        $wpdb->update(
            $t_child,
            array(
                'prenom'         => mb_substr($prenom, 0, 190),
                'nom'            => mb_substr($nom, 0, 190),
                'date_naissance' => $naissance ?: null,
            ),
            array('id' => $child_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        self::parent_form_redirect('child_updated');
    }

    public static function handle_parent_add_child() {
        $parent = self::authed_parent('psc_parent_add_child');
        if (!$parent) self::parent_form_redirect('auth');

        $prenom    = psc_post('new_prenom');
        $nom       = psc_post('new_nom');
        $classe    = psc_post('new_classe');
        $naissance = psc_valid_date(psc_post('new_naissance'));
        $sans_porc = isset($_POST['new_sans_porc']) ? 1 : 0;
        $vegan     = isset($_POST['new_vegan']) ? 1 : 0;

        if ($prenom === '' || $nom === '') self::parent_form_redirect('child_invalid');
        if ($naissance && !psc_valid_child_birthdate($naissance)) {
            self::parent_form_redirect('child_bad_birthdate');
        }

        // Le justificatif d'assurance scolaire est obligatoire dès la
        // création de la fiche enfant, quel que soit le point d'entrée
        // (ici le portail connecté ; cf. Psc_Requests::handle_submit()
        // pour le wizard public). Validé AVANT toute écriture en base : pas
        // de fiche enfant orpheline si le fichier est absent/invalide.
        $file_check = Psc_Assurances::validate_upload(isset($_FILES['new_assurance_file']) ? $_FILES['new_assurance_file'] : null);
        if ($file_check !== true) {
            $codes = array('too_large' => 'assurance_too_large', 'invalid_type' => 'assurance_invalid_type');
            self::parent_form_redirect(isset($codes[$file_check]) ? $codes[$file_check] : 'assurance_required');
        }

        $allowed = array_keys(Psc_School_Years::classe_options());
        if (!in_array($classe, $allowed, true)) $classe = '';

        $year_id = Psc_School_Years::active_id();
        if (!$year_id) self::parent_form_redirect('invalid');

        global $wpdb;
        $t_child = psc_table('children');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_child WHERE parent_id = %d", $parent->id
        ));
        if ($count >= psc_max_children_per_user()) self::parent_form_redirect('child_limit');

        $wpdb->insert($t_child, array(
            'parent_id'      => $parent->id,
            'nom'            => mb_substr($nom, 0, 190),
            'prenom'         => mb_substr($prenom, 0, 190),
            'date_naissance' => $naissance ?: null,
            'sans_porc'      => $sans_porc,
            'vegan'          => $vegan,
            'statut'         => 'actif',
            'created_at'     => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;

        Psc_School_Years::enroll($child_id, $year_id, $classe, 'inscrit', current_time('mysql'));
        Psc_Assurances::store_upload($child_id, $_FILES['new_assurance_file']);

        self::parent_form_redirect('child_added');
    }
}
