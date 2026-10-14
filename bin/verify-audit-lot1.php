<?php
/**
 * Script de vérification autonome des corrections d'audit du lot 1
 * (accès, sessions, demandes, intervenants), en conditions WP-CLI réelles.
 * Les e-mails sont interceptés (pre_wp_mail), les redirections et wp_die()
 * sont capturés pour lire la réponse sans terminer le processus.
 *
 * Vérifie :
 *  - SEC-02 : identité de connexion de la session (titulaire, second
 *    parent, cookie antérieur sans identité, empreinte étrangère,
 *    falsification) ; changement d'adresse refusé au second parent et à
 *    une session sans identité, sans rien enregistrer ; accepté pour le
 *    titulaire, avec avis à l'ancienne adresse ; lien d'annulation : page
 *    de confirmation en GET (rien n'est annulé), annulation en POST,
 *    usage unique, rétablissement de l'ancienne adresse après
 *    confirmation, avec révocation des sessions.
 *  - SEC-01 : une soumission anonyme ne réécrit jamais une demande ; une
 *    demande en attente de la mairie reste intacte (réponse identique) ;
 *    les demandes non confirmées de la même adresse ne disparaissent qu'à
 *    la confirmation de l'une d'elles.
 *  - RGPD-04/L1 : les lignes du journal écrites pour le foyer de test ne
 *    contiennent ni e-mail ni nom (résumé et libellé d'acteur).
 *
 * Usage :
 *   wp --require=bin/verify-audit-lot1.php verify-audit-lot1
 *
 * Crée puis supprime ses propres foyers (adresses @example.invalid). Les
 * lignes du journal d'audit, chaîné et en ajout seul, restent.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

if (!class_exists('Psc_Verify_Lot1_Stop')) {
    class Psc_Verify_Lot1_Stop extends Exception {
        public $kind;
        public $status;
        public function __construct($kind, $message, $status = 0) {
            parent::__construct((string) $message);
            $this->kind = (string) $kind;
            $this->status = (int) $status;
        }
    }
}

WP_CLI::add_command('verify-audit-lot1', function () {

    if (!class_exists('Psc_Parents')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_par = psc_table('parents');
    $emails = array(
        'titulaire' => 'verify-lot1-titulaire@example.invalid',
        'second'    => 'verify-lot1-second@example.invalid',
        'nouvelle'  => 'verify-lot1-nouvelle@example.invalid',
        'autre'     => 'verify-lot1-autre@example.invalid',
        'occupant'  => 'verify-lot1-occupant@example.invalid',
        'demande'   => 'verify-lot1-demande@example.invalid',
    );
    $t_req = psc_table('requests');
    $purge_requests = function () use ($wpdb, $emails, $t_req) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_req WHERE email = %s", $emails['demande'])) as $rid) {
            Psc_Assurances::delete_pending_files((int) $rid);
            $wpdb->delete($t_req, array('id' => (int) $rid));
        }
    };
    $purge_requests();
    $saved_auto = get_option('psc_auto_approve_requests', null);

    $purge = function () use ($wpdb, $emails, $t_par) {
        foreach ($emails as $e) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s OR second_parent_email = %s OR pending_email = %s", $e, $e, $e));
            foreach ($ids as $id) {
                $wpdb->delete($t_par, array('id' => (int) $id));
                $epochs = get_option('psc_session_epoch', array());
                if (is_array($epochs) && isset($epochs[(int) $id])) {
                    unset($epochs[(int) $id]);
                    update_option('psc_session_epoch', $epochs, false);
                }
            }
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $mails = array();
    $capture_mail = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    $redirect = function ($location) { throw new Psc_Verify_Lot1_Stop('redirect', $location, 302); };
    $die_handler = function () {
        return function ($message, $title = '', $args = array()) {
            $status = is_array($args) && isset($args['response']) ? (int) $args['response'] : 500;
            throw new Psc_Verify_Lot1_Stop('die', is_scalar($message) ? (string) $message : '', $status);
        };
    };
    add_filter('pre_wp_mail', $capture_mail, 10, 2);
    // WP-CLI signale chaque redirection par un avertissement : la capture
    // ci-dessous la remplace.
    remove_all_filters('wp_redirect');
    add_filter('wp_redirect', $redirect, 99);
    add_filter('wp_die_handler', $die_handler, 99);
    add_filter('psc_rate_limit_enabled', '__return_false', 99);

    /** Exécute un callable qui se termine par une redirection ou wp_die(). */
    $run = function (callable $fn) {
        ob_start();
        try {
            $fn();
            ob_end_clean();
            return array('kind' => 'return', 'value' => '', 'status' => 0);
        } catch (Psc_Verify_Lot1_Stop $stop) {
            ob_end_clean();
            return array('kind' => $stop->kind, 'value' => $stop->getMessage(), 'status' => $stop->status);
        }
    };
    $msg_of = function (array $r) {
        $q = array();
        parse_str((string) parse_url($r['value'], PHP_URL_QUERY), $q);
        return isset($q['psc_msg']) ? $q['psc_msg'] : '';
    };
    /** Cookie de session tel que l'émet Psc_Parents::open_session(). */
    $use_session = function ($pid, $login_email) {
        @Psc_Parents::open_session($pid, $login_email); // setcookie() sans en-têtes en CLI
    };
    $set_cookie = function ($value) {
        $_COOKIE[psc_session_cookie_name()] = $value;
        Psc_Parents::reset_current_cache();
    };
    $profile_post = function ($pid, $email) use ($run) {
        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $_REQUEST = array(
            'action'      => 'psc_parent_update_profile',
            '_wpnonce'    => wp_create_nonce('psc_parent_update_profile'),
            'psc_nonce'   => psc_parent_nonce('psc_parent_update_profile', $pid),
            'profil_nom'  => 'Lot1',
            'profil_prenom' => 'Verification',
            'profil_email'  => $email,
        );
        return $run(function () { do_action('admin_post_nopriv_psc_parent_update_profile'); });
    };
    $parent_row = function ($pid) use ($wpdb, $t_par) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_par WHERE id = %d", $pid));
    };
    $cancel_token_of = function (array $mails, $to) {
        foreach ($mails as $m) {
            $dest = is_array($m['to']) ? $m['to'][0] : $m['to'];
            if (strcasecmp($dest, $to) === 0 && preg_match('/psc_email_cancel=([a-f0-9]{64})/', (string) $m['message'], $mm)) return $mm[1];
        }
        return '';
    };
    $confirm_token_of = function (array $mails, $to) {
        foreach ($mails as $m) {
            $dest = is_array($m['to']) ? $m['to'][0] : $m['to'];
            if (strcasecmp($dest, $to) === 0 && preg_match('/psc_email_token=([a-f0-9]{64})/', (string) $m['message'], $mm)) return $mm[1];
        }
        return '';
    };
    $cancel_post = function ($token) use ($run) {
        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = array();
        $_POST = $_REQUEST = array(
            'action'           => 'psc_cancel_email_change_link',
            '_wpnonce'         => wp_create_nonce('psc_cancel_email_change_link'),
            'psc_email_cancel' => $token,
        );
        return $run(function () { do_action('admin_post_nopriv_psc_cancel_email_change_link'); });
    };

    /** Soumission publique du formulaire d'inscription (anonyme). */
    $submit_request = function ($nom) use ($run, $emails) {
        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = array();
        $_POST = $_REQUEST = array(
            'action'             => 'psc_submit_request',
            '_wpnonce'           => wp_create_nonce('psc_submit_request'),
            'req_email'          => $emails['demande'],
            'req_nom'            => $nom,
            'req_prenom'         => 'Verification',
            'req_telephone'      => '0612345678',
            'req_adresse'        => '1 rue de la Mairie',
            'req_code_postal'    => '95000',
            'req_ville'          => 'Montgeroult',
            'child_nom_0'        => $nom,
            'child_prenom_0'     => 'Enfant',
            'child_classe_0'     => 'CP',
            'child_naissance_0'  => (string) ((int) gmdate('Y') - 7) . '-03-15',
            'reglement_accepted' => '1',
            'payment_mode'       => 'autre',
        );
        return $run(function () { do_action('admin_post_nopriv_psc_submit_request'); });
    };
    $verify_token_of = function (array $mails) {
        foreach (array_reverse($mails) as $m) {
            if (preg_match('/psc_req=(\d+)&psc_vtoken=([a-f0-9]{64})/', html_entity_decode((string) $m['message']), $mm)) return array((int) $mm[1], $mm[2]);
        }
        return array(0, '');
    };

    try {
        /* ---------- SEC-01 : demandes d'inscription ---------- */
        update_option('psc_auto_approve_requests', 0);
        $mails = array();
        $r = $submit_request('Premiere');
        $check($msg_of($r) === 'request_sent', 'SEC-01 : première soumission → ' . $r['kind'] . ' ' . $msg_of($r));
        list($first_id, $first_token) = $verify_token_of($mails);
        $first_before = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $first_id));
        $check($first_id > 0 && $first_before && $first_before->status === 'unverified', 'SEC-01 données : première demande absente');

        $mails = array();
        $r = $submit_request('Tiers');
        list($second_id, $second_token) = $verify_token_of($mails);
        $first_after = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $first_id));
        $check($second_id > 0 && $second_id !== $first_id, 'SEC-01 : la deuxième soumission n’a pas créé de nouvelle demande');
        $check($first_after && $first_after->nom === 'Premiere' && $first_after->verify_hash === $first_before->verify_hash, 'SEC-01 : la demande non confirmée existante a été réécrite');

        // Confirmation de la première : elle remplace la seconde.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_req' => (string) $first_id, 'psc_vtoken' => $first_token);
        $r = $run(function () { Psc_Requests::maybe_verify(); });
        $check($msg_of($r) === 'verified', 'SEC-01 : confirmation → ' . $msg_of($r));
        $check($wpdb->get_var($wpdb->prepare("SELECT status FROM $t_req WHERE id = %d", $first_id)) === 'pending', 'SEC-01 : demande confirmée non passée en attente');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE id = %d", $second_id)) === 0, 'SEC-01 : demande non confirmée concurrente conservée après confirmation');

        // Demande en attente de la mairie : une nouvelle soumission ne change rien.
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $first_id), ARRAY_A);
        $mails = array();
        $r = $submit_request('Attaquant');
        $after = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $first_id), ARRAY_A);
        $check($msg_of($r) === 'request_sent', 'SEC-01 : réponse différente pour une demande en attente');
        $check($before === $after, 'SEC-01 : demande en attente modifiée par une soumission anonyme');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE email = %s", $emails['demande'])) === 1, 'SEC-01 : nouvelle demande créée malgré une demande en attente');
        $check(count($mails) === 0, 'SEC-01 : e-mail envoyé pour une demande en attente');
        $_GET = array();

        $pid = Psc_Parents::create($emails['titulaire'], 'Lot1', array('second_parent_email' => $emails['second']));
        $check(is_int($pid) && $pid > 0, 'SEC-02 données : foyer non créé');
        $occupant = Psc_Parents::create($emails['occupant'], 'Occupant');
        if (!is_int($pid) || !is_int($occupant)) throw new RuntimeException('données de test');

        /* ---------- Identité de connexion ---------- */
        $use_session($pid, $emails['titulaire']);
        $check(Psc_Parents::session_login_role() === 'titulaire', 'SEC-02 : session du titulaire non reconnue');
        $use_session($pid, strtoupper($emails['second']));
        $check(Psc_Parents::session_login_role() === 'second', 'SEC-02 : session du second parent non reconnue');
        $use_session($pid, $emails['autre']);
        $check(Psc_Parents::current() && Psc_Parents::session_login_role() === '', 'SEC-02 : adresse étrangère au foyer reconnue');
        $use_session($pid, '');
        $check(Psc_Parents::current() && Psc_Parents::session_login_role() === '', 'SEC-02 : session sans adresse dotée d’une identité');

        // Cookie antérieur (5 champs, sans identité) : valide, sans identité.
        $legacy = $pid . '|' . (time() + 3600) . '|' . bin2hex(random_bytes(9)) . '|' . psc_session_epoch($pid);
        $set_cookie($legacy . '|' . psc_sign($legacy));
        $check(Psc_Parents::current() && (int) Psc_Parents::current()->id === $pid, 'SEC-02 : cookie antérieur refusé');
        $check(Psc_Parents::session_login_role() === '', 'SEC-02 : cookie antérieur doté d’une identité');

        // Falsification : remplacer l'empreinte invalide la signature.
        $use_session($pid, $emails['second']);
        $parts = explode('|', $_COOKIE[psc_session_cookie_name()]);
        $check(count($parts) === 6, 'SEC-02 : format de cookie inattendu');
        $parts[4] = psc_session_login_fingerprint($emails['titulaire']);
        $set_cookie(implode('|', $parts));
        $check(Psc_Parents::current() === null && Psc_Parents::session_login_role() === '', 'SEC-02 : empreinte falsifiée acceptée');

        /* ---------- Lien de connexion : identité portée par le jeton ---------- */
        $mails = array();
        Psc_Parents::send_login_link($emails['second']);
        $link = '';
        foreach ($mails as $m) if (preg_match('/psc_token=([a-f0-9]+)/', (string) $m['message'], $mm)) $link = $mm[1];
        $check(strpos($link, '2') === 0 && strlen($link) === 65, 'SEC-02 : jeton du second parent sans préfixe de rôle');
        unset($_COOKIE[psc_session_cookie_name()]);
        $_GET = array('psc_pid' => (string) $pid, 'psc_token' => $link);
        $r = $run(function () { Psc_Parents::maybe_consume_token(); });
        $check($msg_of($r) === 'welcome' && Psc_Parents::session_login_role() === 'second', 'SEC-02 : lien du second parent sans identité « second »');
        // Préfixe modifié : le haché ne correspond plus.
        unset($_COOKIE[psc_session_cookie_name()]);
        $_GET = array('psc_pid' => (string) $pid, 'psc_token' => '1' . substr($link, 1));
        $r = $run(function () { Psc_Parents::maybe_consume_token(); });
        $check($msg_of($r) === 'bad_token', 'SEC-02 : jeton au préfixe modifié accepté');
        $_GET = array();

        /* ---------- Changement d'adresse refusé ---------- */
        $use_session($pid, $emails['second']);
        $r = $profile_post($pid, $emails['nouvelle']);
        $row = $parent_row($pid);
        $check($msg_of($r) === 'email_change_forbidden', 'SEC-02 : second parent → ' . $r['kind'] . ' ' . $msg_of($r) . ' au lieu de email_change_forbidden');
        $check(empty($row->pending_email) && $row->email === $emails['titulaire'], 'SEC-02 : adresse modifiée par le second parent');
        $check($row->prenom !== 'Verification', 'SEC-02 : profil écrit malgré le refus');

        $use_session($pid, '');
        $r = $profile_post($pid, $emails['nouvelle']);
        $check($msg_of($r) === 'email_change_relogin', 'SEC-02 : session sans identité → ' . $msg_of($r) . ' au lieu de email_change_relogin');
        $check(empty($parent_row($pid)->pending_email), 'SEC-02 : adresse modifiée par une session sans identité');

        // Le second parent garde la main sur le reste du profil.
        $use_session($pid, $emails['second']);
        $r = $profile_post($pid, $emails['titulaire']);
        $check($msg_of($r) === 'profil_updated' && $parent_row($pid)->prenom === 'Verification', 'SEC-02 : profil (hors adresse) refusé au second parent');

        /* ---------- Titulaire : demande, avis, annulation avant confirmation ---------- */
        $mails = array();
        $use_session($pid, $emails['titulaire']);
        $r = $profile_post($pid, $emails['nouvelle']);
        $check($msg_of($r) === 'profil_updated_email_pending', 'SEC-02 : titulaire → ' . $msg_of($r));
        $check($parent_row($pid)->pending_email === $emails['nouvelle'], 'SEC-02 : adresse en attente absente');
        $cancel = $cancel_token_of($mails, $emails['titulaire']);
        $check($cancel !== '', 'SEC-02 : aucun avis avec lien d’annulation à l’ancienne adresse');
        $check($cancel_token_of($mails, $emails['nouvelle']) === '', 'SEC-02 : lien d’annulation envoyé à la nouvelle adresse');
        $stored = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like($cancel) . '%'));
        $check((int) $stored === 0, 'SEC-02 : jeton d’annulation stocké en clair');

        // GET : page de confirmation, rien n'est annulé.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_email_cancel' => $cancel);
        $r = $run(function () { Psc_Parents::maybe_show_email_cancel_page(); });
        $check($r['kind'] === 'die' && $r['status'] === 200 && strpos($r['value'], 'psc_cancel_email_change_link') !== false, 'SEC-02 : page de confirmation absente');
        $check($parent_row($pid)->pending_email === $emails['nouvelle'], 'SEC-02 : annulation faite par un simple GET');

        $r = $cancel_post($cancel);
        $check($r['kind'] === 'die' && empty($parent_row($pid)->pending_email), 'SEC-02 : annulation avant confirmation inopérante');
        $r = $cancel_post($cancel);
        $check($r['kind'] === 'die' && strpos($r['value'], 'déjà été utilisé') !== false, 'SEC-02 : jeton d’annulation réutilisable');

        /* ---------- Annulation après confirmation : ancienne adresse rétablie ---------- */
        $mails = array();
        $use_session($pid, $emails['titulaire']);
        $profile_post($pid, $emails['nouvelle']);
        $cancel = $cancel_token_of($mails, $emails['titulaire']);
        $confirm = $confirm_token_of($mails, $emails['nouvelle']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_pid' => (string) $pid, 'psc_email_token' => $confirm);
        $r = $run(function () { Psc_Parents::maybe_consume_email_change(); });
        $check($msg_of($r) === 'email_changed' && $parent_row($pid)->email === $emails['nouvelle'], 'SEC-02 données : confirmation du changement échouée');
        $epoch = psc_session_epoch($pid);
        $r = $cancel_post($cancel);
        $row = $parent_row($pid);
        $check($row->email === $emails['titulaire'], 'SEC-02 : ancienne adresse non rétablie après confirmation');
        $check(psc_session_epoch($pid) > $epoch, 'SEC-02 : sessions non révoquées au rétablissement');

        /* ---------- Ancienne adresse prise entre-temps : rien n'est écrasé ---------- */
        $mails = array();
        $use_session($pid, $emails['titulaire']);
        $profile_post($pid, $emails['nouvelle']);
        $cancel = $cancel_token_of($mails, $emails['titulaire']);
        $confirm = $confirm_token_of($mails, $emails['nouvelle']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_pid' => (string) $pid, 'psc_email_token' => $confirm);
        $run(function () { Psc_Parents::maybe_consume_email_change(); });
        $wpdb->update($t_par, array('second_parent_email' => $emails['titulaire']), array('id' => $occupant));
        $r = $cancel_post($cancel);
        $check($parent_row($pid)->email === $emails['nouvelle'] && strpos($r['value'], 'mairie') !== false, 'SEC-02 : ancienne adresse occupée, rétablissement inattendu ou message absent');
        /* ---------- RGPD-04/L1 : journal sans identité ---------- */
        $t_audit = psc_table('audit_log');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT action, resume, acteur_libelle FROM $t_audit WHERE (famille_id = %d OR (objet_type = 'demande' AND objet_id IN (SELECT r.id FROM $t_req r WHERE r.email = %s))) AND horodatage >= %s",
            $pid, $emails['demande'], gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        $check(count($rows) > 0, 'RGPD-04/L1 : aucune ligne de journal relevée pour le foyer de test');
        foreach ($rows as $row) {
            foreach (array('resume', 'acteur_libelle') as $col) {
                $val = (string) $row->$col;
                $check(strpos($val, '@') === false && stripos($val, 'Lot1') === false && stripos($val, 'Verification') === false,
                    "RGPD-04/L1 : donnée d'identité dans $col de {$row->action}");
            }
        }
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_audit WHERE action = 'famille.connexion' AND famille_id = %d AND acteur_libelle LIKE %s", $pid, '%(second parent)%')),
            'RGPD-04/L1 : connexion du second parent sans rôle dans le libellé');
    } catch (Throwable $e) {
        $failures[] = 'exception ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    } finally {
        remove_filter('pre_wp_mail', $capture_mail, 10);
        remove_filter('wp_redirect', $redirect, 99);
        remove_filter('wp_die_handler', $die_handler, 99);
        remove_filter('psc_rate_limit_enabled', '__return_false', 99);
        unset($_COOKIE[psc_session_cookie_name()]);
        Psc_Parents::reset_current_cache();
        $_GET = $_POST = $_REQUEST = array();
        $purge();
        $purge_requests();
        if ($saved_auto === null) delete_option('psc_auto_approve_requests'); else update_option('psc_auto_approve_requests', $saved_auto);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK.', $checks));
});
