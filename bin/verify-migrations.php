<?php
/**
 * Script de vérification du scénario de migration, en conditions WP-CLI
 * réelles (rôle d'un test unitaire, pas de harnais PHPUnit ici).
 *
 * ⚠ DÉSTRUCTIF — à réserver à un site jetable (l'intégration continue
 * l'exécute juste après l'installation, avant le peuplement des
 * scénarios ; en local, sur la base jetable psc_scratch). Il DÉTRUIT le
 * schéma périscolaire, le reconstruit dans l'état d'un site en schéma
 * 4.15.0 (version 5.28.0, la plus ancienne dont la montée est prise en
 * charge : cf. Psc_Installer::MIN_UPGRADE_FROM), rejoue la montée
 * complète (Psc_Installer::maybe_upgrade, le chemin d'une mise à jour par
 * copie de fichiers), vérifie le résultat, puis reconstruit un schéma
 * vierge (Psc_Installer::activate).
 *
 * Ce que le scénario verrouille :
 *  - en deçà de 4.15.0, la montée est refusée avec une alerte explicite,
 *    sans toucher à la base ;
 *  - 4.16.0 : chaînage v2 du journal d'audit, empreinte de contenu des
 *    lignes existantes (identique au calcul PHP), chaîne toujours valide ;
 *  - 4.17.0 : grille de tarifs reprise de l'ancienne option (et des tarifs
 *    par défaut), enfant « cantine sans repas » converti en période, ancienne
 *    colonne et ancienne option supprimées ;
 *  - 4.18.0 : table des versions de règlements et colonnes d'acceptation ;
 *  - clés étrangères posées, aucune contrainte manquante ;
 *  - une seconde montée ne duplique rien.
 *
 * Usage :
 *   wp --require=bin/verify-migrations.php verify-migrations
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-migrations', function () {

    if (!class_exists('Psc_Installer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $failures = array();
    $checks   = 0;
    $assert = function ($label, $actual, $expected) use (&$failures, &$checks) {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = sprintf('%s : attendu %s, obtenu %s', $label, var_export($expected, true), var_export($actual, true));
        }
    };
    $column_exists = function ($table, $column) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            psc_table($table), $column
        )) > 0;
    };
    $table_exists = function ($table) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            psc_table($table)
        )) > 0;
    };
    $fk_exists = function ($table, $column) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
            psc_table($table), $column
        )) > 0;
    };
    $drop_all_psc_tables = function () use ($wpdb) {
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . 'psc_%'));
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ((array) $tables as $t) $wpdb->query("DROP TABLE IF EXISTS `$t`");
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
    };
    $flush = function () {
        Psc_Tarifs::flush_cache();
        Psc_Sans_Repas::flush_cache();
        Psc_Planning::flush_cache();
        Psc_Document_Versions::flush_cache();
    };

    /* ---------------------------------------------------------------- */
    /* 1. Site en schéma 4.15.0                                            */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Reconstruction d’un site en schéma 4.15.0…');
    $drop_all_psc_tables();
    foreach (array('psc_audit_chain_v2_from', 'psc_audit_last_hash', 'psc_migration_failed', 'psc_constraints_missing') as $o) delete_option($o);
    update_option('psc_db_version', '4.15.0');
    // Première passe de dbDelta, telle qu'une montée la ferait : en schéma
    // 4.15.0, children porte encore cantine_sans_repas (cf. create_tables).
    $create = new ReflectionMethod('Psc_Installer', 'create_tables');
    $create->setAccessible(true);
    $create->invoke(null);
    $assert('4.15.0 : colonne children.cantine_sans_repas présente', $column_exists('children', 'cantine_sans_repas'), true);

    // Données d'époque : grille dans l'ancienne option, un enfant signalé,
    // des lignes de journal chaînées en v1.
    update_option('psc_service_prices', array('CANT' => 6.10, 'FSR' => 7.00));
    $wpdb->insert(psc_table('school_years'), array('year_key' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-07-03', 'statut' => 'active', 'created_at' => current_time('mysql')));
    $wpdb->insert(psc_table('parents'), array('email' => 'verif-migrations@example.invalid', 'nom' => 'Migration', 'active' => 1, 'created_at' => current_time('mysql')));
    $parent_id = (int) $wpdb->insert_id;
    $wpdb->insert(psc_table('children'), array('parent_id' => $parent_id, 'nom' => 'Migration', 'prenom' => 'Sans', 'cantine_sans_repas' => 1, 'created_at' => current_time('mysql')));
    $flagged = (int) $wpdb->insert_id;
    $wpdb->insert(psc_table('children'), array('parent_id' => $parent_id, 'nom' => 'Migration', 'prenom' => 'Avec', 'cantine_sans_repas' => 0, 'created_at' => current_time('mysql')));
    $unflagged = (int) $wpdb->insert_id;
    foreach (array('A', 'B', 'C') as $l) Psc_Audit::log('menu.enregistrement', array('objet_type' => 'menu', 'resume' => 'Migration ' . $l));
    $audit_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . psc_table('audit_log'));

    /* ---------------------------------------------------------------- */
    /* 2. En deçà de 4.15.0 : refus explicite, base intacte                */
    /* ---------------------------------------------------------------- */

    update_option('psc_db_version', '4.14.0');
    Psc_Installer::maybe_upgrade();
    $failed = get_option('psc_migration_failed');
    $assert('< 4.15.0 : version inchangée', get_option('psc_db_version'), '4.14.0');
    $assert('< 4.15.0 : refus consigné (étape « version »)', is_array($failed) ? $failed['etape'] : null, 'version');
    $assert('< 4.15.0 : la colonne d’époque est intacte', $column_exists('children', 'cantine_sans_repas'), true);
    delete_option('psc_migration_failed');

    /* ---------------------------------------------------------------- */
    /* 3. Montée 4.15.0 → version courante                                 */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Montée de version 4.15.0 → ' . Psc_Installer::DB_VERSION . ' (maybe_upgrade)…');
    update_option('psc_db_version', '4.15.0');
    Psc_Installer::maybe_upgrade();
    // Les contraintes se posent au premier écran d'administration (cf.
    // run_upgrade()) : WP-CLI n'en est pas un, on le simule.
    $constraints = new ReflectionMethod('Psc_Installer', 'store_constraints_state');
    $constraints->setAccessible(true);
    $constraints->invoke(null);
    $flush();

    $assert('psc_db_version portée à la version courante', get_option('psc_db_version'), Psc_Installer::DB_VERSION);
    $assert('aucune montée en échec consignée', get_option('psc_migration_failed'), false);
    $assert('aucune contrainte manquante', get_option('psc_constraints_missing'), array());

    // 4.16.0 : journal d'audit.
    $seuil = (int) get_option('psc_audit_chain_v2_from');
    $assert('4.16.0 : seuil du chaînage v2 après les lignes existantes', $seuil > $audit_before, true);
    $ecarts = 0;
    foreach ($wpdb->get_results('SELECT * FROM ' . psc_table('audit_log') . " WHERE id < $seuil") as $r) {
        if ($r->empreinte_contenu !== psc_audit_content_hash($r->horodatage, $r->action, $r->acteur_type, $r->acteur_id, $r->objet_type, $r->objet_id, $r->resume)) $ecarts++;
    }
    $assert('4.16.0 : empreintes de contenu calculées en SQL = calcul PHP', $ecarts, 0);
    $assert('4.16.0 : chaîne du journal valide', Psc_Audit::verify_chain(1000), null);

    // 4.17.0 : tarifs et statut datés.
    $prix = function ($code) use ($wpdb) {
        return (int) $wpdb->get_var($wpdb->prepare('SELECT prix_centimes FROM ' . psc_table('tarifs') . ' WHERE code = %s', $code));
    };
    $assert('4.17.0 : tarif de l’ancienne option repris', $prix('CANT'), 610);
    $assert('4.17.0 : forfait sans repas de l’ancienne option repris', $prix('FSR'), 700);
    $assert('4.17.0 : tarif par défaut pour le reste', $prix('GM'), 185);
    $assert('4.17.0 : grille à compter de la première rentrée', (string) $wpdb->get_var('SELECT MIN(debut) FROM ' . psc_table('tarifs')), '2026-09-01');
    $assert('4.17.0 : ancienne option supprimée', get_option('psc_service_prices', null), null);
    $assert('4.17.0 : enfant signalé converti en période', Psc_Sans_Repas::on($flagged, '2026-10-01'), true);
    $assert('4.17.0 : enfant non signalé sans période', Psc_Sans_Repas::on($unflagged, '2026-10-01'), false);
    $assert('4.17.0 : colonne children.cantine_sans_repas supprimée', $column_exists('children', 'cantine_sans_repas'), false);
    $assert('4.17.0 : clé étrangère des périodes', $fk_exists('sans_repas', 'child_id'), true);

    // 4.18.0 : versions des règlements.
    $assert('4.18.0 : table des versions', $table_exists('document_versions'), true);
    foreach (array(array('parents', 'reglement_version_id'), array('parents', 'sepa_reglement_version_id'), array('requests', 'reglement_version_id'), array('child_school_years', 'reglement_version_id')) as list($t, $c)) {
        $assert("4.18.0 : $t.$c avec sa clé étrangère", $column_exists($t, $c) && $fk_exists($t, $c), true);
    }

    // Seconde montée : rien ne se duplique.
    update_option('psc_db_version', '4.15.0');
    Psc_Installer::maybe_upgrade();
    $flush();
    $assert('seconde montée : une seule ligne de tarif par code', (int) $wpdb->get_var('SELECT MAX(n) FROM (SELECT COUNT(*) n FROM ' . psc_table('tarifs') . ' GROUP BY code) x'), 1);
    $assert('seconde montée : une seule période pour l’enfant signalé', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . psc_table('sans_repas') . ' WHERE child_id = %d', $flagged)), 1);
    $assert('seconde montée : version courante', get_option('psc_db_version'), Psc_Installer::DB_VERSION);

    /* ---------------------------------------------------------------- */
    /* 4. Nettoyage : schéma reconstruit vierge                            */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Nettoyage (reconstruction du schéma)…');
    $drop_all_psc_tables();
    foreach (array('psc_audit_chain_v2_from', 'psc_audit_last_hash') as $o) delete_option($o);
    Psc_Installer::activate();
    $flush();

    WP_CLI::log('');
    WP_CLI::log(sprintf('%d vérification(s) effectuée(s), %d échec(s).', $checks, count($failures)));
    if ($failures) {
        foreach ($failures as $f) WP_CLI::log('  ÉCHEC — ' . $f);
        WP_CLI::error('Le scénario de migration ne produit pas le schéma / les données attendus.');
    }
    WP_CLI::success('Scénario de migration conforme (schéma reconstruit vierge).');
});
