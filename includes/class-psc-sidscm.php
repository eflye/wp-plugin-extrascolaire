<?php
if (!defined('ABSPATH')) exit;

/**
 * "Listes intervenantes SIDSCM" — page publique dédiée (aucun compte
 * WordPress) pour les intervenants sur le terrain (garderie/cantine) :
 * qui est attendu aujourd'hui/cette semaine, par service, avec pointage
 * de présence réelle. Protégée par un code d'accès unique configuré en
 * Registre serveur d'identités individuelles (psc_sidscm_intervenants) —
 * pas d'authentification WordPress, mais un code propre à chaque personne,
 * révocable et expirant. Un jeton opaque éphémère est conservé uniquement
 * en mémoire JavaScript puis revérifié côté serveur à chaque appel AJAX.
 *
 * Les enfants attendus viennent des inscriptions réelles déjà
 * enregistrées (wp_psc_registrations) — aucune nouvelle saisie de
 * planning ici. Le pointage de présence est persisté dans une table
 * dédiée (wp_psc_attendance), horodaté, à trois états (schéma 4.22.0) :
 * à pointer (aucune ligne ou present NULL), présent (1), absent (0). Un
 * enfant n'est plus présent par défaut : l'intervenant pointe chacun, ou
 * marque d'un geste « tous présents » ceux qui restent à pointer. Sur
 * l'onglet Garderie matin porte l'heure d'arrivée réelle (arrival_time),
 * et l'onglet Garderie soir l'heure de départ (departure_time) — une heure
 * n'est enregistrée que pour un enfant pointé présent.
 */
class Psc_Sidscm {

    const SESSION_TTL = 28800;
    /** Longueur minimale d'un code individuel créé ou modifié (SEC-03). */
    const MIN_CODE_LENGTH = 8;
    /** Codes erronés tolérés par heure pour un même intervenant (SEC-03). */
    const BAD_CODES_PER_ID = 10;
    private static $request_actor = null;
    /** @var string[] Noms des intervenants dont le nouveau code a été refusé au dernier enregistrement. */
    private static $rejected_codes = array();


    public static function init() {
        add_shortcode('periscolaire_sidscm', array(__CLASS__, 'shortcode'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'assets'));

        // Même mécanique que Psc_Frontend::hide_page_chrome_when_connected() /
        // add_portal_body_class() pour le portail famille : cet écran est
        // pensé plein écran ("outil de terrain"), le titre/gabarit de page
        // du thème n'a pas sa place ici, ni au tout premier écran de code.
        add_filter('the_content', array(__CLASS__, 'hide_page_chrome'), 20);
        add_filter('body_class', array(__CLASS__, 'add_body_class'));
        // En-têtes privés émis avant tout rendu (PRA-09) : depuis le
        // shortcode, ils partaient trop tard sur un WordPress sans tampon
        // de gabarit.
        add_action('template_redirect', array(__CLASS__, 'private_headers'));

        add_action('wp_ajax_nopriv_psc_sidscm_unlock', array(__CLASS__, 'ajax_unlock'));
        add_action('wp_ajax_psc_sidscm_unlock', array(__CLASS__, 'ajax_unlock'));
        add_action('wp_ajax_nopriv_psc_sidscm_data', array(__CLASS__, 'ajax_data'));
        add_action('wp_ajax_psc_sidscm_data', array(__CLASS__, 'ajax_data'));
        add_action('wp_ajax_nopriv_psc_sidscm_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_psc_sidscm_toggle', array(__CLASS__, 'ajax_toggle'));
        add_action('wp_ajax_nopriv_psc_sidscm_all_present', array(__CLASS__, 'ajax_all_present'));
        add_action('wp_ajax_psc_sidscm_all_present', array(__CLASS__, 'ajax_all_present'));
        add_action('wp_ajax_nopriv_psc_sidscm_arrival', array(__CLASS__, 'ajax_set_arrival'));
        add_action('wp_ajax_psc_sidscm_arrival', array(__CLASS__, 'ajax_set_arrival'));
        add_action('wp_ajax_nopriv_psc_sidscm_departure', array(__CLASS__, 'ajax_set_departure'));
        add_action('wp_ajax_psc_sidscm_departure', array(__CLASS__, 'ajax_set_departure'));
        add_action('wp_ajax_nopriv_psc_sidscm_contacts', array(__CLASS__, 'ajax_contacts'));
        add_action('wp_ajax_psc_sidscm_contacts', array(__CLASS__, 'ajax_contacts'));
    }

    /**
     * ID de la page "Accès intervenants" — d'abord la page choisie en
     * Réglages (psc_sidscm_page_id), sinon la première page publiée
     * portant le shortcode [periscolaire_sidscm] (même helper que
     * Psc_Mailer::form_page_url() pour la page famille). 0 si rien n'est
     * configuré ni détectable.
     */
    public static function page_id() {
        // Recherche commune avec la page du portail (PRA-12) : option des
        // Réglages, sinon détection mise en cache et invalidée à chaque
        // enregistrement de page.
        return psc_shortcode_page_id('periscolaire_sidscm', 'psc_sidscm_page_id');
    }

    /** URL de la page "Accès intervenants", ou l'accueil du site si introuvable. */
    public static function page_url() {
        $id = self::page_id();
        return $id ? get_permalink($id) : home_url('/');
    }

    protected static function page_has_shortcode() {
        if (!is_singular()) return false;
        $post = get_post();
        return $post && has_shortcode($post->post_content, 'periscolaire_sidscm');
    }

    /** template_redirect : en-têtes privés sur la page de l'écran intervenants. */
    public static function private_headers() {
        if (self::page_has_shortcode()) {
            Psc_Frontend::send_private_headers();
        }
    }

    public static function hide_page_chrome($content) {
        if (!self::page_has_shortcode()) return $content;
        return self::shortcode(array());
    }

    public static function add_body_class($classes) {
        if (self::page_has_shortcode()) {
            $classes[] = 'psc-sidscm-active';
        }
        return $classes;
    }

    public static function shortcode($atts) {
        // Listes nominatives + allergies + coordonnées de tiers : mêmes
        // en-têtes anti-intermédiaires que le portail famille (cf.
        // Psc_Frontend::shortcode()), et le code d'accès passe dans le
        // rendu initial. Émis normalement sur template_redirect
        // (private_headers()) ; ici, filet pour un shortcode placé hors
        // d'une page singulière (sans effet si les en-têtes sont partis).
        Psc_Frontend::send_private_headers();

        ob_start();
        include PSC_PATH . 'templates/sidscm.php';
        return ob_get_clean();
    }

