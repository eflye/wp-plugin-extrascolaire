<?php
if (!defined('ABSPATH')) exit;

/**
 * Portail famille : prise de contrôle de la page (chrome, assets), onglets,
 * tableau de bord et vue invité.
 *
 * Les domaines métier vivent dans les classes Psc_Frontend_* déclarées par
 * init(). Cette classe n'en garde que ce qui ne relève d'aucun d'eux.
 */
class Psc_Frontend extends Psc_Frontend_Base {

    /**
     * Déclare les routes propres au noyau (shortcode, chrome, assets),
     * puis délègue à chaque domaine le soin de déclarer les siennes.
     * Le point d'entrée du plugin n'a donc pas à connaître le découpage
     * interne du portail.
     */
    public static function init() {
        add_shortcode('periscolaire_form', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

        // Une fois connectée, la famille n'a plus besoin du texte de
        // présentation rédigé autour du shortcode dans l'éditeur (utile
        // uniquement avant connexion, pour donner envie/expliquer) : le
        // portail occupe alors toute la page, dès le haut.
        add_filter('the_content', array(__CLASS__, 'hide_page_chrome_when_connected'), 20);

        // Le titre de la page (H1 "wp-block-post-title") vient du modèle
        // de page, pas de post_content : le filtre the_content ci-dessus
        // ne peut pas l'atteindre. On ajoute une classe sur <body> pour le
        // masquer en CSS uniquement quand le portail est affiché.
        add_filter('body_class', array(__CLASS__, 'add_portal_body_class'));

        foreach (array(
            'Psc_Frontend_Inscriptions',
            'Psc_Frontend_Enfants',
            'Psc_Frontend_Documents',
            'Psc_Frontend_Pickup',
            'Psc_Frontend_Profil',
            'Psc_Frontend_Reinscription',
            'Psc_Frontend_Menus',
        ) as $domain) {
            call_user_func(array($domain, 'init'));
        }
    }

    /**
     * Remplace tout le contenu de la page par le seul rendu du shortcode
     * quand une famille est connectée — le texte de présentation autour
     * (rédigé dans l'éditeur) n'a de sens qu'avant connexion. On ignore
     * volontairement $content : on ré-exécute le shortcode nous-mêmes
     * plutôt que d'essayer d'en extraire le rendu déjà mélangé au reste
     * de la page, ce qui évite toute dépendance à l'ordre des filtres
     * `the_content`.
     */
    public static function hide_page_chrome_when_connected($content) {
        if (!self::portal_takes_over_page()) return $content;
        return self::shortcode(array());
    }

    /** Ajoute une classe CSS sur <body> pour masquer le titre de page (venu
     *  du modèle, hors de portée de the_content) quand le portail s'affiche. */
    public static function add_portal_body_class($classes) {
        if (self::portal_takes_over_page()) {
            $classes[] = 'psc-portal-active';
        }
        return $classes;
    }

    /** Vrai si la page courante affiche le portail famille connecté (donc
     *  doit céder toute la page — titre et texte d'intro compris). */
    protected static function portal_takes_over_page() {
        if (!is_singular() || !Psc_Parents::current()) return false;
        $post = get_post();
        return $post && has_shortcode($post->post_content, 'periscolaire_form');
    }

    public static function assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_form')) return;

        wp_enqueue_style('psc-frontend', PSC_URL . 'assets/css/frontend.css', array(), PSC_VERSION);
        // Mécanique AJAX commune (assets/js/psc-ajax.js) : déclarée en
        // dépendance pour que WordPress garantisse l'ordre de chargement.
        wp_enqueue_script('psc-ajax', PSC_URL . 'assets/js/psc-ajax.js', array(), PSC_VERSION, true);
        wp_enqueue_script('psc-frontend', PSC_URL . 'assets/js/frontend.js', array('psc-ajax'), PSC_VERSION, true);
        // Second jeton, lié à la famille connectée : le nonce WordPress seul
        // est identique pour tous les visiteurs non connectés (cf.
        // psc_parent_nonce()). Vide pour un visiteur non identifié — les
        // routes AJAX concernées exigent de toute façon une session.
        $psc_parent = Psc_Parents::current();
        wp_localize_script('psc-frontend', 'PSC', array(
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('psc_front'),
            'parent_nonce' => $psc_parent ? psc_parent_nonce('psc_front', $psc_parent->id) : '',
            // Chaînes traduites côté serveur, consommées par frontend.js,
            // guest.js et portal.js : les codes d'erreur restent ceux
            // renvoyés par l'AJAX, seuls les libellés passent par ici.
            'i18n'         => array(
                'auth'              => __('Votre session a expiré. Rechargez la page pour demander un nouveau lien.', 'periscolaire-registration'),
                'forbidden'         => __("Vous n'êtes pas autorisé à modifier cette inscription.", 'periscolaire-registration'),
                'notfound'          => __('Enfant introuvable. Merci de recharger la page.', 'periscolaire-registration'),
                'day_closed'        => __("Ce jour n'est pas ouvert aux inscriptions.", 'periscolaire-registration'),
                'closed'            => __("Aucune période d'inscription n'est ouverte actuellement.", 'periscolaire-registration'),
                'locked'            => __('Le délai de modification est dépassé pour ce jour. Contactez la mairie.', 'periscolaire-registration'),
                'assurance_missing' => __('L\'assurance scolaire de cet enfant n\'a pas été fournie pour l\'année en cours. Ajoutez-la depuis « Mes enfants ».', 'periscolaire-registration'),
                'service_closed'    => __('Cette prestation est fermée ce jour-là. Contactez la mairie.', 'periscolaire-registration'),
                'service'           => __('Prestation inconnue.', 'periscolaire-registration'),
                'invalid'           => __('Données invalides.', 'periscolaire-registration'),
                'nochild'           => __('Aucun enfant rattaché à votre compte.', 'periscolaire-registration'),
                'rate'              => __('Trop de demandes. Merci de patienter quelques minutes.', 'periscolaire-registration'),
                'mail'              => __("L'envoi de l'e-mail a échoué.", 'periscolaire-registration'),
                'network'           => __('Erreur réseau. Vérifiez votre connexion et réessayez.', 'periscolaire-registration'),
                'generic'           => __("Une erreur est survenue. Merci de réessayer.", 'periscolaire-registration'),
                'summary_none'      => __('Aucun jour déclaré', 'periscolaire-registration'),
                'day'               => __('jour', 'periscolaire-registration'),
                'days'              => __('jours', 'periscolaire-registration'),
                'tout'              => __('Tout', 'periscolaire-registration'),
                'remove'            => __('Retirer', 'periscolaire-registration'),
                'sending'           => __('Envoi en cours...', 'periscolaire-registration'),
                'summary_sent'      => __('Récapitulatif envoyé.', 'periscolaire-registration'),
                'firstname'         => __('Prénom', 'periscolaire-registration'),
                'lastname'          => __('Nom', 'periscolaire-registration'),
                'phone'             => __('Téléphone', 'periscolaire-registration'),
                'child_firstname'   => __('Prénom de l’enfant %s', 'periscolaire-registration'),
                'child_lastname'    => __('Nom de l’enfant %s', 'periscolaire-registration'),
                'child_class'       => __('Classe de l’enfant %s', 'periscolaire-registration'),
                'child_birthdate'   => __('Date de naissance de l’enfant %s', 'periscolaire-registration'),
                'insurance'         => __('Justificatif d’assurance scolaire', 'periscolaire-registration'),
                'diet'              => __('Régime alimentaire', 'periscolaire-registration'),
                'diet_pork'         => __('Sans porc', 'periscolaire-registration'),
                'diet_meat'         => __('Sans viande', 'periscolaire-registration'),
                'pickup_title'      => __('Personnes autorisées à récupérer cet enfant en fin de garderie du soir (facultatif)', 'periscolaire-registration'),
                'pickup_add'        => __('+ Ajouter une personne autorisée', 'periscolaire-registration'),
                'child_remove'      => __('Supprimer cet enfant', 'periscolaire-registration'),
                'pickup_firstname'  => __('Prénom de la personne autorisée', 'periscolaire-registration'),
                'pickup_lastname'   => __('Nom de la personne autorisée', 'periscolaire-registration'),
                'pickup_phone'      => __('Téléphone de la personne autorisée', 'periscolaire-registration'),
                'pickup_link'       => __('Lien avec l’enfant', 'periscolaire-registration'),
                'link_placeholder'  => __('Lien (ex : Grand-parent)', 'periscolaire-registration'),
                'pickup_id_check'   => __('Présentera une pièce d’identité', 'periscolaire-registration'),
                'pickup_remove'     => __('Retirer cette personne autorisée', 'periscolaire-registration'),
                'week_load_failed'  => __('Impossible de charger cette semaine. Merci de réessayer.', 'periscolaire-registration'),
                'pickup_add_all_title' => __('Ajouter une personne autorisée', 'periscolaire-registration'),
                'pickup_edit_title' => __('Modifier — %s', 'periscolaire-registration'),
                'next'              => __('Suivant', 'periscolaire-registration'),
                'finish'            => __('Terminer', 'periscolaire-registration'),
                // Autocomplétion d'adresse (wizard invité, guest.js) :
                // recherche Base Adresse Nationale avec bascule manuelle.
                'address_search_placeholder' => __('Saisissez votre adresse (numéro, rue, ville)', 'periscolaire-registration'),
                'address_pick_required'      => __('Veuillez choisir une adresse dans la liste, ou saisir l\'adresse manuellement.', 'periscolaire-registration'),
                'address_no_result'          => __('Aucune adresse trouvée. Vous pouvez saisir votre adresse manuellement.', 'periscolaire-registration'),
                'address_service_error'      => __('Recherche d\'adresse momentanément indisponible. Vous pouvez saisir votre adresse manuellement.', 'periscolaire-registration'),
                'address_toggle_manual'      => __('Saisir l\'adresse manuellement', 'periscolaire-registration'),
                'address_toggle_search'      => __('Rechercher mon adresse', 'periscolaire-registration'),
                'address_attribution'        => __('Recherche d\'adresse propulsée par la Base Adresse Nationale (adresse.data.gouv.fr)', 'periscolaire-registration'),
                // Messages natifs de validation (attributs pattern/max/title).
                'phone_pattern_title'   => __('Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78.', 'periscolaire-registration'),
                'postcode_pattern_title' => __('Format attendu : 5 chiffres.', 'periscolaire-registration'),
                'child_birthdate_max'   => psc_child_birthdate_max(),
                // Écran Planning - 2 (planning-2.js) : libellés d'état et
                // confirmations côté client.
                'day_0'          => __('Dimanche', 'periscolaire-registration'),
                'day_1'          => __('Lundi', 'periscolaire-registration'),
                'day_2'          => __('Mardi', 'periscolaire-registration'),
                'day_3'          => __('Mercredi', 'periscolaire-registration'),
                'day_4'          => __('Jeudi', 'periscolaire-registration'),
                'day_5'          => __('Vendredi', 'periscolaire-registration'),
                'day_6'          => __('Samedi', 'periscolaire-registration'),
                'day_short_0'    => __('Dim.', 'periscolaire-registration'),
                'day_short_1'    => __('Lun.', 'periscolaire-registration'),
                'day_short_2'    => __('Mar.', 'periscolaire-registration'),
                'day_short_3'    => __('Mer.', 'periscolaire-registration'),
                'day_short_4'    => __('Jeu.', 'periscolaire-registration'),
                'day_short_5'    => __('Ven.', 'periscolaire-registration'),
                'day_short_6'    => __('Sam.', 'periscolaire-registration'),
                'exc_all'        => __('Tout', 'periscolaire-registration'),
                'exc_none'       => __('Aucun', 'periscolaire-registration'),
                'exc_bar_month'  => __('ce mois', 'periscolaire-registration'),
                'recap_year_suffix' => __("sur l'année", 'periscolaire-registration'),
                'locked_title'   => __('Verrouillé (délai de modification dépassé)', 'periscolaire-registration'),
                'modifiable_until' => psc_lock_message(current_time('Y-m-d')),
                'exc_state_pattern' => __('Rythme habituel', 'periscolaire-registration'),
                'exc_state_add'     => __('Ajout exceptionnel', 'periscolaire-registration'),
                'exc_state_remove'  => __('Retiré ce jour-là', 'periscolaire-registration'),
                'exc_state_none'    => __('Non déclaré', 'periscolaire-registration'),
                'exc_state_locked'  => __('Verrouillé (délai de modification dépassé)', 'periscolaire-registration'),
                'exc_reset_link'    => __('%s exception(s) ce mois-ci — revenir au rythme', 'periscolaire-registration'),
                'exc_reset_confirm' => __("Retirer toutes les exceptions de ce mois-ci et revenir au rythme habituel ? Les jours déjà verrouillés ne sont pas touchés.", 'periscolaire-registration'),
                'exc_reset_done'    => __('%s exception(s) retirée(s) : ce mois-ci suit de nouveau le rythme habituel.', 'periscolaire-registration'),
                'apply_siblings_confirm' => __("Appliquer ce rythme à toute la fratrie ? Le rythme habituel des autres enfants sera remplacé (leurs exceptions personnelles sont conservées).", 'periscolaire-registration'),
                'apply_siblings_done'    => __('Rythme appliqué à toute la fratrie.', 'periscolaire-registration'),
                'apply_siblings_empty'   => __('Cochez d\'abord au moins une case du rythme habituel.', 'periscolaire-registration'),
                'recap_month'       => __('Mois : %1$s jour(s) · %2$s €', 'periscolaire-registration'),
                'recap_year'        => __('Année : %1$s jour(s) · %2$s €', 'periscolaire-registration'),
                // Wizard invité (guest.js) : allergies + rythme habituel.
                'allergy_toggle'     => __('Cet enfant a une allergie alimentaire', 'periscolaire-registration'),
                'allergy_placeholder' => __('Aliments à exclure des repas, réaction en cas d\'ingestion, conduite à tenir.', 'periscolaire-registration'),
                'allergy_help'       => __("Strictement alimentaire. La mairie vous contactera si un PAI (projet d'accueil individualisé) doit être mis en place. Aucun menu différencié n'est proposé : l'enfant déjeune à la cantine avec son propre repas fourni par la famille.", 'periscolaire-registration'),
            ),
        ));

        // Design v2 : commun au portail connecté et à la vue invité —
        // psc-frontend reste le socle partagé par les deux (bascule de
        // connexion, cases à cocher du calendrier, popins, sauvegarde de
        // défilement).
        wp_enqueue_style('psc-portal', PSC_URL . 'assets/css/portal.css', array('psc-frontend'), PSC_VERSION);

        if (Psc_Parents::current()) {
            // psc-dialog : sémantique de dialogue des popins du portail
            // (role="dialog", aria-modal, piège de focus, Échap) — cf. les
            // modales de portal.js et le tour de découverte.
            wp_enqueue_script('psc-dialog', PSC_URL . 'assets/js/psc-dialog.js', array(), PSC_VERSION, true);
            wp_enqueue_script('psc-portal', PSC_URL . 'assets/js/portal.js', array('psc-ajax', 'psc-dialog'), PSC_VERSION, true);
            // Écran Planning - 2 (rythme + exceptions) : re-rendu des zones
            // après chaque écriture AJAX, navigation mois et onglets enfants.
            wp_enqueue_script('psc-planning-2', PSC_URL . 'assets/js/planning-2.js', array('psc-ajax'), PSC_VERSION, true);
        } else {
            wp_enqueue_script('psc-guest', PSC_URL . 'assets/js/guest.js', array(), PSC_VERSION, true);
        }
    }

