<?php
/**
 * Script de vérification autonome — clé de chiffrement sortie de la base
 * et rechiffrement (Psc_Key_Rotation, `wp psc chiffrement`). Même rôle que
 * bin/verify-write-integrity.php : celui du test unitaire, en conditions
 * WP-CLI réelles.
 *
 * Les clés sont simulées par le filtre psc_encryption_secrets (une
 * constante ne se redéclare pas dans un même processus). Les opérations
 * sont restreintes aux lignes du script : les IBAN réels du site ne sont
 * jamais relus ni réécrits.
 *
 * Vérifie :
 *  - sans constante, la clé vient de la base ;
 *  - constante déclarée : le site lit encore les valeurs de l'ancienne clé ;
 *  - simulation : compte sans rien écrire ;
 *  - rechiffrement : valeurs lisibles avec la seule nouvelle clé, plus avec
 *    l'ancienne ; IBAN hérité en clair chiffré ; valeur illisible intacte ;
 *    relance sans effet ; rotation entre deux constantes ;
 *  - une valeur modifiée entre la lecture et l'écriture n'est pas écrasée.
 *
 * Usage :
 *   wp --require=bin/verify-key-rotation.php verify-key-rotation
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-key-rotation', function () {

    if (!class_exists('Psc_Key_Rotation') || !function_exists('psc_decrypt_with_source')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $emails   = array('verify-key-1@example.invalid', 'verify-key-2@example.invalid', 'verify-key-3@example.invalid', 'verify-key-4@example.invalid');
    $t_parent = psc_table('parents');
    $t_req    = psc_table('requests');
    $org_saved = get_option(Psc_Key_Rotation::OPTION, false);

    $purge = function () use ($wpdb, $emails, $t_parent, $t_req) {
        foreach ($emails as $e) {
            $wpdb->delete($t_parent, array('email' => $e));
            $wpdb->delete($t_req, array('email' => $e));
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    $keys = array();
    $use = function (array $secrets) use (&$keys) { $keys = $secrets; };
    $filter = function () use (&$keys) { return $keys; };
    add_filter('psc_encryption_secrets', $filter, 99);

    $iban = 'FR7630006000011234567890189';
    $s0 = 'secret-en-base-' . wp_generate_password(12, false);
    $s1 = 'constante-' . wp_generate_password(12, false);
    $s2 = 'constante-suivante-' . wp_generate_password(12, false);
    $read = function ($table, $id) use ($wpdb) {
        return $wpdb->get_var($wpdb->prepare('SELECT sepa_iban FROM ' . psc_table($table) . ' WHERE id = %d', $id));
    };

    try {
        // 0. Chaîne réelle (sans filtre) : le secret tiré de la base reste
        //    lisible, qu'une constante soit déclarée ou non.
        remove_filter('psc_encryption_secrets', $filter, 99);
        $real = psc_encryption_secrets();
        $check(isset($real['base']) && $real['base'] === wp_salt('psc_sepa'), 'chaîne réelle : secret de la base absent');
        // Variable d'environnement (WordPress en conteneur) : clé courante
        // hors de la base, le secret de la base restant lisible.
        if (!defined('PSC_ENCRYPTION_KEY')) {
            putenv('PSC_ENCRYPTION_KEY=cle-environnement-verif');
            $env = psc_encryption_secrets();
            $check(key($env) === 'environnement' && reset($env) === 'cle-environnement-verif', 'environnement : variable non retenue comme clé courante');
            $check(psc_encryption_key_outside_db() && isset($env['base']), 'environnement : clé hors base ou repli de la base absents');
            putenv('PSC_ENCRYPTION_KEY');
            $check(psc_encryption_key_source() === 'base', 'environnement : variable retirée mais encore utilisée');
        }
        add_filter('psc_encryption_secrets', $filter, 99);

        // 1. Situation de départ : pas de constante, clé tirée de la base.
        $use(array('base' => $s0));
        $check(psc_encryption_key_source() === 'base', 'source : base attendue sans constante');
        $enc0 = psc_encrypt($iban);
        $ids = array();
        foreach (array(0, 1) as $i) {
            $wpdb->insert($t_parent, array('email' => $emails[$i], 'nom' => 'VerifyKey', 'active' => 1, 'sepa_iban' => $enc0, 'created_at' => current_time('mysql')));
            $ids['p' . $i] = (int) $wpdb->insert_id;
        }
        $wpdb->insert($t_parent, array('email' => $emails[2], 'nom' => 'VerifyKey', 'active' => 1, 'sepa_iban' => $iban, 'created_at' => current_time('mysql'))); // hérité en clair
        $ids['clair'] = (int) $wpdb->insert_id;
        $use(array('base' => 'une-cle-perdue'));
        $foreign = psc_encrypt($iban);
        $use(array('base' => $s0));
        $wpdb->insert($t_parent, array('email' => $emails[3], 'nom' => 'VerifyKey', 'active' => 1, 'sepa_iban' => $foreign, 'created_at' => current_time('mysql')));
        $ids['illisible'] = (int) $wpdb->insert_id;
        $wpdb->insert($t_req, array('email' => $emails[0], 'nom' => 'VerifyKey', 'status' => 'pending', 'sepa_iban' => $enc0, 'created_at' => current_time('mysql')));
        $ids['req'] = (int) $wpdb->insert_id;
        update_option(Psc_Key_Rotation::OPTION, $enc0);
        $scope = array('parents' => array($ids['p0'], $ids['p1'], $ids['clair'], $ids['illisible']), 'requests' => array($ids['req']), 'option' => true);

        // 2. Constante déclarée : lecture assurée pendant la transition.
        $use(array('constante' => $s1, 'base' => $s0));
        $check(psc_encryption_key_source() === 'constante', 'source : constante attendue');
        $check(psc_decrypt($read('parents', $ids['p0'])) === $iban, 'transition : ancienne valeur illisible');

        // 3. Simulation.
        $before = $read('parents', $ids['p0']);
        $r = Psc_Key_Rotation::run(true, $scope);
        $check($r['ancienne_cle'] === 4 && $r['clair'] === 1 && $r['illisible'] === 1 && $r['rechiffre'] === 0, 'simulation : ' . wp_json_encode($r));
        $check($read('parents', $ids['p0']) === $before, 'simulation : valeur réécrite');

        // 4. Rechiffrement.
        $r = Psc_Key_Rotation::run(false, $scope);
        $check($r['rechiffre'] === 5 && $r['erreur'] === 0 && $r['illisible'] === 1, 'rechiffrement : ' . wp_json_encode($r));
        $use(array('constante' => $s1));
        foreach (array('p0', 'p1', 'clair') as $k) {
            $check(psc_decrypt($read('parents', $ids[$k])) === $iban, "rechiffrement : $k illisible avec la seule nouvelle clé");
        }
        $check(psc_decrypt($read('requests', $ids['req'])) === $iban, 'rechiffrement : demande illisible avec la seule nouvelle clé');
        $check(psc_decrypt(get_option(Psc_Key_Rotation::OPTION)) === $iban, 'rechiffrement : IBAN du créancier illisible avec la seule nouvelle clé');
        $check(strpos((string) $read('parents', $ids['clair']), 'psc1:') === 0, 'rechiffrement : IBAN hérité resté en clair');
        $use(array('base' => $s0));
        $check(psc_decrypt($read('parents', $ids['p0'])) === null, 'rechiffrement : encore lisible avec la clé de la base');
        $check($read('parents', $ids['illisible']) === $foreign, 'rechiffrement : valeur illisible modifiée');

        // 5. Relance sans effet.
        $use(array('constante' => $s1, 'base' => $s0));
        $again = $read('parents', $ids['p0']);
        $r = Psc_Key_Rotation::run(false, $scope);
        $check($r['rechiffre'] === 0 && $r['courant'] === 5, 'relance : ' . wp_json_encode($r));
        $check($read('parents', $ids['p0']) === $again, 'relance : valeur réécrite');

        // 6. Rotation entre deux constantes.
        $use(array('constante' => $s2, 'constante_precedente' => $s1, 'base' => $s0));
        $r = Psc_Key_Rotation::run(false, $scope);
        $check($r['rechiffre'] === 5, 'rotation : ' . wp_json_encode($r));
        $use(array('constante' => $s2));
        $check(psc_decrypt($read('requests', $ids['req'])) === $iban, 'rotation : illisible avec la seule clé suivante');

        // 7. Valeur changée entre lecture et écriture : pas écrasée.
        $use(array('constante' => $s2, 'base' => $s0));
        $changed = psc_encrypt('FR1420041010050500013M02606');
        $wpdb->update($t_parent, array('sepa_iban' => $enc0), array('id' => $ids['p1'])); // à rechiffrer
        $race = function ($sql) use ($wpdb, $t_parent, $ids, $changed) {
            static $done = false;
            if (!$done && strpos($sql, "UPDATE $t_parent SET sepa_iban") === 0) {
                $done = true;
                $wpdb->query($wpdb->prepare("UPDATE $t_parent SET sepa_iban = %s WHERE id = %d", $changed, $ids['p1']));
            }
            return $sql;
        };
        add_filter('query', $race);
        Psc_Key_Rotation::run(false, array('parents' => array($ids['p1'])));
        remove_filter('query', $race);
        $check($read('parents', $ids['p1']) === $changed, 'concurrence : IBAN modifié entre-temps écrasé');
    } finally {
        remove_filter('psc_encryption_secrets', $filter, 99);
        $purge();
        if ($org_saved === false) delete_option(Psc_Key_Rotation::OPTION);
        else update_option(Psc_Key_Rotation::OPTION, $org_saved);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Rechiffrement : $checks vérifications.");
});
