<?php
/**
 * Seed idempotent pour tests/sidscm.spec.ts.
 *
 * Usage :
 *   wp --require=bin/seed-sidscm.php seed-sidscm
 *
 * Met en place l'écran « Espace intervenants » (SIDSCM) pour le scénario
 * de pointage :
 *
 *   - code d'accès configuré (psc_sidscm_access_code) : la valeur de test
 *     ÉCRASE celle du site — l'écran n'a aucun code sur une installation
 *     neuve, et le scénario doit franchir l'écran de verrouillage. Sur un
 *     site de démonstration, reconfigurer le code après le run si besoin ;
 *   - page dédiée au shortcode [periscolaire_sidscm], créée une fois puis
 *     retrouvée par son slug aux runs suivants ;
 *   - une famille de test (parent + deux enfants actifs, noms et e-mail
 *     dédiés pour ne jamais croiser les autres seeds), purgée puis
 *     recréée à chaque run ;
 *   - un trimestre couvrant la semaine réellement en cours (recréé à
 *     chaque run : ses dates doivent suivre le calendrier) et des
 *     inscriptions GM + CANT + GS pour Nina, GS seul pour Marco, sur
 *     TOUS les jours ouverts de la semaine — psc_open_days(), le même
 *     calcul que l'écran, exclut déjà vacances, fériés et fermetures.
 *
 * Aucune ligne de pointage (wp_psc_attendance) n'est créée ici : les
 * enfants sont « présents par défaut » tant qu'ils n'ont jamais été
 * pointés explicitement — c'est le contrat même du scénario.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-sidscm', function () {
    global $wpdb;

    if (!class_exists('Psc_Installer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $config = array(
        'parent_email'    => 'sidscm.e2e@example.test',
        'parent_nom'      => 'SidscmTest',
        'enfant_a_prenom' => 'Nina',
        'enfant_b_prenom' => 'Marco',
        'nom'             => 'SidscmTest',
        'access_code'     => 'E2E-SIDSCM-42',
        'page_slug'       => 'acces-intervenants-e2e',
        'page_title'      => 'Accès intervenants (e2e)',
        'trim_label'      => 'E2E SIDSCM',
    );

    /* ---------------------------------------------------------------- */
    /* Purge — les données de CE seed uniquement                          */
    /* ---------------------------------------------------------------- */

    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_reg    = psc_table('registrations');
    $t_att    = psc_table('attendance');
    $t_trim   = psc_table('trimestres');

    $parent_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $config['parent_email']));
    if ($parent_id) {
        $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $parent_id));
        foreach ((array) $child_ids as $cid) {
            $wpdb->delete($t_att, array('child_id' => (int) $cid), array('%d'));
            $wpdb->delete($t_reg, array('child_id' => (int) $cid), array('%d'));
        }
        $wpdb->delete($t_child, array('parent_id' => $parent_id), array('%d'));
        $wpdb->delete($t_parent, array('id' => $parent_id), array('%d'));
    }
    // Le trimestre d'un run antérieur : ses dates ne correspondent plus à
    // la semaine courante. La cascade (FK registrations.trimestre_id)
    // emporte ses inscriptions — uniquement les nôtres.
    $wpdb->query($wpdb->prepare("DELETE FROM $t_trim WHERE label = %s", $config['trim_label']));

    /* ---------------------------------------------------------------- */
    /* Code d'accès + page du shortcode                                   */
    /* ---------------------------------------------------------------- */

    update_option('psc_sidscm_access_code', $config['access_code']);

    $page_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish'",
        $config['page_slug']
    ));
    if (!$page_id) {
        $page_id = (int) wp_insert_post(array(
            'post_title'   => $config['page_title'],
            'post_name'    => $config['page_slug'],
            'post_content' => '[periscolaire_sidscm]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ));
        if (!$page_id || is_wp_error($page_id)) {
            WP_CLI::error('Création de la page SIDSCM impossible.');
        }
    }
    update_option('psc_sidscm_page_id', $page_id);
    $page_url = get_permalink($page_id);

    /* ---------------------------------------------------------------- */
    /* Famille + enfants                                                  */
    /* ---------------------------------------------------------------- */

    $wpdb->insert($t_parent, array(
        'email'      => $config['parent_email'],
        'nom'        => $config['parent_nom'],
        'active'     => 1,
        'created_at' => current_time('mysql'),
    ), array('%s', '%s', '%d', '%s'));
    $parent_id = (int) $wpdb->insert_id;

    $make_child = function ($prenom) use ($wpdb, $t_child, $parent_id, $config) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $config['nom'],
            'prenom'     => $prenom,
            'statut'     => 'actif',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));
        return (int) $wpdb->insert_id;
    };
    $enfant_a_id = $make_child($config['enfant_a_prenom']);
    $enfant_b_id = $make_child($config['enfant_b_prenom']);

    /* ---------------------------------------------------------------- */
    /* Trimestre + inscriptions de la semaine courante                    */
    /* ---------------------------------------------------------------- */

    $monday = psc_week_start(current_time('Y-m-d'));
    // Le même calcul que Psc_Sidscm::ajax_data() : la seed et l'écran
    // voient toujours les mêmes jours, vacances/fériés compris.
    $open_days = psc_open_days($monday);
    if (empty($open_days)) {
        WP_CLI::error("Semaine sans jour ouvert (vacances, calendrier non chargé ?) — le scénario SIDSCM n'a pas de terrain.");
    }

    $wpdb->insert($t_trim, array(
        'label'      => $config['trim_label'],
        'date_debut' => $monday,
        'date_fin'   => end($open_days),
        'active'     => 0,
    ), array('%s', '%s', '%s', '%d'));
    $trim_id = (int) $wpdb->insert_id;

    foreach ($open_days as $jour => $date) {
        foreach (array('GM', 'CANT', 'GS') as $service) {
            $wpdb->insert($t_reg, array(
                'child_id'      => $enfant_a_id,
                'trimestre_id'  => $trim_id,
                'jour_date'     => $date,
                'service'       => $service,
                'updated_at'    => current_time('mysql'),
            ), array('%d', '%d', '%s', '%s', '%s'));
        }
        $wpdb->insert($t_reg, array(
            'child_id'      => $enfant_b_id,
            'trimestre_id'  => $trim_id,
            'jour_date'     => $date,
            'service'       => 'GS',
            'updated_at'    => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s'));
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('');
    WP_CLI::log("Page SIDSCM ............. $page_url");
    WP_CLI::log("Code d'accès ............ {$config['access_code']}");
    WP_CLI::log("Enfants ................. {$config['enfant_a_prenom']} (id $enfant_a_id), {$config['enfant_b_prenom']} (id $enfant_b_id)");
    WP_CLI::log('Jours ouverts ...........' . wp_json_encode($open_days));

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'page_url'        => $page_url,
        'access_code'     => $config['access_code'],
        'enfant_a_id'     => $enfant_a_id,
        'enfant_a_prenom' => $config['enfant_a_prenom'],
        'enfant_b_id'     => $enfant_b_id,
        'enfant_b_prenom' => $config['enfant_b_prenom'],
        'first_jour'      => array_key_first($open_days),
        'first_date'      => reset($open_days),
    )));

    WP_CLI::success('Seed SIDSCM prêt.');
});
