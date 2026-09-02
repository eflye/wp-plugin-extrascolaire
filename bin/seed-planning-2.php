<?php
/**
 * Seed idempotent pour tests/planning-2.spec.ts.
 *
 * Usage :
 *   wp --require=bin/seed-planning-2.php seed-planning-2
 *
 * Met en place l'écran « Planning - 2 » (rythme + exceptions) :
 *
 *   - une famille de test (e-mail dédié, purgée puis recréée à chaque run) ;
 *   - TROIS enfants : Alice (rythme CANT lun-ven, assurance fournie),
 *     Bob (aucun rythme, assurance fournie) et Chloé (SANS justificatif —
 *     le scénario durcit précisément ce cas : l'ajout exceptionnel doit y
 *     être refusé, le retrait et le retour au rythme doivent passer) ;
 *   - la configuration d'année scolaire du planning (ensure_default) ;
 *   - un « mois de travail » : le premier mois de l'année qui compte au
 *     moins cinq jours d'école, avec deux dates non verrouillées :
 *     pattern_date (CANT posé par le rythme) et free_date (aucune
 *     déclaration). Le mois courant peut tomber hors période (été) — le
 *     seed ne fait jamais d'hypothèse sur la date d'exécution.
 *
 * Aucune EXCEPTION n'est créée ici : la table des exceptions doit ressortir
 * vide du seed, l'invariant d'écriture (aucune ligne résiduelle après
 * cocher/décocher) est vérifié par le scénario lui-même, base à l'appui.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-planning-2', function () {
    global $wpdb;

    if (!class_exists('Psc_Installer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $config = array(
        'parent_email'    => 'planning2.e2e@example.test',
        'parent_nom'      => 'PlanningTwoTest',
        'enfant_a_prenom' => 'Alice',
        'enfant_b_prenom' => 'Bob',
        'enfant_c_prenom' => 'Chloe',
        'nom'             => 'PlanningTwoTest',
        'page_slug'       => null, // non utilisé ; le parcours passe par le portail du site
    );

    /* ---------------------------------------------------------------- */
    /* Purge — les données de CE seed uniquement                          */
    /* ---------------------------------------------------------------- */

    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');

    $parent_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $config['parent_email']));
    if ($parent_id) {
        $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $parent_id));
        foreach ((array) $child_ids as $cid) {
            Psc_Planning::delete_for_child((int) $cid);
            $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid), array('%d'));
        }
        $wpdb->delete($t_child, array('parent_id' => $parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $parent_id), array('%d'));
    }

    /* ---------------------------------------------------------------- */
    /* Année scolaire du planning                                        */
    /* ---------------------------------------------------------------- */

    Psc_School_Year::ensure_default();
    $year = Psc_School_Year::active();
    if (!$year) {
        WP_CLI::error("Aucune configuration d'année scolaire — le scénario Planning - 2 n'a pas de terrain.");
    }

    // Premier mois comptant au moins DEUX jours d'école non verrouillés à
    // venir : le mois courant peut être hors période (août) ou entièrement
    // passé — le scénario a besoin de deux dates cliquables, jamais
    // d'hypothèse sur la date d'exécution.
    $today = current_time('Y-m-d');
    $month_key = '';
    $days = array();
    foreach (Psc_School_Year::months($year->year_key) as $m) {
        $days = Psc_School_Year::school_days_in_month($m['key']);
        $future = array_values(array_filter($days, function ($d) use ($today) {
            return !psc_is_locked($d) && $d >= $today;
        }));
        if (count($future) >= 2) {
            $month_key = $m['key'];
            break;
        }
    }
    if ($month_key === '') {
        WP_CLI::error("Aucun mois exploitable dans l'année scolaire (moins de deux jours d'école non verrouillés).");
    }

    $pattern_date = null; // jour d'école non verrouillé porté par le rythme d'Alice
    $free_date    = null; // jour d'école non verrouillé sans déclaration

    foreach ($days as $d) {
        if (psc_is_locked($d) || $d < $today) continue;
        if ($pattern_date === null) $pattern_date = $d;
        elseif ($free_date === null) $free_date = $d;
    }
    if (!$pattern_date || !$free_date) {
        WP_CLI::error("Pas assez de jours d'école non verrouillés dans $month_key.");
    }

    /* ---------------------------------------------------------------- */
    /* Famille + enfants                                                  */
    /* ---------------------------------------------------------------- */

    $wpdb->insert($t_parent, array(
        'email'      => $config['parent_email'],
        'nom'        => $config['parent_nom'],
        'active'     => 1,
        'onboarding_seen_at' => current_time('mysql'),
        'created_at' => current_time('mysql'),
    ), array('%s', '%s', '%d', '%s', '%s'));
    $parent_id = (int) $wpdb->insert_id;

    $year_id = Psc_School_Years::active_id();

    $make_child = function ($prenom, $classe) use ($wpdb, $t_child, $parent_id, $year_id, $config) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $config['nom'],
            'prenom'     => $prenom,
            'statut'     => 'actif',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($child_id, $year_id, $classe, 'inscrit', current_time('mysql'));
        return $child_id;
    };

    $alice_id = $make_child($config['enfant_a_prenom'], 'CM1');
    $bob_id   = $make_child($config['enfant_b_prenom'], 'CP');
    $chloe_id = $make_child($config['enfant_c_prenom'], 'CE2');

    // Rythme d'Alice : CANT lun-ven (les autres prestations restent vides,
    // la grille doit refléter exactement ces cinq lignes).
    foreach (array(1, 2, 4, 5) as $wd) {
        Psc_Planning::toggle_pattern($alice_id, $year->year_key, $wd, 'CANT', true);
    }

    // Assurances Alice + Bob : fichier réel dans le répertoire privé + chemin
    // en base (même technique que bin/seed-journey.php:psc_seed_attach_assurance).
    // Chloé n'en a PAS : c'est le cas durci du scénario.
    $attach_assurance = function ($child_id) use ($wpdb) {
        psc_ensure_private_dir();
        $rel = Psc_Assurances::BASE . '/seed/child-' . (int) $child_id . '.pdf';
        $abs = psc_private_path($rel);
        wp_mkdir_p(dirname($abs));
        if (!file_exists($abs)) {
            $written = @file_put_contents($abs, // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
                "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
            );
            if ($written === false) {
                WP_CLI::error("Écriture impossible dans $abs (dossier privé non accessible en écriture ?).");
            }
        }
        $wpdb->update(
            psc_table('child_school_years'),
            array(
                'assurance_file_path'         => $rel,
                'assurance_original_filename' => 'attestation-assurance.pdf',
                'assurance_uploaded_at'       => current_time('mysql'),
            ),
            array('child_id' => (int) $child_id, 'school_year_id' => Psc_School_Years::active_id()),
            array('%s', '%s', '%s'),
            array('%d', '%d')
        );
    };
    $attach_assurance($alice_id);
    $attach_assurance($bob_id);

    Psc_Planning::flush_cache();

    // L'invariant du scénario porte sur les jours NON verrouillés (ceux que
    // les clics modifient) : les jours passés légitiment figés par la pose
    // du rythme (état d'avant = non déclaré) ne comptent pas.
    $rows = $wpdb->get_results(
        "SELECT jour_date FROM " . psc_table('exception') . "
         WHERE child_id IN (" . implode(',', array($alice_id, $bob_id, $chloe_id)) . ")"
    );
    $unlocked = 0;
    foreach ((array) $rows as $r) {
        if (!psc_is_locked($r->jour_date)) $unlocked++;
    }
    if ($unlocked !== 0) {
        WP_CLI::error("La seed a produit $unlocked exception(s) sur des jours non verrouillés — la table doit en sortir vide pour que l'invariant soit vérifiable.");
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('');
    WP_CLI::log("Parent ................. {$config['parent_email']} (id $parent_id)");
    WP_CLI::log("Enfants ................ Alice ($alice_id, rythme CANT + assurance), Bob ($bob_id, assurance), Chloé ($chloe_id, SANS assurance)");
    WP_CLI::log("Année .................. {$year->year_key}");
    WP_CLI::log("Mois de travail ........ $month_key");
    WP_CLI::log("pattern_date ........... $pattern_date");
    WP_CLI::log("free_date .............. $free_date");

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'parent_id'    => $parent_id,
        'parent_email' => $config['parent_email'],
        'alice_id'     => $alice_id,
        'bob_id'       => $bob_id,
        'chloe_id'     => $chloe_id,
        'year_key'     => $year->year_key,
        'month'        => $month_key,
        'pattern_date' => $pattern_date,
        'free_date'    => $free_date,
    )));

    WP_CLI::success('Seed Planning - 2 prêt.');
});
