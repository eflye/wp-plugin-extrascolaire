<?php
if (!defined('ABSPATH')) exit;

/**
 * Personnes autorisées à récupérer les enfants, gérées par la famille :
 * par enfant depuis « Mes enfants », à l'échelle du foyer (tous les
 * enfants actifs d'un coup) depuis « Mon profil ».
 */
class Psc_Frontend_Pickup extends Psc_Frontend_Base {

    public static function init() {
        add_action('admin_post_nopriv_psc_parent_add_pickup_person', array(__CLASS__, 'handle_parent_add_pickup_person'));
        add_action('admin_post_psc_parent_add_pickup_person', array(__CLASS__, 'handle_parent_add_pickup_person'));
        add_action('admin_post_nopriv_psc_parent_update_pickup_person', array(__CLASS__, 'handle_parent_update_pickup_person'));
        add_action('admin_post_psc_parent_update_pickup_person', array(__CLASS__, 'handle_parent_update_pickup_person'));
        add_action('admin_post_nopriv_psc_parent_remove_pickup_person', array(__CLASS__, 'handle_parent_remove_pickup_person'));
        add_action('admin_post_psc_parent_remove_pickup_person', array(__CLASS__, 'handle_parent_remove_pickup_person'));

        add_action('admin_post_nopriv_psc_parent_add_household_pickup_person', array(__CLASS__, 'handle_parent_add_household_pickup_person'));
        add_action('admin_post_psc_parent_add_household_pickup_person', array(__CLASS__, 'handle_parent_add_household_pickup_person'));
        add_action('admin_post_nopriv_psc_parent_remove_household_pickup_person', array(__CLASS__, 'handle_parent_remove_household_pickup_person'));
        add_action('admin_post_psc_parent_remove_household_pickup_person', array(__CLASS__, 'handle_parent_remove_household_pickup_person'));
    }

    /** Vrai si l'enfant de $person appartient bien à $parent_id. */
    protected static function pickup_person_owned_by($person, $parent_id) {
        global $wpdb;
        $t_child = psc_table('children');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", (int) $person->child_id, $parent_id
        ));
    }

    protected static function pickup_fields_from_post() {
        return array(
            'prenom'         => psc_post('prenom'),
            'nom'            => psc_post('nom'),
            'telephone'      => psc_post('telephone'),
            'lien'           => psc_post('lien'),
            'piece_identite' => isset($_POST['piece_identite']) ? 1 : 0,
        );
    }

    /**
     * Ajout d'une personne autorisée à récupérer un enfant depuis "Mes
     * enfants". Le nonce prouve l'intention, mais c'est la vérification
     * d'appartenance ci-dessous qui empêche une famille connectée
     * d'ajouter une entrée sur un enfant qui n'est pas le sien.
     */
    public static function handle_parent_add_pickup_person() {
        $parent = self::authed_parent('psc_parent_pickup_person');
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $child_id = psc_post_int('child_id');
        $t_child  = psc_table('children');
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_child WHERE id = %d AND parent_id = %d", $child_id, $parent->id
        ));
        if (!$owned) self::parent_form_redirect('pickup_invalid');

        $result = Psc_Pickup_Persons::add($child_id, self::pickup_fields_from_post(), 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_added');
    }

    /**
     * Modification d'une personne autorisée existante. Un pickup_id
     * n'appartient à un parent qu'à travers l'enfant qu'il concerne —
     * c'est cet enfant, pas seulement la ligne, qui est revérifié.
     */
    public static function handle_parent_update_pickup_person() {
        $parent = self::authed_parent('psc_parent_pickup_person');
        if (!$parent) self::parent_form_redirect('auth');

        $pickup_id = psc_post_int('pickup_id');
        $person = Psc_Pickup_Persons::get($pickup_id);
        if (!$person || !self::pickup_person_owned_by($person, $parent->id)) {
            self::parent_form_redirect('pickup_invalid');
        }

        $result = Psc_Pickup_Persons::update($pickup_id, self::pickup_fields_from_post(), 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_updated');
    }

    /** Retrait (soft-delete) d'une personne autorisée. */
    public static function handle_parent_remove_pickup_person() {
        $parent = self::authed_parent('psc_parent_remove_pickup_person');
        if (!$parent) self::parent_form_redirect('auth');

        $pickup_id = psc_post_int('pickup_id');
        $person = Psc_Pickup_Persons::get($pickup_id);
        if (!$person || !self::pickup_person_owned_by($person, $parent->id)) {
            self::parent_form_redirect('pickup_invalid');
        }

        $result = Psc_Pickup_Persons::remove($pickup_id, 'parent');
        if (is_wp_error($result)) self::parent_form_redirect('pickup_invalid');

        self::parent_form_redirect('pickup_removed');
    }

    /**
     * Ajoute un tiers autorisé depuis "Mon profil" : contrairement à "Mes
     * enfants" (par enfant), l'ajout s'applique à tous les enfants actifs
     * du foyer d'un coup — c'est la vue foyer, sans enfant précis en
     * contexte, qui l'exige. Un échec sur un enfant (ex. plafond atteint)
     * n'empêche pas l'ajout pour les autres.
     */
    public static function handle_parent_add_household_pickup_person() {
        $parent = self::authed_parent('psc_parent_add_household_pickup_person');
        if (!$parent) self::parent_form_redirect('auth');

        $fields = self::pickup_fields_from_post();
        if ($fields['prenom'] === '' || $fields['nom'] === '' || $fields['telephone'] === '') {
            self::parent_form_redirect('household_pickup_invalid');
        }

        $children = self::children_of($parent->id, true);
        if (empty($children)) self::parent_form_redirect('household_pickup_invalid');

        $any_ok = false;
        foreach ($children as $child) {
            $result = Psc_Pickup_Persons::add($child->id, $fields, 'parent');
            if (!is_wp_error($result)) $any_ok = true;
        }

        self::parent_form_redirect($any_ok ? 'household_pickup_added' : 'household_pickup_invalid');
    }

    /**
     * Retire un tiers depuis "Mon profil" : reçoit la liste des
     * pickup_person_id regroupés par la vue foyer dédupliquée (un même
     * tiers peut avoir une ligne par enfant) et les retire tous d'un coup.
     */
    public static function handle_parent_remove_household_pickup_person() {
        $parent = self::authed_parent('psc_parent_remove_household_pickup_person');
        if (!$parent) self::parent_form_redirect('auth');

        $raw_ids = isset($_POST['pickup_ids']) ? sanitize_text_field(wp_unslash($_POST['pickup_ids'])) : '';
        $ids = array_filter(array_map('absint', explode(',', $raw_ids)));
        if (empty($ids)) self::parent_form_redirect('household_pickup_invalid');

        foreach ($ids as $pickup_id) {
            $person = Psc_Pickup_Persons::get($pickup_id);
            if (!$person || !self::pickup_person_owned_by($person, $parent->id)) continue;
            Psc_Pickup_Persons::remove($pickup_id, 'parent');
        }

        self::parent_form_redirect('household_pickup_removed');
    }
}
