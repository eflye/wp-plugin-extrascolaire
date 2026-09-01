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
        } else {
            wp_enqueue_script('psc-guest', PSC_URL . 'assets/js/guest.js', array(), PSC_VERSION, true);
        }
    }

    /**
     * Construit la table des prestations fermées individuellement (calendrier
     * scolaire v2) pour une liste de jours. Clé : date|service — même
     * principe que reg_map(), mais sans dimension enfant (une fermeture de
     * prestation s'applique à toute la structure, pas à un enfant en particulier).
     */
    protected static function service_closures_map($days) {
        if (empty($days)) return array();
        global $wpdb;

        $dates = wp_list_pluck($days, 'jour_date');
        $placeholders = implode(',', array_fill(0, count($dates), '%s'));
        $t = psc_table('service_closures');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT jour_date, service FROM $t WHERE jour_date IN ($placeholders)",
            $dates
        ));

        $map = array();
        foreach ($rows as $r) {
            $map[$r->jour_date . '|' . $r->service] = 1;
        }
        return $map;
    }

    /* ---------------- Espace famille v2 ("Family Portal") ---------------- */

    protected static function portal_tab_defs() {
        $tabs = array(
            'dashboard' => array(
                'label' => 'Tableau de bord',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9h13v-9"/><path d="M10 19v-6h4v6"/></svg>',
            ),
            'cantine' => array(
                'label' => 'Cantine & Garderie',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3.5" y="5" width="17" height="15" rx="1"/><path d="M3.5 9.5h17"/><path d="M8 3v3M16 3v3"/></svg>',
            ),
            'menu' => array(
                'label' => 'Menu de la semaine',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3v8a2 2 0 0 0 4 0V3M6 6h4"/><path d="M14 3c-1.2 1.3-1.2 6 0 8M14 3v18M17 3v6a2 2 0 0 0 2 2v10"/></svg>',
            ),
            'enfants' => array(
                'label' => 'Mes enfants',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="3.2"/><path d="M5 20c0-3.5 3.2-6 7-6s7 2.5 7 6"/></svg>',
            ),
            'factures' => array(
                'label' => 'Mes factures',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
            ),
            'profil' => array(
                'label' => 'Mon profil',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-4 3.1-7 7-7s7 3 7 7"/></svg>',
            ),
            'documents' => array(
                'label' => 'Documents',
                'icon'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>',
            ),
        );
        // Onglet supplémentaire, visible seulement pendant la fenêtre de
        // réinscription ouverte par la mairie (Réglages) — pas une gestion
        // courante comme les autres onglets, une action annuelle ponctuelle.
        if (Psc_Frontend_Reinscription::reinscription_window_open()) {
            $tabs['reinscription'] = array(
                'label' => 'Réinscription',
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
     * pour "Mon profil" avec les messages liés à sa propre mise à jour.
     */
    protected static function resolve_active_tab($psc_msg) {
        $known = array_keys(self::portal_tab_defs());
        $requested = isset($_GET['psc_tab']) ? sanitize_key(wp_unslash($_GET['psc_tab'])) : '';
        $tab = in_array($requested, $known, true) ? $requested : 'dashboard';

        if (in_array($psc_msg, array(
            'child_updated', 'child_added', 'child_invalid', 'child_limit',
            'assurance_uploaded', 'assurance_invalid', 'assurance_upload_failed',
            'assurance_too_large', 'assurance_invalid_type', 'assurance_required',
            'pickup_added', 'pickup_updated', 'pickup_removed', 'pickup_invalid',
        ), true)) {
            $tab = 'enfants';
        }
        if (in_array($psc_msg, array(
            'profil_updated', 'profil_updated_email_pending', 'profil_error',
            'email_taken', 'email_changed', 'email_change_cancelled',
            'bad_email_token', 'expired_email_token',
            'second_parent_updated', 'second_parent_removed',
            'second_parent_bad_email', 'second_parent_bad_phone', 'second_parent_email_taken',
            'household_pickup_added', 'household_pickup_removed', 'household_pickup_invalid',
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
            );
        }
        return $tabs;
    }

    /**
     * Statistiques du tableau de bord : cumul période (tous enfants
     * actifs confondus), prochaine facture non envoyée, menu de la
     * semaine réelle en cours, résumé par enfant.
     */
    protected static function dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices) {
        $days_count = 0;
        $amount = 0.0;
        $children_summaries = array();

        foreach ($children as $child) {
            $child_days = 0;
            $child_amount = 0.0;
            foreach ($days_by_month as $days) {
                foreach ($days as $d) {
                    $has_reg = false;
                    foreach (psc_allowed_services() as $s) {
                        if (isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s])) {
                            $has_reg = true;
                            $child_amount += (float) $services[$s]['price'];
                        }
                    }
                    if ($has_reg) $child_days++;
                }
            }
            $days_count += $child_days;
            $amount += $child_amount;

            $diet_bits = array();
            if ((int) $child->sans_porc) $diet_bits[] = 'Sans porc';
            if ((int) $child->vegan) $diet_bits[] = 'Sans viande';
            $meta = Psc_School_Years::classe_for($child->id);
            if ($diet_bits) $meta .= ($meta !== '' ? ' · ' : '') . implode(', ', $diet_bits);
            if ($meta === '') $meta = '—';

            $children_summaries[] = array(
                'name'    => trim($child->prenom . ' ' . $child->nom),
                'meta'    => $meta,
                'summary' => sprintf(
                    '%d jour%s déclaré%s · %s €',
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
                'status_label' => 'En attente',
            );
        }

        $current_week = psc_week_start(current_time('Y-m-d'));

        return array(
            'title'          => $parent->nom !== '' ? 'Famille ' . $parent->nom : 'Bienvenue',
            'days_label'     => $days_count . ($days_count > 1 ? ' jours' : ' jour'),
            'amount_label'   => number_format_i18n($amount, 2),
            'next_invoice'   => $next_invoice,
            'menu'           => Psc_Frontend_Menus::menu_days_for_week($current_week),
            'menu_no_school' => !Psc_Frontend_Menus::week_has_school_day($current_week),
            'children'       => $children_summaries,
        );
    }

    /**
     * Prestations à venir, non verrouillées, déjà cochées pour chaque
     * enfant — sert à peupler la popin "Annulation prestations" du tableau
     * de bord. Un forfait journée (FORF) couvre GM+CANT+GS pour un prix
     * unique et indivisible : il est listé comme 3 prestations séparées
     * pour la lisibilité, mais les 3 pointent vers la même inscription —
     * en cocher une seule annule le forfait en entier (cf.
     * Psc_Frontend_Inscriptions::handle_cancel_absence(), qui dédoublonne
     * par date+service). Un enfant sans prestation annulable n'apparaît
     * pas dans le résultat.
     */
    protected static function absence_candidates($children) {
        global $wpdb;
        $t_reg  = psc_table('registrations');
        $t_days = psc_table('calendar_days');
        $today  = current_time('Y-m-d');
        $svc_labels = psc_services();

        $out = array();
        foreach ($children as $child) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.jour_date, r.service FROM $t_reg r
                 INNER JOIN $t_days d ON d.jour_date = r.jour_date AND d.is_open = 1
                 WHERE r.child_id = %d AND r.jour_date >= %s
                 ORDER BY r.jour_date ASC",
                $child->id, $today
            ));
            $items = array();
            foreach ($rows as $row) {
                if (psc_is_locked($row->jour_date)) continue;
                $day_label = psc_day_label($row->jour_date) . ' ' . date_i18n('d/m/Y', strtotime($row->jour_date));
                // Un forfait se lit comme les trois prestations qu'il couvre :
                // la famille les voit détaillées, mais c'est bien le forfait
                // entier qui sera annulé (cf. 'service' ci-dessous).
                $sub_services = $row->service === psc_forfait_code()
                    ? psc_unit_services()
                    : array($row->service);
                foreach ($sub_services as $sub) {
                    $items[] = array(
                        'date'    => $row->jour_date,
                        'service' => $row->service, // valeur réellement annulée (FORF si forfait)
                        'label'   => $day_label . ' — ' . ($svc_labels[$sub]['label'] ?? $sub),
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
            'assurance_required'       => array('step' => 1, 'sepa' => false),
            'assurance_too_large'      => array('step' => 1, 'sepa' => false),
            'assurance_invalid_type'   => array('step' => 1, 'sepa' => false),
            'sepa_reglement_required'  => array('step' => 2, 'sepa' => true),
            'sepa_missing'             => array('step' => 2, 'sepa' => true),
            'bad_iban'                 => array('step' => 2, 'sepa' => true),
            'bad_bic'                  => array('step' => 2, 'sepa' => true),
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

        $trimestre    = Psc_Trimestres::active();
        $all_children = self::children_of($parent->id);               // pour la section "Mes enfants"
        $children     = self::children_of($parent->id, true);         // uniquement actifs → calendrier
        $days_by_month = array();
        $reg_map = array();
        $service_closures_map = array();

        if ($trimestre) {
            global $wpdb;
            $t_days = psc_table('calendar_days');
            $days = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $t_days WHERE trimestre_id = %d AND is_open = 1 ORDER BY jour_date",
                $trimestre->id
            ));
            foreach ($days as $d) {
                $days_by_month[date_i18n('F Y', strtotime($d->jour_date))][] = $d;
            }
            $reg_map = self::reg_map($trimestre->id, $children);
            $service_closures_map = self::service_closures_map($days);
        }

        $services = psc_services();
        $invoices = Psc_Invoices::get_for_parent($parent->id);

        $active_tab       = self::resolve_active_tab($psc_msg);
        $psc_portal_tabs  = self::portal_tabs_data();
        $psc_portal_menu  = Psc_Frontend_Menus::portal_menu_data();
        $psc_portal_dashboard = self::dashboard_data($parent, $children, $days_by_month, $reg_map, $services, $invoices);
        $psc_portal_absence_days = self::absence_candidates($children);
        $psc_assurance_map = Psc_Assurances::map_for($all_children);

        // Uniquement les enfants actifs : un enfant sorti disparaît du
        // planning (cf. $children plus haut), la liste des personnes
        // autorisées le suit pour la même raison.
        $psc_pickup_map = array();
        foreach ($children as $child) {
            $psc_pickup_map[$child->id] = Psc_Pickup_Persons::for_child($child->id);
        }

        // "Mon profil" : vue foyer dédupliquée (les deux parents toujours
        // présents, même sans enfant actif ; un tiers ajouté depuis "Mes
        // enfants" pour plusieurs enfants n'apparaît qu'une fois, avec
        // tous les pickup_person_id concernés regroupés dans 'ids' — un
        // retrait depuis cette vue les retire de tous ces enfants d'un coup).
        $psc_household_authorized = Psc_Pickup_Persons::parent_entries($parent);
        foreach ($psc_household_authorized as &$psc_hh_entry) {
            $psc_hh_entry['ids'] = array();
        }
        unset($psc_hh_entry);
        $psc_household_seen = array();
        foreach ($psc_household_authorized as $psc_hh_i => $psc_hh_entry) {
            $psc_household_seen[mb_strtolower($psc_hh_entry['prenom'] . '|' . $psc_hh_entry['nom'] . '|' . $psc_hh_entry['telephone'])] = $psc_hh_i;
        }
        foreach ($children as $child) {
            foreach (Psc_Pickup_Persons::for_child($child->id) as $p) {
                $key = mb_strtolower($p->prenom . '|' . $p->nom . '|' . $p->telephone);
                if (isset($psc_household_seen[$key])) {
                    $psc_household_authorized[$psc_household_seen[$key]]['ids'][] = (int) $p->id;
                    continue;
                }
                $psc_household_authorized[] = array(
                    'role'      => $p->lien !== '' ? $p->lien : 'Autre',
                    'prenom'    => $p->prenom,
                    'nom'       => $p->nom,
                    'telephone' => $p->telephone,
                    'removable' => true,
                    'ids'       => array((int) $p->id),
                );
                $psc_household_seen[$key] = count($psc_household_authorized) - 1;
            }
        }

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
