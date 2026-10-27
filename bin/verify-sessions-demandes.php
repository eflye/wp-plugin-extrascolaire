<?php
/**
 * Script de vérification autonome — sessions des familles (identité de
 * connexion, changement d'adresse, signature), demandes d'inscription et
 * accès des intervenants, en conditions WP-CLI réelles. Les e-mails sont
 * interceptés (pre_wp_mail), les redirections et wp_die() sont capturés
 * pour lire la réponse sans terminer le processus.
 *
 * Origine : audit du 14/10/2026, lot 1 (ex-bin/verify-audit-lot1.php).
 * Les références SEC-*, RGPD-*, PERF-*, PRA-* renvoient à AUDIT.md.
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
 *  - RGPD-03/L1 : coordonnées bancaires d'une demande effacées au refus
 *    et à la validation (IBAN, BIC, titulaire, adresse du compte).
 *  - RGPD-01/L1 : une demande en attente de la mairie depuis plus de 180
 *    jours est purgée par cleanup(), pas une plus récente.
 *  - SEC-03 : écran intervenants — session révoquée dès que l'intervenant
 *    est désactivé, code de moins de 8 caractères refusé à la création ou
 *    à la modification (code existant conservé), identifiant obligatoire,
 *    limite d'échecs par intervenant.
 *  - RGPD-06 : liste intervenants — allergies transmises seulement pour un
 *    enfant attendu au midi, personnes autorisées seulement sur demande
 *    (semaine en cours).
 *  - SEC-07 : avec une clé hors base, sessions et jetons du portail sont
 *    signés avec elle ; un cookie signé avec le sel en base est refusé.
 *  - SEC-10 : au plus 3 demandes de changement d'adresse par jour et par
 *    foyer ; la 4e est refusée sans e-mail (email_change_rate).
 *  - PERF-07 : sans objet depuis 4.20.0 (RGPD-10) — le rapprochement des
 *    allergies a été supprimé avec le texte libre ; vérifié absent.
 *  - PRA-18 : en consultation mairie, la lecture AJAX du planning ne crée
 *    pas d'année scolaire (ensure_default() non appelé).
 *  - PRA-14/L1 : purge des demandes non confirmées au seuil de 7 jours
 *    calculé en heure du site, comme created_at.
 *  - RGPD-04/L1 : les lignes du journal écrites pour le foyer de test ne
 *    contiennent ni e-mail ni nom (résumé et libellé d'acteur).
 *
 * Usage :
 *   wp --require=bin/verify-sessions-demandes.php verify-sessions-demandes
 *
 * Crée puis supprime ses propres foyers (adresses @example.invalid). Les
 * lignes du journal d'audit, chaîné et en ajout seul, restent.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

if (!class_exists('Psc_Verify_Sessions_Demandes_Stop')) {
    class Psc_Verify_Sessions_Demandes_Stop extends Exception {
        public $kind;
        public $status;
        public function __construct($kind, $message, $status = 0) {
            parent::__construct((string) $message);
            $this->kind = (string) $kind;
            $this->status = (int) $status;
        }
    }
}

WP_CLI::add_command('verify-sessions-demandes', function () {

    if (!class_exists('Psc_Parents')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_par = psc_table('parents');
    $emails = array(
        'titulaire' => 'verify-sessions-titulaire@example.invalid',
        'second'    => 'verify-sessions-second@example.invalid',
        'nouvelle'  => 'verify-sessions-nouvelle@example.invalid',
        'autre'     => 'verify-sessions-autre@example.invalid',
        'occupant'  => 'verify-sessions-occupant@example.invalid',
        'demande'   => 'verify-sessions-demande@example.invalid',
        'sepa_refus' => 'verify-sessions-sepa-refus@example.invalid',
        'sepa_ok'    => 'verify-sessions-sepa-ok@example.invalid',
        'sidscm'     => 'verify-sessions-sidscm@example.invalid',
        'perf1'      => 'verify-sessions-perf1@example.invalid',
        'perf2'      => 'verify-sessions-perf2@example.invalid',
        'perf3'      => 'verify-sessions-perf3@example.invalid',
    );
    $t_req = psc_table('requests');
    $purge_requests = function () use ($wpdb, $emails, $t_req) {
        foreach (array('demande', 'sepa_refus', 'sepa_ok', 'perf1', 'perf2', 'perf3') as $k) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_req WHERE email = %s", $emails[$k])) as $rid) {
                Psc_Assurances::delete_pending_files((int) $rid);
                $wpdb->delete($t_req, array('id' => (int) $rid));
            }
        }
        // Foyer créé par la validation automatique (RGPD-03/L1).
        $fid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . psc_table('parents') . ' WHERE email = %s', $emails['sepa_ok']));
        if ($fid) Psc_Admin_Familles::delete_family($fid);
    };
    $purge_requests();

    // Liens d'annulation (SEC-02) émis pour les foyers de test : transients
    // à supprimer, leur valeur porte l'ancienne adresse de test.
    $purge_transients = function () use ($wpdb) {
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
            $wpdb->esc_like('_transient_psc_email_cancel_') . '%',
            '%' . $wpdb->esc_like('verify-sessions-') . '%'
        ));
        foreach ($names as $name) delete_transient(substr($name, strlen('_transient_')));
    };
    $purge_transients();

    // Registre des intervenants : un registre non vide désactive l'ancien
    // code partagé (SEC-03). Les intervenants de test laissés par un run
    // interrompu (erreur fatale, qui contourne finally) sont d'abord
    // retirés, puis la valeur d'origine est sauvegardée et restaurée à
    // l'identique en fin de run, y compris sur erreur fatale (shutdown).
    $test_intervenants = array('Verif Court', 'Verif Long');
    $leftover = get_option('psc_sidscm_intervenants', null);
    if (is_array($leftover)) {
        $clean = array_values(array_filter($leftover, function ($row) use ($test_intervenants) {
            return !(is_array($row) && in_array($row['nom'] ?? '', $test_intervenants, true));
        }));
        if (count($clean) !== count($leftover)) {
            if ($clean) update_option('psc_sidscm_intervenants', $clean); else delete_option('psc_sidscm_intervenants');
        }
    }
    $saved_auto = get_option('psc_auto_approve_requests', null);
    $saved_intervenants = get_option('psc_sidscm_intervenants', null);
    $options_restored = false;
    $restore_options = function () use (&$options_restored, $saved_intervenants, $saved_auto) {
        if ($options_restored) return;
        $options_restored = true;
        if ($saved_intervenants === null) delete_option('psc_sidscm_intervenants'); else update_option('psc_sidscm_intervenants', $saved_intervenants);
        if ($saved_auto === null) delete_option('psc_auto_approve_requests'); else update_option('psc_auto_approve_requests', $saved_auto);
    };
    register_shutdown_function($restore_options);

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
    $redirect = function ($location) { throw new Psc_Verify_Sessions_Demandes_Stop('redirect', $location, 302); };
    $die_handler = function () {
        return function ($message, $title = '', $args = array()) {
            $status = is_array($args) && isset($args['response']) ? (int) $args['response'] : 500;
            throw new Psc_Verify_Sessions_Demandes_Stop('die', is_scalar($message) ? (string) $message : '', $status);
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
        } catch (Psc_Verify_Sessions_Demandes_Stop $stop) {
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
            'profil_nom'  => 'Verifsessions',
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
    $submit_request = function ($nom, $email_key = 'demande', $sepa = false) use ($run, $emails) {
        wp_set_current_user(0);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = array();
        $_POST = $_REQUEST = array(
            'action'             => 'psc_submit_request',
            '_wpnonce'           => wp_create_nonce('psc_submit_request'),
            'req_email'          => $emails[$email_key],
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
        if ($sepa) {
            $_POST += array(
                'payment_mode'            => 'prelevement',
                'sepa_reglement_accepted' => '1',
                'sepa_titulaire'          => 'Titulaire Verification',
                'sepa_adresse'            => '1 rue de la Mairie',
                'sepa_code_postal'        => '95000',
                'sepa_ville'              => 'Montgeroult',
                'sepa_iban'               => 'FR7630006000011234567890189',
                'sepa_bic'                => 'BNPAFRPP',
            );
            $_POST['payment_mode'] = 'prelevement';
            $_REQUEST = $_POST;
        }
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

        /* ---------- RGPD-03/L1 : coordonnées bancaires effacées à la décision ---------- */
        $sepa_cols = array('sepa_iban', 'sepa_bic', 'sepa_titulaire', 'sepa_adresse', 'sepa_code_postal', 'sepa_ville');
        $sepa_left = function ($rid) use ($wpdb, $t_req, $sepa_cols) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $rid), ARRAY_A);
            if (!$row) return array('demande absente');
            return array_keys(array_filter(array_intersect_key($row, array_flip($sepa_cols)), function ($v) { return $v !== null; }));
        };
        // Refus par la mairie.
        $mails = array();
        $submit_request('Refus', 'sepa_refus', true);
        list($rid, $tok) = $verify_token_of($mails);
        $check($rid > 0 && count($sepa_left($rid)) === 6, 'RGPD-03/L1 données : demande SEPA incomplète avant refus');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_req' => (string) $rid, 'psc_vtoken' => $tok);
        $run(function () { Psc_Requests::maybe_verify(); });
        $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
        wp_set_current_user($admins ? (int) $admins[0] : 0);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = array();
        $_POST = $_REQUEST = array('action' => 'psc_reject_request', '_wpnonce' => wp_create_nonce('psc_reject_request'), 'id' => (string) $rid, 'note' => '');
        $run(function () { do_action('admin_post_psc_reject_request'); });
        wp_set_current_user(0);
        $check($wpdb->get_var($wpdb->prepare("SELECT status FROM $t_req WHERE id = %d", $rid)) === 'rejected', 'RGPD-03/L1 données : refus non enregistré');
        $check($sepa_left($rid) === array(), 'RGPD-03/L1 : coordonnées bancaires conservées après refus : ' . implode(', ', $sepa_left($rid)));
        // Validation (automatique, même chemin approve_request() que la mairie).
        update_option('psc_auto_approve_requests', 1);
        $mails = array();
        $submit_request('Valide', 'sepa_ok', true);
        list($rid, $tok) = $verify_token_of($mails);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = array('psc_req' => (string) $rid, 'psc_vtoken' => $tok);
        $run(function () { Psc_Requests::maybe_verify(); });
        unset($_COOKIE[psc_session_cookie_name()]);
        Psc_Parents::reset_current_cache();
        update_option('psc_auto_approve_requests', 0);
        $check($wpdb->get_var($wpdb->prepare("SELECT status FROM $t_req WHERE id = %d", $rid)) === 'approved', 'RGPD-03/L1 données : validation non enregistrée');
        $check($sepa_left($rid) === array(), 'RGPD-03/L1 : coordonnées bancaires conservées après validation : ' . implode(', ', $sepa_left($rid)));
        $fam = Psc_Parents::get_by_email($emails['sepa_ok']);
        $check($fam && !empty($fam->sepa_iban) && $fam->sepa_titulaire === 'Titulaire Verification', 'RGPD-03/L1 : coordonnées bancaires non reportées sur le compte famille');
        $_GET = array();

        /* ---------- RGPD-01/L1 : demandes en attente purgées à 180 jours ---------- */
        $wpdb->update($t_req, array('created_at' => wp_date('Y-m-d H:i:s', time() - 181 * DAY_IN_SECONDS)), array('id' => $first_id));
        $fresh = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE status = 'pending' AND created_at >= %s", wp_date('Y-m-d H:i:s', time() - 179 * DAY_IN_SECONDS)));
        Psc_Requests::cleanup();
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE id = %d", $first_id)) === 0, 'RGPD-01/L1 : demande en attente depuis 181 jours conservée');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_req WHERE status = 'pending' AND created_at >= %s", wp_date('Y-m-d H:i:s', time() - 179 * DAY_IN_SECONDS))) === $fresh, 'RGPD-01/L1 : demande en attente récente purgée');

        /* ---------- SEC-03 : écran intervenants ---------- */
        $auth = new ReflectionMethod('Psc_Sidscm', 'authenticate');
        $auth->setAccessible(true);
        $skey = new ReflectionMethod('Psc_Sidscm', 'session_key');
        $skey->setAccessible(true);
        $bad = new ReflectionMethod('Psc_Sidscm', 'count_bad_code');
        $bad->setAccessible(true);
        $rows = Psc_Sidscm::sanitize_intervenants(array(
            array('nom' => 'Verif Court', 'code' => 'court', 'active' => 1),
            array('nom' => 'Verif Long', 'code' => 'Code-Long-123', 'active' => 1),
        ));
        $check(count($rows) === 1 && $rows[0]['nom'] === 'Verif Long', 'SEC-03 : intervenant créé avec un code de moins de 8 caractères');
        $check(Psc_Sidscm::rejected_codes() === array('Verif Court'), 'SEC-03 : code trop court non signalé');
        $iid = (string) $rows[0]['id'];
        update_option('psc_sidscm_intervenants', $rows);
        $kept = Psc_Sidscm::sanitize_intervenants(array(array('id' => $iid, 'nom' => 'Verif Long', 'code' => '1234', 'active' => 1)));
        $check(count($kept) === 1 && wp_check_password('Code-Long-123', $kept[0]['code_hash']), 'SEC-03 : code existant perdu après une modification trop courte');
        update_option('psc_sidscm_intervenants', $rows);
        $check($auth->invoke(null, '', '', 'Code-Long-123') === false, 'SEC-03 : code accepté sans intervenant désigné');
        $actor = $auth->invoke(null, '', $iid, 'Code-Long-123');
        $check(is_array($actor) && $actor['id'] === $iid, 'SEC-03 : code valide refusé pour son intervenant');
        $token = wp_generate_password(48, false, false);
        set_transient($skey->invoke(null, $token), $actor, HOUR_IN_SECONDS);
        $check(is_array($auth->invoke(null, $token, $iid, '')), 'SEC-03 : session valide refusée');
        $rows[0]['active'] = 0;
        update_option('psc_sidscm_intervenants', $rows);
        $check($auth->invoke(null, $token, $iid, '') === false, 'SEC-03 : session d’un intervenant désactivé acceptée');
        $check(get_transient($skey->invoke(null, $token)) === false, 'SEC-03 : session d’un intervenant désactivé non supprimée');
        // Limite d'échecs par intervenant (indépendante de l'adresse IP).
        add_filter('psc_rate_limit_enabled', '__return_true', 100);
        $rl_key = 'psc_rl_' . md5('sidscm_bad_id_' . $iid);
        delete_transient($rl_key);
        for ($i = 0; $i < Psc_Sidscm::BAD_CODES_PER_ID; $i++) $bad->invoke(null, $iid);
        $check(psc_rate_limit_reached('sidscm_bad_id_' . $iid, Psc_Sidscm::BAD_CODES_PER_ID), 'SEC-03 : limite d’échecs par intervenant non atteinte');
        $check($bad->invoke(null, $iid) === false, 'SEC-03 : échec au-delà de la limite par intervenant accepté');
        remove_filter('psc_rate_limit_enabled', '__return_true', 100);
        delete_transient($rl_key);
        delete_transient('psc_rl_' . md5('sidscm_bad_' . psc_client_ip()));

        /* ---------- RGPD-06 : minimisation de la liste intervenants ---------- */
        $open_days = psc_open_days(psc_week_start(current_time('Y-m-d')));
        $year_id = $open_days ? Psc_School_Years::id_for_date(reset($open_days)) : 0;
        if ($open_days && $year_id) {
            $sid_family = Psc_Parents::create($emails['sidscm'], 'Verifsessions', array('second_parent_prenom' => 'Second', 'second_parent_nom' => 'Verifsessions', 'second_parent_telephone' => '0612345678'));
            $t_child = psc_table('children');
            $mk = function ($prenom) use ($wpdb, $t_child, $sid_family) {
                $wpdb->insert($t_child, array('parent_id' => $sid_family, 'nom' => 'Verifsessions', 'prenom' => $prenom, 'food_allergy_signal' => 1, 'created_at' => current_time('mysql')));
                return (int) $wpdb->insert_id;
            };
            $garderie = $mk('Garderie');
            $cantine = $mk('Cantine');
            Psc_School_Years::enroll($garderie, $year_id, 'CP');
            Psc_School_Years::enroll($cantine, $year_id, 'CP');
            foreach ($open_days as $date) {
                Psc_Planning::toggle_exception($garderie, $date, 'GS', true, true);
                Psc_Planning::toggle_exception($cantine, $date, 'CANT', true, true);
            }
            Psc_Planning::flush_cache();
            $by_id = function (array $week) {
                $out = array();
                foreach ($week['children'] as $c) $out[$c['id']] = $c;
                return $out;
            };
            $with = $by_id(Psc_Sidscm::week_children($open_days, true));
            $without = $by_id(Psc_Sidscm::week_children($open_days, false));
            $check(isset($with[$garderie], $with[$cantine]), 'RGPD-06 données : enfants de test absents de la liste');
            if (isset($with[$garderie], $with[$cantine])) {
                $check($with[$garderie]['allergies'] === null, 'RGPD-06 : allergies transmises pour un enfant de garderie seule');
                $check(is_string($with[$cantine]['allergies']) && $with[$cantine]['allergies'] !== '', 'RGPD-06 : signalement d’allergie absent pour un enfant de cantine');
                $check(!empty($with[$garderie]['authorized']), 'RGPD-06 : personnes autorisées absentes pour la semaine en cours');
                $check(empty($with[$cantine]['authorized']), 'RGPD-06 : personnes autorisées transmises hors garderie du soir');
                $check(empty($without[$garderie]['authorized']), 'RGPD-06 : personnes autorisées transmises hors semaine en cours');
            }
            Psc_Admin_Familles::delete_family($sid_family);
        } else {
            WP_CLI::log('RGPD-06 : semaine sans jour ouvert, vérification sautée.');
        }

        $pid = Psc_Parents::create($emails['titulaire'], 'Verifsessions', array('second_parent_email' => $emails['second']));
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
        /* ---------- SEC-07 : signature avec la clé hors base ---------- */
        $current_email = (string) $parent_row($pid)->email; // modifiée par la section SEC-02
        $use_session($pid, $current_email);
        $db_cookie = $_COOKIE[psc_session_cookie_name()];
        $db_nonce = psc_parent_nonce('psc_parent_update_profile', $pid);
        $outside = function ($secrets) { return array('constante' => 'verify-sessions-cle-hors-base-0123456789') + $secrets; };
        add_filter('psc_encryption_secrets', $outside);
        $check(psc_encryption_key_outside_db() && psc_session_signing_key() !== wp_salt('psc_session'), 'SEC-07 : clé hors base non utilisée pour signer');
        $set_cookie($db_cookie);
        $check(Psc_Parents::current() === null, 'SEC-07 : cookie signé avec le sel en base accepté malgré la clé hors base');
        $check(!psc_verify_parent_nonce('psc_parent_update_profile', $pid, $db_nonce), 'SEC-07 : jeton du portail signé avec le sel en base accepté');
        $use_session($pid, $current_email);
        $check(Psc_Parents::current() && Psc_Parents::session_login_role() === 'titulaire', 'SEC-07 : session signée avec la clé hors base refusée');
        remove_filter('psc_encryption_secrets', $outside);
        $check(psc_session_signing_key() === wp_salt('psc_session'), 'SEC-07 : repli sur wp_salt absent sans clé hors base');

        /* ---------- SEC-10 : limite des changements d'adresse ---------- */
        $wpdb->update($t_par, array('email' => $emails['titulaire'], 'pending_email' => null), array('id' => $pid));
        add_filter('psc_rate_limit_enabled', '__return_true', 100);
        $rl = 'psc_rl_' . md5('email_change_' . $pid);
        delete_transient($rl);
        $use_session($pid, $emails['titulaire']);
        $codes = array();
        for ($i = 0; $i < 4; $i++) $codes[] = $msg_of($profile_post($pid, $emails['nouvelle']));
        $mails = array();
        $codes[] = $msg_of($profile_post($pid, $emails['nouvelle']));
        remove_filter('psc_rate_limit_enabled', '__return_true', 100);
        delete_transient($rl);
        $check(array_slice($codes, 0, 3) === array_fill(0, 3, 'profil_updated_email_pending'), 'SEC-10 : demandes légitimes refusées : ' . implode(', ', $codes));
        $check($codes[3] === 'email_change_rate' && $codes[4] === 'email_change_rate', 'SEC-10 : 4e demande du jour acceptée : ' . implode(', ', $codes));
        $check(count($mails) === 0, 'SEC-10 : e-mail envoyé au-delà de la limite');

        /* ---------- PERF-07 : rapprochement supprimé avec le texte libre (RGPD-10) ---------- */
        $check(!method_exists('Psc_Requests', 'allergies_to_reconcile') && !has_action('admin_post_psc_reconcile_request_allergies'), 'PERF-07 : le rapprochement des allergies existe encore');

        /* ---------- PRA-14/L1 : horloge de la purge des demandes ---------- */
        $mk_unverified = function ($age) use ($wpdb, $t_req, $emails) {
            $wpdb->insert($t_req, array('email' => $emails['demande'], 'nom' => 'Horloge', 'prenom' => 'Test', 'status' => 'unverified', 'verified' => 0,
                'created_at' => wp_date('Y-m-d H:i:s', time() - $age)));
            return (int) $wpdb->insert_id;
        };
        $old_req = $mk_unverified(7 * DAY_IN_SECONDS + 30 * MINUTE_IN_SECONDS);
        $young_req = $mk_unverified(7 * DAY_IN_SECONDS - 30 * MINUTE_IN_SECONDS);
        Psc_Requests::cleanup();
        $check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_req WHERE id = %d", $old_req)), 'PRA-14/L1 : demande non confirmée de 7 j 30 min conservée');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_req WHERE id = %d", $young_req)), 'PRA-14/L1 : demande non confirmée de 6 j 23 h 30 purgée');
        if (wp_timezone()->getOffset(new DateTime('now', new DateTimeZone('UTC'))) === 0) {
            WP_CLI::log('PRA-14/L1 : fuseau du site en UTC, le décalage d’horloge n’est pas exercé.');
        }

        /* ---------- PRA-18 : consultation en lecture seule ---------- */
        $ajax_year = new ReflectionMethod('Psc_Frontend_Inscriptions', 'ajax_year');
        $ajax_year->setAccessible(true);
        $imp_cache = new ReflectionProperty('Psc_Impersonation', 'active_cache');
        $imp_cache->setAccessible(true);
        $imp_set = new ReflectionProperty('Psc_Impersonation', 'active_cache_set');
        $imp_set->setAccessible(true);
        $year_queries = 0;
        $spy = function ($q) use (&$year_queries) {
            if (stripos($q, 'SHOW TABLES LIKE') !== false && strpos($q, psc_table('school_years')) !== false) $year_queries++;
            return $q;
        };
        add_filter('query', $spy);
        $imp_cache->setValue(null, (object) array('id' => 0, 'family_id' => $pid));
        $imp_set->setValue(null, true);
        $run(function () use ($ajax_year) { $ajax_year->invoke(null); });
        $check($year_queries === 0, 'PRA-18 : ensure_default() appelé pendant une consultation');
        $imp_cache->setValue(null, null);
        $imp_set->setValue(null, false);
        $run(function () use ($ajax_year) { $ajax_year->invoke(null); });
        $check($year_queries === 1, 'PRA-18 : ensure_default() non appelé hors consultation (sonde inopérante)');
        remove_filter('query', $spy);

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
                $check(strpos($val, '@') === false && stripos($val, 'Verifsessions') === false && stripos($val, 'Verification') === false,
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
        $purge_transients();
        $restore_options();
    }
    $check(get_option('psc_sidscm_intervenants', null) === $saved_intervenants, 'nettoyage : registre des intervenants non restauré à l’identique');
    // Valeur scalaire : le cache d'options peut rendre 0 pour '0'.
    $check(($saved_auto === null) === (get_option('psc_auto_approve_requests', null) === null) && (string) get_option('psc_auto_approve_requests', '') === (string) $saved_auto, 'nettoyage : réglage de validation automatique non restauré');
    $check((int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s AND (option_name LIKE %s OR option_name = 'psc_sidscm_intervenants')",
        '%' . $wpdb->esc_like('verify-sessions-') . '%', $wpdb->esc_like('_transient_') . '%'
    )) === 0, 'nettoyage : transient de test restant');

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK.', $checks));
});
