<?php
/**
 * Seed idempotent pour tests/supplier-order.spec.ts.
 *
 * Usage :
 *   wp --require=bin/seed-supplier-order.php seed-supplier-order
 *
 * Purge puis recrée les données identifiées par l'adresse e-mail du parent
 * de test et le libellé du trimestre de test : réexécutable N fois sans
 * jamais dupliquer de parent ni d'inscription. Ne touche à aucune autre
 * donnée du site (jamais de TRUNCATE), et n'active jamais ce trimestre
 * (Psc_Supplier_Orders::compute_counts() ne lit que les inscriptions,
 * jamais wp_psc_calendar_days ni le trimestre actif — un trimestre
 * "figurant" scopé par label suffit à satisfaire la contrainte NOT NULL
 * de wp_psc_registrations.trimestre_id).
 *
 * Semaine cible : le lundi tombant 90 jours après aujourd'hui, ramené au
 * lundi de sa semaine — toujours dans le futur quel que soit le jour
 * d'exécution, et assez loin des fenêtres de trimestre de
 * bin/seed-journey.php (-14/+60 jours max) pour ne jamais chevaucher ses
 * données.
 *
 * Deux enfants, classes distinctes, inscriptions Cantine (CANT) sur des
 * jours différents de la semaine cible + une inscription Garderie Matin
 * (GM) volontairement mêlée : sert à vérifier que le calcul ne compte
 * bien QUE la cantine, pas les autres prestations.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-supplier-order', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_Supplier_Orders')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $tz    = wp_timezone();
    $today = new DateTime('today', $tz);

    $target = (clone $today)->modify('+90 days');
    $dow    = (int) $target->format('N'); // 1 (lundi) .. 7 (dimanche)
    if ($dow > 1) {
        $target->modify('-' . ($dow - 1) . ' days');
    }
    $semaine_debut = $target->format('Y-m-d');

    $jours = array();
    foreach (Psc_Supplier_Orders::JOUR_OFFSETS as $jour => $offset) {
        $jours[$jour] = (clone $target)->modify("+{$offset} days")->format('Y-m-d');
    }

    $config = array(
        // wp_psc_school_years.label est VARCHAR(20) : contrairement au
        // trimestre (VARCHAR(191)), un libellé trop long fait échouer
        // silencieusement l'INSERT sous le sql_mode strict de MySQL 8.
        'school_year_label' => 'Année E2E — cmd',
        'trimestre_label' => 'Trimestre E2E — commande fournisseur',
        'parent_email'    => 'fournisseur.e2e@example.test',
        'parent_nom'      => 'E2E',
        'enfants'         => array(
            array('prenom' => 'Aline',    'nom' => 'Test', 'classe' => 'CP'),
            array('prenom' => 'Baptiste', 'nom' => 'Test', 'classe' => 'CE2'),
        ),
    );

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');
    $t_trim   = psc_table('trimestres');
    $t_reg    = psc_table('registrations');
    $t_sup    = psc_table('supplier_orders');

    /* ---------------------------------------------------------------- */
    /* Purge — scoping strict à l'identité du profil (jamais un TRUNCATE)*/
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Purge des données existantes…');

    $old_parent_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $t_parent WHERE email = %s", strtolower($config['parent_email'])
    ));
    if ($old_parent_id) {
        $child_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t_child WHERE parent_id = %d", $old_parent_id
        ));
        if ($child_ids) {
            $placeholders = implode(',', array_fill(0, count($child_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_reg WHERE child_id IN ($placeholders)", $child_ids));
        }
        $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
    }

    $old_trim_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $t_trim WHERE label = %s", $config['trimestre_label']
    ));
    foreach ($old_trim_ids as $trim_id) {
        $wpdb->delete($t_trim, array('id' => $trim_id), array('%d'));
    }

    $old_year_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $t_years WHERE label = %s", $config['school_year_label']
    ));
    foreach ($old_year_ids as $year_id) {
        $wpdb->delete($t_cy, array('school_year_id' => $year_id), array('%d'));
        $wpdb->delete($t_years, array('id' => $year_id), array('%d'));
    }

    // Historique de commandes fournisseur générées par un run précédent de
    // ce même spec, pour la même semaine cible.
    $wpdb->delete($t_sup, array('semaine_debut' => $semaine_debut), array('%s'));

    /* ---------------------------------------------------------------- */
    /* Recréation                                                        */
    /* ---------------------------------------------------------------- */

    // Année scolaire et trimestre "figurants", ni l'une ni l'autre jamais
    // activés : Psc_Supplier_Orders résout désormais l'année à partir de
    // la semaine demandée (Psc_School_Years::for_date()), pas de l'année
    // active du site — un couple année/trimestre non actifs mais dont les
    // dates couvrent la semaine cible suffit, sans jamais toucher à ce qui
    // est actif pour de vrai sur le site.
    $years_inserted = $wpdb->insert($t_years, array(
        'label'      => $config['school_year_label'],
        'date_debut' => $jours['lundi'],
        'date_fin'   => $jours['vendredi'],
        'statut'     => 'archivee',
        'created_at' => current_time('mysql'),
    ), array('%s', '%s', '%s', '%s', '%s'));
    if (!$years_inserted) {
        WP_CLI::error('Création de l\'année scolaire figurante : ' . $wpdb->last_error);
    }
    $school_year_id = (int) $wpdb->insert_id;

    $wpdb->insert($t_trim, array(
        'label'          => $config['trimestre_label'],
        'school_year_id' => $school_year_id,
        'date_debut'     => $jours['lundi'],
        'date_fin'       => $jours['vendredi'],
        'active'         => 0,
    ), array('%s', '%d', '%s', '%s', '%d'));
    $trimestre_id = (int) $wpdb->insert_id;

    $parent_id = Psc_Parents::create($config['parent_email'], $config['parent_nom']);
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent : ' . $parent_id->get_error_message());
    }

    $child_ids = array();
    foreach ($config['enfants'] as $c) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $c['nom'],
            'prenom'     => $c['prenom'],
            'statut'     => 'actif',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        $child_ids[] = $child_id;

        $wpdb->insert($t_cy, array(
            'child_id'       => $child_id,
            'school_year_id' => $school_year_id,
            'classe'         => $c['classe'],
            'statut'         => 'inscrit',
            'date_inscription' => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s'));
    }
    list($aline_id, $baptiste_id) = $child_ids;

    // Aline (CP) : Cantine lundi + mardi, et une Garderie Matin lundi
    // (ne doit JAMAIS compter dans le total repas). Baptiste (CE2) :
    // Cantine jeudi seulement.
    $regs = array(
        array($aline_id,    $jours['lundi'],  'CANT'),
        array($aline_id,    $jours['lundi'],  'GM'),
        array($aline_id,    $jours['mardi'],  'CANT'),
        array($baptiste_id, $jours['jeudi'],  'CANT'),
    );
    foreach ($regs as list($child_id, $date, $service)) {
        $wpdb->insert($t_reg, array(
            'child_id'     => $child_id,
            'trimestre_id' => $trimestre_id,
            'jour_date'    => $date,
            'service'      => $service,
            'updated_at'   => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s'));
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    $expected = array(
        'classes' => array('CP' => 'CP', 'CE2' => 'CE2'),
        'counts'  => array(
            'CP'  => array('lundi' => 1, 'mardi' => 1, 'jeudi' => 0, 'vendredi' => 0),
            'CE2' => array('lundi' => 0, 'mardi' => 0, 'jeudi' => 1, 'vendredi' => 0),
        ),
        'totaux_jour'   => array('lundi' => 1, 'mardi' => 1, 'jeudi' => 1, 'vendredi' => 0),
        'totaux_classe' => array('CP' => 2, 'CE2' => 1),
        'total'         => 3,
    );

    WP_CLI::log('');
    WP_CLI::log("Trimestre (figurant) ... {$config['trimestre_label']} (id $trimestre_id)");
    WP_CLI::log("Semaine cible .......... $semaine_debut");
    foreach ($jours as $jour => $date) {
        WP_CLI::log("  $jour ................ $date");
    }
    WP_CLI::log("Parent ................. {$config['parent_email']} (id $parent_id)");
    foreach ($config['enfants'] as $i => $c) {
        WP_CLI::log("  enfant-$i ............ {$c['prenom']} {$c['nom']} ({$c['classe']}, id {$child_ids[$i]})");
    }
    WP_CLI::log('Total repas attendu .... ' . $expected['total']);

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'semaine_debut' => $semaine_debut,
        'jours'         => $jours,
        'trimestre_id'  => $trimestre_id,
        'parent_id'     => $parent_id,
        'child_ids'     => $child_ids,
        'expected'      => $expected,
    )));

    WP_CLI::success('Seed commande fournisseur prêt.');
});
