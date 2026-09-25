<?php
/**
 * Seed idempotent pour les captures d'écran de la documentation
 * administrateur (cf. docs-plan/PLAN.md, étape 04 et scripts/screenshots/).
 *
 * Usage :
 *   wp --require=bin/seed-docs-screenshots.php seed-docs-screenshots
 *
 * Jeu de données entièrement fictif (commune de Montgeroult, cf. section C
 * du plan) : purge puis recrée les données identifiées par l'adresse
 * e-mail du foyer Rivière — réexécutable N fois sans jamais dupliquer de
 * parent, d'enfant ou de demande. Ne touche à aucune autre donnée du site
 * (jamais de TRUNCATE), même principe que bin/seed-school-year-promotion.php.
 *
 * Contrairement aux seeds E2E, ce script ne teste rien : il peuple un état
 * plausible pour que chaque écran capturé par scripts/screenshots/capture.spec.ts
 * ait quelque chose à montrer. Certaines écritures passent par l'API
 * publique du plugin (Psc_Parents::create(), Psc_School_Years::enroll(),
 * Psc_Pickup_Persons::add(), Psc_Menus::save(), Psc_Planning::toggle_pattern())
 * quand elle suffit ; d'autres (facture, demande tierce, identité créancier
 * SEPA, justificatif d'assurance) écrivent directement en base, comme
 * tests/integration/pain008-fixture.php le fait déjà pour une facture de
 * test : générer une facture réelle exigerait de simuler tout un mois de
 * présences facturables, ce qui n'apporte rien à une simple capture d'écran.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-docs-screenshots', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_School_Years')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    // Les suites E2E fonctionnelles peuvent figer l'horloge du plugin et
    // afficher un bandeau réservé aux tests dans le backoffice. Les captures
    // de documentation doivent refléter une installation normale.
    delete_option('psc_test_frozen_now');

    $tz    = wp_timezone();
    $today = new DateTime('today', $tz);
    $fmt   = function (DateTime $d) { return $d->format('Y-m-d'); };

    $config = array(
        // Une année par rentrée : l'année en cours (couvrant aujourd'hui)
        // et la suivante, nommées d'après leur rentrée.
        'year_debut'    => psc_rentree_year() . '-09-01',
        'year_fin'      => (psc_rentree_year() + 1) . '-07-04',
        'parent_email'  => 'camille.riviere@example.invalid',
        'parent_nom'    => 'Rivière',
        'parent_prenom' => 'Camille',
        'request_email' => 'famille.dupont@example.invalid',
        'next_year_debut' => (psc_rentree_year() + 1) . '-09-01',
        'next_year_fin'   => (psc_rentree_year() + 2) . '-07-03',
    );
    $config['year_label']      = Psc_School_Years::key_for_start($config['year_debut']);
    $config['next_year_label'] = Psc_School_Years::key_for_start($config['next_year_debut']);

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');
    $t_req    = psc_table('requests');
    $t_inv    = psc_table('invoices');
    $t_pickup = psc_table('pickup_persons');
    $t_pkhist = psc_table('pickup_history');

    /* ---------------------------------------------------------------- */
    /* Purge — scoping strict à l'identité du profil (jamais un TRUNCATE)*/
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Purge des données existantes…');

    $old_parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $config['parent_email']));
    if ($old_parent_id) {
        $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $old_parent_id));
        if ($child_ids) {
            $ph = implode(',', array_fill(0, count($child_ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_cy WHERE child_id IN ($ph)", $child_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_pkhist WHERE child_id IN ($ph)", $child_ids));
            $wpdb->query($wpdb->prepare("DELETE FROM $t_pickup WHERE child_id IN ($ph)", $child_ids));
            foreach ($child_ids as $cid) Psc_Planning::delete_for_child($cid);
        }
        $wpdb->delete($t_inv, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
    }

    $wpdb->delete($t_req, array('email' => $config['request_email']), array('%s'));

    // L'année suivante est recréée à chaque exécution (sans inscription
    // résiduelle d'un passage d'année de démonstration précédent).
    $old_next = Psc_School_Years::get_by_key($config['next_year_label']);
    if ($old_next && $old_next->statut !== 'active') Psc_School_Years::delete((int) $old_next->id);
    Psc_School_Years::clear_staged_promotion();

    /* ---------------------------------------------------------------- */
    /* Année scolaire (dossier + configuration planning)                 */
    /* ---------------------------------------------------------------- */

    $wpdb->query("UPDATE $t_years SET statut = 'archivee' WHERE statut = 'preparation'");

    $year_id = Psc_School_Years::ensure($config['year_debut'], $config['year_fin']);
    if (is_wp_error($year_id)) {
        WP_CLI::error('Création de l\'année : ' . $year_id->get_error_message());
    }
    Psc_School_Years::activate($year_id);

    // Configuration du planning (wp_psc_school_year, distincte du dossier
    // ci-dessus, cf. class-psc-school-year.php) : garantit une année
    // couvrant aujourd'hui pour que les rythmes déclarés plus bas
    // (Psc_Planning::toggle_pattern) comptent comme présences réelles.
    Psc_School_Year::ensure_default();
    $planning_year = Psc_School_Year::active();
    if (!$planning_year) {
        WP_CLI::error('Aucune configuration de planning active — Psc_School_Year::ensure_default() a échoué.');
    }
    $year_key = $planning_year->year_key;

    /* ---------------------------------------------------------------- */
    /* Foyer Rivière                                                      */
    /* ---------------------------------------------------------------- */

    $parent_id = Psc_Parents::create($config['parent_email'], $config['parent_nom'], array(
        'prenom'                     => $config['parent_prenom'],
        'onboarding_seen_at'         => current_time('mysql'),
        'payment_mode'               => 'prelevement',
        'sepa_iban'                  => 'FR7630006000011234567890189',
        'sepa_bic'                   => 'AGRIFRPP',
        'sepa_titulaire'             => 'Camille Rivière',
        'sepa_mandate_ref'           => 'PSC-DOC-RIVIERE',
        'sepa_reglement_accepted_at' => $fmt((clone $today)->modify('-90 days')) . ' 09:00:00',
        'second_parent_prenom'       => 'Hugo',
        'second_parent_nom'          => 'Rivière',
        'second_parent_email'        => 'hugo.riviere@example.invalid',
    ));
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du foyer Rivière : ' . $parent_id->get_error_message());
    }

    $make_child = function ($prenom, $nom, $classe, $extra = array()) use ($wpdb, $t_child, $parent_id, $year_id) {
        $wpdb->insert($t_child, array_merge(array(
            'parent_id'  => $parent_id,
            'nom'        => $nom,
            'prenom'     => $prenom,
            'created_at' => current_time('mysql'),
        ), $extra), array_merge(array('%d', '%s', '%s', '%s', '%s'), array_fill(0, count($extra), '%s')));
        $child_id = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($child_id, $year_id, $classe, 'inscrit', current_time('mysql'));
        return $child_id;
    };

    // Noé (CP) : sans porc, personne autorisée Sophie Martin, justificatif
    // d'assurance en attente de revue.
    $noe_id = $make_child('Noé', 'Rivière', 'CP', array('sans_porc' => 1));

    // Alma (CE2) : allergie alimentaire déclarée et consentie — capture
    // de la case "Cet enfant a une allergie alimentaire" (étape 12).
    $alma_id = $make_child('Alma', 'Rivière', 'CE2', array(
        'food_allergies'          => 'Arachides, réaction cutanée',
        'food_allergy_consent_at' => current_time('mysql'),
    ));

    /* ---------------------------------------------------------------- */
    /* Rythme habituel — cantine du lundi au vendredi (hors mercredi)     */
    /* pour Noé et Alma : donne du contenu réel aux captures de présences */
    /* déclarées, d'annulation famille et de facturation.                */
    /* ---------------------------------------------------------------- */

    foreach (array($noe_id, $alma_id) as $child_id) {
        foreach (Psc_Planning::WEEKDAYS as $weekday) {
            Psc_Planning::toggle_pattern($child_id, $year_key, $weekday, 'CANT', true);
        }
    }

    /* ---------------------------------------------------------------- */
    /* Passage d'année — récapitulatif mis en attente de confirmation     */
    /* (Psc_School_Years::stage_promotion(), même mécanisme que l'écran   */
    /* réel : le récapitulatif n'affiche rien tant qu'un plan n'est pas   */
    /* posé en transient).                                                */
    /* ---------------------------------------------------------------- */

    $next_year_id = Psc_School_Years::create($config['next_year_debut'], $config['next_year_fin']);
    if (is_wp_error($next_year_id)) {
        WP_CLI::error('Création de l\'année suivante : ' . $next_year_id->get_error_message());
    }
    $promotion_plan = Psc_School_Years::build_promotion_plan($year_id, $next_year_id);
    Psc_School_Years::stage_promotion($year_id, $next_year_id, $promotion_plan);

    /* ---------------------------------------------------------------- */
    /* Personne autorisée — Sophie Martin sur Noé                        */
    /* ---------------------------------------------------------------- */

    $sophie_id = Psc_Pickup_Persons::add($noe_id, array(
        'nom'       => 'Martin',
        'prenom'    => 'Sophie',
        'telephone' => '0612345678',
        'lien'      => 'Grand-mère',
    ), 'admin');
    if (is_wp_error($sophie_id)) {
        WP_CLI::error('Ajout de Sophie Martin : ' . $sophie_id->get_error_message());
    }

    /* ---------------------------------------------------------------- */
    /* Justificatif d'assurance de Noé — en attente de revue             */
    /* ---------------------------------------------------------------- */

    // Les autres suites E2E peuvent laisser l'installation en validation
    // automatique. La capture documente explicitement la revue manuelle :
    // forcer ce réglage rend le statut « En attente » déterministe.
    update_option('psc_assurance_review_mode', 'manual');

    $wpdb->update($t_cy, array(
        'assurance_file_path'          => 'docs-fixtures/noe-assurance-2026.pdf',
        'assurance_original_filename'  => 'attestation-assurance-noe.pdf',
        'assurance_uploaded_at'        => current_time('mysql'),
        'assurance_status'             => 'pending',
    ), array('child_id' => $noe_id, 'school_year_id' => $year_id), array('%s', '%s', '%s', '%s'), array('%d', '%d'));

    /* ---------------------------------------------------------------- */
    /* Demande d'inscription tierce — en attente (modération)            */
    /* ---------------------------------------------------------------- */

    $wpdb->insert($t_req, array(
        'email'          => $config['request_email'],
        'nom'            => 'Dupont',
        'prenom'         => 'Marie',
        'telephone'      => '0698765432',
        'adresse'        => '4 rue des Écoles',
        'code_postal'    => '95650',
        'ville'          => 'Montgeroult',
        'children_json'  => wp_json_encode(array(array(
            'nom'            => 'Dupont',
            'prenom'         => 'Léo',
            'classe'         => 'CE1',
            'date_naissance' => $fmt((clone $today)->modify('-7 years')),
            'sans_porc'      => 0,
            'vegan'          => 0,
            'food_allergies' => '',
        ))),
        'verified'       => 1,
        'status'         => 'pending',
        'payment_mode'   => 'autre',
        'created_at'     => current_time('mysql'),
    ));
    if (!$wpdb->insert_id) {
        WP_CLI::error('Création de la demande tierce a échoué.');
    }

    /* ---------------------------------------------------------------- */
    /* Menu de cantine — semaine prochaine ouverte                       */
    /* ---------------------------------------------------------------- */

    $next_week = psc_next_open_week(gmdate('Y-m-d', strtotime('+7 days')));
    $menu_id = Psc_Menus::save(0, $next_week, array(
        'lundi'    => "Carottes râpées\nSauté de dinde, riz\nYaourt",
        'mardi'    => "Melon\nOmelette, ratatouille\nCompote",
        'jeudi'    => "Salade verte\nPoisson pané, purée\nFromage blanc",
        'vendredi' => "Tomates mozzarella\nGratin de courgettes\nFruit de saison",
    ), 'Origine France');
    if (is_wp_error($menu_id)) {
        WP_CLI::error('Saisie du menu de cantine : ' . $menu_id->get_error_message());
    }

    /* ---------------------------------------------------------------- */
    /* Factures — une générée et envoyée, une générée non envoyée         */
    /* ---------------------------------------------------------------- */

    $mois_envoye    = (clone $today)->modify('-1 month')->format('Y-m');
    $mois_a_envoyer = $today->format('Y-m');

    $wpdb->insert($t_inv, array(
        'parent_id'  => $parent_id,
        'mois'       => $mois_envoye,
        'total'      => 84.50,
        'pdf_path'   => null,
        'sent_at'    => (clone $today)->modify('-25 days')->format('Y-m-d H:i:s'),
        'created_at' => (clone $today)->modify('-28 days')->format('Y-m-d H:i:s'),
    ), array('%d', '%s', '%f', '%s', '%s', '%s'));
    if (!$wpdb->insert_id) {
        WP_CLI::error('Création de la facture envoyée a échoué.');
    }

    $wpdb->insert($t_inv, array(
        'parent_id'  => $parent_id,
        'mois'       => $mois_a_envoyer,
        'total'      => 92.00,
        'pdf_path'   => null,
        'sent_at'    => null,
        'created_at' => current_time('mysql'),
    ), array('%d', '%s', '%f', '%s', '%s', '%s'));
    if (!$wpdb->insert_id) {
        WP_CLI::error('Création de la facture non envoyée a échoué.');
    }

    /* ---------------------------------------------------------------- */
    /* Identité créancier SEPA — export pain.008                         */
    /* ---------------------------------------------------------------- */

    update_option('psc_billing_org_name', 'Mairie de Montgeroult');
    update_option('psc_billing_org_ics', 'FR15ZZZ612780');
    update_option('psc_billing_org_iban', psc_encrypt('FR7630006000011234567890189'), false);
    update_option('psc_billing_org_bic', 'AGRIFRPP', false);

    /* ---------------------------------------------------------------- */
    /* Fenêtre de réinscription — toujours ouverte au moment du run       */
    /* ---------------------------------------------------------------- */

    update_option('psc_reinscription_debut', $fmt((clone $today)->modify('-15 days')));
    update_option('psc_reinscription_fin', $fmt((clone $today)->modify('+45 days')));

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('');
    WP_CLI::log("Année (active) .......... {$config['year_label']} (id $year_id, year_key $year_key)");
    WP_CLI::log("Foyer Rivière ............ {$config['parent_email']} (id $parent_id)");
    WP_CLI::log("  Noé Rivière (CP, id $noe_id) — sans porc, assurance en attente, personne autorisée Sophie Martin (id $sophie_id)");
    WP_CLI::log("  Alma Rivière (CE2, id $alma_id) — allergie arachides consentie");
    WP_CLI::log("Demande tierce en attente : {$config['request_email']}");
    WP_CLI::log("Menu semaine du $next_week (id $menu_id)");
    WP_CLI::log("Factures : $mois_envoye (envoyée), $mois_a_envoyer (non envoyée)");
    WP_CLI::log("Passage d'année en attente : {$config['year_label']} -> {$config['next_year_label']} (id $next_year_id)");

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'parent_email'   => $config['parent_email'],
        'parent_id'      => $parent_id,
        'noe_id'         => $noe_id,
        'alma_id'        => $alma_id,
        'sophie_id'      => $sophie_id,
        'request_email'  => $config['request_email'],
        'year_id'        => $year_id,
        'year_key'       => $year_key,
        'next_year_id'   => $next_year_id,
        'next_week'      => $next_week,
        'invoice_mois_envoye'    => $mois_envoye,
        'invoice_mois_a_envoyer' => $mois_a_envoyer,
        'form_page_url'  => Psc_Mailer::form_page_url(),
    )));

    WP_CLI::success('Seed captures de documentation prêt.');
});
