<?php
/**
 * Script de vérification autonome (P2-02) — écritures composées sous
 * concurrence et en cas d'échec intermédiaire. Même rôle que
 * bin/verify-promotion-logic.php : ce script joue celui du test unitaire,
 * en conditions WP-CLI réelles.
 *
 * La concurrence est reproduite avec une SECONDE connexion MySQL, qui
 * tient le verrou qu'une requête concurrente tiendrait ; l'échec
 * intermédiaire, en sabotant une requête précise par le filtre « query ».
 *
 * Vérifie :
 *  - validation d'une demande : une seconde validation (objet périmé, ou
 *    concurrente) ne crée rien ; un échec à mi-course ne laisse ni famille,
 *    ni enfant, ni e-mail ; un refus ne remplace pas une validation ;
 *  - planning : un échec au milieu d'un changement de rythme ne détruit
 *    rien ; un clic concurrent sur le même enfant ne passe pas outre le
 *    verrou et répond « error » ; aucune réponse ne prétend un succès que
 *    la base n'a pas enregistré.
 *
 * Usage :
 *   wp --require=bin/verify-write-integrity.php verify-write-integrity
 *
 * Données scopées par des adresses e-mail dédiées, purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Psc_Verify_Stop extends Exception {}

WP_CLI::add_command('verify-write-integrity', function () {

    if (!class_exists('Psc_Requests') || !class_exists('Psc_Planning')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_req    = psc_table('requests');
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_pat    = psc_table('pattern');
    $t_exc    = psc_table('exception');
    $emails   = array('verify-integrity-a@example.invalid', 'verify-integrity-b@example.invalid', 'verify-integrity-c@example.invalid');

    $purge = function () use ($wpdb, $emails, $t_req, $t_parent, $t_child, $t_pat, $t_exc) {
        foreach ($emails as $email) {
            $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
            if ($pid) {
                foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $pid)) as $cid) {
                    $wpdb->delete($t_pat, array('child_id' => (int) $cid));
                    $wpdb->delete($t_exc, array('child_id' => (int) $cid));
                    $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
                    $wpdb->delete($t_child, array('id' => (int) $cid));
                }
                $wpdb->delete($t_parent, array('id' => $pid));
            }
            $wpdb->delete($t_req, array('email' => $email));
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Compte des e-mails partis, sans rien envoyer.
    $mails = 0;
    add_filter('pre_wp_mail', function () use (&$mails) { $mails++; return true; }, 99);

    $approve = new ReflectionMethod('Psc_Requests', 'approve_request');
    $approve->setAccessible(true);
    $new_request = function ($email, array $children) use ($wpdb, $t_req) {
        $wpdb->insert($t_req, array(
            'email' => $email, 'nom' => 'VerifyIntegrity', 'prenom' => 'Famille', 'status' => 'pending',
            'children_json' => wp_json_encode($children), 'created_at' => current_time('mysql'),
        ));
        return Psc_Requests::get((int) $wpdb->insert_id);
    };
    $count_children = function ($email) use ($wpdb, $t_parent, $t_child) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_child c JOIN $t_parent p ON p.id = c.parent_id WHERE p.email = %s", $email
        ));
    };
    $status_of = function ($id) use ($wpdb, $t_req) {
        return $wpdb->get_var($wpdb->prepare("SELECT status FROM $t_req WHERE id = %d", $id));
    };
    // Seconde connexion : joue la requête concurrente qui tient un verrou.
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $wpdb->query('SET SESSION innodb_lock_wait_timeout = 1');
    $suppress = $wpdb->suppress_errors(true);

    try {
        $child = array('nom' => 'Integrity', 'prenom' => 'Lou', 'classe' => 'CE1', 'food_allergy_signal' => 1);

        // 1. Deux validations successives de la même demande (objet périmé :
        //    la seconde a lu « pending » avant la première).
        $req = $new_request($emails[0], array($child));
        $first = $approve->invoke(null, $req, Psc_Requests::children_of($req), false);
        $check(!is_wp_error($first) && $first, 'validation : première validation refusée');
        $mails_after_first = $mails;
        $second = $approve->invoke(null, $req, Psc_Requests::children_of($req), false);
        $check(is_wp_error($second) && $second->get_error_code() === 'psc_request_already_decided', 'validation : seconde validation acceptée');
        $check($count_children($emails[0]) === 1, 'validation : enfant dupliqué (' . $count_children($emails[0]) . ')');
        $check($mails === $mails_after_first, 'validation : e-mail envoyé par la seconde validation');

        // 2. Validation pendant qu'une autre tient la demande (verrou).
        $req = $new_request($emails[1], array($child));
        $other->query('START TRANSACTION');
        $other->get_var($other->prepare("SELECT status FROM $t_req WHERE id = %d FOR UPDATE", $req->id));
        $concurrent = $approve->invoke(null, $req, Psc_Requests::children_of($req), false);
        $other->query('ROLLBACK');
        $check(is_wp_error($concurrent), 'concurrence : validation passée outre le verrou');
        $check($count_children($emails[1]) === 0, 'concurrence : enfant créé malgré le verrou');
        $check($status_of($req->id) === 'pending', 'concurrence : demande modifiée malgré le verrou');

        // 3. Échec au milieu de la validation : le second enfant ne s'insère pas.
        $mails_before = $mails;
        $sabotage = function ($sql) use ($t_child) {
            return (strpos($sql, "INSERT INTO `$t_child`") === 0 && strpos($sql, 'Sabotage') !== false) ? 'SELECT * FROM psc_table_inexistante' : $sql;
        };
        add_filter('query', $sabotage);
        $req = $new_request($emails[2], array($child, array('nom' => 'Sabotage', 'prenom' => 'Noa', 'classe' => 'CE2')));
        $failed = $approve->invoke(null, $req, Psc_Requests::children_of($req), false);
        remove_filter('query', $sabotage);
        $check(is_wp_error($failed), 'échec intermédiaire : validation annoncée réussie');
        $check(!$wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $emails[2])), 'échec intermédiaire : famille créée');
        $check($count_children($emails[2]) === 0, 'échec intermédiaire : enfant créé');
        $check($status_of($req->id) === 'pending', 'échec intermédiaire : demande clôturée');
        $check($mails === $mails_before, 'échec intermédiaire : alerte alimentation envoyée pour un enfant jamais créé');

        // 4. Refus d'une demande déjà validée (lue « pending » juste avant).
        $validated = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE email = %s", $emails[0]));
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        wp_set_current_user((int) $admins[0]->ID);
        $_POST = $_REQUEST = array('id' => (string) $validated->id, 'notify' => '1', '_wpnonce' => wp_create_nonce('psc_reject_request'));
        $read = new ReflectionMethod('Psc_Requests', 'get');
        $redirect = function ($location) { throw new Psc_Verify_Stop($location); };
        // WP-CLI signale toute redirection par un avertissement : la nôtre
        // est interceptée, le sien est retiré pour ce processus.
        remove_all_filters('wp_redirect');
        add_filter('wp_redirect', $redirect, 99);
        // Simule la lecture périmée : la demande paraît encore en attente.
        $stale = function ($sql) use ($t_req, $validated) {
            return strpos($sql, "SELECT * FROM $t_req WHERE id = " . (int) $validated->id) === 0
                ? str_replace('SELECT *', "SELECT id, email, 'pending' AS status", $sql) : $sql;
        };
        add_filter('query', $stale);
        $mails_before = $mails;
        $location = '';
        try {
            Psc_Requests::handle_reject();
        } catch (Psc_Verify_Stop $stop) {
            $location = $stop->getMessage();
        }
        remove_filter('query', $stale);
        remove_filter('wp_redirect', $redirect, 99);
        wp_set_current_user(0);
        $_POST = $_REQUEST = array();
        $check($status_of($validated->id) === 'approved', 'refus : une demande validée est passée « refusée »');
        $check($mails === $mails_before, 'refus : e-mail de refus envoyé pour une demande validée');
        $check(strpos($location, 'psc_msg=invalid') !== false, 'refus : réponse « refusée » alors que rien n’a changé (' . $location . ')');

        // 5. Planning : échec au milieu d'un changement de rythme.
        $cid = (int) $wpdb->get_var($wpdb->prepare("SELECT c.id FROM $t_child c JOIN $t_parent p ON p.id = c.parent_id WHERE p.email = %s", $emails[0]));
        $year = Psc_School_Year::active();
        Psc_Planning::toggle_pattern($cid, $year->year_key, 1, 'GM', true);
        $sabotage_pat = function ($sql) use ($t_pat) {
            return (strpos($sql, "INSERT INTO $t_pat") !== false && strpos($sql, "'FORF'") !== false) ? 'SELECT * FROM psc_table_inexistante' : $sql;
        };
        add_filter('query', $sabotage_pat);
        $result = Psc_Planning::toggle_pattern($cid, $year->year_key, 1, 'FORF', true);
        remove_filter('query', $sabotage_pat);
        $check($result['status'] === 'error', 'rythme : échec annoncé comme ' . $result['status']);
        $pats = $wpdb->get_col($wpdb->prepare("SELECT service_code FROM $t_pat WHERE child_id = %d AND weekday = 1", $cid));
        $check($pats === array('GM'), 'rythme partiellement détruit : ' . wp_json_encode($pats));

        // 6. Planning : clic concurrent sur le même enfant.
        $other->query('START TRANSACTION');
        $other->get_var($other->prepare("SELECT id FROM $t_child WHERE id = %d FOR UPDATE", $cid));
        $result = Psc_Planning::toggle_pattern($cid, $year->year_key, 1, 'GS', true);
        $exc_result = Psc_Planning::toggle_exception($cid, Psc_School_Year::school_days_in_month(substr($year->date_start, 0, 7))[0] ?? $year->date_start, 'GS', true, true);
        $other->query('ROLLBACK');
        $check($result['status'] === 'error', 'rythme concurrent : réponse ' . $result['status']);
        $check(in_array($exc_result['status'], array('error', 'day_closed'), true), 'exception concurrente : réponse ' . $exc_result['status']);
        $pats = $wpdb->get_col($wpdb->prepare("SELECT service_code FROM $t_pat WHERE child_id = %d AND weekday = 1", $cid));
        $check($pats === array('GM'), 'rythme concurrent : écriture passée outre le verrou : ' . wp_json_encode($pats));

        // Et sans concurrence, la même écriture réussit.
        $result = Psc_Planning::toggle_pattern($cid, $year->year_key, 1, 'GS', true);
        $check($result['status'] === 'ok', 'rythme : écriture normale en échec (' . $result['status'] . ')');
    } finally {
        $wpdb->suppress_errors($suppress);
        $other->close();
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Écritures composées : $checks vérifications.");
});
