<?php
/**
 * Script de vérification autonome pour Psc_School_Years::build_promotion_plan()
 * / apply_promotion() — pas de harnais PHPUnit dans ce projet (Playwright
 * seul pour les tests E2E) et aucune nouvelle dépendance Composer n'est
 * ajoutée pour ça : ce script joue le rôle du test unitaire, en conditions
 * WP-CLI réelles (progression par défaut + table de correspondance
 * personnalisée, déduction par date de naissance, exclusion des enfants
 * sortis, application avec correction manuelle d'une ligne).
 *
 * Usage :
 *   wp --require=bin/verify-promotion-logic.php verify-promotion-logic
 *
 * Toutes les données créées (années, enfants, parent) sont scopées par un
 * préfixe de libellé dédié, purgées avant ET après le run : ne laisse rien
 * derrière lui, contrairement aux seed-*.php destinés à Playwright.
 * N'active jamais les années créées (Psc_School_Years::activate() est
 * volontairement évité) : ce script ne doit jamais perturber l'année
 * active réelle du site.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-promotion-logic', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_School_Years')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');

    // wp_psc_school_years.label est VARCHAR(20) : Psc_School_Years::create()
    // tronque silencieusement au-delà (mb_substr(...,0,20)), donc le préfixe
    // + suffixe le plus long ('N+1') doit tenir dans cette limite pour que
    // les deux années de test restent distinguables par leur libellé.
    $label_prefix  = 'Vérif. promo — ';
    $parent_email  = 'verif.promotion@example.test';

    $failures = array();
    $checks   = 0;

    $assert = function ($label, $actual, $expected) use (&$failures, &$checks) {
        $checks++;
        if ($actual !== $expected) {
            $failures[] = sprintf(
                "%s : attendu %s, obtenu %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            );
        }
    };

    /* ---------------------------------------------------------------- */
    /* Purge (avant ET après, idempotent — mêmes principes que seed-*.php) */
    /* ---------------------------------------------------------------- */

    $purge = function () use ($wpdb, $t_parent, $t_child, $t_years, $t_cy, $label_prefix, $parent_email) {
        $parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $parent_email));
        if ($parent_id) {
            $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $parent_id));
            if ($child_ids) {
                $ph = implode(',', array_fill(0, count($child_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_cy WHERE child_id IN ($ph)", $child_ids));
            }
            $wpdb->delete($t_child, array('parent_id' => $parent_id), array('%d'));
            $wpdb->delete($t_parent, array('id' => $parent_id), array('%d'));
        }
        $year_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_years WHERE label LIKE %s", $label_prefix . '%'));
        foreach ($year_ids as $yid) {
            $wpdb->delete($t_cy, array('school_year_id' => $yid), array('%d'));
            $wpdb->delete($t_years, array('id' => $yid), array('%d'));
        }
    };

    WP_CLI::log('Purge préalable…');
    $purge();

    /* ---------------------------------------------------------------- */
    /* Fixtures : deux années (ni l'une ni l'autre activée), un parent    */
    /* fictif, cinq enfants couvrant les cas limites.                    */
    /* ---------------------------------------------------------------- */

    $tz    = wp_timezone();
    $today = new DateTime('today', $tz);
    $from_debut = $today->format('Y-m-d');
    $from_fin   = (clone $today)->modify('+300 days')->format('Y-m-d');
    $to_debut   = (clone $today)->modify('+301 days')->format('Y-m-d');
    $to_fin     = (clone $today)->modify('+600 days')->format('Y-m-d');

    $from_year_id = Psc_School_Years::create($label_prefix . 'N', $from_debut, $from_fin);
    $to_year_id   = Psc_School_Years::create($label_prefix . 'N+1', $to_debut, $to_fin);
    if (is_wp_error($from_year_id) || is_wp_error($to_year_id)) {
        WP_CLI::error('Création des années de test impossible.');
    }

    $parent_id = Psc_Parents::create($parent_email, 'Vérification');
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent de test : ' . $parent_id->get_error_message());
    }

    // rentree_year de l'année N+1, utilisé par psc_classe_for_birthdate()
    // pour la fixture "classe inconnue" : un enfant né il y a 6 ans doit
    // être déduit en CP à cette rentrée-là (âge au 31/12).
    $rentree_year_to = (int) date('Y', strtotime($to_debut));
    $birthdate_for_cp = ($rentree_year_to - 6) . '-06-15';

    // L'enfant CM2 suppose que la table de correspondance (par défaut ou
    // personnalisée dans Réglages) fait toujours de CM2 une fin de cycle
    // ('sortie') — vrai dans tous les cas réalistes pour un périscolaire
    // d'école primaire française. Si ce site a une configuration exotique
    // qui contredit cette hypothèse, l'assertion "CM2 marqué sorti"
    // ci-dessous échouera légitimement : à ajuster manuellement plutôt
    // qu'à ignorer.
    $fixtures = array(
        'cp_vers_ce1'      => array('classe' => 'CP',  'naissance' => null,             'statut' => 'actif'),
        'cm2_vers_sortie'  => array('classe' => 'CM2', 'naissance' => null,             'statut' => 'actif'),
        'sans_classe'      => array('classe' => '',    'naissance' => $birthdate_for_cp, 'statut' => 'actif'),
        'enfant_sorti'     => array('classe' => 'CE2', 'naissance' => null,             'statut' => 'sorti'),
    );

    $child_ids = array();
    foreach ($fixtures as $key => $f) {
        $wpdb->insert($t_child, array(
            'parent_id'      => $parent_id,
            'nom'            => 'Test',
            'prenom'         => $key,
            'date_naissance' => $f['naissance'],
            'statut'         => $f['statut'],
            'created_at'     => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        $child_ids[$key] = $child_id;

        Psc_School_Years::enroll($child_id, $from_year_id, $f['classe'], 'inscrit');
    }

    /* ---------------------------------------------------------------- */
    /* build_promotion_plan() — progression par défaut                   */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Vérification de build_promotion_plan() (progression par défaut)…');

    $plan = Psc_School_Years::build_promotion_plan($from_year_id, $to_year_id);
    $by_child = array();
    foreach ($plan as $row) {
        $by_child[$row['child_id']] = $row;
    }

    $assert(
        "Enfant sorti absent du plan",
        isset($by_child[$child_ids['enfant_sorti']]),
        false
    );
    // Comparé à la progression réellement configurée (psc_classe_progression()),
    // pas à une valeur codée en dur : ce script vérifie que build_promotion_plan()
    // consulte bien la table de correspondance, quelle que soit celle
    // effectivement enregistrée sur ce site (Réglages a pu la personnaliser).
    $assert(
        "CP -> classe suivante d'après la table de correspondance configurée",
        $by_child[$child_ids['cp_vers_ce1']]['classe_proposee'] ?? null,
        psc_classe_superieure('CP')
    );
    $assert(
        "CM2 -> classe suivante d'après la table de correspondance configurée",
        $by_child[$child_ids['cm2_vers_sortie']]['classe_proposee'] ?? null,
        psc_classe_superieure('CM2')
    );
    $assert(
        "Classe inconnue déduite de la date de naissance -> CP",
        $by_child[$child_ids['sans_classe']]['classe_proposee'] ?? null,
        'CP'
    );

    /* ---------------------------------------------------------------- */
    /* apply_promotion() — avec une correction manuelle (override)       */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Vérification de apply_promotion() (avec override manuel)…');

    // Corrige manuellement l'enfant "sans_classe" en CE1 au lieu du CP
    // proposé, comme le ferait la mairie sur l'écran de récapitulatif.
    $overrides = array($child_ids['sans_classe'] => 'CE1');
    Psc_School_Years::apply_promotion($to_year_id, $plan, $overrides);

    $assert(
        "CP promu et inscrit dans sa classe suivante pour N+1",
        Psc_School_Years::classe_for($child_ids['cp_vers_ce1'], $to_year_id),
        psc_classe_superieure('CP')
    );
    $assert(
        "Override manuel respecté (CE1 au lieu du CP proposé)",
        Psc_School_Years::classe_for($child_ids['sans_classe'], $to_year_id),
        'CE1'
    );

    $cm2_child = $wpdb->get_row($wpdb->prepare("SELECT statut FROM $t_child WHERE id = %d", $child_ids['cm2_vers_sortie']));
    $assert(
        "CM2 marqué sorti après passage d'année",
        $cm2_child ? $cm2_child->statut : null,
        'sorti'
    );

    $sorti_enrollment = Psc_School_Years::enrollment($child_ids['enfant_sorti'], $to_year_id);
    $assert(
        "Enfant déjà sorti : aucune ligne créée pour N+1",
        $sorti_enrollment,
        null
    );

    /* ---------------------------------------------------------------- */
    /* Nettoyage                                                          */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Nettoyage…');
    $purge();

    WP_CLI::log('');
    WP_CLI::log(sprintf('%d vérification(s) effectuée(s), %d échec(s).', $checks, count($failures)));
    if ($failures) {
        foreach ($failures as $f) {
            WP_CLI::log('  ÉCHEC — ' . $f);
        }
        WP_CLI::error('La logique de passage d\'année ne correspond pas au comportement attendu.');
    }

    WP_CLI::success('Logique de passage d\'année conforme.');
});
