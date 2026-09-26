<?php
/**
 * Script de vérification autonome (P1-11) — purge du journal d'audit par
 * niveau, sans trou dans la chaîne (chaînage v2, schéma 4.16.0).
 *
 * Ajoute ses propres lignes en fin de journal, vieillies à la main puis
 * rechaînées, dans cet ordre :
 *   critique conservée · volumineuse expirée · normale conservée ·
 *   volumineuse expirée · critique conservée
 * et vérifie :
 *  - les lignes expirées sont vidées (acteur, résumé, détails, IP,
 *    identifiants) et marquées purgées, même encadrées par des lignes
 *    conservées ; les autres sont intactes ;
 *  - la chaîne reste vérifiable, et une ligne purgée falsifiée est
 *    détectée ;
 *  - les lignes purgées ne sont ni listées ni exportées ;
 *  - l'oubli d'une famille rechaîne correctement une série qui contient
 *    des lignes purgées ;
 *  - une deuxième purge ne retouche rien.
 *
 * Ses lignes (et celles écrites après elles) sont supprimées à la fin, et
 * l'empreinte de tête restaurée.
 *
 * Usage :
 *   wp --require=bin/verify-audit-retention.php verify-audit-retention
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-audit-retention', function () {

    if (!class_exists('Psc_Audit') || !function_exists('psc_audit_expected_hashes')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }
    if (Psc_Audit::chain_v2_from() <= 0) {
        WP_CLI::error('Chaînage v2 absent : la mise à jour 4.16.0 n’a pas tourné.');
    }

    global $wpdb;
    $t = psc_table('audit_log');
    $start_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $t");
    $saved_hash = get_option('psc_audit_last_hash', '');

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $row = function ($id) use ($wpdb, $t) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", $id));
    };
    $ours = function () use ($wpdb, $t, $start_id) {
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE id > %d ORDER BY id ASC", $start_id));
    };
    // Chaîne vérifiée depuis la dernière ligne antérieure (ancre) jusqu'à la fin.
    $verify = function () use ($wpdb, $t, $start_id) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE id >= %d ORDER BY id ASC", max(1, $start_id)));
        return psc_audit_verify_chain_rows($rows, Psc_Audit::chain_v2_from());
    };

    $family = 987654321;
    $cases = array(
        array('critique_1', 'famille.suppression', 900),
        array('volumineuse_1', 'planning.exception_modifiee', 400),
        array('normale', 'menu.enregistrement', 200),
        array('volumineuse_2', 'planning.exception_modifiee', 400),
        array('critique_2', 'famille.suppression', 900),
    );

    try {
        $ids = array();
        foreach ($cases as list($key, $action, $days)) {
            Psc_Audit::log($action, array(
                'resume'     => 'VerifyRetention ' . $key,
                'famille_id' => $family,
                'meta'       => array('cas' => $key),
            ));
            $ids[$key] = (int) $wpdb->get_var("SELECT MAX(id) FROM $t");
            $wpdb->update($t, array('horodatage' => gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS), 'ip' => '192.0.2.10'), array('id' => $ids[$key]));
        }
        // Rechaînage des lignes vieillies, depuis la dernière ligne antérieure.
        $previous = (string) $wpdb->get_var($wpdb->prepare("SELECT empreinte FROM $t WHERE id <= %d ORDER BY id DESC LIMIT 1", $start_id));
        foreach ($ours() as $r) {
            $e = psc_audit_expected_hashes($previous, $r, Psc_Audit::chain_v2_from());
            $wpdb->update($t, array('empreinte' => $e['empreinte'], 'empreinte_contenu' => $e['contenu']), array('id' => $r->id));
            $previous = $e['empreinte'];
        }
        update_option('psc_audit_last_hash', $previous, false);
        $check($verify() === null, 'avant purge : chaîne invalide');

        $purged = Psc_Audit::purge_expired(20);
        $check($purged >= 2, "purge : $purged ligne(s) vidée(s), au moins 2 attendues");
        foreach (array('volumineuse_1', 'volumineuse_2') as $key) {
            $r = $row($ids[$key]);
            $check($r && $r->purgee_le !== null, "$key : non purgée");
            $check($r && $r->resume === '' && $r->acteur_libelle === '' && $r->details === null && $r->ip === null
                && $r->famille_id === null && $r->acteur_id === null && $r->objet_id === null, "$key : contenu personnel conservé");
            $check($r && strlen((string) $r->empreinte_contenu) === 64 && $r->action === 'planning.exception_modifiee', "$key : empreinte de contenu ou code d’action perdus");
        }
        foreach (array('critique_1', 'normale', 'critique_2') as $key) {
            $r = $row($ids[$key]);
            $check($r && $r->purgee_le === null && $r->resume === 'VerifyRetention ' . $key && $r->ip === '192.0.2.10', "$key : altérée alors qu’elle est conservée");
        }
        $check($verify() === null, 'après purge : chaîne invalide (trou créé)');

        // Lignes purgées : ni listées, ni comptées, ni exportées (filtre
        // sur leur code d'action, qu'elles gardent).
        $purged_ids = array($ids['volumineuse_1'], $ids['volumineuse_2']);
        $filter = array('action' => 'planning.exception_modifiee', 'du' => gmdate('Y-m-d', time() - 500 * DAY_IN_SECONDS));
        $listed = array_map('intval', wp_list_pluck(Psc_Audit::query($filter + array('per_page' => 500)), 'id'));
        $check(!array_intersect($purged_ids, $listed), 'liste : ligne purgée affichée');
        $exported = array();
        Psc_Audit::stream($filter, function ($r) use (&$exported) { $exported[] = (int) $r->id; });
        $check(!array_intersect($purged_ids, $exported), 'export : ligne purgée exportée');
        $check(Psc_Audit::count(array('famille_id' => $family)) === 3, 'comptage : lignes conservées de la famille');

        // Une ligne purgée falsifiée est détectée.
        $orig = $row($ids['volumineuse_1'])->empreinte_contenu;
        $wpdb->update($t, array('empreinte_contenu' => str_repeat('0', 64)), array('id' => $ids['volumineuse_1']));
        $check($verify() === $ids['volumineuse_1'], 'falsification d’une ligne purgée non détectée');
        $wpdb->update($t, array('empreinte_contenu' => $orig), array('id' => $ids['volumineuse_1']));

        // Deuxième passage : rien à refaire, chaîne intacte.
        $check(Psc_Audit::purge_expired(20) === 0, 'deuxième purge : lignes retouchées');

        // Oubli de la famille : rechaînage à travers les lignes purgées.
        $n = Psc_Audit::forget_family($family, array('VerifyRetention'));
        $check($n === 3, "oubli : $n ligne(s) anonymisée(s) au lieu de 3");
        $check($verify() === null, 'oubli : chaîne invalide après rechaînage');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE id > %d AND resume LIKE %s", $start_id, 'VerifyRetention%')) === 0, 'oubli : résumé nominatif conservé');
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE id > %d", $start_id));
        update_option('psc_audit_last_hash', $saved_hash, false);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Purge du journal par niveau : $checks vérifications.");
});
