<?php
if (!defined('ABSPATH')) exit;

/**
 * Authentification des familles SANS compte WordPress.
 *
 * Principe : la mairie enregistre les adresses e-mail des familles.
 * Le parent saisit son adresse sur le site, reçoit un lien à usage unique
 * valable 30 minutes, et accède à son planning. Aucun mot de passe n'est
 * créé ni transmis.
 *
 * Ce choix évite :
 *  - de gérer des mots de passe pour des données concernant des mineurs,
 *  - de dépendre des droits d'administration WordPress du site,
 *  - l'inscription libre, qui exposerait la base à n'importe qui.
 */
class Psc_Parents {

    public static function init() {
        add_action('init', array(__CLASS__, 'maybe_consume_token'), 5);
        add_action('admin_post_nopriv_psc_request_link', array(__CLASS__, 'handle_request_link'));
        add_action('admin_post_psc_request_link', array(__CLASS__, 'handle_request_link'));
        add_action('admin_post_nopriv_psc_logout', array(__CLASS__, 'handle_logout'));
        add_action('admin_post_psc_logout', array(__CLASS__, 'handle_logout'));
    }

    /* ---------------- Accès aux données ---------------- */

    public static function get_by_email($email) {
        global $wpdb;
        $email = sanitize_email($email);
        if (!is_email($email)) return null;
        $t = psc_table('parents');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE email = %s AND active = 1", strtolower($email)
        ));
    }

    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        $t = psc_table('parents');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE id = %d AND active = 1", $id
        ));
    }

    /* ---------------- Envoi du lien ---------------- */

    /**
     * Génère un jeton et envoie le lien de connexion.
     *
     * Renvoie toujours true côté appelant, même si l'adresse est inconnue :
     * répondre différemment permettrait à un tiers de découvrir quelles
     * familles sont inscrites au service (énumération).
     */
    public static function send_login_link($email, $context = 'login') {
        global $wpdb;

        $parent = self::get_by_email($email);
        if (!$parent) {
            return true; // réponse volontairement identique
        }

        // Jeton aléatoire cryptographiquement sûr, stocké haché.
        $token = bin2hex(random_bytes(32));
        $wpdb->update(
            psc_table('parents'),
            array(
                'token_hash'    => psc_hash_token($token),
                'token_expires' => gmdate('Y-m-d H:i:s', time() + psc_login_link_ttl()),
            ),
            array('id' => $parent->id),
            array('%s', '%s'),
            array('%d')
        );

        $url = add_query_arg(
            array('psc_pid' => $parent->id, 'psc_token' => $token),
            Psc_Mailer::form_page_url()
        );

        return Psc_Mailer::send_login_link($parent, $url, $context);
    }

    public static function handle_request_link() {
        check_admin_referer('psc_request_link');

        $email = isset($_POST['psc_email']) ? sanitize_email(wp_unslash($_POST['psc_email'])) : '';
        $back  = Psc_Mailer::form_page_url();

        if (!is_email($email)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_email', $back));
            exit;
        }

        // Deux limites : par adresse (évite le harcèlement d'une famille)
        // et par IP (évite l'usage du site comme relais d'envoi).
        $ok_mail = psc_rate_limit('mail_' . strtolower($email), 3, 15 * MINUTE_IN_SECONDS);
        $ok_ip   = psc_rate_limit('ip_' . psc_client_ip(), 10, HOUR_IN_SECONDS);

        if ($ok_mail && $ok_ip) {
            self::send_login_link($email);
        }

        // Message identique dans tous les cas.
        wp_safe_redirect(add_query_arg('psc_msg', 'link_sent', $back));
        exit;
    }

    /* ---------------- Consommation du jeton ---------------- */

    /**
     * Si l'URL contient un jeton valide, ouvre la session et retire le
     * jeton de l'URL par une redirection (évite qu'il reste dans
     * l'historique du navigateur ou dans un lien partagé).
     */
    public static function maybe_consume_token() {
        if (empty($_GET['psc_token']) || empty($_GET['psc_pid'])) {
            return;
        }

        global $wpdb;

        $pid = absint($_GET['psc_pid']);
        $token = sanitize_text_field(wp_unslash($_GET['psc_token']));
        $parent = self::get_by_id($pid);

        $redirect = remove_query_arg(array('psc_token', 'psc_pid'));

        if (!$parent || empty($parent->token_hash) || empty($parent->token_expires)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_token', $redirect));
            exit;
        }

        if (strtotime($parent->token_expires . ' UTC') < time()) {
            wp_safe_redirect(add_query_arg('psc_msg', 'expired_token', $redirect));
            exit;
        }

        // hash_equals : comparaison à temps constant, protège contre les
        // attaques par mesure du temps de réponse.
        if (!hash_equals($parent->token_hash, psc_hash_token($token))) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_token', $redirect));
            exit;
        }

        // Jeton à usage unique : il est effacé dès qu'il a servi.
        $wpdb->update(
            psc_table('parents'),
            array('token_hash' => null, 'token_expires' => null, 'last_login' => current_time('mysql')),
            array('id' => $parent->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        self::open_session($parent->id);

        wp_safe_redirect(add_query_arg('psc_msg', 'welcome', $redirect));
        exit;
    }

    /* ---------------- Session ---------------- */

    protected static function open_session($parent_id) {
        $expires = time() + psc_session_ttl();
        $payload = $parent_id . '|' . $expires;
        $value = $payload . '|' . psc_sign($payload);

        setcookie(
            psc_session_cookie_name(),
            $value,
            array(
                'expires'  => $expires,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,   // inaccessible au JavaScript
                'samesite' => 'Lax',  // limite les requêtes inter-sites
            )
        );
        $_COOKIE[psc_session_cookie_name()] = $value;
    }

    public static function close_session() {
        setcookie(
            psc_session_cookie_name(),
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        unset($_COOKIE[psc_session_cookie_name()]);
    }

    /**
     * Parent actuellement connecté, ou null.
     * La validité est recalculée à chaque appel : signature, expiration,
     * puis existence effective du parent en base (un parent désactivé par
     * la mairie perd immédiatement l'accès).
     */
    public static function current() {
        static $cache = false;
        if ($cache !== false) return $cache;

        $cache = null;
        $name = psc_session_cookie_name();
        if (empty($_COOKIE[$name])) return null;

        $raw = sanitize_text_field(wp_unslash($_COOKIE[$name]));
        $parts = explode('|', $raw);
        if (count($parts) !== 3) return null;

        list($pid, $expires, $sig) = $parts;

        if (!hash_equals(psc_sign($pid . '|' . $expires), $sig)) return null;
        if ((int) $expires < time()) return null;

        $cache = self::get_by_id($pid);
        return $cache;
    }

    public static function handle_logout() {
        check_admin_referer('psc_logout');
        self::close_session();
        wp_safe_redirect(add_query_arg('psc_msg', 'logged_out', Psc_Mailer::form_page_url()));
        exit;
    }

    /* ---------------- Administration ---------------- */

    public static function create($email, $nom = '') {
        global $wpdb;

        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) {
            return new WP_Error('psc_bad_email', 'Adresse e-mail invalide.');
        }
        if (self::get_by_email($email)) {
            return new WP_Error('psc_exists', 'Cette adresse est déjà enregistrée.');
        }

        $wpdb->insert(psc_table('parents'), array(
            'email'      => $email,
            'nom'        => mb_substr(sanitize_text_field($nom), 0, 190),
            'active'     => 1,
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%d', '%s'));

        return (int) $wpdb->insert_id;
    }

    public static function all() {
        global $wpdb;
        $t = psc_table('parents');
        return $wpdb->get_results("SELECT * FROM $t ORDER BY nom, email");
    }
}
