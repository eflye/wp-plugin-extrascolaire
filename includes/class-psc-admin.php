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
     * Enregistre les écrans communs, puis délègue à chaque domaine le soin
     * de déclarer ses propres routes. Le point d'entrée du plugin n'a donc
     * pas à connaître le découpage interne de l'administration.
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_notices', array(__CLASS__, 'notice_private_dir_exposed'));
        add_action('admin_notices', array(__CLASS__, 'notice_db_constraints'));

        foreach (array(
            'Psc_Admin_School_Years',
            'Psc_Admin_Familles',
            'Psc_Admin_Inscriptions',
            'Psc_Admin_Cantine',
            'Psc_Admin_Invoices',
            'Psc_Admin_Config',
            'Psc_Admin_Requests',
        ) as $domain) {
            call_user_func(array($domain, 'init'));
        }
    }

    /**
     * Regroupement du menu (du plus quotidien au plus occasionnel) :
     * Tableau de bord, Calendrier en cours, Cantine, Demandes
     * & suivi, Familles, Facturation, Configuration, puis Années scolaires
     * — reléguée juste avant Réglages car utilisée seulement ~1 fois par an
     * (création/activation d'année, passage de classe), loin des écrans
     * consultés au quotidien. Le slug du menu de premier niveau est
     * 'psc_dashboard' (avant : 'psc_inscriptions', qui reste une page
     * valide — seule sa place dans l'arborescence change, aucun lien
     * existant vers admin.php?page=psc_inscriptions n'est cassé).
     * "Inscriptions" est renommé "Présences déclarées" dans le menu : le
     * libellé prêtait à confusion avec "Demandes d'inscription" juste à
     * côté, alors que ce sont deux écrans très différents (vue calendrier
     * des présences déjà déclarées, vs file de modération des nouvelles
     * familles). Les séparateurs visuels entre blocs sont en CSS
     * (assets/css/admin.css), WordPress ne proposant pas de séparateur
     * natif dans un sous-menu de plugin.
     */
    public static function menu() {
        $cap = psc_manage_cap();
        add_menu_page(__('Périscolaire', 'periscolaire-registration'), __('Périscolaire', 'periscolaire-registration'), $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-groups', 58);
        add_submenu_page('psc_dashboard', __('Tableau de bord', 'periscolaire-registration'), __('Tableau de bord', 'periscolaire-registration'), $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'));

        // Calendrier scolaire en cours (Psc_Admin_Calendar_V2, classe
        // isolée) : enregistré ici plutôt que dans sa propre classe pour
        // contrôler sa position dans le sous-menu (juste sous le tableau
        // de bord, c'est l'écran le plus consulté au quotidien).
        add_submenu_page('psc_dashboard', __('Calendrier scolaire en cours', 'periscolaire-registration'), __('Calendrier scolaire en cours', 'periscolaire-registration'), $cap, 'psc_school_calendar_v2', array('Psc_Admin_Calendar_V2', 'page_calendar_v2'));

        // Cantine
        add_submenu_page('psc_dashboard', __('Menus cantine', 'periscolaire-registration'), __('Menus cantine', 'periscolaire-registration'), $cap, 'psc_menus', array('Psc_Admin_Cantine', 'page_menus'));
        add_submenu_page('psc_dashboard', __('Commande fournisseur', 'periscolaire-registration'), __('Commande fournisseur', 'periscolaire-registration'), $cap, 'psc_supplier_orders', array('Psc_Admin_Cantine', 'page_supplier_orders'));

        // Demandes & suivi
        $pending = Psc_Requests::pending_count();
        $req_label = $pending
            ? sprintf(__('Demandes <span class="awaiting-mod"><span class="pending-count">%d</span></span>', 'periscolaire-registration'), $pending)
            : __('Demandes', 'periscolaire-registration');
        add_submenu_page('psc_dashboard', __('Demandes d\'inscription', 'periscolaire-registration'), $req_label, $cap, 'psc_requests', array('Psc_Admin_Requests', 'page_requests'));
        add_submenu_page('psc_dashboard', __('Présences déclarées', 'periscolaire-registration'), __('Présences déclarées', 'periscolaire-registration'), $cap, 'psc_inscriptions', array('Psc_Admin_Inscriptions', 'page_inscriptions'));

        // Familles
        add_submenu_page('psc_dashboard', __('Familles', 'periscolaire-registration'), __('Familles', 'periscolaire-registration'), $cap, 'psc_parents', array('Psc_Admin_Familles', 'page_parents'));
        add_submenu_page('psc_dashboard', __('Enfants', 'periscolaire-registration'), __('Enfants', 'periscolaire-registration'), $cap, 'psc_children', array('Psc_Admin_Familles', 'page_children'));
        // Fiche "Personnes autorisées" d'un enfant — accessible uniquement
        // depuis la ligne de l'enfant dans Enfants, jamais dans le menu.
        add_submenu_page('psc_dashboard', __('Personnes autorisées', 'periscolaire-registration'), null, $cap, 'psc_pickup_persons', array('Psc_Admin_Familles', 'page_pickup_persons'));

        // Facturation
        add_submenu_page('psc_dashboard', __('Factures', 'periscolaire-registration'), __('Factures', 'periscolaire-registration'), $cap, 'psc_factures', array('Psc_Admin_Invoices', 'page_factures'));

        // Configuration
        add_submenu_page('psc_dashboard', __('Modèles e-mails', 'periscolaire-registration'), __('Modèles e-mails', 'periscolaire-registration'), $cap, 'psc_email_templates', array('Psc_Admin_Config', 'page_email_templates'));

        // Années scolaires : usage occasionnel (~1 fois par an), reléguée
        // en bas du menu plutôt qu'à côté des écrans du quotidien.
        add_submenu_page('psc_dashboard', __('Années scolaires', 'periscolaire-registration'), __('Années scolaires', 'periscolaire-registration'), $cap, 'psc_school_years', array('Psc_Admin_School_Years', 'page_school_years'));
        // Écran intermédiaire du passage d'année (récapitulatif + confirmation) :
        // pas un lien de menu à part entière, seulement atteint depuis
        // "Années scolaires" — menu_title à null pour ne pas apparaître dans
        // la barre latérale.
        add_submenu_page('psc_dashboard', __('Passage d\'année', 'periscolaire-registration'), null, $cap, 'psc_passage_annee', array('Psc_Admin_School_Years', 'page_passage_annee'));

        add_submenu_page('psc_dashboard', __('Réglages', 'periscolaire-registration'), __('Réglages', 'periscolaire-registration'), $cap, 'psc_settings', array('Psc_Admin_Config', 'page_settings'));
    }

    public static function assets($hook) {
        if (strpos($hook, 'psc_') === false) return;
        wp_enqueue_style('psc-admin', PSC_URL . 'assets/css/admin.css', array(), PSC_VERSION);
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
                'url'   => admin_url('admin.php?page=psc_school_years'),
            );
        } else {
            $days_left = (int) floor((strtotime($annee->date_end) - strtotime(current_time('Y-m-d'))) / DAY_IN_SECONDS);
            if ($days_left <= 30) {
                $todos[] = array(
                    'label' => $days_left >= 0
                        ? sprintf(__('L\'année scolaire se termine dans %d jour(s) — préparez la suivante (dates, vacances, fériés)', 'periscolaire-registration'), $days_left)
                        : __('L\'année scolaire est terminée — configurez la suivante', 'periscolaire-registration'),
                    'done' => false,
                    'url'  => admin_url('admin.php?page=psc_school_years'),
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
