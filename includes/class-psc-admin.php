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

        foreach (array(
            'Psc_Admin_Trimestres',
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
     * Tableau de bord, Calendrier en cours + Trimestres, Cantine, Demandes
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
        add_menu_page('Périscolaire', 'Périscolaire', $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'), 'dashicons-groups', 58);
        add_submenu_page('psc_dashboard', 'Tableau de bord', 'Tableau de bord', $cap, 'psc_dashboard', array(__CLASS__, 'page_dashboard'));

        // Calendrier scolaire en cours (Psc_Admin_Calendar_V2, classe
        // isolée) : enregistré ici plutôt que dans sa propre classe pour
        // contrôler sa position dans le sous-menu (juste sous le tableau
        // de bord, c'est l'écran le plus consulté au quotidien).
        add_submenu_page('psc_dashboard', 'Calendrier scolaire en cours', 'Calendrier scolaire en cours', $cap, 'psc_school_calendar_v2', array('Psc_Admin_Calendar_V2', 'page_calendar_v2'));

        add_submenu_page('psc_dashboard', 'Trimestres', 'Trimestres', $cap, 'psc_trimestres', array('Psc_Admin_Trimestres', 'page_trimestres'));

        // Cantine
        add_submenu_page('psc_dashboard', 'Menus cantine', 'Menus cantine', $cap, 'psc_menus', array('Psc_Admin_Cantine', 'page_menus'));
        add_submenu_page('psc_dashboard', 'Commande fournisseur', 'Commande fournisseur', $cap, 'psc_supplier_orders', array('Psc_Admin_Cantine', 'page_supplier_orders'));

        // Demandes & suivi
        $pending = Psc_Requests::pending_count();
        $req_label = $pending
            ? sprintf('Demandes <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $pending)
            : 'Demandes';
        add_submenu_page('psc_dashboard', "Demandes d'inscription", $req_label, $cap, 'psc_requests', array('Psc_Admin_Requests', 'page_requests'));
        add_submenu_page('psc_dashboard', 'Présences déclarées', 'Présences déclarées', $cap, 'psc_inscriptions', array('Psc_Admin_Inscriptions', 'page_inscriptions'));

        // Familles
        add_submenu_page('psc_dashboard', 'Familles', 'Familles', $cap, 'psc_parents', array('Psc_Admin_Familles', 'page_parents'));
        add_submenu_page('psc_dashboard', 'Enfants', 'Enfants', $cap, 'psc_children', array('Psc_Admin_Familles', 'page_children'));
        // Fiche "Personnes autorisées" d'un enfant — accessible uniquement
        // depuis la ligne de l'enfant dans Enfants, jamais dans le menu.
        add_submenu_page('psc_dashboard', 'Personnes autorisées', null, $cap, 'psc_pickup_persons', array('Psc_Admin_Familles', 'page_pickup_persons'));

        // Facturation
        add_submenu_page('psc_dashboard', 'Factures', 'Factures', $cap, 'psc_factures', array('Psc_Admin_Invoices', 'page_factures'));

        // Configuration
        add_submenu_page('psc_dashboard', 'Modèles e-mails', 'Modèles e-mails', $cap, 'psc_email_templates', array('Psc_Admin_Config', 'page_email_templates'));

        // Années scolaires : usage occasionnel (~1 fois par an), reléguée
        // en bas du menu plutôt qu'à côté des écrans du quotidien.
        add_submenu_page('psc_dashboard', 'Années scolaires', 'Années scolaires', $cap, 'psc_school_years', array('Psc_Admin_School_Years', 'page_school_years'));
        // Écran intermédiaire du passage d'année (récapitulatif + confirmation) :
        // pas un lien de menu à part entière, seulement atteint depuis
        // "Années scolaires" — menu_title à null pour ne pas apparaître dans
        // la barre latérale.
        add_submenu_page('psc_dashboard', 'Passage d\'année', null, $cap, 'psc_passage_annee', array('Psc_Admin_School_Years', 'page_passage_annee'));

        add_submenu_page('psc_dashboard', 'Réglages', 'Réglages', $cap, 'psc_settings', array('Psc_Admin_Config', 'page_settings'));
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
                <p><strong>Périscolaire — le dossier des documents est inutilisable.</strong></p>
                <p>
                    L'extension ne peut ni créer ni écrire dans
                    <code><?php echo esc_html($dir); ?></code>. Les justificatifs d'assurance et les
                    factures ne pourront pas être enregistrés ni téléchargés tant que ce sera le cas.
                </p>
                <p>
                    Vérifiez la ligne <code>PSC_PRIVATE_DIR</code> de <code>wp-config.php</code> :
                    le dossier parent doit exister et être accessible en écriture. Si le chemin est
                    erroné, retirez la ligne — l'extension reprendra son emplacement par défaut.
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
            <p><strong>Périscolaire — les documents des familles sont téléchargeables sans connexion.</strong></p>
            <p>
                Les justificatifs d'assurance et les factures sont accessibles publiquement sous
                <code><?php echo esc_html($base); ?></code>. Le serveur web ne tient pas compte du fichier
                <code>.htaccess</code> déposé par l'extension.
            </p>
            <p>
                <strong>Correctif, sans accès au serveur :</strong> ajoutez cette ligne dans
                <code>wp-config.php</code> (avant la ligne <code>/* C'est tout… */</code>), puis rechargez
                cette page. Les documents déjà déposés seront déplacés automatiquement.
            </p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:10px;overflow:auto;"><?php echo esc_html($suggestion); ?></pre>
            <p class="description">
                Ce dossier se place à côté de la racine du site (et non dedans), ce qui le rend
                inaccessible par le web quelle que soit la configuration de l'hébergement.
                Si votre hébergeur vous donne la main sur la configuration du serveur, une règle
                <code>deny</code> sur le dossier fonctionne également.
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
     * Indicateurs globaux, sans notion d'urgence — la liste "à faire"
     * (dashboard_todos) porte les actions concrètes.
     */
    protected static function dashboard_stats() {
        global $wpdb;
        $trimestre = Psc_Trimestres::active();
        return array(
            'trimestre'        => $trimestre,
            'familles_actives' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . psc_table('parents') . ' WHERE active = 1'),
            'enfants_actifs'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . psc_table('children') . " WHERE statut = 'actif'"),
        );
    }

    /**
     * Actions concrètes à faire dans les jours/semaines à venir, dérivées
     * de données déjà existantes (aucune nouvelle table) : demandes en
     * attente, menu et commande fournisseur de la semaine prochaine pas
     * encore envoyés, trimestre actif proche de sa fin ou absent. Chaque
     * entrée : array('label'=>, 'done'=>bool, 'url'=>).
     */
    protected static function dashboard_todos() {
        global $wpdb;
        $todos = array();

        $pending = Psc_Requests::pending_count();
        $todos[] = array(
            'label' => $pending > 0
                ? sprintf('%d demande(s) d\'inscription en attente de traitement', $pending)
                : 'Aucune demande d\'inscription en attente',
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
                'Menu de cantine — semaine du %s : %s',
                $next_week_label,
                $menu_sent ? 'envoyé' : ($menu_has_content ? 'saisi, pas encore envoyé' : 'pas encore saisi')
            ),
            'done' => (bool) $menu_sent,
            'url'  => admin_url('admin.php?page=psc_menus'),
        );

        $order_sent = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . psc_table('supplier_orders') . ' WHERE semaine_debut = %s', $next_week
        ));
        $todos[] = array(
            'label' => sprintf(
                'Commande fournisseur — semaine du %s : %s',
                $next_week_label,
                $order_sent ? 'envoyée' : 'pas encore envoyée'
            ),
            'done' => (bool) $order_sent,
            'url'  => admin_url('admin.php?page=psc_supplier_orders&semaine_debut=' . $next_week),
        );

        $trimestre = Psc_Trimestres::active();
        if (!$trimestre) {
            $todos[] = array(
                'label' => 'Aucun trimestre actif — créez-en un pour ouvrir les inscriptions',
                'done'  => false,
                'url'   => admin_url('admin.php?page=psc_trimestres'),
            );
        } else {
            $days_left = (int) floor((strtotime($trimestre->date_fin) - strtotime(current_time('Y-m-d'))) / DAY_IN_SECONDS);
            if ($days_left <= 14) {
                $todos[] = array(
                    'label' => $days_left >= 0
                        ? sprintf('Le trimestre actif se termine dans %d jour(s) — pensez à préparer le suivant', $days_left)
                        : 'Le trimestre actif est terminé — pensez à en activer un nouveau',
                    'done' => false,
                    'url'  => admin_url('admin.php?page=psc_trimestres'),
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
