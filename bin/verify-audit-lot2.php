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
        // Ligne historique demande.* : e-mail dans le résumé, sans famille_id.
        Psc_Audit::log('demande.validation', array(
            'acteur' => $agent, 'objet_type' => 'demande', 'objet_id' => 1,
            'resume' => 'Demande de ' . $tag . 'a@example.test validée',
        ));
        $r_legacy = $last_audit_id();
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

        // Oubli de la famille, sans rechaînage (PERF-05) : aucune empreinte
        // ni la tête de chaîne ne bougent.
        $hashes_before = $wpdb->get_results($wpdb->prepare("SELECT id, empreinte, empreinte_contenu FROM $t_audit WHERE id > %d", $start_id), OBJECT_K);
        $head_before = get_option('psc_audit_last_hash', '');
        $n = Psc_Audit::forget_family($fam);
        $hashes_after = $wpdb->get_results($wpdb->prepare("SELECT id, empreinte, empreinte_contenu FROM $t_audit WHERE id > %d", $start_id), OBJECT_K);
        $check($hashes_before == $hashes_after, 'PERF-05 : empreintes recalculées par l’oubli');
        $check(get_option('psc_audit_last_hash', '') === $head_before, 'PERF-05 : tête de chaîne modifiée par l’oubli');
        $check($n === 4, "RGPD-04 forget_family : $n ligne(s) au lieu de 4 (connexion, demande, consultation, demande historique)");
        $r = $audit_row($r_legacy);
        $check($r && $r->resume === psc_audit_default_resume('demande.validation'), 'RGPD-04 forget_family : ligne demande historique (sans famille_id) non anonymisée');
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

        /* ---------------- PERF-05 : lignes anonymisées et vérification ---------------- */
        $rows_since = function () use ($wpdb, $t_audit, $start_id) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_audit WHERE id >= %d ORDER BY id ASC", max(1, $start_id)));
        };
        $report = psc_audit_verify_chain_report($rows_since(), Psc_Audit::chain_v2_from());
        $check($report['rupture'] === null && $report['anonymisees'] >= 2, 'PERF-05 : lignes anonymisées non décomptées (' . $report['anonymisees'] . ')');
        $self = $audit_row($r_self);
        $check($self && $self->resume === psc_audit_default_resume('famille.connexion'), 'PERF-05 : résumé anonymisé différent du résumé générique');
        // Toute autre réécriture du résumé reste une rupture.
        $wpdb->update($t_audit, array('resume' => 'Résumé réécrit'), array('id' => $r_self));
        $check($verify() === $r_self, 'PERF-05 : résumé réécrit (non générique) non détecté');
        // Résumé générique mais identifiants conservés : rupture aussi.
        $wpdb->update($t_audit, array('resume' => $self->resume, 'famille_id' => $fam), array('id' => $r_self));
        $check($verify() === $r_self, 'PERF-05 : résumé générique avec famille_id conservé non détecté');
        $wpdb->update($t_audit, array('famille_id' => null, 'details' => '{"x":1}'), array('id' => $r_self));
        $check($verify() === $r_self, 'PERF-05 : résumé générique avec détail conservé non détecté');
        $wpdb->update($t_audit, array('details' => null, 'ip' => '192.0.2.45'), array('id' => $r_self));
        $check($verify() === $r_self, 'PERF-05 : résumé générique avec IP conservée non détecté');
        $wpdb->update($t_audit, array('ip' => null), array('id' => $r_self));
        $check($verify() === null, 'PERF-05 : ligne anonymisée restaurée non acceptée');
        // Une ligne intacte réécrite au résumé générique, identifiants vidés,
        // ne peut qu'effacer : elle est acceptée et décomptée à part.
        $before = psc_audit_verify_chain_report($rows_since(), Psc_Audit::chain_v2_from());
        $wpdb->update($t_audit, array('resume' => psc_audit_default_resume('planning.exception_modifiee'), 'ip' => null), array('id' => $r_imperso));
        $after = psc_audit_verify_chain_report($rows_since(), Psc_Audit::chain_v2_from());
        $check($after['rupture'] === null && $after['anonymisees'] === $before['anonymisees'] + 1, 'PERF-05 : effacement au résumé générique non décompté');

        // Suppression manuelle complète : aucune identité écrite au journal.
        $fam_b = $make_family('b');
        $family_ids[] = $fam_b;
        $make_child($fam_b, 'Leo' . $tag);
        $res = Psc_Admin_Familles::delete_family($fam_b);
        $check(is_array($res) && $res['children_purged'] === 1, 'RGPD-04 delete_family : enfant non purgé');
        $check($mentions($tag) === 0, 'RGPD-04 delete_family : identité écrite au journal');
        $check($verify() === null, 'RGPD-04 delete_family : chaîne invalide');

        /* ---------------- RGPD-02 : pièces jointes des échanges ---------------- */
        $fam_c = $make_family('c');
        $family_ids[] = $fam_c;
        $now_mysql = current_time('mysql');
        $wpdb->insert(psc_table('conversations'), array(
            'family_id' => $fam_c, 'sujet' => 'VerifyLot2', 'initiee_par' => 'famille',
            'dernier_message_at' => $now_mysql, 'dernier_auteur' => 'famille', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
        ));
        $conv_id = (int) $wpdb->insert_id;
        $rel_dir = 'conversations/verify-lot2-' . $tag;
        wp_mkdir_p(psc_private_path($rel_dir));
        $attached = $rel_dir . '/piece.pdf';
        $orphan = $rel_dir . '/orphelin.pdf';
        $recent_orphan = $rel_dir . '/recent.pdf';
        foreach (array($attached, $orphan, $recent_orphan) as $rel) file_put_contents(psc_private_path($rel), '%PDF-1.4 verify');
        $wpdb->insert(psc_table('conversation_messages'), array(
            'conversation_id' => $conv_id, 'auteur_type' => 'famille', 'corps' => 'VerifyLot2',
            'piece_jointe_path' => $attached, 'piece_jointe_nom' => 'piece.pdf', 'piece_jointe_taille' => 15, 'created_at' => $now_mysql,
        ));
        Psc_Conversations::delete_for_family($fam_c);
        $check(!file_exists(psc_private_path($attached)), 'RGPD-02 : pièce jointe conservée après suppression des échanges de la famille');
        $check(!$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('conversation_messages') . ' WHERE conversation_id = %d', $conv_id)), 'RGPD-02 : messages conservés');
        // Orphelin ancien supprimé, orphelin récent conservé (dépôt en cours).
        touch(psc_private_path($orphan), 978307200); // 01/01/2001
        $swept = Psc_Conversations::sweep_orphan_attachments(978393600); // 02/01/2001 : ne vise que la fixture
        $check($swept === 1 && !file_exists(psc_private_path($orphan)), 'RGPD-02 : orphelin ancien non supprimé');
        $check(file_exists(psc_private_path($recent_orphan)), 'RGPD-02 : fichier récent supprimé à tort');
        @unlink(psc_private_path($recent_orphan));
        @rmdir(psc_private_path($rel_dir));

        /* ---------------- RGPD-03 : demandes et accusés de lecture ---------------- */
        $t_req = psc_table('requests');
        $t_dest = psc_table('message_destinataires');
        $make_request = function ($email, $second = null) use ($wpdb, $t_req) {
            $wpdb->insert($t_req, array(
                'email' => $email, 'nom' => 'Demande', 'status' => 'pending', 'second_parent_email' => $second,
                'second_parent_nom' => $second ? 'SecondDemande' : null, 'created_at' => current_time('mysql'),
            ));
            return (int) $wpdb->insert_id;
        };
        $req_exists = function ($id) use ($wpdb, $t_req) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_req WHERE id = %d", $id));
        };
        $lone = 'demande' . $tag . '@example.test';
        $r1 = $make_request($lone);
        $export = Psc_Privacy::export_family_data($lone);
        $groups = wp_list_pluck($export['data'], 'group_id');
        $check(in_array('psc-demandes', $groups, true), 'RGPD-03 : export vide pour une famille jamais validée');
        $erase = Psc_Privacy::erase_family_data($lone);
        $check(!empty($erase['items_removed']) && !$req_exists($r1), 'RGPD-03 : demande non effacée (famille jamais validée)');

        $other = 'autre' . $tag . '@example.test';
        $second = 'secdemande' . $tag . '@example.test';
        $r2 = $make_request($other, $second);
        Psc_Privacy::erase_family_data($second);
        $r = $req_exists($r2);
        $check($r && $r->second_parent_email === null && $r->second_parent_nom === null, 'RGPD-03 : second parent d’une demande non effacé, ou demande de l’autre parent supprimée');
        $wpdb->delete($t_req, array('id' => $r2), array('%d'));

        $fam_d = $make_family('d');
        $family_ids[] = $fam_d;
        $fam_d_email = $tag . 'd@example.test';
        $r3 = $make_request($fam_d_email);
        $wpdb->insert($t_dest, array('message_id' => 999999999, 'family_id' => $fam_d, 'token' => md5($tag)));
        $receipt = (int) $wpdb->insert_id;
        $groups = wp_list_pluck(Psc_Privacy::export_family_data($fam_d_email)['data'], 'group_id');
        $check(in_array('psc-demandes', $groups, true) && in_array('psc-diffusions', $groups, true), 'RGPD-03 : export sans demandes ou accusés de lecture');
        Psc_Admin_Familles::delete_family($fam_d);
        $check(!$req_exists($r3), 'RGPD-03 : demande conservée après suppression de la famille');
        $check(!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_dest WHERE id = %d", $receipt)), 'RGPD-03 : accusé de lecture conservé après suppression de la famille');
        $wpdb->delete($t_dest, array('id' => $receipt), array('%d'));

        /* ---------------- RGPD-01 : foyers sans enfant, enfants sans année ---------------- */
        // Fixtures datées de 1990 et horloge simulée en 1991 : seules elles
        // sont assez anciennes pour être purgées par ces passages.
        $old = '1990-01-01 00:00:00';
        $now_1991 = strtotime('1991-02-10 00:00:00');
        $fam_e = $make_family('e'); // sans enfant, inactif depuis 1990
        $fam_f = $make_family('f'); // un enfant sans ligne d'année, créé en 1990
        $fam_g = $make_family('g'); // sans enfant mais connecté récemment
        $family_ids[] = $fam_e; $family_ids[] = $fam_f; $family_ids[] = $fam_g;
        $wpdb->query($wpdb->prepare("UPDATE $t_par SET created_at = %s WHERE id IN (%d, %d, %d)", $old, $fam_e, $fam_f, $fam_g));
        $wpdb->update($t_par, array('last_login' => '1991-01-20 00:00:00'), array('id' => $fam_g));
        $child_f = $make_child($fam_f, 'Sans' . $tag);
        $wpdb->update($t_child, array('created_at' => $old), array('id' => $child_f));

        $purged_children = Psc_Retention::purge_departed_children($now_1991);
        $check($purged_children === 1 && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_child WHERE id = %d", $child_f)), "RGPD-01 : enfant sans ligne d'année non purgé ($purged_children)");
        $purged_families = Psc_Retention::purge_childless_families($now_1991);
        $check($purged_families === 2, "RGPD-01 : $purged_families foyer(s) purgé(s), 2 attendus");
        $check(!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_par WHERE id IN (%d, %d)", $fam_e, $fam_f)), 'RGPD-01 : foyer sans enfant inactif conservé');
        $check((bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_par WHERE id = %d", $fam_g)), 'RGPD-01 : foyer actif récemment purgé à tort');
        $check(Psc_Retention::purge_childless_families($now_1991) === 0, 'RGPD-01 : deuxième passage non idempotent');
        $check($mentions($tag) === 0, 'RGPD-01 : identité écrite au journal par la purge');

        /* ---------------- RGPD-05 : export complet, portée du second parent ---------------- */
        $fam_h = $make_family('h');
        $family_ids[] = $fam_h;
        $wpdb->update($t_par, array('adresse' => 'AdresseTitulaire' . $tag), array('id' => $fam_h));
        $child_h = $make_child($fam_h, 'Exp' . $tag);
        $wpdb->update($t_child, array('food_allergies' => 'Arachide' . $tag), array('id' => $child_h));
        $wpdb->insert(psc_table('attendance'), array('child_id' => $child_h, 'jour_date' => '1990-03-05', 'service' => 'GM', 'present' => 1, 'arrival_time' => '07:45:00', 'pointed_at' => '1990-03-05 07:45:00'));
        $now_mysql = current_time('mysql');
        $wpdb->insert(psc_table('conversations'), array(
            'family_id' => $fam_h, 'sujet' => 'VerifyLot2', 'initiee_par' => 'famille',
            'dernier_message_at' => $now_mysql, 'dernier_auteur' => 'famille', 'created_at' => $now_mysql, 'updated_at' => $now_mysql,
        ));
        $conv_h = (int) $wpdb->insert_id;
        $wpdb->insert(psc_table('conversation_messages'), array('conversation_id' => $conv_h, 'auteur_type' => 'famille', 'corps' => 'Corps' . $tag, 'created_at' => $now_mysql));
        $flatten = function ($export) {
            $out = '';
            foreach ($export['data'] as $item) foreach ($item['data'] as $f) $out .= $item['group_id'] . '|' . $f['value'] . "\n";
            return $out;
        };
        $full = $flatten(Psc_Privacy::export_family_data($tag . 'h@example.test'));
        $check(strpos($full, 'psc-presences|') !== false && strpos($full, '07:45') !== false, 'RGPD-05 : présences absentes de l’export');
        $check(strpos($full, 'Arachide' . $tag) !== false, 'RGPD-05 : contenu des allergies absent de l’export');
        $check(strpos($full, 'Corps' . $tag) !== false, 'RGPD-05 : contenu des messages absent de l’export');
        $second_export = $flatten(Psc_Privacy::export_family_data('second' . $tag . 'h@example.test'));
        $check(strpos($second_export, 'AdresseTitulaire' . $tag) === false && strpos($second_export, $tag . 'h@example.test|') === false, 'RGPD-05 : export du second parent avec les coordonnées du titulaire');
        $check(strpos($second_export, 'Exp' . $tag) !== false && strpos($second_export, 'second' . $tag . 'h@example.test') !== false, 'RGPD-05 : export du second parent sans ses données ou sans les enfants');
        Psc_Conversations::delete_for_family($fam_h);
        Psc_Admin_Familles::purge_child($child_h);

        /* ---------------- RGPD-07 : suppression explicite de l'IBAN ---------------- */
        $iban = 'FR7630006000011234567890189';
        $fam_i = $make_family('i');
        $family_ids[] = $fam_i;
        $wpdb->update($t_par, array('payment_mode' => 'prelevement', 'sepa_iban' => psc_encrypt($iban), 'sepa_bic' => 'AGRIFRPP882', 'sepa_titulaire' => 'Titulaire' . $tag, 'sepa_adresse' => '1 rue ' . $tag), array('id' => $fam_i));
        $parent_i = function () use ($wpdb, $t_par, $fam_i) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_par WHERE id = %d", $fam_i));
        };
        $saved_post = $_POST;
        $_POST = array('sepa_supprimer' => '1', 'sepa_iban' => '');
        $check(Psc_Admin_Familles::bank_fields_from_request(false) === array(), 'RGPD-07 : suppression acceptée sans habilitation Facturation');
        Psc_Parents::update($fam_i, Psc_Admin_Familles::bank_fields_from_request(false));
        $check(psc_read_iban($parent_i()) === $iban, 'RGPD-07 : IBAN modifié sans habilitation Facturation');
        $_POST = array('sepa_iban' => '');
        Psc_Parents::update($fam_i, Psc_Admin_Familles::bank_fields_from_request(true));
        $check(psc_read_iban($parent_i()) === $iban, 'RGPD-07 : champ IBAN vide a effacé l’IBAN');
        $_POST = array('sepa_supprimer' => '1', 'sepa_iban' => 'DE89370400440532013000');
        $res = Psc_Parents::update($fam_i, Psc_Admin_Familles::bank_fields_from_request(true));
        $_POST = $saved_post;
        $p = $parent_i();
        $check(!is_wp_error($res) && psc_read_iban($p) === '' && empty($p->sepa_bic) && empty($p->sepa_titulaire) && empty($p->sepa_adresse) && $p->payment_mode === 'autre', 'RGPD-07 : coordonnées bancaires non supprimées');
        $check($mentions($iban) === 0 && $mentions('Titulaire' . $tag) === 0, 'RGPD-07 : IBAN complet ou titulaire écrit au journal');

        /* ---------------- SEC-06 : verrou de chaînage ---------------- */
        // Écritures concurrentes : 4 processus WP-CLI écrivent 15 lignes
        // chacun en même temps ; la chaîne doit rester sans rupture.
        $php = escapeshellarg(PHP_BINARY);
        $cli = escapeshellarg(isset($GLOBALS['argv'][0]) ? realpath($GLOBALS['argv'][0]) : '/usr/local/bin/wp-cli.phar');
        $code = escapeshellarg("for (\$i = 0; \$i < 15; \$i++) Psc_Audit::log('menu.enregistrement', array('resume' => 'VerifyLot2 concurrence'));");
        $cmd = "$php $cli --allow-root --path=" . escapeshellarg(ABSPATH) . " eval $code";
        $procs = array();
        for ($p = 0; $p < 4; $p++) {
            $procs[] = proc_open($cmd, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
        }
        foreach ($procs as $proc) if (is_resource($proc)) proc_close($proc);
        $concurrent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_audit WHERE id > %d AND resume = %s", $start_id, 'VerifyLot2 concurrence'));
        $check($concurrent === 60, "SEC-06 : $concurrent ligne(s) concurrente(s) écrite(s) au lieu de 60");
        $check($verify() === null, 'SEC-06 : rupture de chaîne après écritures concurrentes');

        // Verrou tenu ailleurs : l'écriture a lieu quand même, l'échec est compté.
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var("SELECT GET_LOCK('psc_audit_chain', 5)");
        $timeouts = (int) get_option('psc_audit_lock_timeouts', 0);
        $before_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_audit WHERE id > %d", $start_id));
        Psc_Audit::log('menu.enregistrement', array('resume' => 'VerifyLot2 verrou occupé'));
        $other->get_var("SELECT RELEASE_LOCK('psc_audit_chain')");
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_audit WHERE id > %d", $start_id)) === $before_count + 1, 'SEC-06 : ligne perdue quand le verrou est occupé');
        $check((int) get_option('psc_audit_lock_timeouts', 0) === $timeouts + 1, 'SEC-06 : délai de verrou non compté');
        update_option('psc_audit_lock_timeouts', $timeouts, false);
        $check($verify() === null, 'SEC-06 : chaîne invalide après un délai de verrou');

        /* ---------------- RGPD-13 : même reliquat que la suppression manuelle ---------------- */
        $fam_j = $make_family('j');
        $family_ids[] = $fam_j;
        $make_child($fam_j, 'Eff' . $tag);
        $erased = Psc_Privacy::erase_family_data($tag . 'j@example.test');
        $check(!empty($erased['items_removed']) && empty($erased['items_retained']), 'RGPD-13 : bilan de l’effaceur incorrect (sans facture)');
        $check(!$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_par WHERE id = %d", $fam_j)), 'RGPD-13 : foyer sans facture anonymisé au lieu d’être supprimé');
        $check($mentions($tag . 'j') === 0, 'RGPD-13 : identité du foyer effacé encore au journal');
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
