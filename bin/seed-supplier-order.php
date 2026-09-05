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
 * Semaine cible : le premier lundi de septembre à TROIS rentrées d'écart
 * (année scolaire future sans AUCUN pattern d'aucune famille de test —
 * les rythmes des autres seeds vivent dans les années courantes) — la
 * semaine n'est donc déclarée que par CE seed, quelle que soit la
 * pollution du site de développement. Les jours fériés de la semaine sont
 * évités par balayage : les attendus sont calculés depuis les jours
 * réellement ouverts.
 *
 * Quatre enfants couvrant la ventilation de l'e-mail : standard, sans
 * porc, végétarien (libellé famille « sans viande »), et un enfant
 * allergique (apporte son repas ET son goûter — compté dans aucune
 * colonne). Une inscription Garderie Matin volontairement mêlée vérifie
 * que le calcul ne compte bien QUE la cantine (et le goûter GS), pas les
 * autres prestations.
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

    // Première semaine de septembre à trois rentrées d'écart : aucune
    // famille de test n'a de pattern dans cette année scolaire future, la
    // semaine n'est déclarée que par ce seed. Balayage semaine par semaine
    // jusqu'à trouver 4 jours ouverts (un lundi de septembre peut tomber
    // sur un pont, jamais sur une plage de vacances entière).
    $rentree = psc_rentree_year() + 3;
    $target  = new DateTime($rentree . '-09-01', $tz);
    $dow     = (int) $target->format('N'); // 1 (lundi) .. 7 (dimanche)
    if ($dow > 1) {
        $target->modify('-' . ($dow - 1) . ' days'); // lundi de la semaine du 1er septembre
    }
    $semaine_debut = $target->format('Y-m-d');
    while (count(psc_open_days($semaine_debut)) < 4) {
        $target->modify('+7 days');
        $semaine_debut = $target->format('Y-m-d');
    }

    // Configuration du planning ISOLANTE : sans elle, une date sans année
    // couvrante retombe sur l'année ACTIVE (year_key_for_date) et les
    // patterns des autres familles de test s'appliqueraient à cette
    // semaine lointaine. La config ci-dessous capte ces dates dans une
    // année 'Y-Y+1' où personne d'autre n'a de rythme.
    $y = (int) substr($semaine_debut, 0, 4);
    $planning_key = ((int) substr($semaine_debut, 5, 2) >= 7)
        ? $y . '-' . ($y + 1)
        : ($y - 1) . '-' . $y;
    Psc_School_Year::save($planning_key, $semaine_debut, gmdate('Y-m-d', strtotime($semaine_debut . ' +6 days')), '[]', psc_lock_hours());

    $jours = array();
    foreach (Psc_Supplier_Orders::JOUR_OFFSETS as $jour => $offset) {
        $jours[$jour] = (clone $target)->modify("+{$offset} days")->format('Y-m-d');
    }

    $config = array(
        // wp_psc_school_years.label est VARCHAR(20) : un libellé trop long
        // fait échouer silencieusement l'INSERT sous le sql_mode strict de
        // MySQL 8.
        'school_year_label' => 'Année E2E — cmd',
        'parent_email'    => 'fournisseur.e2e@example.test',
        'parent_nom'      => 'E2E',
        // Quatre profils qui couvrent toute la ventilation de l'e-mail :
        // standard, sans porc, végétarien (libellé famille « sans viande »),
        // et un enfant allergique (apporte son repas ET son goûter — compté
        // dans aucune colonne). Baptiste porte en plus le flag mairie
        // « cantine sans repas » : sa cantine du jeudi vaut midi sans repas,
        // elle n'entre dans aucune colonne (son goûter reste compté).
        'enfants'         => array(
            array('prenom' => 'Aline',    'nom' => 'Test', 'classe' => 'CP',  'sans_porc' => 0, 'vegan' => 0, 'allergies' => '', 'csr' => 0),
            array('prenom' => 'Baptiste', 'nom' => 'Test', 'classe' => 'CE2', 'sans_porc' => 1, 'vegan' => 0, 'allergies' => '', 'csr' => 1),
            array('prenom' => 'Chloé',    'nom' => 'Test', 'classe' => 'CP',  'sans_porc' => 0, 'vegan' => 1, 'allergies' => '', 'csr' => 0),
            array('prenom' => 'Théo',     'nom' => 'Test', 'classe' => 'CE2', 'sans_porc' => 0, 'vegan' => 0, 'allergies' => 'Arachides — réaction allergique grave, conduit à contacter le 15', 'csr' => 0),
        ),
    );

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');
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
            foreach ($child_ids as $cid) {
                Psc_Planning::delete_for_child((int) $cid);
            }
        }
        $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
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

    // Le pied de mail configurable du modèle « Commande fournisseur » est
    // modifié par le scénario E2E : on repart du défaut à chaque seed, sans
    // toucher aux personnalisations des AUTRES modèles.
    if (class_exists('Psc_Email_Templates')) {
        Psc_Email_Templates::reset('supplier_order');
    }

    /* ---------------------------------------------------------------- */
    /* Recréation                                                        */
    /* ---------------------------------------------------------------- */

    // Année scolaire "figurante", jamais activée : Psc_Supplier_Orders
    // résout l'année à partir de la semaine demandée
    // (Psc_School_Years::for_date()) — une année archivée dont les dates
    // couvrent la semaine cible suffit, sans jamais toucher à ce qui est
    // actif pour de vrai sur le site.
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

    // onboarding_seen_at fixé à la création : cette spec ne teste pas la
    // popin de découverte, qui bloquerait sinon les clics Playwright sur
    // le reste du portail si jamais un test venait à s'y connecter (cf.
    // templates/frontend-portal.php).
    $parent_id = Psc_Parents::create($config['parent_email'], $config['parent_nom'], array(
        'onboarding_seen_at' => current_time('mysql'),
    ));
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent : ' . $parent_id->get_error_message());
    }

    $child_ids = array();
    $csr_ids = array();
    foreach ($config['enfants'] as $c) {
        $wpdb->insert($t_child, array(
            'parent_id'      => $parent_id,
            'nom'            => $c['nom'],
            'prenom'         => $c['prenom'],
            'sans_porc'      => (int) $c['sans_porc'],
            'vegan'          => (int) $c['vegan'],
            'cantine_sans_repas' => (int) $c['csr'],
            'food_allergies' => $c['allergies'] !== '' ? $c['allergies'] : null,
            'statut'         => 'actif',
            'created_at'     => current_time('mysql'),
        ), array('%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        $child_ids[] = $child_id;
        if ((int) $c['csr']) $csr_ids[] = $child_id;

        $wpdb->insert($t_cy, array(
            'child_id'       => $child_id,
            'school_year_id' => $school_year_id,
            'classe'         => $c['classe'],
            'statut'         => 'inscrit',
            'date_inscription' => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s'));
    }
    list($aline_id, $baptiste_id, $chloe_id, $theo_id) = $child_ids;

    // Déclarations (exceptions ponctuelles posées par la mairie, hors
    // verrou — la résolution psc_is_declared est la même que celle de la
    // commande) :
    //   Aline    (standard)     : CANT lun + mar, GM lun (hors total), GS lun (goûter)
    //   Baptiste (sans porc)    : CANT jeu (flag mairie → midi sans repas), GS jeu (goûter)
    //   Chloé    (végétarien)   : CANT mar, MSR jeu (midi sans repas, jamais compté)
    //   Théo     (allergique)   : CANT lun, GS lun — compté nulle part
    $regs = array(
        array($aline_id,    $jours['lundi'],  'CANT'),
        array($aline_id,    $jours['lundi'],  'GM'),
        array($aline_id,    $jours['mardi'],  'CANT'),
        array($aline_id,    $jours['lundi'],  'GS'),
        array($baptiste_id, $jours['jeudi'],  'CANT'),
        array($baptiste_id, $jours['jeudi'],  'GS'),
        array($chloe_id,    $jours['mardi'],  'CANT'),
        array($chloe_id,    $jours['jeudi'],  'MSR'),
        array($theo_id,     $jours['lundi'],  'CANT'),
        array($theo_id,     $jours['lundi'],  'GS'),
    );
    foreach ($regs as list($child_id, $date, $service)) {
        Psc_Planning::toggle_exception($child_id, $date, $service, true, true);
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    // Attendus calculés depuis les jours réellement ouverts de la semaine
    // (un jour férié disparaît de la liste) et les déclarations ci-dessus :
    //   Aline (standard)  : CANT lun + mar, GM lun (hors comptage), GS lun (goûter)
    //   Baptiste (sans porc) : CANT jeu (flag mairie « cantine sans repas »
    //                          → midi sans repas, non compté), GS jeu (goûter)
    //   Chloé (végétarienne) : CANT mar ; MSR jeu (midi sans repas) — jamais compté
    //   Théo (allergique)    : CANT lun, GS lun — compté dans aucune colonne
    $kind_by_child = array(
        $aline_id    => 'standard',
        $baptiste_id => 'sans_porc',
        $chloe_id    => 'vegetarien',
        // $theo_id : allergique, volontairement absent de la ventilation
    );
    $expected_rows = array();
    foreach ($jours as $jour => $date) {
        $expected_rows[$jour] = array('standard' => 0, 'sans_porc' => 0, 'vegetarien' => 0, 'midi' => 0, 'gouter' => 0);
    }
    foreach ($regs as list($child_id, $date, $service)) {
        $jour = array_search($date, $jours, true);
        if ($jour === false) continue;
        // L'enfant allergique n'entre dans AUCUNE colonne (ni repas ni goûter).
        if (!isset($kind_by_child[$child_id])) continue;
        // Enfant flagué « cantine sans repas » (conversion à la résolution) :
        // sa cantine vaut midi sans repas — aucune colonne repas, le goûter
        // reste compté.
        if ($service === 'CANT' && in_array($child_id, $csr_ids, true)) continue;
        if ($service === 'CANT') {
            $expected_rows[$jour][$kind_by_child[$child_id]]++;
            $expected_rows[$jour]['midi']++;
        }
        if ($service === 'GS') {
            $expected_rows[$jour]['gouter']++;
        }
    }
    $expected_totaux = array('standard' => 0, 'sans_porc' => 0, 'vegetarien' => 0, 'midi' => 0, 'gouter' => 0);
    foreach ($expected_rows as $row) {
        foreach ($expected_totaux as $k => $v) $expected_totaux[$k] += $row[$k];
    }

    $expected = array(
        'rows'  => $expected_rows,
        'totaux'          => $expected_totaux,
        'total'           => $expected_totaux['midi'],
        'total_standard'  => $expected_totaux['standard'],
        'total_sans_porc' => $expected_totaux['sans_porc'],
        'total_vegetarien' => $expected_totaux['vegetarien'],
        'total_gouters'   => $expected_totaux['gouter'],
    );

    WP_CLI::log('');
    WP_CLI::log("Année scolaire (figurante) ... {$config['school_year_label']} (id $school_year_id)");
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
        'year_id'       => $school_year_id,
        'parent_id'     => $parent_id,
        'child_ids'     => $child_ids,
        'expected'      => $expected,
    )));

    WP_CLI::success('Seed commande fournisseur prêt.');
});
