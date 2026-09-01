<?php
if (!defined('ABSPATH')) exit;

/**
 * Profil du foyer depuis « Mon profil » : état civil et coordonnées,
 * second parent facultatif, popin de découverte à la première connexion.
 * Le changement d'e-mail du titulaire suit un chemin séparé
 * (Psc_Parents::request_email_change), lancé depuis handle_parent_update_profile().
 */
class Psc_Frontend_Profil extends Psc_Frontend_Base {

    public static function init() {
        add_action('admin_post_nopriv_psc_parent_update_profile', array(__CLASS__, 'handle_parent_update_profile'));
        add_action('admin_post_psc_parent_update_profile', array(__CLASS__, 'handle_parent_update_profile'));

        add_action('admin_post_nopriv_psc_parent_update_second_parent', array(__CLASS__, 'handle_parent_update_second_parent'));
        add_action('admin_post_psc_parent_update_second_parent', array(__CLASS__, 'handle_parent_update_second_parent'));
        add_action('admin_post_nopriv_psc_parent_remove_second_parent', array(__CLASS__, 'handle_parent_remove_second_parent'));
        add_action('admin_post_psc_parent_remove_second_parent', array(__CLASS__, 'handle_parent_remove_second_parent'));

        add_action('admin_post_nopriv_psc_parent_dismiss_onboarding', array(__CLASS__, 'handle_parent_dismiss_onboarding'));
        add_action('admin_post_psc_parent_dismiss_onboarding', array(__CLASS__, 'handle_parent_dismiss_onboarding'));
    }

    /**
     * Mise à jour de l'état civil / coordonnées / adresse du foyer depuis
     * "Mon profil". Le changement d'e-mail suit un chemin séparé
     * (Psc_Parents::request_email_change) : il ne prend effet qu'après
     * confirmation par lien, jamais immédiatement.
     */
    public static function handle_parent_update_profile() {
        $parent = self::authed_parent('psc_parent_update_profile');
        if (!$parent) self::parent_form_redirect('auth');

        // Formats contrôlés ici et pas dans Psc_Parents::update() : la
        // même méthode écrit les coordonnées depuis l'approbation d'une
        // demande (données déjà validées à la source) — la rejeter là
        // rendrait des demandes légitimes inapprovables. Champs
        // facultatifs : seuls les valeurs renseignées sont contrôlées.
        $profil_cps = psc_post('profil_code_postal');
        foreach (array(psc_post('profil_tel_mobile'), psc_post('profil_tel_fixe')) as $profil_tel) {
            if ($profil_tel !== '' && psc_valid_phone($profil_tel) === false) {
                self::parent_form_redirect('profil_invalid');
            }
        }
        if ($profil_cps !== '' && !psc_valid_postcode($profil_cps)) {
            self::parent_form_redirect('profil_invalid');
        }

        $result = Psc_Parents::update($parent->id, array(
            'nom'              => psc_post('profil_nom'),
            'prenom'           => psc_post('profil_prenom'),
            'telephone_mobile' => psc_post('profil_tel_mobile'),
            'telephone_fixe'   => psc_post('profil_tel_fixe'),
            'adresse'          => psc_post('profil_adresse'),
            'code_postal'      => psc_post('profil_code_postal'),
            'ville'            => psc_post('profil_ville'),
        ));
        if (is_wp_error($result)) self::parent_form_redirect('profil_error');

        $new_email = strtolower(sanitize_email(psc_post('profil_email')));
        if ($new_email && $new_email !== $parent->email) {
            $r = Psc_Parents::request_email_change($parent->id, $new_email);
            if (is_wp_error($r)) self::parent_form_redirect('email_taken');
            self::parent_form_redirect('profil_updated_email_pending');
        }

        self::parent_form_redirect('profil_updated');
    }

    /**
     * Enregistre/modifie le second parent (facultatif) depuis "Mon
     * profil". Chaque champ reste indépendamment optionnel — même
     * logique de validation que Psc_Requests::handle_submit() côté
     * inscription (Psc_Parents::update() revalide email/téléphone).
     */
    public static function handle_parent_update_second_parent() {
        $parent = self::authed_parent('psc_parent_update_second_parent');
        if (!$parent) self::parent_form_redirect('auth');

        $result = Psc_Parents::update($parent->id, array(
            'second_parent_prenom'    => psc_post('second_parent_prenom'),
            'second_parent_nom'       => psc_post('second_parent_nom'),
            'second_parent_email'     => psc_post('second_parent_email'),
            'second_parent_telephone' => psc_post('second_parent_telephone'),
        ));
        if (is_wp_error($result)) {
            $codes = array(
                'psc_bad_second_parent_email'    => 'second_parent_bad_email',
                'psc_second_parent_email_taken'  => 'second_parent_email_taken',
                'psc_bad_second_parent_phone'    => 'second_parent_bad_phone',
            );
            self::parent_form_redirect($codes[$result->get_error_code()] ?? 'second_parent_bad_phone');
        }

        self::parent_form_redirect('second_parent_updated');
    }

    /**
     * Retire le second parent : vide les 4 champs. Il disparaît alors de
     * la liste des personnes autorisées (synthétisée à la lecture depuis
     * wp_psc_parents, jamais une ligne wp_psc_pickup_persons à supprimer).
     */
    public static function handle_parent_remove_second_parent() {
        $parent = self::authed_parent('psc_parent_remove_second_parent');
        if (!$parent) self::parent_form_redirect('auth');

        Psc_Parents::update($parent->id, array(
            'second_parent_prenom'    => '',
            'second_parent_nom'       => '',
            'second_parent_email'     => '',
            'second_parent_telephone' => '',
        ));

        self::parent_form_redirect('second_parent_removed');
    }

    /**
     * Popin de découverte affichée une seule fois, à la toute première
     * connexion (cf. templates/frontend-portal.php et
     * assets/js/portal.js:initOnboardingTour()) — plusieurs étapes
     * naviguées côté client (aucun aller-retour serveur entre elles),
     * un seul appel ici quand le parent la termine ou la passe. Le drapeau
     * est sur le foyer (wp_psc_parents), pas par personne : si le second
     * parent se connecte après le titulaire l'a déjà vue, elle ne
     * réapparaît pas — cohérent avec le compte partagé du foyer.
     */
    public static function handle_parent_dismiss_onboarding() {
        $parent = self::authed_parent('psc_parent_dismiss_onboarding');
        if (!$parent) self::parent_form_redirect('auth');

        global $wpdb;
        $wpdb->update(
            psc_table('parents'),
            array('onboarding_seen_at' => current_time('mysql')),
            array('id' => $parent->id),
            array('%s'),
            array('%d')
        );

        // Code volontairement absent de $psc_notices (templates/frontend-portal.php) :
        // aucun bandeau à afficher, la popin elle-même vient de se fermer.
        self::parent_form_redirect('onboarding_dismissed');
    }
}
