<?php
if (!defined('ABSPATH')) exit;

/**
 * Écrans transverses de l'administration : menu, ressources, alertes et
 * tableau de bord.
 *
 * Les domaines métier vivent dans les classes Psc_Admin_* déclarées par
 * init(). Cette classe n'en garde que ce qui ne relève d'aucun d'eux.
 */
class Psc_Admin extends Psc_Admin_Base {

    /**
     * Slugs des 6 intitulés de section (§3) — non cliquables, jamais
     * marqués courants (cf. le filtre submenu_file dans menu()) — vers la
     * première entrée réelle de chacune, seule source de vérité pour
     * section_redirect() ET pour l'interception précoce
     * redirect_section_slugs() (cf. son docblock : la redirection posée
     * comme simple $callback de la page ne suffit pas).
     */
    const SECTION_TARGETS = array(
        'psc-section-a-traiter'     => 'psc_dashboard',
        'psc-section-cantine'       => 'psc_menus',
        'psc-section-familles'      => 'psc_parents',
        'psc-section-facturation'   => 'psc_factures',
        'psc-section-communication' => 'psc_messages',
        'psc-section-configuration' => 'psc_school_calendar_v2',
    );

    /**
     * Enregistre les écrans communs, puis délègue à chaque domaine le soin
     * de déclarer ses propres routes. Le point d'entrée du plugin n'a donc
     * pas à connaître le découpage interne de l'administration.
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        // wp-admin/menu.php vérifie user_can_access_admin_page() et meurt
        // en 403 pour un slug qui n'existe plus DU TOUT dans le menu — et
        // ce, avant même que admin_init ne se déclenche (require
        // wp-admin/menu.php précède do_action('admin_init') dans
        // wp-admin/admin.php). Un ancien slug totalement supprimé
        // (psc_school_years) doit donc être intercepté ICI, pendant
        // admin_menu lui-même — jamais sur admin_init, trop tard pour ce
        // cas précis (cf. redirect_legacy_urls()).
        add_action('admin_menu', array(__CLASS__, 'redirect_legacy_urls'), 20);
        // À l'inverse, les intitulés de section restent des pages
        // valablement enregistrées : leur rediriger depuis leur propre
        // $callback (section_redirect()) échoue avec "headers already
        // sent", ce callback s'exécutant après que wp-admin a déjà
        // commencé à écrire la page (en-tête, menu). admin_init reste ici
        // le bon moment — avant tout octet de sortie pour une page qui,
        // elle, existe bel et bien (cf. redirect_section_slugs()).
        add_action('admin_init', array(__CLASS__, 'redirect_section_slugs'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_notices', array(__CLASS__, 'notice_private_dir_exposed'));
        add_action('admin_notices', array(__CLASS__, 'notice_db_constraints'));
        add_action('admin_notices', array(__CLASS__, 'notice_audit_health'));
        add_action('show_user_profile', array(__CLASS__, 'user_capabilities_fields'));
        add_action('edit_user_profile', array(__CLASS__, 'user_capabilities_fields'));
        add_action('personal_options_update', array(__CLASS__, 'save_user_capabilities'));
        add_action('edit_user_profile_update', array(__CLASS__, 'save_user_capabilities'));

        foreach (array(
            'Psc_Admin_School_Years',
            'Psc_Admin_Familles',
            'Psc_Admin_Assurances',
            'Psc_Admin_Inscriptions',
            'Psc_Admin_Cantine',
            'Psc_Admin_Invoices',
            'Psc_Admin_Config',
            'Psc_Admin_Requests',
        ) as $domain) {
            call_user_func(array($domain, 'init'));
        }
    }

    public static function user_capabilities_fields($user) {
        if (!current_user_can('edit_user', $user->ID) || !current_user_can('psc_manage_config')) return;
        $caps = psc_domain_capabilities();
        wp_nonce_field('psc_user_capabilities', 'psc_user_capabilities_nonce');
        echo '<h2>' . esc_html__('Habilitations périscolaires', 'periscolaire-registration') . '</h2><p>' . esc_html__('Une personne peut cumuler plusieurs habilitations. Les cases contrôlent les accès métier côté serveur.', 'periscolaire-registration') . '</p><table class="form-table" role="presentation"><tbody>';
        foreach ($caps as $cap => $label) {
            printf('<tr><th scope="row"><label for="psc-cap-%1$s">%2$s</label></th><td><label><input type="checkbox" id="psc-cap-%1$s" name="psc_caps[]" value="%1$s" %3$s> %4$s</label></td></tr>', esc_attr($cap), esc_html($cap), checked($user->has_cap($cap), true, false), esc_html($label));
        }
        echo '</tbody></table>';
    }

    public static function save_user_capabilities($user_id) {
        if (!current_user_can('edit_user', $user_id) || !current_user_can('psc_manage_config')) return;
        if (empty($_POST['psc_user_capabilities_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['psc_user_capabilities_nonce'])), 'psc_user_capabilities')) return;
        $user = get_user_by('id', $user_id);
        if (!$user) return;
        $allowed = array_keys(psc_domain_capabilities());
        $selected = isset($_POST['psc_caps']) && is_array($_POST['psc_caps']) ? array_map('sanitize_key', wp_unslash($_POST['psc_caps'])) : array();
        foreach ($allowed as $cap) {
            if (in_array($cap, $selected, true)) $user->add_cap($cap);
            else $user->remove_cap($cap);
        }
        Psc_Audit::log('utilisateur.habilitations_modifiees', array('objet_type' => 'utilisateur', 'objet_id' => (int) $user_id, 'meta' => array('nombre' => count(array_intersect($selected, $allowed)))));
    }

    /**
     * Réorganisation du menu (2026) : les 17 entrées à plat d'origine sont
     * regroupées en 6 sections, dans l'ordre où une mairie les consulte au
     * quotidien — ce qui attend une action humaine d'abord (À traiter,
     * seul endroit à porter des compteurs), ce qui se consulte
     * ponctuellement en dernier (Configuration). Le slug du menu de
     * premier niveau reste 'psc_dashboard' ; tous les slugs, capabilities
     * et callbacks des entrées réelles sont inchangés — seuls l'ordre, le
     * regroupement et (pour 6 d'entre elles, §4) l'intitulé affiché
     * changent. Aucun lien existant vers une de ces URL n'est cassé.
     *
     * Les intitulés de section (À TRAITER, CANTINE & GARDERIE...) sont des
     * entrées de sous-menu à part entière — WordPress n'a pas de troisième
     * niveau — rendues non cliquables par CSS (cf. .psc-menu-section dans
     * assets/css/admin.css) et dont le callback se contente de rediriger
     * vers la première entrée réelle de la section (sécurité : un accès
     * direct à l'URL ne tombe jamais sur une page blanche).
     */
    public static function menu() {
        $cap = psc_manage_cap();

        $pending     = self::cached_count('psc_menu_count_requests', array('Psc_Requests', 'pending_count'));
        $conv_unread = self::cached_count('psc_menu_count_conversations', array('Psc_Conversations', 'unread_count_for_mairie'));

        add_menu_page(
            __('Périscolaire', 'periscolaire-registration'),
            __('Périscolaire', 'periscolaire-registration') . self::count_badge($pending + $conv_unread),
            $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-groups', 58
        );

        /* ---------------- À TRAITER ---------------- */
        // Capability la plus permissive de la section : $cap (Tableau de
        // bord, Demandes) est un sur-ensemble de psc_manage_messages
        // (Échanges familles) sous la répartition de rôles par défaut
        // (Psc_Installer::sync_roles()) — un utilisateur avec
        // psc_manage_messages mais sans $cap ne verrait de toute façon
        // aucune des deux autres entrées de la section.
        //
        // "Tableau de bord" (slug psc_dashboard, identique au parent) doit
        // impérativement être le TOUT PREMIER add_submenu_page() enregistré
        // pour ce parent, section comprise : WordPress (add_submenu_page(),
        // wp-admin/includes/plugin.php) injecte sinon automatiquement un
        // lien dupliqué vers le sommet du menu comme premier élément du
        // sous-menu, dès lors que le tout premier appel enregistré porte un
        // slug différent de celui du parent. La section "À traiter" doit
        // malgré tout apparaître AU-DESSUS de "Tableau de bord" à l'écran :
        // on l'insère donc explicitement en position 0 juste après.
        add_submenu_page('psc_dashboard', __('Tableau de bord', 'periscolaire-registration'), __('Tableau de bord', 'periscolaire-registration'), $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'));
        add_submenu_page('psc_dashboard', __('À traiter', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('À traiter', 'periscolaire-registration') . '</span>', $cap, 'psc-section-a-traiter', self::section_redirect('psc_dashboard'), 0);
        add_submenu_page('psc_dashboard', __('Demandes d\'inscription', 'periscolaire-registration'), __('Demandes d\'inscription', 'periscolaire-registration') . self::count_badge($pending), $cap, 'psc_requests', array('Psc_Admin_Requests', 'page_requests'));
        add_submenu_page('psc_dashboard', __('Échanges familles', 'periscolaire-registration'), __('Échanges familles', 'periscolaire-registration') . self::count_badge($conv_unread), 'psc_manage_messages', 'psc_conversations', array('Psc_Conversations_Admin', 'page_list'));

        /* ---------------- CANTINE & GARDERIE ---------------- */
        add_submenu_page('psc_dashboard', __('Cantine & garderie', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('Cantine & garderie', 'periscolaire-registration') . '</span>', $cap, 'psc-section-cantine', self::section_redirect('psc_menus'));
        add_submenu_page('psc_dashboard', __('Menus', 'periscolaire-registration'), __('Menus', 'periscolaire-registration'), $cap, 'psc_menus', array('Psc_Admin_Cantine', 'page_menus'));
        add_submenu_page('psc_dashboard', __('Commande fournisseur', 'periscolaire-registration'), __('Commande fournisseur', 'periscolaire-registration'), $cap, 'psc_supplier_orders', array('Psc_Admin_Cantine', 'page_supplier_orders'));
        add_submenu_page('psc_dashboard', __('Présences déclarées', 'periscolaire-registration'), __('Présences déclarées', 'periscolaire-registration'), $cap, 'psc_inscriptions', array('Psc_Admin_Inscriptions', 'page_inscriptions'));

        /* ---------------- FAMILLES ---------------- */
        add_submenu_page('psc_dashboard', __('Familles', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('Familles', 'periscolaire-registration') . '</span>', $cap, 'psc-section-familles', self::section_redirect('psc_parents'));
        add_submenu_page('psc_dashboard', __('Familles', 'periscolaire-registration'), __('Familles', 'periscolaire-registration'), $cap, 'psc_parents', array('Psc_Admin_Familles', 'page_parents'));
        add_submenu_page('psc_dashboard', __('Enfants', 'periscolaire-registration'), __('Enfants', 'periscolaire-registration'), $cap, 'psc_children', array('Psc_Admin_Familles', 'page_children'));
        add_submenu_page('psc_dashboard', 'Assurances scolaires', 'Assurances scolaires', $cap, 'psc_assurances', array('Psc_Admin_Assurances', 'page'));
        // Fiche "Personnes autorisées" d'un enfant — accessible uniquement
        // depuis la ligne de l'enfant dans Enfants, jamais dans le menu.
        add_submenu_page('psc_dashboard', __('Personnes autorisées', 'periscolaire-registration'), null, $cap, 'psc_pickup_persons', array('Psc_Admin_Familles', 'page_pickup_persons'));
        // Écran de confirmation d'une consultation : accessible depuis les
        // fiches famille, jamais comme destination autonome du menu.
        add_submenu_page('psc_dashboard', __('Consulter un espace famille', 'periscolaire-registration'), null, 'psc_impersonate_family', 'psc_impersonate', array('Psc_Admin_Familles', 'page_impersonate'));

        /* ---------------- FACTURATION ---------------- */
        add_submenu_page('psc_dashboard', __('Facturation', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('Facturation', 'periscolaire-registration') . '</span>', $cap, 'psc-section-facturation', self::section_redirect('psc_factures'));
        add_submenu_page('psc_dashboard', __('Factures', 'periscolaire-registration'), __('Factures', 'periscolaire-registration'), $cap, 'psc_factures', array('Psc_Admin_Invoices', 'page_factures'));
        add_submenu_page('psc_dashboard', __('État des comptes', 'periscolaire-registration'), __('État des comptes', 'periscolaire-registration'), $cap, 'psc_comptes_familles', array('Psc_Admin_Invoices', 'page_comptes_familles'));

        /* ---------------- COMMUNICATION ---------------- */
        // Une seule entrée réelle aujourd'hui : la capability de la section
        // est directement celle de cette entrée (psc_manage_messages).
        add_submenu_page('psc_dashboard', __('Communication', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('Communication', 'periscolaire-registration') . '</span>', 'psc_manage_messages', 'psc-section-communication', self::section_redirect('psc_messages'));
        // Communication descendante. Les écrans d'édition et de suivi, sans
        // libellé de menu, restent enregistrés par Psc_Messages_Admin.
        add_submenu_page('psc_dashboard', __('Messages aux familles', 'periscolaire-registration'), __('Messages aux familles', 'periscolaire-registration'), 'psc_manage_messages', 'psc_messages', array('Psc_Messages_Admin', 'page_list'));

        /* ---------------- CONFIGURATION ---------------- */
        // Capability la plus permissive : $cap, sur-ensemble de
        // psc_view_audit (Journal d'audit) sous la répartition par défaut
        // (administrateur a toujours $cap ET psc_view_audit).
        add_submenu_page('psc_dashboard', __('Configuration', 'periscolaire-registration'), '<span class="psc-menu-section">' . esc_html__('Configuration', 'periscolaire-registration') . '</span>', $cap, 'psc-section-configuration', self::section_redirect('psc_school_calendar_v2'));
        // « Année scolaire » réunit désormais Calendrier scolaire en cours
        // et Années scolaires sous une barre d'onglets (§5) — slug
        // conservé de l'ancien "Calendrier scolaire en cours".
        add_submenu_page('psc_dashboard', __('Année scolaire', 'periscolaire-registration'), __('Année scolaire', 'periscolaire-registration'), $cap, 'psc_school_calendar_v2', array('Psc_Admin_Calendar_V2', 'page_calendar_v2'));
        // Écran intermédiaire du passage d'année (récapitulatif + confirmation) :
        // pas un lien de menu à part entière, seulement atteint depuis
        // "Année scolaire" — menu_title à null pour ne pas apparaître dans
        // la barre latérale.
        add_submenu_page('psc_dashboard', __('Passage d\'année', 'periscolaire-registration'), null, $cap, 'psc_passage_annee', array('Psc_Admin_School_Years', 'page_passage_annee'));
        add_submenu_page('psc_dashboard', __('Modèles d\'e-mails', 'periscolaire-registration'), __('Modèles d\'e-mails', 'periscolaire-registration'), $cap, 'psc_email_templates', array('Psc_Admin_Config', 'page_email_templates'));
        add_submenu_page('psc_dashboard', __('Réglages', 'periscolaire-registration'), __('Réglages', 'periscolaire-registration'), $cap, 'psc_settings', array('Psc_Admin_Config', 'page_settings'));
        // Journal d'audit : capacité dédiée, plus restreinte que $cap (cf.
        // Psc_Installer::sync_roles()) — le journal agrège l'activité de
        // toutes les familles et de tous les agents, pas seulement le
        // périmètre courant d'un éditeur.
        add_submenu_page('psc_dashboard', __('Journal d\'audit', 'periscolaire-registration'), __('Journal d\'audit', 'periscolaire-registration'), 'psc_view_audit', 'psc_audit', array('Psc_Admin_Audit', 'page_list'));

        // Les intitulés de section redirigent avant tout rendu (cf.
        // section_redirect()) : dans l'usage normal, WordPress ne les
        // marque donc jamais "courants" (aucune page ne reste affichée le
        // temps qu'ils soient sélectionnés). Filet de sécurité explicite
        // demandé par la réorganisation malgré tout, au cas où un contexte
        // d'affichage inhabituel (aperçu, cache) contournerait la
        // redirection.
        add_filter('submenu_file', array(__CLASS__, 'never_highlight_sections'));
    }

    public static function never_highlight_sections($submenu_file) {
        return isset(self::SECTION_TARGETS[$submenu_file]) ? '' : $submenu_file;
    }

    /**
     * Interception précoce (admin_init, avant tout octet de sortie) des
     * intitulés de section : leur propre $callback (section_redirect())
     * arrive trop tard dans le cycle de wp-admin pour pouvoir rediriger
     * (l'en-tête d'administration est déjà envoyé) — cf. le commentaire
     * dans init(). C'est donc ici, et non dans le callback de la page, que
     * la redirection a réellement lieu en pratique.
     */
    public static function redirect_section_slugs() {
        if (!isset($_GET['page'])) return;
        $page = sanitize_key(wp_unslash($_GET['page']));
        if (!isset(self::SECTION_TARGETS[$page])) return;

        wp_safe_redirect(admin_url('admin.php?page=' . self::SECTION_TARGETS[$page]));
        exit;
    }

    /**
     * Callback d'un intitulé de section : redirige immédiatement vers la
     * première entrée réelle de la section, pour qu'un accès direct à
     * l'URL (favori, lien copié) n'affiche jamais une page blanche.
     */
    protected static function section_redirect($target_page) {
        return function () use ($target_page) {
            wp_safe_redirect(admin_url('admin.php?page=' . $target_page));
            exit;
        };
    }

    /**
     * Compteur mis en cache 60 secondes (réorganisation du menu, §7) :
     * Psc_Requests::pending_count() et Psc_Conversations::unread_count_for_mairie()
     * sont chacun une requête SQL triviale (COUNT() simple), la mise en
     * cache est donc un confort plutôt qu'une nécessité de performance —
     * elle évite surtout de recalculer à chaque affichage de sous-menu
     * (rendu sur CHAQUE écran d'administration, pas seulement les écrans
     * du plugin).
     */
    protected static function cached_count($transient_key, $callback) {
        $count = get_transient($transient_key);
        if ($count === false) {
            $count = (int) call_user_func($callback);
            set_transient($transient_key, $count, 60);
        }
        return (int) $count;
    }

    /**
     * Bulle de comptage WordPress standard, ajoutée à un $menu_title —
     * jamais de style maison. Rien n'est affiché si $count est à zéro.
     */
    protected static function count_badge($count) {
        if ($count <= 0) return '';
        return sprintf(' <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $count);
    }

    /**
     * Compatibilité des anciennes URL (réorganisation du menu, §8) :
     * "Années scolaires" n'est plus une page autonome, fusionnée dans
     * "Année scolaire" (cf. §5). Aucun ancien slug de réglages fournisseur
     * n'a jamais existé en tant que page à part entière — ces réglages
     * vivaient comme simple section de la page Réglages (psc_settings),
     * jamais derrière leur propre ?page=, donc rien à rediriger pour eux.
     */
    public static function redirect_legacy_urls() {
        if (!isset($_GET['page'])) return;
        if (sanitize_key(wp_unslash($_GET['page'])) !== 'psc_school_years') return;

        wp_safe_redirect(add_query_arg(
            array('page' => 'psc_school_calendar_v2', 'tab' => 'historique'),
            admin_url('admin.php')
        ), 302);
        exit;
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_') === false) return;
        if (strpos($hook, 'psc_parents') !== false || strpos($hook, 'psc_settings') !== false) psc_banking_assets();
        wp_enqueue_style('psc-admin', PSC_URL . 'assets/css/admin.css', array(), PSC_VERSION);
        if (strpos($hook, 'psc_menus') !== false) {
            wp_enqueue_script('psc-menu-preview', PSC_URL . 'assets/js/menu-preview.js', array(), PSC_VERSION, true);
            wp_localize_script('psc-menu-preview', 'PSC_MENU', array('rules' => Psc_Menus::label_rules(), 'labels' => Psc_Menus::quality_labels(),
                'summary' => __('%n plats saisis · %b bio · %r Label Rouge (%p % labellisés)', 'periscolaire-registration')));
        }
        if (strpos($hook, 'psc_settings') !== false) {
            wp_enqueue_media();
        }
    }

    /**
     * Alerte si les documents des familles sont téléchargeables sans
     * authentification.
     *
     * Les fichiers .htaccess/web.config posés par psc_ensure_private_dir()
     * ne protègent que sous Apache et IIS ; nginx les ignore. Plutôt que de
     * supposer que la protection tient, on la vérifie réellement (une requête
     * HTTP sur un fichier témoin, mise en cache) et on le dit clairement à
     * l'administrateur si ce n'est pas le cas — il n'a alors qu'une règle
     * serveur à poser.
     */
    public static function notice_private_dir_exposed() {
        if (!psc_user_can_manage()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_psc = $screen && strpos((string) $screen->id, 'psc_') !== false;
        if (!$on_psc && !($screen && $screen->id === 'dashboard')) return;

        // Un chemin déclaré mais inutilisable (droits refusés par
        // l'hébergeur, dossier parent inexistant) rendrait tous les
        // justificatifs et factures introuvables, sans le moindre message :
        // le plugin écrirait et lirait dans un dossier qui n'existe pas.
        // C'est le premier écueil d'une configuration manuelle, il vaut
        // mieux le nommer que laisser chercher.
        $dir = psc_private_dir();
        if (!is_dir($dir) || !wp_is_writable($dir)) {
            ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e('Périscolaire — le dossier des documents est inutilisable.', 'periscolaire-registration'); ?></strong></p>
                <p>
                    <?php esc_html_e("L'extension ne peut ni créer ni écrire dans", 'periscolaire-registration'); ?>
                    <code><?php echo esc_html($dir); ?></code>. <?php esc_html_e("Les justificatifs d'assurance et les factures ne pourront pas être enregistrés ni téléchargés tant que ce sera le cas.", 'periscolaire-registration'); ?>
                </p>
                <p>
                    <?php esc_html_e('Vérifiez la ligne', 'periscolaire-registration'); ?> <code>PSC_PRIVATE_DIR</code> <?php esc_html_e('de', 'periscolaire-registration'); ?> <code>wp-config.php</code> :
                    <?php esc_html_e("le dossier parent doit exister et être accessible en écriture. Si le chemin est erroné, retirez la ligne — l'extension reprendra son emplacement par défaut.", 'periscolaire-registration'); ?>
                </p>
            </div>
            <?php
            return;
        }

        $base = psc_private_dir_url();
        if ($base === null) return; // dossier hors racine web : rien à vérifier

        $probe = trailingslashit($base) . 'psc-probe.txt';
        // Correctif privilégié : déplacer le dossier hors de la racine web
        // depuis wp-config.php. C'est le seul levier disponible en
        // hébergement mutualisé, où la configuration du serveur est hors de
        // portée — et il est plus sûr qu'une règle serveur, puisqu'il ne
        // dépend d'aucun réglage d'Apache ou de nginx.
        $suggestion = "define('PSC_PRIVATE_DIR', dirname(ABSPATH) . '/psc-private');";
        ?>
        <div class="notice notice-error" id="psc-private-exposed" hidden>
            <p><strong><?php esc_html_e('Périscolaire — les documents des familles sont téléchargeables sans connexion.', 'periscolaire-registration'); ?></strong></p>
            <p>
                <?php esc_html_e("Les justificatifs d'assurance et les factures sont accessibles publiquement sous", 'periscolaire-registration'); ?>
                <code><?php echo esc_html($base); ?></code>. <?php esc_html_e('Le serveur web ne tient pas compte du fichier', 'periscolaire-registration'); ?>
                <code>.htaccess</code> <?php esc_html_e("déposé par l'extension.", 'periscolaire-registration'); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Correctif, sans accès au serveur :', 'periscolaire-registration'); ?></strong> <?php esc_html_e('ajoutez cette ligne dans', 'periscolaire-registration'); ?>
                <code>wp-config.php</code> <?php esc_html_e('(avant la ligne', 'periscolaire-registration'); ?> <code>/* C'est tout… */</code><?php esc_html_e('), puis rechargez cette page. Les documents déjà déposés seront déplacés automatiquement.', 'periscolaire-registration'); ?>
            </p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;overflow:auto;"><?php echo esc_html($suggestion); ?></pre>
            <p class="description">
                <?php esc_html_e("Ce dossier se place à côté de la racine du site (et non dedans), ce qui le rend inaccessible par le web quelle que soit la configuration de l'hébergement.", 'periscolaire-registration'); ?>
                <?php esc_html_e('Si votre hébergeur vous donne la main sur la configuration du serveur, une règle', 'periscolaire-registration'); ?>
                <code>deny</code> <?php esc_html_e('sur le dossier fonctionne également.', 'periscolaire-registration'); ?>
            </p>
        </div>
        <script>
        /* La vérification se fait depuis le navigateur, et non depuis le serveur :
           c'est le seul point de vue qui reflète ce qu'un visiteur peut réellement
           atteindre (le serveur, lui, n'arrive pas toujours à se joindre lui-même). */
        (function () {
            fetch(<?php echo wp_json_encode($probe); ?>, { cache: 'no-store', credentials: 'omit' })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (body) {
                    if (body && body.indexOf('psc-probe-') === 0) {
                        var el = document.getElementById('psc-private-exposed');
                        if (el) el.hidden = false;
                    }
                })
                .catch(function () { /* injoignable = protégé */ });
        })();
        </script>
        <?php
    }

    /**
     * Alerte si une contrainte de base (clé étrangère, CHECK) n'a pas pu
     * être posée — ALTER TABLE refusé par l'hébergeur, donnée hors liste.
     *
     * Ces échecs étaient jusqu'ici silencieux : psc_db_version avançait,
     * et la contrainte n'était retentée qu'à la prochaine montée de
     * version. Psc_Installer::store_constraints_state() publie désormais
     * l'état dans l'option psc_constraints_missing et retente à chaque
     * écran admin ; cet avis ferme la boucle côté mairie, qui voit enfin
     * pourquoi sa base ne garantit pas la cohérence qu'elle croit avoir.
     */
    public static function notice_db_constraints() {
        if (!psc_user_can_manage()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_psc = $screen && strpos((string) $screen->id, 'psc_') !== false;
        if (!$on_psc && !($screen && $screen->id === 'dashboard')) return;

        $missing = get_option('psc_constraints_missing');
        if (!is_array($missing) || !$missing) return;
        ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Périscolaire — contraintes de base de données non posées.', 'periscolaire-registration'); ?></strong></p>
            <p>
                <?php esc_html_e('Le site fonctionne, mais l\'hébergement a refusé une modification de schéma ou des données empêchent la pose des contraintes ci-dessous. La base ne garantit donc pas elle-même la cohérence des données — la validation applicative reste la première barrière.', 'periscolaire-registration'); ?>
            </p>
            <ul>
                <?php foreach ($missing as $constraint) : ?>
                <li><?php echo esc_html(self::constraint_label($constraint)); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <?php esc_html_e('Une nouvelle tentative est faite à chaque ouverture du backoffice : cet avertissement disparaîtra seul dès que la contrainte sera posée. S\'il persiste, la cause est côté hébergement (dépassement de quota, moteur de table non transactionnel) ou une donnée à corriger dans la liste ci-dessus.', 'periscolaire-registration'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Une défaillance silencieuse du journal d'audit est le pire des cas :
     * elle doit se voir. Deux signaux distincts, cf. Psc_Audit::log() et
     * Psc_Audit::capture_generic() : des insertions qui échouent
     * (psc_audit_failures) et des actions absentes du registre
     * (psc_audit_unknown_actions, journalisées sous 'inconnu.action').
     */
    public static function notice_audit_health() {
        if (!current_user_can('psc_view_audit')) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_psc = $screen && strpos((string) $screen->id, 'psc_') !== false;
        if (!$on_psc && !($screen && $screen->id === 'dashboard')) return;

        $failures = (int) get_option('psc_audit_failures', 0);
        $unknown = get_option('psc_audit_unknown_actions', array());
        if (!is_array($unknown)) $unknown = array();
        if ($failures === 0 && !$unknown) return;
        ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Périscolaire — le journal d’audit signale un problème.', 'periscolaire-registration'); ?></strong></p>
            <?php if ($failures > 0): ?>
            <p><?php echo esc_html(sprintf(_n('%d écriture du journal a échoué (repli sur le fichier journal-acces.log).', '%d écritures du journal ont échoué (repli sur le fichier journal-acces.log).', $failures, 'periscolaire-registration'), $failures)); ?></p>
            <?php endif; ?>
            <?php if ($unknown): ?>
            <p><?php esc_html_e('Actions non classées dans le registre d’audit (journalisées sous « inconnu.action ») :', 'periscolaire-registration'); ?> <code><?php echo esc_html(implode(', ', $unknown)); ?></code></p>
            <?php endif; ?>
            <p>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_audit'), admin_url('admin.php'))); ?>"><?php esc_html_e('Voir le journal filtré', 'periscolaire-registration'); ?></a>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                    <?php wp_nonce_field('psc_audit_reset_failures'); ?>
                    <input type="hidden" name="action" value="psc_audit_reset_failures">
                    <button type="submit" class="button"><?php esc_html_e('Remettre le compteur à zéro', 'periscolaire-registration'); ?></button>
                </form>
            </p>
        </div>
        <?php
    }

    /**
     * Libellé lisible d'une contrainte non posée, pour l'alerte ci-dessus.
     * Les entrées viennent de Psc_Installer (clé 'type' = 'fk' | 'check').
     */
    private static function constraint_label($constraint) {
        $type = isset($constraint['type']) ? $constraint['type'] : '';

        if ($type === 'fk') {
            $action = isset($constraint['action']) && $constraint['action'] === 'SET NULL'
                ? __('mise à null à la suppression', 'periscolaire-registration')
                : __('suppression en cascade', 'periscolaire-registration');
            return sprintf(
                __('Clé étrangère %1$s.%2$s → %3$s (%4$s)', 'periscolaire-registration'),
                isset($constraint['table']) ? $constraint['table'] : '?',
                isset($constraint['column']) ? $constraint['column'] : '?',
                isset($constraint['ref']) ? $constraint['ref'] : '?',
                $action
            );
        }

        if ($type === 'check' && isset($constraint['reason']) && $constraint['reason'] === 'dirty') {
            return __('Contrainte CHECK sur registrations.service : des lignes portent une prestation inconnue de la liste actuelle, à corriger avant la pose.', 'periscolaire-registration');
        }

        return __('Contrainte CHECK sur registrations.service (codes de prestation autorisés).', 'periscolaire-registration');
    }

    /**
     * Indicateurs globaux, sans notion d'urgence — la liste "à faire"
     * (dashboard_todos) porte les actions concrètes.
     */
    protected static function dashboard_stats() {
        global $wpdb;
        $annee = Psc_School_Year::active();
        return array(
            'annee'            => $annee,
            'familles_actives' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . psc_table('parents') . ' WHERE active = 1'),
            'enfants_actifs'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . psc_table('children') . " WHERE statut = 'actif'"),
        );
    }

    /**
     * Actions concrètes à faire dans les jours/semaines à venir, dérivées
     * de données déjà existantes (aucune nouvelle table) : demandes en
     * attente, menu et commande fournisseur de la semaine prochaine pas
     * encore envoyés, année scolaire proche de sa fin ou absente. Chaque
     * entrée : array('label'=>, 'done'=>bool, 'url'=>).
     */
    protected static function dashboard_todos() {
        global $wpdb;
        $todos = array();

        $pending = Psc_Requests::pending_count();
        $todos[] = array(
            'label' => $pending > 0
                ? sprintf(__('%d demande(s) d\'inscription en attente de traitement', 'periscolaire-registration'), $pending)
                : __('Aucune demande d\'inscription en attente', 'periscolaire-registration'),
            'done'  => $pending === 0,
            'url'   => admin_url('admin.php?page=psc_requests'),
        );

        // Échanges familles : contrairement aux autres lignes, affichée
        // seulement s'il y a effectivement quelque chose à traiter — pas de
        // ligne "aucun échange non lu" à demeure.
        $conv_unread = Psc_Conversations::unread_count_for_mairie();
        if ($conv_unread > 0) {
            $todos[] = array(
                'label' => sprintf(_n('%d échange non lu', '%d échanges non lus', $conv_unread, 'periscolaire-registration'), $conv_unread),
                'done'  => false,
                'url'   => admin_url('admin.php?page=psc_conversations&filtre=non_lues'),
            );
        }

        // Semaine prochaine, ramenée à la prochaine semaine ayant au moins un
        // jour d'école ouvert : inutile de rappeler à l'admin de saisir un
        // menu ou une commande fournisseur pour une semaine de vacances.
        $next_week = psc_next_open_week(gmdate('Y-m-d', strtotime('+7 days')));
        $next_week_label = date_i18n('d/m', strtotime($next_week));

        $menu = Psc_Menus::get_by_week($next_week);
        $menu_has_content = false;
        if ($menu) {
            foreach (Psc_Menus::JOURS as $jour) {
                if (trim((string) $menu->$jour) !== '') { $menu_has_content = true; break; }
            }
        }
        $menu_sent = $menu && $menu->sent_at;
        $todos[] = array(
            'label' => sprintf(
                __('Menu de cantine — semaine du %s : %s', 'periscolaire-registration'),
                $next_week_label,
                $menu_sent ? __('envoyé', 'periscolaire-registration') : ($menu_has_content ? __('saisi, pas encore envoyé', 'periscolaire-registration') : __('pas encore saisi', 'periscolaire-registration'))
            ),
            'done' => (bool) $menu_sent,
            'url'  => admin_url('admin.php?page=psc_menus'),
        );

        $order_sent = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . psc_table('supplier_orders') . ' WHERE semaine_debut = %s', $next_week
        ));
        $todos[] = array(
            'label' => sprintf(
                __('Commande fournisseur — semaine du %s : %s', 'periscolaire-registration'),
                $next_week_label,
                $order_sent ? __('envoyée', 'periscolaire-registration') : __('pas encore envoyée', 'periscolaire-registration')
            ),
            'done' => (bool) $order_sent,
            'url'  => admin_url('admin.php?page=psc_supplier_orders&semaine_debut=' . $next_week),
        );

        $annee = Psc_School_Year::active();
        if (!$annee) {
            $todos[] = array(
                'label' => __('Aucune année scolaire configurée — définissez dates, vacances et fériés pour ouvrir le planning', 'periscolaire-registration'),
                'done'  => false,
                'url'   => admin_url('admin.php?page=psc_school_calendar_v2&tab=historique'),
            );
        } else {
            $days_left = (int) floor((strtotime($annee->date_end) - strtotime(current_time('Y-m-d'))) / DAY_IN_SECONDS);
            if ($days_left <= 30) {
                $todos[] = array(
                    'label' => $days_left >= 0
                        ? sprintf(__('L\'année scolaire se termine dans %d jour(s) — préparez la suivante (dates, vacances, fériés)', 'periscolaire-registration'), $days_left)
                        : __('L\'année scolaire est terminée — configurez la suivante', 'periscolaire-registration'),
                    'done' => false,
                    'url'  => admin_url('admin.php?page=psc_school_calendar_v2&tab=historique'),
                );
            }
        }

        return $todos;
    }

    public static function page_dashboard() {
        if (!psc_user_can_manage()) wp_die(esc_html__('Accès refusé.', 'periscolaire-registration'), '', array('response' => 403));
        $stats = self::dashboard_stats();
        $todos = self::dashboard_todos();
        include PSC_PATH . 'templates/admin-dashboard.php';
    }
}