    /* ---------------- Espace famille v2 ("Family Portal") ---------------- */

    /**
     * Onglet Planning : les deux variantes (Planning - 1 « saisie jour par
     * jour », Planning - 2 « rythme + exceptions ») lisent et écrivent LE
     * MÊME modèle — une saisie faite dans l'une se retrouve dans l'autre,
     * aucune synchronisation. Le réglage psc_planning_variant (Réglages)
     * limite l'exposition à une seule variante sans redéploiement ; un
     * onglet unique s'appelle alors simplement « Planning ».
     *
     * Quand les DEUX variantes sont exposées, Planning - 1 n'a pas de lien
     * dans le menu (cantine2 porte la navigation) : l'écran reste accessible
     * via la bascule de Planning - 2 et son URL directe. d'où le drapeau
     * 'menu' consommé par portal_tabs_data()/frontend-portal.php.
     */
    protected static function planning_tab_defs() {
        $variants = psc_planning_variants();
        $single   = psc_planning_single_variant();
        $icon     = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.5" y="5" width="17" height="15" rx="1"/><path d="M3.5 9.5h17"/><path d="M8 3v3M16 3v3"/></svg>';

        $defs = array();
        if (in_array('cantine', $variants, true)) {
            $defs['cantine'] = array(
                'label' => $single ? __('Planning', 'periscolaire-registration') : __('Planning - 1', 'periscolaire-registration'),
                'icon'  => $icon,
                'menu'  => $single,
            );
        }
        if (in_array('cantine2', $variants, true)) {
            $defs['cantine2'] = array(
                'label' => $single ? __('Planning', 'periscolaire-registration') : __('Planning - 2', 'periscolaire-registration'),
                'icon'  => $icon,
                'menu'  => true,
            );
        }
        return $defs;
    }

