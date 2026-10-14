<?php
/**
 * Script de vérification autonome des corrections du lot 2 de l'audit
 * (données, RGPD, journal d'audit, échanges, messages).
 *
 * Chaque section crée ses propres données (famille, enfant, lignes de
 * journal marquées « VerifyLot2 »), vérifie, puis les supprime. Les
 * lignes de journal écrites pendant le script (id > id de départ) sont
 * supprimées à la fin et l'empreinte de tête restaurée, comme
 * bin/verify-audit-retention.php.
 *
 * Usage :
 *   wp --require=bin/verify-audit-lot2.php verify-audit-lot2
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-audit-lot2', function () {

    if (!class_exists('Psc_Audit') || !function_exists('psc_audit_expected_hashes')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_audit = psc_table('audit_log');
    $t_par   = psc_table('parents');
    $t_child = psc_table('children');
    $start_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM $t_audit");
    $saved_hash = get_option('psc_audit_last_hash', '');
    $tag = 'verifylot2' . wp_generate_password(6, false, false);

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $audit_row = function ($id) use ($wpdb, $t_audit) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_audit WHERE id = %d", $id));
    };
    $last_audit_id = function () use ($wpdb, $t_audit) {
        return (int) $wpdb->get_var("SELECT MAX(id) FROM $t_audit");
    };
    // Chaîne vérifiée depuis la dernière ligne antérieure au script.
    $verify = function () use ($wpdb, $t_audit, $start_id) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_audit WHERE id >= %d ORDER BY id ASC", max(1, $start_id)));
        return psc_audit_verify_chain_rows($rows, Psc_Audit::chain_v2_from());
    };
    $mentions = function ($needle) use ($wpdb, $t_audit, $start_id) {
        $like = '%' . $wpdb->esc_like($needle) . '%';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_audit WHERE id > %d AND (resume LIKE %s OR details LIKE %s OR acteur_libelle LIKE %s)",
            $start_id, $like, $like, $like
        ));
    };
    $make_family = function ($suffix) use ($wpdb, $t_par, $tag) {
        $wpdb->insert($t_par, array(
            'email'               => $tag . $suffix . '@example.test',
            'nom'                 => 'Nom' . $tag . $suffix,
            'prenom'              => 'Prenom' . $tag,
            'second_parent_email' => 'second' . $tag . $suffix . '@example.test',
            'second_parent_nom'   => 'Second' . $tag . $suffix,
            'created_at'          => current_time('mysql'),
        ));
        return (int) $wpdb->insert_id;
    };
    $make_child = function ($family_id, $prenom) use ($wpdb, $t_child) {
        $wpdb->insert($t_child, array(
            'parent_id'      => $family_id,
            'nom'            => 'Enfant' . $prenom,
            'prenom'         => $prenom,
            'date_naissance' => '2019-04-07',
            'created_at'     => current_time('mysql'),
        ));
        return (int) $wpdb->insert_id;
    };
    $family_ids = array();

    try {
        /* ---------------- RGPD-04 : oubli d'un enfant, d'une famille ---------------- */
        $fam = $make_family('a');
        $family_ids[] = $fam;
        $child_prenom = 'Zoe' . $tag;
        $child = $make_child($fam, $child_prenom);
        $agent = array('type' => 'agent', 'id' => 1, 'libelle' => 'admin', 'pour_le_compte_de' => null);

        Psc_Audit::log('enfant.modification', array(
            'acteur' => $agent, 'objet_type' => 'enfant', 'objet_id' => $child, 'famille_id' => $fam, 'enfant_id' => $child,
            'avant' => array('prenom' => 'x'), 'apres' => array('prenom' => $child_prenom),
            'resume' => 'Fiche de ' . $child_prenom . ' modifiée',
        ));
        $r_child = $last_audit_id();
        Psc_Audit::log('planning.exception_modifiee', array(
            'acteur' => $agent, 'famille_id' => $fam,
            'resume' => 'Planning de ' . $child_prenom . ' (07/04/2019)',
        ));
        $r_family_named_child = $last_audit_id();
        Psc_Audit::log('famille.connexion', array(
            'acteur' => array('type' => 'famille', 'id' => $fam, 'libelle' => 'Nom' . $tag . 'a (' . $tag . 'a@example.test)', 'pour_le_compte_de' => null),
            'famille_id' => $fam, 'resume' => 'Connexion de ' . $tag . 'a@example.test',
        ));
        $r_self = $last_audit_id();
        Psc_Audit::log('demande.creation', array(
            'acteur' => array('type' => 'public', 'id' => null, 'libelle' => 'visiteur', 'pour_le_compte_de' => null),
            'famille_id' => $fam, 'resume' => 'Demande déposée',
        ));
        $r_public = $last_audit_id();
        Psc_Audit::log('planning.exception_modifiee', array(
            'acteur' => array('type' => 'agent', 'id' => 1, 'libelle' => 'admin', 'pour_le_compte_de' => $fam),
            'resume' => 'Planning modifié en consultation',
        ));
        $r_imperso = $last_audit_id();
        $wpdb->query($wpdb->prepare("UPDATE $t_audit SET ip = '192.0.2.44' WHERE id > %d", $start_id));
        $check($verify() === null, 'RGPD-04 : chaîne invalide avant oubli');

        // Oubli de l'enfant seul.
        $n = Psc_Audit::forget_child($child);
        $r = $audit_row($r_child);
        $check($n >= 2, "RGPD-04 forget_child : $n ligne(s) modifiée(s), au moins 2 attendues");
        $check($r && $r->enfant_id === null && $r->details === null, 'RGPD-04 forget_child : enfant_id ou détail conservé');
        $check($r && strpos($r->resume, $child_prenom) === false, 'RGPD-04 forget_child : résumé nominatif conservé');
        $r = $audit_row($r_family_named_child);
        $check($r && strpos($r->resume, $child_prenom) === false && strpos($r->resume, '07/04/2019') === false, 'RGPD-04 forget_child : ligne famille nommant l’enfant non neutralisée');
        $r = $audit_row($r_self);
        $check($r && (int) $r->famille_id === $fam && $r->ip === '192.0.2.44', 'RGPD-04 forget_child : ligne famille sans rapport modifiée');
        $check($verify() === null, 'RGPD-04 forget_child : chaîne invalide');
        $check($mentions($child_prenom) === 0, 'RGPD-04 forget_child : prénom de l’enfant encore dans le journal');

        // Oubli de la famille.
        $n = Psc_Audit::forget_family($fam);
        $check($n >= 4, "RGPD-04 forget_family : $n ligne(s), au moins 4 attendues");
        $r = $audit_row($r_self);
        $check($r && $r->famille_id === null && $r->ip === null && $r->details === null, 'RGPD-04 forget_family : famille_id, IP ou détail conservés (acteur famille)');
        $check($r && strpos($r->acteur_libelle, $tag) === false, 'RGPD-04 forget_family : libellé d’acteur nominatif conservé');
        $check($r && strpos($r->resume, $tag) === false, 'RGPD-04 forget_family : e-mail conservé dans le résumé');
        $r = $audit_row($r_public);
        $check($r && $r->ip === null && $r->famille_id === null, 'RGPD-04 forget_family : IP du visiteur conservée');
        $r = $audit_row($r_imperso);
        $check($r && $r->pour_le_compte_de === null, 'RGPD-04 forget_family : pour_le_compte_de conservé');
        $check($r && $r->ip === '192.0.2.44', 'RGPD-04 forget_family : IP de l’agent effacée à tort');
        $check($verify() === null, 'RGPD-04 forget_family : chaîne invalide');
        $check($mentions($tag) === 0, 'RGPD-04 forget_family : identité de la famille encore dans le journal');

        // Suppression manuelle complète : aucune identité écrite au journal.
        $fam_b = $make_family('b');
        $family_ids[] = $fam_b;
        $make_child($fam_b, 'Leo' . $tag);
        $res = Psc_Admin_Familles::delete_family($fam_b);
        $check(is_array($res) && $res['children_purged'] === 1, 'RGPD-04 delete_family : enfant non purgé');
        $check($mentions($tag) === 0, 'RGPD-04 delete_family : identité écrite au journal');
        $check($verify() === null, 'RGPD-04 delete_family : chaîne invalide');
    } finally {
        foreach ($family_ids as $fid) {
            $wpdb->delete($t_child, array('parent_id' => $fid), array('%d'));
            $wpdb->delete($t_par, array('id' => $fid), array('%d'));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $t_audit WHERE id > %d", $start_id));
        update_option('psc_audit_last_hash', $saved_hash, false);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Corrections du lot 2 : $checks vérifications.");
});