    public static function assets() {
        if (!is_singular()) return;
        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'periscolaire_sidscm')) return;

        // Dépend de psc-portal uniquement pour réutiliser sa @font-face
        // auto-hébergée (Public Sans) sans la dupliquer — jamais de requête
        // vers Google Fonts.
        wp_enqueue_style('psc-portal', PSC_URL . 'assets/css/portal.css', array(), PSC_VERSION);
        wp_enqueue_style('psc-sidscm', PSC_URL . 'assets/css/sidscm.css', array('psc-portal'), PSC_VERSION);
        // Mécanique AJAX commune (assets/js/psc-ajax.js) : déclarée en
        // dépendance pour que WordPress garantisse l'ordre de chargement.
        wp_enqueue_script('psc-ajax', PSC_URL . 'assets/js/psc-ajax.js', array(), PSC_VERSION, true);
        wp_enqueue_script('psc-sidscm', PSC_URL . 'assets/js/sidscm.js', array('psc-ajax'), PSC_VERSION, true);
        wp_localize_script('psc-sidscm', 'PSC_SIDSCM', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('psc_sidscm_front'),
            // Transmise plutôt que redéclarée côté navigateur : la liste des
            // prestations élémentaires n'a qu'une seule source (psc_unit_services()).
            'services' => psc_unit_services(),
            'intervenants' => self::public_intervenants(),
            // Libellés affichés par sidscm.js (vues Jour et Semaine) :
            // traduits côté serveur, codes et attributs techniques restent bruts.
            'i18n'     => array(
                'empty_day'     => __('Aucun enfant attendu ce jour pour ce service.', 'periscolaire-registration'),
                // Pluriel choisi côté navigateur (le nombre n'est connu
                // qu'au pointage) : les deux formes de _n() sont transmises,
                // calculées pour 1 et pour 2.
                /* translators: %s: nombre d'enfants pointés présents */
                'count_present'      => _n('%s présent', '%s présents', 2, 'periscolaire-registration'),
                /* translators: %s: nombre d'enfants pointés présents */
                'count_present_one'  => _n('%s présent', '%s présents', 1, 'periscolaire-registration'),
                /* translators: %s: nombre d'enfants pointés absents */
                'count_absent'       => _n('%s absent', '%s absents', 2, 'periscolaire-registration'),
                /* translators: %s: nombre d'enfants pointés absents */
                'count_absent_one'   => _n('%s absent', '%s absents', 1, 'periscolaire-registration'),
                /* translators: %s: nombre d'enfants restant à pointer */
                'count_todo'         => _n('%s à pointer', '%s à pointer', 2, 'periscolaire-registration'),
                /* translators: %s: nombre d'enfants restant à pointer */
                'count_todo_one'     => _n('%s à pointer', '%s à pointer', 1, 'periscolaire-registration'),
                /* translators: 1: libellé du bouton Présent, 2: libellé du bouton Absent */
                'day_help'      => __('Pour chaque enfant, touchez %1$s ou %2$s.', 'periscolaire-registration'),
                'present'       => __('Présent', 'periscolaire-registration'),
                'absent'        => __('Absent', 'periscolaire-registration'),
                /* translators: %s: prénom et nom de l'enfant */
                'presence_of'   => __('Présence de %s', 'periscolaire-registration'),
                'all_present'   => __('Tous présents', 'periscolaire-registration'),
                'service_done'  => __('pointage terminé', 'periscolaire-registration'),
                /* translators: 1: nombre de présents (« 2 présents »), 2: nombre d'absents (« 0 absent ») */
                'day_done'      => __('Pointage terminé : %1$s, %2$s.', 'periscolaire-registration'),
                /* translators: %s: prénom et nom de l'enfant */
                'save_error'    => __('Enregistrement impossible : le pointage de %s n’a pas été modifié. Vérifiez la connexion et réessayez.', 'periscolaire-registration'),
                /* translators: %s: prénom et nom de l'enfant */
                'time_error'    => __('Enregistrement impossible : l’heure de %s n’a pas été modifiée. Vérifiez la connexion et réessayez.', 'periscolaire-registration'),
                'all_error'     => __('Enregistrement impossible : la liste n’a pas été modifiée. Vérifiez la connexion et réessayez.', 'periscolaire-registration'),
                'authorised'    => __('Autorisés', 'periscolaire-registration'),
                'arrival'       => __('Arrivée', 'periscolaire-registration'),
                'departure'     => __('Départ', 'periscolaire-registration'),
                'no_authorised' => __('Aucune personne autorisée renseignée.', 'periscolaire-registration'),
                'authorised_current_week' => __('Personnes autorisées consultables uniquement pour la semaine en cours.', 'periscolaire-registration'),
                'choose_week' => __('Semaine à consulter', 'periscolaire-registration'),
                'load_error' => __('Chargement impossible. Réessayez.', 'periscolaire-registration'),
                'week'          => __('semaine', 'periscolaire-registration'),
                'child'         => __('Enfant', 'periscolaire-registration'),
                'no_meal'       => __('Midi sans repas — à ne pas compter dans les couverts', 'periscolaire-registration'),
                'emergency'          => __('Contact d’urgence', 'periscolaire-registration'),
                /* translators: %s: prénom et nom de l'enfant */
                'emergency_for'      => __('Contact d’urgence des parents de %s', 'periscolaire-registration'),
                'emergency_loading'  => __('Chargement des numéros…', 'periscolaire-registration'),
                'emergency_none'     => __('Aucun numéro renseigné : contactez la mairie.', 'periscolaire-registration'),
                'emergency_no_phone' => __('aucun numéro', 'periscolaire-registration'),
                /* translators: 1: libellé du numéro (Mobile, Fixe…), 2: prénom et nom du parent */
                'emergency_call'     => __('Appeler %2$s (%1$s)', 'periscolaire-registration'),
            ),
        ));
    }

    /* ---------------- Identités et code d'accès ---------------- */

    public static function intervenants() {
        $rows = get_option('psc_sidscm_intervenants', array());
        return is_array($rows) ? array_values(array_filter($rows, function ($row) {
            return is_array($row) && !empty($row['id']) && !empty($row['nom']);
        })) : array();
    }

    public static function public_intervenants() {
        return array_values(array_map(function ($row) {
            return array('id' => (string) $row['id'], 'nom' => (string) $row['nom']);
        }, array_filter(self::intervenants(), function ($row) {
            return !empty($row['active']) && empty($row['revoked_at']) && (empty($row['expires_at']) || $row['expires_at'] >= current_time('Y-m-d'));
        })));
    }

    /**
     * Intervenant du registre actif à ce jour (ni désactivé, ni révoqué,
     * ni expiré), ou null.
     *
     * @return array|null
     */
    public static function find_active_intervenant($id) {
        $id = (string) $id;
        if ($id === '') return null;
        foreach (self::intervenants() as $row) {
            if ((string) $row['id'] !== $id) continue;
            if (empty($row['active']) || !empty($row['revoked_at']) || (($row['expires_at'] ?? '') !== '' && $row['expires_at'] < current_time('Y-m-d'))) return null;
            return $row;
        }
        return null;
    }

    /**
     * Noms des intervenants dont le nouveau code a été refusé par le
     * dernier appel à sanitize_intervenants() (trop court) : l'écran des
     * réglages les signale. Un intervenant existant garde alors son code.
     *
     * @return string[]
     */
    public static function rejected_codes() {
        return self::$rejected_codes;
    }

    public static function sanitize_intervenants($raw) {
        $out = array();
        self::$rejected_codes = array();
        foreach (is_array($raw) ? $raw : array() as $row) {
            if (!is_array($row)) continue;
            $nom = sanitize_text_field($row['nom'] ?? '');
            $id = sanitize_key($row['id'] ?? '');
            $code = trim(sanitize_text_field($row['code'] ?? ''));
            if ($nom === '') continue;
            if ($id === '') $id = sanitize_key(substr(md5($nom . wp_generate_uuid4()), 0, 12));
            $old = null;
            foreach (self::intervenants() as $candidate) if ((string) $candidate['id'] === $id) { $old = $candidate; break; }
            // Un code créé ou modifié doit compter au moins MIN_CODE_LENGTH
            // caractères (SEC-03) ; les codes déjà enregistrés restent
            // valables. Refusé : l'ancien code est conservé (nouvel
            // intervenant : il n'est pas créé) et l'écran le signale.
            if ($code !== '' && mb_strlen($code) < self::MIN_CODE_LENGTH) {
                self::$rejected_codes[] = mb_substr($nom, 0, 120);
                $code = '';
            }
            $hash = $code !== '' ? wp_hash_password($code) : (!empty($old['code_hash']) ? $old['code_hash'] : '');
            if ($hash === '') continue;
            $out[] = array(
                'id' => $id, 'nom' => mb_substr($nom, 0, 120), 'code_hash' => $hash,
                'active' => !empty($row['active']) ? 1 : 0,
                'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['expires_at'] ?? '')) ? (string) $row['expires_at'] : '',
                'revoked_at' => !empty($row['revoked_at']) ? sanitize_text_field($row['revoked_at']) : '',
                'created_at' => !empty($old['created_at']) ? $old['created_at'] : current_time('mysql'),
            );
        }
        return $out;
    }

    private static function session_key($token) { return 'psc_sidscm_session_' . hash('sha256', (string) $token); }

    public static function current_intervenant() { return self::$request_actor; }

    private static function authenticate($token, $id, $code) {
        if ($token !== '') {
            $actor = get_transient(self::session_key($token));
            // Une session ouverte avec l'ancien code partagé n'a pas d'id :
            // elle vaut tant qu'aucune identité individuelle n'est
            // configurée, comme le code lui-même (cf. ci-dessous). Exiger
            // un id refusait toute requête après le déverrouillage.
            $legacy_ok = is_array($actor) && array_key_exists('id', $actor) && $actor['id'] === null && !self::intervenants();
            // Le registre est revérifié à chaque appel (SEC-03) : un
            // intervenant révoqué, désactivé ou expiré perd sa session
            // tout de suite, pas au bout de 8 h.
            $row = is_array($actor) && !empty($actor['id']) ? self::find_active_intervenant($actor['id']) : null;
            if (is_array($actor) && !empty($actor['nom']) && ($row || $legacy_ok)) {
                if ($row) $actor['nom'] = (string) $row['nom'];
                set_transient(self::session_key($token), $actor, self::SESSION_TTL);
                self::$request_actor = $actor;
                return $actor;
            }
            if ($actor !== false) delete_transient(self::session_key($token));
        }
        $rows = self::intervenants();
        foreach ($rows as $row) {
            if (empty($row['active']) || !empty($row['revoked_at']) || ($row['expires_at'] !== '' && $row['expires_at'] < current_time('Y-m-d'))) continue;
            // L'intervenant est désigné (SEC-03) : un code n'est jamais
            // essayé contre tout le registre. L'écran de déverrouillage
            // transmet toujours l'identifiant choisi.
            if ($id === '' || (string) $row['id'] !== $id) continue;
            if (!empty($row['code_hash']) && wp_check_password($code, $row['code_hash'])) {
                $actor = array('id' => (string) $row['id'], 'nom' => (string) $row['nom']);
                self::$request_actor = $actor;
                return $actor;
            }
        }
        // Migration fallback: the legacy shared code is accepted only until
        // the first individual identity is configured.
        if (!$rows && self::code_valid($code)) {
            $actor = array('id' => null, 'nom' => __('écran intervenants (ancien code partagé)', 'periscolaire-registration'));
            self::$request_actor = $actor;
            return $actor;
        }
        return false;
    }

    /**
     * Aucun code configuré = accès désactivé pour tout le monde (pas de
     * "code vide accepte tout" par défaut).
     */
    protected static function code_valid($submitted) {
        $configured = trim((string) get_option('psc_sidscm_access_code', ''));
        if ($configured === '') return false;
        return hash_equals(strtoupper($configured), strtoupper(trim((string) $submitted)));
    }

    /**
     * Le code de la requête AJAX courante est-il valide ? Utilisé par
     * Psc_Audit pour attribuer une action à « écran intervenants » sans
     * dupliquer la comparaison hash_equals() de code_valid() ailleurs
     * dans le plugin — une seule vérité sur ce qui authentifie cet écran.
     */
    public static function is_authenticated_request() {
        return (bool) self::authenticate((string) psc_post('session_token'), sanitize_key((string) psc_post('intervenant_id')), (string) psc_post('code'));
    }

    /**
     * Le code est la seule barrière des endpoints anonymes de cet écran
     * (pas d'authentification WordPress) : chaque tentative à code
     * erroné, sur n'importe quel endpoint, alimente le même seau —
     * 20 mauvais codes par heure et par IP, comme ajax_unlock(). Sans
     * cela, ajax_data / ajax_toggle / ajax_set_departure offraient une
     * porte d'énumération à débit illimité : les noms, classes et
     * régimes des mineurs, et la falsification des pointages, à
     * raison d'autant d'essais de code que l'attaquant veut.
     *
     * Les appels légitimes portent toujours le bon code (sidscm.js le
     * renvoie depuis son état à chaque requête), donc seul un attaquant
     * ou un code périmé dans un localStorage consomme ce seau : une
     * rafale de pointage ne peut pas s'y faire bloquer.
     */
    protected static function require_code() {
        $id = sanitize_key((string) psc_post('intervenant_id'));
        $actor = self::authenticate((string) psc_post('session_token'), $id, (string) psc_post('code'));
        if ($actor) return $actor;

        if (!self::count_bad_code($id)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        wp_send_json_error(array('code' => 'auth'), 403);
    }

    /**
     * Compte un échec d'authentification : 20 par heure et par IP, et en
     * plus 10 par heure et par intervenant visé (SEC-03), pour qu'un
     * attaquant changeant d'adresse IP ne puisse pas éprouver un même
     * code indéfiniment. Faux si une limite est atteinte.
     */
    private static function count_bad_code($id) {
        $ok_ip = psc_rate_limit_by_ip('sidscm_bad_', 20, HOUR_IN_SECONDS);
        $ok_id = $id === '' || psc_rate_limit('sidscm_bad_id_' . $id, self::BAD_CODES_PER_ID, HOUR_IN_SECONDS);
        return $ok_ip && $ok_id;
    }

    /* ---------------- Cohérence jour/service ---------------- */

    /**
     * On ne pointe que ce que l'écran affiche (cohérence jour/service) :
     * un jour réellement en service cette semaine (l'écran ne propose que
     * psc_open_days() de la semaine courante), et un enfant réellement
     * attendu à ce service ce jour-là (cf. is_expected()). Sans ce
     * contrôle, une requête forgée — ou une simple erreur de manipulation
     * — crée une ligne de pointage valide en base pour un enfant non
     * inscrit, un mercredi ou en pleines vacances : la donnée fausse
     * entre ensuite dans les statistiques comme si elle était vraie.
     */
    protected static function require_day_service($child_id, $date, $service) {
        self::require_open_day($date);
        $expected = self::is_expected($child_id, $date, $service);
        if (!$expected && $service === 'CANT') {
            // Présence au créneau du midi SANS repas (MSR — enfant flagué
            // « cantine sans repas » ou déclaration explicite) : la liste
            // de l'onglet cantine la fusionne déjà (cf. le payload) ; le
            // pointage part avec la clé CANT du créneau, elle doit donc
            // être acceptée, sinon une présence affichée est impossible
            // à pointer.
            $expected = Psc_Planning::is_declared($child_id, $date, psc_midi_sans_repas_code());
        }
        if (!$expected) {
            wp_send_json_error(array('code' => 'not_expected'), 400);
        }
    }

    /** Le jour pointé doit être un jour ouvert de la semaine en cours (cf. require_day_service()). */
    protected static function require_open_day($date) {
        $monday = psc_week_start(current_time('Y-m-d'));
        if (!in_array($date, array_values(psc_open_days($monday)), true)) {
            wp_send_json_error(array('code' => 'not_open'), 400);
        }
    }

    /**
     * L'enfant est-il attendu à ce service ce jour-là ? Source de vérité
     * unique (psc_is_declared) : la même fonction que la facturation et
     * les exports mairie — le forfait qui couvre GM/CANT/GS, les
     * exceptions de retrait et les fermetures y sont déjà arbitrés.
     */
    protected static function is_expected($child_id, $date, $service) {
        return Psc_Planning::is_declared($child_id, $date, $service);
    }

    /**
     * Écrit un pointage (ligne enfant × jour × service) en une seule
     * requête : INSERT … ON DUPLICATE KEY UPDATE sur la clé unique
     * child_date_service. Un « lire puis insérer » laissait deux appels
     * simultanés (double appui) se marcher dessus, et la réponse annonçait
     * un succès même quand l'écriture avait échoué. Seules les colonnes
     * de $values (plus pointed_at) sont écrites. Une ligne créée sans
     * `present` vaut « à pointer » (défaut NULL, schéma 4.22.0) : les
     * heures seules passent par save_time_if_present(), jamais par ici.
     * Répond en erreur, sans revenir, si l'écriture échoue.
     *
     * @param array $values colonne => valeur ; null écrit NULL.
     */
    private static function save_attendance($child_id, $date, $service, array $values) {
        global $wpdb;
        $t_att = psc_table('attendance');
        $values['pointed_at'] = current_time('mysql');
        $row = array('child_id' => (int) $child_id, 'jour_date' => $date, 'service' => $service) + $values;

        $placeholders = array();
        $args = array();
        foreach ($row as $value) {
            if ($value === null) {
                $placeholders[] = 'NULL';
                continue;
            }
            $placeholders[] = is_int($value) ? '%d' : '%s';
            $args[] = $value;
        }
        $updates = array();
        foreach (array_keys($values) as $column) {
            $updates[] = "$column = VALUES($column)";
        }

        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $t_att (" . implode(', ', array_keys($row)) . ')
             VALUES (' . implode(', ', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $args
        ));
        if ($ok === false) {
            wp_send_json_error(array('code' => 'save'), 500);
        }
    }

    /**
     * Heure d'arrivée (GM) ou de départ (GS) d'un enfant pointé présent, en
     * une requête gardée : UPDATE … WHERE present = 1. La garde est dans
     * l'écriture même, pas dans une lecture préalable — un passage en
     * absent simultané ne peut pas recevoir l'heure. Aucune ligne n'est
     * créée : sans pointage présent, l'heure est refusée (not_present).
     * $time vide efface l'heure. Répond en erreur, sans revenir.
     */
    private static function save_time_if_present($child_id, $date, $service, $column, $time) {
        global $wpdb;
        $t_att = psc_table('attendance');
        $where = array(current_time('mysql'), (int) $child_id, $date, $service);
        $done = $time !== ''
            ? $wpdb->query($wpdb->prepare(
                "UPDATE $t_att SET $column = %s, pointed_at = %s WHERE child_id = %d AND jour_date = %s AND service = %s AND present = 1",
                array_merge(array($time . ':00'), $where)
            ))
            : $wpdb->query($wpdb->prepare(
                "UPDATE $t_att SET $column = NULL, pointed_at = %s WHERE child_id = %d AND jour_date = %s AND service = %s AND present = 1",
                $where
            ));
        if ($done === false) {
            wp_send_json_error(array('code' => 'save'), 500);
        }
        if ($done > 0) return;
        // Aucune ligne modifiée : soit la valeur était déjà celle-ci (dans
        // la même seconde), soit l'enfant n'est pas pointé présent.
        $present = $wpdb->get_var($wpdb->prepare(
            "SELECT present FROM $t_att WHERE child_id = %d AND jour_date = %s AND service = %s",
            (int) $child_id, $date, $service
        ));
        if ($present === null || (int) $present !== 1) {
            wp_send_json_error(array('code' => 'not_present'), 400);
        }
    }

    /** Colonne d'heure portée par un service : arrivée (GM), départ (GS), aucune (CANT). */
    private static function time_column($service) {
        if ($service === 'GM') return 'arrival_time';
        if ($service === 'GS') return 'departure_time';
        return null;
    }

    /** Heure saisie valide (HH:MM, 00:00 à 23:59) ? */
    private static function valid_time($time) {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time);
    }

    public static function ajax_unlock() {
        check_ajax_referer('psc_sidscm_front', 'nonce');

        if (!psc_rate_limit_by_ip('sidscm_unlock_', 20, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }

        $id = sanitize_key((string) psc_post('intervenant_id'));
        // Intervenant verrouillé par trop d'échecs : refusé avant même de
        // tester le code (sinon le bon code finirait par passer).
        if ($id !== '' && psc_rate_limit_reached('sidscm_bad_id_' . $id, self::BAD_CODES_PER_ID)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        $actor = self::authenticate('', $id, (string) psc_post('code'));
        if (!$actor) {
            // Les échecs de déverrouillage comptent aussi dans le seau
            // partagé des mauvais codes (cf. require_code()) : 20 essais
            // ratés par heure et par IP, 10 par intervenant visé.
            if (!self::count_bad_code($id)) {
                wp_send_json_error(array('code' => 'rate'), 429);
            }
            Psc_Audit::log('intervenant.deverrouillage', array('resultat' => 'refus', 'resume' => __('Code d’accès intervenants invalide.', 'periscolaire-registration')));
            wp_send_json_error(array('code' => 'bad_code'), 403);
        }

        $token = wp_generate_password(48, false, false);
        set_transient(self::session_key($token), $actor, self::SESSION_TTL);
        Psc_Audit::log('intervenant.deverrouillage', array('acteur' => array('type' => 'intervenant', 'id' => $actor['id'], 'libelle' => $actor['nom'], 'pour_le_compte_de' => null), 'resume' => __('Écran intervenants déverrouillé.', 'periscolaire-registration')));
        wp_send_json_success(array('session_token' => $token, 'intervenant' => $actor));
    }

    /* ---------------- Données : semaine en cours ---------------- */

    /**
     * Enfants attendus pour la semaine réellement en cours (lundi de
     * aujourd'hui), tous services confondus — tout le filtrage jour/
     * service/vue se fait ensuite côté client, comme le reste de
     * l'interaction (bascule jour/semaine, onglets service) : un seul
     * aller-retour serveur pour charger la semaine, la présence se
     * pointe ensuite sans recharger la liste.
     */
    public static function ajax_data() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        // Volume borné même avec un code valide divulgué : un appel par
        // chargement d'écran, 60/heure laisse toute la marge à une équipe
        // complète derrière l'adresse IP de l'école.
        if (!psc_rate_limit_by_ip('sidscm_data_', 60, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        global $wpdb;
        $current_week = psc_week_start(current_time('Y-m-d'));
        $weeks = array();
        for ($offset = 0; $offset <= 8; $offset++) {
            $start = date('Y-m-d', strtotime($current_week . ' +' . $offset . ' weeks'));
            /* translators: 1: date du lundi, 2: date du vendredi (jj/mm/aaaa) */
            $weeks[$start] = sprintf(__('Semaine du %1$s au %2$s', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($start)), date_i18n('d/m/Y', strtotime($start . ' +4 days')));
        }
        $monday = psc_post('week') ?: $current_week;
        if (!isset($weeks[$monday])) wp_send_json_error(array('code' => 'invalid_week'), 400);
        // Ne contient jamais le mercredi (psc_is_school_day()) et exclut déjà
        // vacances/jours fériés/fermetures ponctuelles : les seuls jours à
        // afficher sont ceux réellement en service cette semaine.
        $open_days = psc_open_days($monday);

        $out = array(
            'week' => $monday,
            'current_week' => $current_week,
            'weeks' => $weeks,
            'days'       => $open_days,
            'services'   => psc_services(),
            'children'   => array(),
            'attendance' => new stdClass(), // objet vide côté JSON si aucune ligne
            'arrivals'    => new stdClass(),
            'departures' => new stdClass(),
        );

        if (empty($open_days)) {
            wp_send_json_success($out);
        }

        // Personnes autorisées (noms, téléphones de tiers) : seulement pour
        // la semaine en cours, la seule où un départ se contrôle (RGPD-06).
        $week = self::week_children($open_days, $monday === $current_week);
        $out['children']   = $week['children'];
        $out['attendance'] = $week['attendance'] ?: new stdClass();
        $out['arrivals']   = $week['arrivals'] ?: new stdClass();
        $out['departures'] = $week['departures'] ?: new stdClass();

        wp_send_json_success($out);
    }

    /**
     * Enfants attendus sur les jours ouverts d'une semaine, avec leurs
     * pointages : le cœur de ajax_data(), extrait pour être mesuré
     * (bin/verify-query-budget.php). Nombre de requêtes indépendant du
     * nombre d'enfants.
     *
     * Minimisation (RGPD-06) : le texte des allergies et la mention « apporte
     * son repas » ne partent que pour un enfant attendu au créneau du midi
     * cette semaine ; les personnes autorisées que pour un enfant attendu
     * en garderie du soir, et seulement si $with_authorized (semaine en
     * cours).
     *
     * @param array $open_days [jour => date]
     * @param bool  $with_authorized Joindre les personnes autorisées.
     * @return array ['children', 'attendance', 'arrivals', 'departures']
     */
    public static function week_children(array $open_days, $with_authorized = true) {
        global $wpdb;
        $empty = array('children' => array(), 'attendance' => array(), 'arrivals' => array(), 'departures' => array());
        if (!$open_days) return $empty;

        $t_child = psc_table('children');
        // Enfants inscrits à l'année de la semaine affichée.
        $year_id = Psc_School_Years::id_for_date(reset($open_days));
        $children = $wpdb->get_results("SELECT c.* FROM $t_child c WHERE " . Psc_School_Years::inscrit_sql('c.id', $year_id) . ' ORDER BY c.nom, c.prenom');
        if (empty($children)) return $empty;

        $dates = array_values($open_days);
        $child_ids = wp_list_pluck($children, 'id');
        $ph_child = implode(',', array_fill(0, count($child_ids), '%d'));
        $ph_date  = implode(',', array_fill(0, count($dates), '%s'));

        // Déclarations de la semaine en un lot : source de vérité unique
        // (psc_is_declared), jamais l'ancienne table.
        $declared = Psc_Planning::declared_map($child_ids, $dates);

        $t_att = psc_table('attendance');
        $att_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT child_id, jour_date, service, present, arrival_time, departure_time FROM $t_att
             WHERE child_id IN ($ph_child) AND jour_date IN ($ph_date)",
            array_merge($child_ids, $dates)
        ));
        $attendance = array();
        $arrivals = array();
        $departures = array();
        foreach ($att_rows as $a) {
            $key = $a->child_id . '|' . $a->jour_date . '|' . $a->service;
            // present NULL = « à pointer » (schéma 4.22.0) : la clé reste
            // absente de la carte, seuls les présents (1) et absents (0)
            // pointés y figurent.
            if ($a->present !== null) {
                $attendance[$key] = (int) $a->present;
            }
            if ($a->arrival_time) {
                $arrivals[$key] = substr($a->arrival_time, 0, 5);
            }
            if ($a->departure_time) {
                // Colonne TIME MySQL -> "HH:MM" pour <input type="time">
                // (l'attribut value n'accepte pas les secondes en trop).
                $departures[$key] = substr($a->departure_time, 0, 5);
            }
        }

        // Source de vérité unique : la résolution (psc_is_declared) arbitre
        // déjà forfait (couvre GM+CANT+GS), exceptions de retrait et
        // fermetures de prestation.
        $expected = function ($child_id, $date, $service) use ($declared) {
            return !empty($declared[$child_id][$date][$service]);
        };

        // Lectures groupées (P2-09) : classes en une requête, personnes
        // autorisées en trois requêtes pour toute la semaine — elles
        // étaient lues enfant par enfant.
        $classes = Psc_School_Years::classes_for($child_ids);
        $needs_authorized = array();

        $out_children = array();
        foreach ($children as $c) {
            $classe = $classes[(int) $c->id] ?? '';

            $diet_bits = array();
            if ((int) $c->sans_porc === 1) $diet_bits[] = __('Sans porc', 'periscolaire-registration');
            if ((int) $c->vegan === 1) $diet_bits[] = __('Sans viande', 'periscolaire-registration');

            $per_service = array();
            $has_any = false;
            foreach (psc_allowed_services() as $svc) {
                if ($svc === psc_forfait_code()) continue;
                $jours = array();
                foreach ($open_days as $jour => $date) {
                    if ($expected($c->id, $date, $svc)) {
                        $jours[] = $jour;
                        $has_any = true;
                    }
                }
                $per_service[$svc] = $jours;
            }
            if (!$has_any) continue; // rien à afficher pour cet enfant cette semaine

            // Personnes autorisées : seulement pour un enfant attendu en
            // garderie du soir (sidscm.js n'affiche ce bloc que sur cet
            // onglet) ; lues en lot après la boucle.
            if ($with_authorized && !empty($per_service['GS'])) $needs_authorized[] = (int) $c->id;

            // Donnée de santé : transmise seulement si l'enfant déjeune
            // (cantine ou midi sans repas) cette semaine — un intervenant
            // de garderie n'en a pas l'usage (RGPD-06).
            $at_lunch = !empty($per_service['CANT']) || !empty($per_service['MSR']);
            // Plus de texte d'allergies (RGPD-10) : le signalement suffit,
            // le détail relève du PAI. Un enfant qui apporte son repas est en
            // « cantine sans repas » : il figure au midi comme MSR.
            $allergies = ($at_lunch && !empty($c->food_allergy_signal))
                ? __('Signalement alimentaire : voir la mairie (PAI)', 'periscolaire-registration')
                : '';

            $out_children[] = array(
                'id'         => (int) $c->id,
                'prenom'     => $c->prenom,
                'nom'        => $c->nom,
                'classe'     => $classe,
                'diet'       => $diet_bits ? implode(', ', $diet_bits) : __('Standard', 'periscolaire-registration'),
                // Allergie alimentaire : usage critique de la liste cantine,
                // en priorité et lisible sans déplier (#9E4A4A, cf. sidscm.js).
                'allergies'  => $allergies !== '' ? $allergies : null,
                'GM'         => $per_service['GM'],
                // Liste cantine : un enfant « midi sans repas » est présent
                // sur le créneau du midi — il y figure ; la liste MSR (jours
                // où il n'a PAS de repas) sert au badge de la ligne.
                'CANT'       => array_values(array_unique(array_merge($per_service['CANT'], $per_service['MSR']))),
                'MSR'        => $per_service['MSR'],
                'GS'         => $per_service['GS'],
                'authorized' => array(),
            );
        }

        // Personnes autorisées : uniquement pour les enfants attendus en
        // garderie du soir (seul service avec un vrai départ à contrôler),
        // recalculées à la volée, jamais une copie figée à l'inscription.
        if ($needs_authorized) {
            $lists = Psc_Pickup_Persons::authorized_for_children($needs_authorized);
            foreach ($out_children as $k => $child) {
                if (!isset($lists[$child['id']])) continue;
                $out_children[$k]['authorized'] = array_map(function ($p) {
                    return array(
                        'role'      => $p['role'],
                        'prenom'    => $p['prenom'],
                        'nom'       => $p['nom'],
                        'telephone' => $p['telephone'],
                    );
                }, $lists[$child['id']]);
            }
        }


        return array('children' => $out_children, 'attendance' => $attendance, 'arrivals' => $arrivals, 'departures' => $departures);
    }

    /* ---------------- Contact d'urgence ---------------- */

    /**
     * Numéros des parents d'un enfant, sur demande de l'intervenant
     * (décisions du 16/10/2026) : jamais dans la liste du jour, chargés au
     * clic sur « Contact d'urgence » et journalisés (quel intervenant, quel
     * enfant). Parents uniquement — titulaire (mobile et fixe) et second
     * parent —, chacun avec son nom et son rôle ; les tiers restent dans
     * « Personnes autorisées » (garderie du soir).
     *
     * Minimisation (RGPD-06) : seulement pour un enfant attendu à au moins
     * un service un jour ouvert de la semaine en cours.
     */
    public static function ajax_contacts() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        // Consultation ponctuelle : 60/heure couvre largement une urgence
        // ou une journée chargée, sans permettre d'aspirer l'annuaire.
        if (!psc_rate_limit_by_ip('sidscm_contacts_', 60, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        $child_id = psc_post_int('child_id');
        if (!$child_id) wp_send_json_error(array('code' => 'invalid'), 400);
        if (!self::expected_this_week($child_id)) {
            wp_send_json_error(array('code' => 'not_expected'), 400);
        }

        global $wpdb;
        $parent_id = (int) $wpdb->get_var($wpdb->prepare('SELECT parent_id FROM ' . psc_table('children') . ' WHERE id = %d', $child_id));
        $parent = $parent_id ? Psc_Parents::get_by_id($parent_id) : null;
        $contacts = $parent ? self::emergency_contacts($parent) : array();

        Psc_Audit::log('intervenant.contacts_consultes', array(
            'objet_type' => 'enfant', 'objet_id' => $child_id, 'enfant_id' => $child_id,
            'famille_id' => $parent_id ?: null,
            'resume' => __('Contacts d’urgence des parents consultés depuis l’écran intervenants.', 'periscolaire-registration'),
        ));

        nocache_headers();
        wp_send_json_success(array('child_id' => $child_id, 'contacts' => $contacts));
    }

    /** L'enfant est-il attendu à au moins un service cette semaine (jours ouverts) ? */
    protected static function expected_this_week($child_id) {
        $dates = array_values(psc_open_days(psc_week_start(current_time('Y-m-d'))));
        if (!$dates) return false;
        $declared = Psc_Planning::declared_map(array((int) $child_id), $dates);
        foreach ((array) ($declared[(int) $child_id] ?? array()) as $services) {
            if (array_filter((array) $services)) return true;
        }
        return false;
    }

    /**
     * Parents joignables : rôle, nom et numéros (libellé, affichage, cible
     * tel:). Une personne sans aucun numéro reste listée (« aucun numéro »)
     * pour que l'intervenant sache qu'il faut passer par la mairie.
     *
     * @return array<int, array{role: string, prenom: string, nom: string, phones: array<int, array{label: string, number: string, tel: string}>}>
     */
    public static function emergency_contacts($parent) {
        $phone = function ($label, $number) {
            $number = trim((string) $number);
            if ($number === '') return null;
            $tel = preg_replace('/[^0-9+]/', '', $number);
            return array('label' => $label, 'number' => $number, 'tel' => $tel);
        };
        $contacts = array(array(
            'role'   => __('Titulaire', 'periscolaire-registration'),
            'prenom' => (string) $parent->prenom,
            'nom'    => (string) $parent->nom,
            'phones' => array_values(array_filter(array(
                $phone(__('Mobile', 'periscolaire-registration'), $parent->telephone_mobile),
                $phone(__('Fixe', 'periscolaire-registration'), $parent->telephone_fixe),
            ))),
        ));
        if (trim((string) $parent->second_parent_prenom) !== '' || trim((string) $parent->second_parent_nom) !== '' || trim((string) $parent->second_parent_telephone) !== '') {
            $contacts[] = array(
                'role'   => __('Second parent', 'periscolaire-registration'),
                'prenom' => (string) $parent->second_parent_prenom,
                'nom'    => (string) $parent->second_parent_nom,
                'phones' => array_values(array_filter(array(
                    $phone(__('Téléphone', 'periscolaire-registration'), $parent->second_parent_telephone),
                ))),
            );
        }
        return $contacts;
    }

    /* ---------------- Pointage de présence ---------------- */

    /**
     * Pointage à trois états d'un enfant × jour × service (schéma 4.22.0),
     * paramètre `state` :
     *  - present : present = 1 ; si `time` (HH:MM) est fourni sur GM/GS, il
     *    devient l'heure d'arrivée/de départ, sinon l'heure existante reste ;
     *  - absent  : present = 0, l'heure du service est effacée ;
     *  - none    : annulation du pointage (second appui sur le bouton
     *    actif) — present NULL et les deux heures effacées, la ligne reste.
     * Réponse : l'état écrit et l'heure du service qui en résulte.
     */
    public static function ajax_toggle() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        // Pointage en rafale (une requête par enfant, hors « tous
        // présents ») : 300/heure laisse toute la marge au pointage complet
        // d'une cantine, corrections comprises.
        if (!psc_rate_limit_by_ip('sidscm_toggle_', 300, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        $child_id = psc_post_int('child_id');
        $date     = psc_valid_date(psc_post('jour_date'));
        $service  = psc_post('service');
        $state    = psc_post('state');
        $time     = psc_post('time');

        if (!$child_id || !$date || !in_array($service, psc_unit_services(), true) || !in_array($state, array('present', 'absent', 'none'), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        $column = self::time_column($service);
        // L'heure ne compte que pour un présent sur un service horodaté ;
        // ailleurs elle est ignorée, mais jamais acceptée mal formée.
        if ($state !== 'present' || $column === null) $time = '';
        if ($time !== '' && !self::valid_time($time)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        // Pointage hors jour ou hors inscription refusé (cohérence
        // jour/service, cf. require_day_service()).
        self::require_day_service($child_id, $date, $service);

        if ($state === 'present') {
            $values = array('present' => 1);
            if ($time !== '') $values[$column] = $time . ':00';
        } elseif ($state === 'absent') {
            $values = array('present' => 0);
            if ($column !== null) $values[$column] = null;
        } else {
            $values = array('present' => null, 'arrival_time' => null, 'departure_time' => null);
        }
        self::save_attendance($child_id, $date, $service, $values);

        // Heure résultante : fournie, effacée, ou conservée (présent sans
        // heure saisie) — relue dans ce dernier cas seulement.
        $saved_time = null;
        if ($state === 'present' && $column !== null) {
            if ($time !== '') {
                $saved_time = $time;
            } else {
                global $wpdb;
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT $column FROM " . psc_table('attendance') . ' WHERE child_id = %d AND jour_date = %s AND service = %s',
                    $child_id, $date, $service
                ));
                $saved_time = $existing ? substr((string) $existing, 0, 5) : null;
            }
        }

        $meta = array('date' => $date, 'service' => $service, 'state' => $state);
        if ($saved_time !== null) $meta['time'] = $saved_time;
        Psc_Audit::log('presence.pointage', array(
            'objet_type' => 'presence', 'objet_id' => $child_id, 'enfant_id' => $child_id,
            'meta' => $meta,
        ));

        wp_send_json_success(array('state' => $state, 'time' => $saved_time));
    }

    /* ---------------- « Tous présents » ---------------- */

    /**
     * Marque présents, en un geste, les enfants attendus ce jour à ce
     * service qui restent à pointer (aucune ligne ou present NULL) ; un
     * enfant déjà pointé — absent compris — n'est jamais modifié. Si `time`
     * (HH:MM) est fourni sur GM/GS, il devient l'heure des enfants ainsi
     * marqués qui n'en ont pas encore. Réponse : les identifiants marqués
     * et l'heure appliquée.
     */
    public static function ajax_all_present() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        // Un geste par liste (jour × service), pas par enfant : 60/heure
        // couvre tous les onglets d'une journée, recommencés plusieurs fois.
        if (!psc_rate_limit_by_ip('sidscm_all_present_', 60, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        $date    = psc_valid_date(psc_post('jour_date'));
        $service = psc_post('service');
        $time    = psc_post('time');
        if (!$date || !in_array($service, psc_unit_services(), true)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        if (self::time_column($service) === null) $time = '';
        if ($time !== '' && !self::valid_time($time)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        self::require_open_day($date);

        $updated = self::mark_all_present($date, $service, $time);
        if ($updated === null) {
            wp_send_json_error(array('code' => 'save'), 500);
        }

        $meta = array('date' => $date, 'service' => $service, 'nombre' => count($updated));
        if ($time !== '') $meta['time'] = $time;
        Psc_Audit::log('presence.pointage_groupe', array(
            'objet_type' => 'presence',
            'meta' => $meta,
        ));

        wp_send_json_success(array('updated' => $updated, 'time' => $time !== '' ? $time : null));
    }

    /**
     * Cœur de ajax_all_present(), extrait pour être vérifié sans requête
     * HTTP. Candidats : les enfants que l'écran liste (inscrits à l'année
     * du jour) et attendus à ce service — même règle que
     * require_day_service(), cantine comprise pour un « midi sans repas ».
     * Nombre de requêtes indépendant du nombre d'enfants : une lecture des
     * pointages existants, une écriture groupée, une relecture.
     *
     * L'écriture est gardée dans la requête elle-même (ON DUPLICATE KEY
     * UPDATE … IF(present IS NULL, …)) : un enfant pointé absent entre la
     * lecture et l'écriture ne repasse pas présent.
     *
     * @param string $time HH:MM ou '' (aucune heure à poser).
     * @return int[]|null Identifiants marqués présents ; null si l'écriture échoue.
     */
    public static function mark_all_present($date, $service, $time = '') {
        global $wpdb;
        $t_child = psc_table('children');
        $t_att = psc_table('attendance');
        $column = self::time_column($service);
        if ($column === null) $time = '';

        $year_id = Psc_School_Years::id_for_date($date);
        $child_ids = array_map('intval', (array) $wpdb->get_col("SELECT c.id FROM $t_child c WHERE " . Psc_School_Years::inscrit_sql('c.id', $year_id)));
        if (!$child_ids) return array();

        $declared = Psc_Planning::declared_map($child_ids, array($date));
        $msr = psc_midi_sans_repas_code();
        $expected = array();
        foreach ($child_ids as $cid) {
            $day = $declared[$cid][$date] ?? array();
            if (!empty($day[$service]) || ($service === 'CANT' && !empty($day[$msr]))) $expected[] = $cid;
        }
        if (!$expected) return array();

        $ph_child = implode(',', array_fill(0, count($expected), '%d'));
        $pointed = $wpdb->get_col($wpdb->prepare(
            "SELECT child_id FROM $t_att WHERE child_id IN ($ph_child) AND jour_date = %s AND service = %s AND present IS NOT NULL",
            array_merge($expected, array($date, $service))
        ));
        $candidates = array_values(array_diff($expected, array_map('intval', (array) $pointed)));
        if (!$candidates) return array();

        $now = current_time('mysql');
        $with_time = $time !== '';
        $rows = array();
        $args = array();
        foreach ($candidates as $cid) {
            $rows[] = $with_time ? '(%d, %s, %s, 1, %s, %s)' : '(%d, %s, %s, 1, %s)';
            $args[] = $cid;
            $args[] = $date;
            $args[] = $service;
            if ($with_time) $args[] = $time . ':00';
            $args[] = $now;
        }
        // Ordre voulu : MySQL applique les affectations de gauche à droite,
        // chacune voyant les colonnes déjà réécrites. `present` vient donc
        // en dernier, pour que les gardes précédentes lisent son ancienne
        // valeur.
        $updates = array('pointed_at = IF(present IS NULL, VALUES(pointed_at), pointed_at)');
        if ($with_time) $updates[] = "$column = IF(present IS NULL AND $column IS NULL, VALUES($column), $column)";
        $updates[] = 'present = IF(present IS NULL, 1, present)';

        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $t_att (child_id, jour_date, service, present, " . ($with_time ? "$column, " : '') . 'pointed_at)
             VALUES ' . implode(', ', $rows) . '
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates),
            $args
        ));
        if ($ok === false) return null;

        // Relecture : seuls les candidats effectivement présents sont
        // annoncés (un absent pointé entre-temps est resté absent).
        $ph_cand = implode(',', array_fill(0, count($candidates), '%d'));
        $present = $wpdb->get_col($wpdb->prepare(
            "SELECT child_id FROM $t_att WHERE child_id IN ($ph_cand) AND jour_date = %s AND service = %s AND present = 1",
            array_merge($candidates, array($date, $service))
        ));
        $present = array_map('intval', (array) $present);
        return array_values(array_intersect($candidates, $present));
    }

    /* ---------------- Heure d'arrivée (Garderie matin) ---------------- */

    /**
     * Enregistre l'heure d'arrivée d'un enfant pointé présent, sans toucher
     * au pointage ; refusée (not_present) sinon. Une heure vide l'efface.
     */
    public static function ajax_set_arrival() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        if (!psc_rate_limit_by_ip('sidscm_arrival_', 240, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        $child_id = psc_post_int('child_id');
        $date     = psc_valid_date(psc_post('jour_date'));
        $time     = psc_post('arrival_time');
        if (!$child_id || !$date || ($time !== '' && !self::valid_time($time))) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        self::require_day_service($child_id, $date, 'GM');

        self::save_time_if_present($child_id, $date, 'GM', 'arrival_time', $time);

        Psc_Audit::log('presence.pointage', array(
            'objet_type' => 'presence', 'objet_id' => $child_id, 'enfant_id' => $child_id,
            'meta' => array('date' => $date, 'service' => 'GM', 'type' => 'arrivee'),
        ));

        wp_send_json_success();
    }

    /* ---------------- Heure de départ (Garderie soir) ---------------- */

    /**
     * Heure de départ réelle, saisie par l'intervenant sur l'onglet
     * Garderie soir uniquement — même mécanisme de persistance que le
     * pointage de présence (même ligne wp_psc_attendance, enfant × jour
     * × service='GS'), mais une colonne dédiée : ne touche jamais
     * `present`. Refusée (not_present) tant que l'enfant n'est pas pointé
     * présent ; une heure vide l'efface.
     */
    public static function ajax_set_departure() {
        check_ajax_referer('psc_sidscm_front', 'nonce');
        // Même logique que le pointage : les heures de départ se saisissent
        // en série le soir (une requête par enfant de garderie).
        if (!psc_rate_limit_by_ip('sidscm_depart_', 240, HOUR_IN_SECONDS)) {
            wp_send_json_error(array('code' => 'rate'), 429);
        }
        self::require_code();

        $child_id = psc_post_int('child_id');
        $date     = psc_valid_date(psc_post('jour_date'));
        $time     = psc_post('departure_time');

        if (!$child_id || !$date) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }
        if ($time !== '' && !self::valid_time($time)) {
            wp_send_json_error(array('code' => 'invalid'), 400);
        }

        // Départ hors jour ou pour un enfant non attendu en garderie soir
        // refusé (cohérence jour/service, cf. require_day_service()).
        self::require_day_service($child_id, $date, 'GS');

        self::save_time_if_present($child_id, $date, 'GS', 'departure_time', $time);

        Psc_Audit::log('presence.pointage', array(
            'objet_type' => 'presence', 'objet_id' => $child_id, 'enfant_id' => $child_id,
            'meta' => array('date' => $date, 'service' => 'GS', 'type' => 'depart'),
        ));

        wp_send_json_success();
    }
}