    protected static function portal_tab_defs() {
        $tabs = array(
            'dashboard' => array(
                'label' => __('Tableau de bord', 'periscolaire-registration'),
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9h13v-9"/><path d="M10 19v-6h4v6"/></svg>',
            ),
        );
        $tabs = array_merge($tabs, self::planning_tab_defs());
        $tabs['menu'] = array(
            'label' => __('Menu de la semaine', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3v8a2 2 0 0 0 4 0V3M6 6h4"/><path d="M14 3c-1.2 1.3-1.2 6 0 8M14 3v18M17 3v6a2 2 0 0 0 2 2v10"/></svg>',
        );
        $tabs['enfants'] = array(
            'label' => __('Mes enfants', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="3.2"/><path d="M5 20c0-3.5 3.2-6 7-6s7 2.5 7 6"/></svg>',
        );
        $tabs['habilitations'] = array(
            'label' => __('Habilitations', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 3l7 3v5c0 4.6-3 7.6-7 9-4-1.4-7-4.4-7-9V6l7-3Z"/><path d="M9 11.5l2 2 4-4"/></svg>',
        );
        $tabs['factures'] = array(
            'label' => __('Mes factures', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
        );
        $tabs['profil'] = array(
            'label' => __('Mon profil', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-4 3.1-7 7-7s7 3 7 7"/></svg>',
        );
        $tabs['documents'] = array(
            'label' => __('Documents', 'periscolaire-registration'),
            'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>',
        );
        // Onglet supplémentaire, visible seulement pendant la fenêtre de
        // réinscription ouverte par la mairie (Réglages) — pas une gestion
        // courante comme les autres onglets, une action annuelle ponctuelle.
        if (Psc_Frontend_Reinscription::reinscription_window_open()) {
            $tabs['reinscription'] = array(
                'label' => __('Réinscription', 'periscolaire-registration'),
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 12a8 8 0 1 1 2.34 5.66"/><path d="M4 21v-5h5"/></svg>',
            );
        }
        return $tabs;
    }

    /**
     * Onglet actif : ?psc_tab= si connu, sinon le tableau de bord. Un
     * message lié à la gestion des enfants (retour d'un POST classique,
     * cf. handle_parent_*) ramène toujours sur l'onglet "Mes enfants",
     * quel que soit l'onglet d'où le formulaire a été soumis — même chose
     * pour "Habilitations" (personnes autorisées) et pour "Mon profil"
     * avec les messages liés à leur propre mise à jour.
     */
    protected static function resolve_active_tab($psc_msg) {
        $known = array_keys(self::portal_tab_defs());
        $requested = isset($_GET['psc_tab']) ? sanitize_key(wp_unslash($_GET['psc_tab'])) : '';
        $tab = in_array($requested, $known, true) ? $requested : 'dashboard';

        // Un onglet Planning demandé mais non exposé (réglage variante) :
        // retombe sur le premier onglet Planning disponible, sinon dashboard.
        if (in_array($tab, array('cantine', 'cantine2'), true) && !in_array($tab, $known, true)) {
            $planning = array_intersect(array('cantine', 'cantine2'), $known);
            $tab = $planning ? reset($planning) : 'dashboard';
        }

        if (in_array($psc_msg, array(
            'child_updated', 'child_added', 'child_invalid', 'child_limit', 'child_bad_birthdate',
            'assurance_uploaded', 'assurance_invalid', 'assurance_upload_failed',
            'assurance_too_large', 'assurance_invalid_type', 'assurance_required',
        ), true)) {
            $tab = 'enfants';
        }
        // Personnes autorisées (ajout foyer, édition et retrait par ligne) :
        // le bloc vit dans l'onglet « Habilitations », les retours y reviennent.
        if (in_array($psc_msg, array(
            'pickup_updated', 'pickup_removed', 'pickup_invalid',
            'household_pickup_added', 'household_pickup_invalid',
        ), true)) {
            $tab = 'habilitations';
        }
        if (in_array($psc_msg, array(
            'profil_updated', 'profil_updated_email_pending', 'profil_error', 'profil_invalid',
            'email_taken', 'email_changed', 'email_change_cancelled',
            'bad_email_token', 'expired_email_token',
            'second_parent_updated', 'second_parent_removed',
            'second_parent_bad_email', 'second_parent_bad_phone', 'second_parent_email_taken',
        ), true)) {
            $tab = 'profil';
        }
        if (in_array($psc_msg, array(
            'reinscription_confirmee', 'reinscription_invalid', 'reinscription_required',
        ), true)) {
            $tab = 'reinscription';
        }
        return $tab;
    }

    protected static function portal_tabs_data() {
        $base = remove_query_arg(array('psc_tab', 'psc_semaine', 'psc_msg'));
        $tabs = array();
        foreach (self::portal_tab_defs() as $key => $def) {
            $tabs[$key] = array(
                'label' => $def['label'],
                'icon'  => $def['icon'],
                'url'   => add_query_arg('psc_tab', $key, $base),
                // Les onglets sans lien de menu (Planning - 1 quand les deux
                // variantes sont exposées) restent rendus et atteignables
                // (bascule de Planning - 2, URL directe) — la sidebar, elle,
                // saute les entrées marquées menu = false.
                'menu'  => $def['menu'] ?? true,
            );
        }
        return $tabs;
    }

    /**
     * Statistiques du tableau de bord : cumul ANNÉE scolaire (tous enfants
     * actifs confondus — plus aucune notion de trimestre), prochaine facture
     * non envoyée, menu de la semaine réelle en cours, résumé par enfant.
     * Les déclarations viennent de Psc_Planning::year_summary() (source de
     * vérité unique, un lot de requêtes).
     */
    protected static function dashboard_data($parent, $children, $year_summary, $invoices) {
        $year = $year_summary['year'];
        $days_count = $year['days'];
        $amount = $year['amount'];
        $children_summaries = array();

        foreach ($children as $child) {
            $cid = (int) $child->id;
            $child_days = isset($year['per_child'][$cid]) ? $year['per_child'][$cid]['days'] : 0;
            $child_amount = isset($year['per_child'][$cid]) ? $year['per_child'][$cid]['amount'] : 0.0;

            $diet_bits = array();
            if ((int) $child->sans_porc) $diet_bits[] = __('Sans porc', 'periscolaire-registration');
            if ((int) $child->vegan) $diet_bits[] = __('Sans viande', 'periscolaire-registration');
            $meta = Psc_School_Years::classe_for($child->id);
            if ($diet_bits) $meta .= ($meta !== '' ? ' · ' : '') . implode(', ', $diet_bits);
            $allergies = trim((string) $child->food_allergies);
            if ($allergies !== '') {
                $meta .= ($meta !== '' ? ' · ' : '') . __('Allergies alimentaires', 'periscolaire-registration');
            }
            if ($meta === '') $meta = '—';

            $children_summaries[] = array(
                'name'    => trim($child->prenom . ' ' . $child->nom),
                'meta'    => $meta,
                'summary' => sprintf(
                    __('%d jour%s déclaré%s · %s €', 'periscolaire-registration'),
                    $child_days, $child_days > 1 ? 's' : '', $child_days > 1 ? 's' : '',
                    number_format_i18n($child_amount, 2)
                ),
            );
        }

        $next_invoice = null;
        $pending = array_values(array_filter($invoices, function ($i) { return empty($i->sent_at); }));
        if ($pending) {
            usort($pending, function ($a, $b) { return strcmp($a->mois, $b->mois); });
            $next_invoice = array(
                'mois_label'   => Psc_Invoices::month_label($pending[0]->mois),
                'status_label' => __('En attente', 'periscolaire-registration'),
            );
        }

        $current_week = psc_week_start(current_time('Y-m-d'));

        return array(
            'title'          => $parent->nom !== '' ? __('Famille ', 'periscolaire-registration') . $parent->nom : __('Bienvenue', 'periscolaire-registration'),
            'days_label'     => $days_count . ($days_count > 1 ? ' ' . __('jours', 'periscolaire-registration') : ' ' . __('jour', 'periscolaire-registration')),
            'amount_label'   => number_format_i18n($amount, 2),
            'next_invoice'   => $next_invoice,
            'menu'           => Psc_Frontend_Menus::menu_days_for_week($current_week),
            'menu_no_school' => !Psc_Frontend_Menus::week_has_school_day($current_week),
            'children'       => $children_summaries,
        );
    }

    /**
     * Prestations à venir, non verrouillées, déclarées pour chaque enfant —
     * sert à peupler la popin "Annulation prestations" du tableau de bord.
     * Les déclarations viennent de la carte de résolution (psc_is_declared)
     * : un forfait journée (FORF) couvre GM+CANT+GS pour un prix unique et
     * indivisible — il est listé comme 3 prestations séparées pour la
     * lisibilité, mais les 3 pointent vers la même exception — en cocher
     * une seule annule le forfait en entier. Un enfant sans prestation
     * annulable n'apparaît pas dans le résultat.
     */
    protected static function absence_candidates($children, $declared) {
        $today  = current_time('Y-m-d');
        $svc_labels = psc_services();

        $out = array();
        foreach ($children as $child) {
            $dates = isset($declared[(int) $child->id]) ? $declared[(int) $child->id] : array();
            ksort($dates);
            $items = array();
            foreach ($dates as $date => $services_map) {
                if ($date < $today) continue;
                if (psc_is_locked($date)) continue;
                if (!Psc_School_Year::is_school_day($date)) continue;

                $day_label = psc_day_label($date) . ' ' . date_i18n('d/m/Y', strtotime($date));
                $forf = psc_forfait_code();
                if (!empty($services_map[$forf])) {
                    // Un forfait se lit comme les trois prestations qu'il couvre :
                    // la famille les voit détaillées, mais c'est bien le forfait
                    // entier qui sera annulé (cf. 'service' ci-dessous).
                    foreach (psc_unit_services() as $sub) {
                        $items[] = array(
                            'date'    => $date,
                            'service' => $forf, // valeur réellement annulée (FORF)
                            'label'   => $day_label . ' — ' . ($svc_labels[$sub]['label'] ?? $sub),
                        );
                    }
                    continue;
                }
                foreach (psc_unit_services() as $svc) {
                    if (empty($services_map[$svc])) continue;
                    $items[] = array(
                        'date'    => $date,
                        'service' => $svc,
                        'label'   => $day_label . ' — ' . ($svc_labels[$svc]['label'] ?? $svc),
                    );
                }
            }
            if ($items) {
                $out[$child->id] = array(
                    'name'  => trim($child->prenom . ' ' . $child->nom),
                    'items' => $items,
                );
            }
        }
        return $out;
    }

    /* ---------------- Vue invité v2 ("Vue visiteur") ---------------- */

    /**
     * Un message d'erreur retourné après une soumission de demande ratée
     * (validation serveur inchangée, cf. Psc_Requests::handle_submit) doit
     * rouvrir le stepper sur l'étape concernée — sinon l'erreur affichée
     * ne correspond à aucun champ visible. Le rechargement complet de la
     * page (redirection) efface aussi tout ce que le parent avait saisi,
     * y compris le mode de paiement choisi : pour une erreur liée au
     * prélèvement, on rouvre donc le panneau SEPA plutôt que de retomber
     * sur "Chèque ou espèces" (qui masquerait le champ à corriger).
     */
    protected static function wizard_error_context($psc_msg) {
        $map = array(
            'coordonnees_incomplete'   => array('step' => 0, 'sepa' => false),
            'second_parent_bad_email'   => array('step' => 0, 'sepa' => false),
            'second_parent_bad_phone'   => array('step' => 0, 'sepa' => false),
            'second_parent_email_taken' => array('step' => 0, 'sepa' => false),
            'need_child'               => array('step' => 1, 'sepa' => false),
            'child_incomplete'         => array('step' => 1, 'sepa' => false),
            'child_bad_birthdate'      => array('step' => 1, 'sepa' => false),
            'pickup_person_incomplete' => array('step' => 1, 'sepa' => false),
            'assurance_required'       => array('step' => 1, 'sepa' => false),
            'assurance_too_large'      => array('step' => 1, 'sepa' => false),
            'assurance_invalid_type'   => array('step' => 1, 'sepa' => false),
            'sepa_reglement_required'  => array('step' => 2, 'sepa' => true),
            'sepa_missing'             => array('step' => 2, 'sepa' => true),
            'bad_iban'                 => array('step' => 2, 'sepa' => true),
            'bad_bic'                  => array('step' => 2, 'sepa' => true),
            'bad_code_postal'          => array('step' => 2, 'sepa' => true),
            'reglement_required'       => array('step' => 3, 'sepa' => false),
        );
        if (isset($map[$psc_msg])) {
            return array(
                'step'         => $map[$psc_msg]['step'],
                'payment_mode' => $map[$psc_msg]['sepa'] ? 'prelevement' : 'autre',
                'has_error'    => true,
            );
        }
        return array('step' => 0, 'payment_mode' => 'autre', 'has_error' => false);
    }

    /* ---------------- Affichage ---------------- */

    public static function shortcode($atts) {
        ob_start();

        $psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
        $parent = Psc_Parents::current();

        if (!$parent) {
            $psc_guest_menu = Psc_Frontend_Menus::guest_menu_data();
            $psc_wizard = self::wizard_error_context($psc_msg);
            include PSC_PATH . 'templates/frontend-guest.php';
            return ob_get_clean();
        }

        // Ceinture de sécurité : la configuration du planning de l'année
        // en cours doit exister (dates, fériés) même si la mairie n'a rien
        // fait depuis la mise à jour.
        Psc_School_Year::ensure_default();

        $all_children = self::children_of($parent->id);               // pour la section "Mes enfants"
        $children     = self::children_of($parent->id, true);         // uniquement actifs → planning
        $services = psc_services();
        $invoices = Psc_Invoices::get_for_parent($parent->id);

        // Année scolaire et mois affiché (navigation ← →, ?psc_mois=YYYY-MM).
        $psc_year = Psc_School_Year::active();
        $psc_months = Psc_School_Year::months();
        $psc_month_keys = wp_list_pluck($psc_months, 'key');
        $psc_month_key = '';
        foreach ($psc_months as $m) {
            $t = current_time('Y-m');
            if ($m['key'] === $t) { $psc_month_key = $m['key']; break; }
        }
        if (!$psc_month_key && $psc_month_keys) {
            // Hors période scolaire (été) : premier mois de l'année.
            $psc_month_key = $psc_month_keys[0];
        }
        $requested_month = isset($_GET['psc_mois']) ? sanitize_text_field(wp_unslash($_GET['psc_mois'])) : '';
        if (preg_match('/^\d{4}-\d{2}$/', $requested_month) && in_array($requested_month, $psc_month_keys, true)) {
            $psc_month_key = $requested_month;
        }
        $psc_month_index = array_search($psc_month_key, $psc_month_keys, true);
        $psc_prev_month  = $psc_month_index > 0 ? $psc_month_keys[$psc_month_index - 1] : null;
        $psc_next_month  = $psc_month_index !== false && $psc_month_index < count($psc_month_keys) - 1 ? $psc_month_keys[$psc_month_index + 1] : null;
        $psc_month_label = '';
        foreach ($psc_months as $m) {
            if ($m['key'] === $psc_month_key) { $psc_month_label = $m['label']; break; }
        }

        // Année scolaire : récap fratrie + estimation (frise, cartes,
        // tableau de bord) — un seul lot de résolutions.
        $psc_year_summary = Psc_Planning::year_summary($children);
        $psc_planning_variant = null;
        $psc_planning_data = null;

        $active_tab       = self::resolve_active_tab($psc_msg);
        $psc_portal_tabs  = self::portal_tabs_data();
        $psc_portal_menu  = Psc_Frontend_Menus::portal_menu_data();
        $psc_portal_dashboard = self::dashboard_data($parent, $children, $psc_year_summary, $invoices);
        $psc_assurance_map = Psc_Assurances::map_for($all_children);

        // Uniquement les enfants actifs : un enfant sorti disparaît du
        // planning (cf. $children plus haut), la liste des personnes
        // autorisées le suit pour la même raison.
        $psc_pickup_map = array();
        foreach ($children as $child) {
            $psc_pickup_map[$child->id] = Psc_Pickup_Persons::for_child($child->id);
        }

        // Données propres aux écrans Planning : chaque variante exposée est
        // rendue dans le DOM — pas seulement l'onglet actif — car la
        // navigation d'onglets bascule l'affichage localement (portal.js)
        // SANS recharger la page. Seul le mois affiché est rendu, jamais
        // l'année entière.
        if (!empty($psc_portal_tabs['cantine'])) {
            $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
            $month_dates = Psc_School_Year::school_days_in_month($psc_month_key);
            Psc_School_Calendar::preload_closed($month_dates);
            $psc_declared = Psc_Planning::declared_map($child_ids, $month_dates);

            $psc_planning_variant = null;
            $psc_planning_data = array(
                'month_dates' => $month_dates,
                'declared'    => $psc_declared,
                // Cases « explicites » de la variante 1 : une case cochée =
                // une ligne stockée (pattern ou exception), le forfait ne
                // couvrant que sa propre colonne.
                'explicit'    => Psc_Planning::month_explicit_map($child_ids, $psc_month_key),
                'active_child' => 0,
                'patterns'    => array(),
                'cells'       => array('dates' => array(), 'cells' => array()),
            );

            // Popin "Annulation prestations" du tableau de bord : les
            // déclarations à venir (non verrouillées), calculées sur le
            // reste de l'année scolaire.
            $psc_planning_data['year_declared'] = Psc_Planning::declared_map(
                $child_ids,
                Psc_School_Year::school_days(current_time('Y-m-d'), $psc_year->date_end)
            );
        }

        // Écran Planning - 2 (rythme + exceptions) : enfant affiché (onglets).
        // La section est rendue dans le DOM dès que la variante est exposée —
        // pas seulement quand l'onglet est actif : le clic sur « Planning - 2 »
        // du menu bascule localement (portal.js) SANS recharger la page, une
        // section rendue vide y afficherait « Aucun enfant… » alors que le
        // foyer en a. Même raisonnement que le bloc ci-dessus.
        if (!empty($psc_portal_tabs['cantine2']) && !empty($children)) {
            // Enfant affiché (onglets) : ?psc_child= ou le premier.
            $requested_child = isset($_GET['psc_child']) ? absint($_GET['psc_child']) : 0;
            foreach ($children as $c) {
                if ((int) $c->id === $requested_child) { $psc_planning_data['active_child'] = $requested_child; break; }
            }
            if (!$psc_planning_data['active_child'] && $children) {
                $psc_planning_data['active_child'] = (int) $children[0]->id;
            }

            $psc_planning_data['patterns'] = Psc_Planning::load_patterns(array_map(function ($c) { return (int) $c->id; }, $children));
            $psc_planning_data['cells'] = $psc_planning_data['active_child']
                ? Psc_Planning::month_state($psc_planning_data['active_child'], $psc_month_key)
                : array('dates' => array(), 'cells' => array());
        }

        $psc_portal_absence_days = $children ? self::absence_candidates(
            $children,
            isset($psc_planning_data['year_declared']) ? $psc_planning_data['year_declared'] : array()
        ) : array();

        $psc_portal_reinscription = null;
        if (isset($psc_portal_tabs['reinscription'])) {
            $psc_portal_reinscription = array(
                'target_year' => Psc_Frontend_Reinscription::reinscription_target_year(),
                'children'    => array_map(function ($child) {
                    $classe_actuelle = Psc_School_Years::classe_for($child->id);
                    $classe_proposee = $classe_actuelle !== '' ? Psc_School_Years::classe_superieure($classe_actuelle) : null;
                    return array(
                        'id'              => $child->id,
                        'name'            => trim($child->prenom . ' ' . $child->nom),
                        'classe_actuelle' => $classe_actuelle,
                        'classe_proposee' => $classe_proposee, // null|code classe|'sortie'
                    );
                }, $children),
            );
        }

        include PSC_PATH . 'templates/frontend-portal.php';
        return ob_get_clean();
    }
}
