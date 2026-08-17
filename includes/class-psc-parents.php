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
        add_action('init', array(__CLASS__, 'maybe_consume_email_change'), 5);
        add_action('admin_post_nopriv_psc_request_link', array(__CLASS__, 'handle_request_link'));
        add_action('admin_post_psc_request_link', array(__CLASS__, 'handle_request_link'));
        add_action('admin_post_nopriv_psc_logout', array(__CLASS__, 'handle_logout'));
        add_action('admin_post_psc_logout', array(__CLASS__, 'handle_logout'));
        add_action('admin_post_nopriv_psc_cancel_email_change', array(__CLASS__, 'handle_cancel_email_change'));
        add_action('admin_post_psc_cancel_email_change', array(__CLASS__, 'handle_cancel_email_change'));
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

    /* ---------------- Changement d'adresse e-mail ---------------- */

    /**
     * L'e-mail sert d'identifiant de connexion (lien magique) : un
     * changement ne doit jamais être appliqué immédiatement, au risque de
     * verrouiller le compte sur une faute de frappe. La nouvelle adresse
     * est stockée en attente ("pending_email") et un lien de confirmation
     * lui est envoyé — l'ancienne adresse reste pleinement fonctionnelle
     * tant qu'il n'a pas été cliqué.
     */
    public static function request_email_change($parent_id, $new_email) {
        global $wpdb;
        $new_email = strtolower(sanitize_email($new_email));
        if (!is_email($new_email)) {
            return new WP_Error('psc_bad_email', 'Adresse e-mail invalide.');
        }
        if (self::get_by_email($new_email)) {
            return new WP_Error('psc_email_taken', 'Cette adresse est déjà utilisée par une autre famille.');
        }

        $token = bin2hex(random_bytes(32));
        $wpdb->update(
            psc_table('parents'),
            array(
                'pending_email'               => $new_email,
                'pending_email_token_hash'    => psc_hash_token($token),
                'pending_email_token_expires' => gmdate('Y-m-d H:i:s', time() + psc_email_confirmation_ttl()),
            ),
            array('id' => $parent_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        $parent = self::get_by_id($parent_id);
        $url = add_query_arg(
            array('psc_pid' => $parent_id, 'psc_email_token' => $token),
            Psc_Mailer::form_page_url()
        );

        Psc_Mailer::send_email_change_confirmation($parent, $new_email, $url);
        return true;
    }

    /**
     * Si l'URL contient un jeton de changement d'e-mail valide, bascule
     * l'adresse de connexion. Jeton et logique séparés de
     * maybe_consume_token() (lien de connexion) : les deux ne doivent
     * jamais interférer l'un avec l'autre.
     */
    public static function maybe_consume_email_change() {
        if (empty($_GET['psc_email_token']) || empty($_GET['psc_pid'])) {
            return;
        }

        global $wpdb;

        $parent   = self::get_by_id(absint($_GET['psc_pid']));
        $token    = sanitize_text_field(wp_unslash($_GET['psc_email_token']));
        $redirect = remove_query_arg(array('psc_email_token', 'psc_pid'));

        if (!$parent || empty($parent->pending_email_token_hash) || empty($parent->pending_email_token_expires)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_email_token', $redirect));
            exit;
        }
        if (strtotime($parent->pending_email_token_expires . ' UTC') < time()) {
            wp_safe_redirect(add_query_arg('psc_msg', 'expired_email_token', $redirect));
            exit;
        }
        if (!hash_equals($parent->pending_email_token_hash, psc_hash_token($token))) {
            wp_safe_redirect(add_query_arg('psc_msg', 'bad_email_token', $redirect));
            exit;
        }

        // Ré-vérifie l'unicité au moment de la bascule : une autre famille
        // a pu prendre l'adresse entre la demande et le clic sur le lien.
        if (self::get_by_email($parent->pending_email)) {
            wp_safe_redirect(add_query_arg('psc_msg', 'email_taken', $redirect));
            exit;
        }

        $wpdb->update(
            psc_table('parents'),
            array(
                'email'                       => $parent->pending_email,
                'pending_email'               => null,
                'pending_email_token_hash'    => null,
                'pending_email_token_expires' => null,
            ),
            array('id' => $parent->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        wp_safe_redirect(add_query_arg('psc_msg', 'email_changed', $redirect));
        exit;
    }

    public static function handle_cancel_email_change() {
        check_admin_referer('psc_cancel_email_change');

        $parent = self::current();
        if (!$parent) {
            wp_safe_redirect(Psc_Mailer::form_page_url());
            exit;
        }

        global $wpdb;
        $wpdb->update(
            psc_table('parents'),
            array('pending_email' => null, 'pending_email_token_hash' => null, 'pending_email_token_expires' => null),
            array('id' => $parent->id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        wp_safe_redirect(add_query_arg(
            'psc_msg', 'email_change_cancelled',
            add_query_arg('psc_tab', 'profil', Psc_Mailer::form_page_url())
        ));
        exit;
    }

    /* ---------------- Session ---------------- */

    /**
     * Public : appelée aussi par Psc_Requests::maybe_verify() pour ouvrir
     * la session tout de suite après une validation automatique de
     * demande, sans faire attendre un second e-mail au parent.
     */
    public static function open_session($parent_id) {
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

    public static function create($email, $nom = '', $extra = array()) {
        global $wpdb;

        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) {
            return new WP_Error('psc_bad_email', 'Adresse e-mail invalide.');
        }
        if (self::get_by_email($email)) {
            return new WP_Error('psc_exists', 'Cette adresse est déjà enregistrée.');
        }

        $payment_mode = ($extra['payment_mode'] ?? '') === 'prelevement' ? 'prelevement' : 'autre';

        $data = array(
            'email'                      => $email,
            'nom'                        => mb_substr(sanitize_text_field($nom), 0, 190),
            'prenom'                     => mb_substr(sanitize_text_field($extra['prenom'] ?? ''), 0, 190),
            'adresse'                    => mb_substr(sanitize_text_field($extra['adresse'] ?? ''), 0, 255),
            'code_postal'                => mb_substr(sanitize_text_field($extra['code_postal'] ?? ''), 0, 10),
            'ville'                      => mb_substr(sanitize_text_field($extra['ville'] ?? ''), 0, 100),
            'active'                     => 1,
            'payment_mode'               => $payment_mode,
            'sepa_iban'                  => $extra['sepa_iban'] ?? null,
            'sepa_bic'                   => $extra['sepa_bic'] ?? null,
            'sepa_titulaire'             => mb_substr(sanitize_text_field($extra['sepa_titulaire'] ?? ''), 0, 190) ?: null,
            'sepa_adresse'               => mb_substr(sanitize_text_field($extra['sepa_adresse'] ?? ''), 0, 255) ?: null,
            'sepa_code_postal'           => mb_substr(sanitize_text_field($extra['sepa_code_postal'] ?? ''), 0, 10) ?: null,
            'sepa_ville'                 => mb_substr(sanitize_text_field($extra['sepa_ville'] ?? ''), 0, 100) ?: null,
            'sepa_mandate_ref'           => $extra['sepa_mandate_ref'] ?? null,
            'reglement_accepted_at'      => $extra['reglement_accepted_at'] ?? null,
            'sepa_reglement_accepted_at' => $extra['sepa_reglement_accepted_at'] ?? null,
            // Second parent facultatif : jamais revalidé ici, l'appelant
            // (ex. Psc_Requests::approve_request()) est responsable du
            // format — mêmes conventions que sepa_iban/sepa_bic ci-dessus.
            'second_parent_prenom'       => mb_substr(sanitize_text_field($extra['second_parent_prenom'] ?? ''), 0, 190) ?: null,
            'second_parent_nom'          => mb_substr(sanitize_text_field($extra['second_parent_nom'] ?? ''), 0, 190) ?: null,
            'second_parent_email'        => $extra['second_parent_email'] ?? null,
            'second_parent_telephone'    => $extra['second_parent_telephone'] ?? null,
            'created_at'                 => current_time('mysql'),
        );

        $wpdb->insert(psc_table('parents'), $data);

        return (int) $wpdb->insert_id;
    }

    public static function update($parent_id, $data) {
        global $wpdb;
        $parent_id = absint($parent_id);
        if (!$parent_id) return false;

        $allowed = array(
            'nom'                   => 190,
            'prenom'                => 190,
            'telephone_mobile'      => 40,
            'telephone_fixe'        => 40,
            'adresse'               => 255,
            'code_postal'           => 10,
            'ville'                 => 100,
            'sepa_titulaire'        => 190,
            'sepa_adresse'          => 255,
            'sepa_code_postal'      => 10,
            'sepa_ville'            => 100,
            'second_parent_prenom'  => 190,
            'second_parent_nom'     => 190,
        );
        $set     = array();
        $formats = array();
        foreach ($allowed as $field => $max) {
            if (array_key_exists($field, $data)) {
                $val = mb_substr(sanitize_text_field((string) $data[$field]), 0, $max);
                $set[$field] = $val !== '' ? $val : null;
                $formats[]   = '%s';
            }
        }

        // Champs à validation dédiée (pas de simple sanitize_text_field).
        if (array_key_exists('payment_mode', $data)) {
            $set['payment_mode'] = ($data['payment_mode'] === 'prelevement') ? 'prelevement' : 'autre';
            $formats[] = '%s';
        }
        if (array_key_exists('sepa_iban', $data)) {
            $iban = !empty($data['sepa_iban']) ? psc_valid_iban($data['sepa_iban']) : null;
            if (!empty($data['sepa_iban']) && !$iban) return new WP_Error('psc_bad_iban', 'IBAN invalide.');
            $set['sepa_iban'] = $iban;
            $formats[] = '%s';
        }
        if (array_key_exists('sepa_bic', $data)) {
            $bic = !empty($data['sepa_bic']) ? psc_valid_bic($data['sepa_bic']) : null;
            if (!empty($data['sepa_bic']) && !$bic) return new WP_Error('psc_bad_bic', 'BIC invalide.');
            $set['sepa_bic'] = $bic;
            $formats[] = '%s';
        }
        // Second parent : chaque champ reste facultatif, mais un format
        // invalide (s'il est renseigné) est rejeté plutôt qu'enregistré tel quel.
        if (array_key_exists('second_parent_email', $data)) {
            $raw_email = trim((string) $data['second_parent_email']);
            if ($raw_email !== '' && !is_email($raw_email)) {
                return new WP_Error('psc_bad_second_parent_email', 'E-mail du second parent invalide.');
            }
            $set['second_parent_email'] = $raw_email !== '' ? sanitize_email($raw_email) : null;
            $formats[] = '%s';
        }
        if (array_key_exists('second_parent_telephone', $data)) {
            $raw_phone = trim((string) $data['second_parent_telephone']);
            $phone     = $raw_phone !== '' ? psc_valid_phone($raw_phone) : null;
            if ($raw_phone !== '' && !$phone) {
                return new WP_Error('psc_bad_second_parent_phone', 'Téléphone du second parent invalide.');
            }
            $set['second_parent_telephone'] = $phone;
            $formats[] = '%s';
        }

        // Champs générés côté serveur (pas de saisie libre à nettoyer).
        foreach (array('sepa_mandate_ref', 'reglement_accepted_at', 'sepa_reglement_accepted_at') as $field) {
            if (array_key_exists($field, $data)) {
                $set[$field] = $data[$field] ?: null;
                $formats[]   = '%s';
            }
        }

        if (empty($set)) return false;

        return $wpdb->update(psc_table('parents'), $set, array('id' => $parent_id), $formats, array('%d'));
    }

    public static function all() {
        global $wpdb;
        $t = psc_table('parents');
        return $wpdb->get_results("SELECT * FROM $t ORDER BY nom, email");
    }
}
