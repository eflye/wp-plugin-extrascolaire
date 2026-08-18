<?php
/**
 * Seed idempotent pour tests/pickup-persons.spec.ts.
 *
 * Usage :
 *   wp --require=bin/seed-pickup-persons.php seed-pickup-persons
 *
 * Deux identités distinctes, purgées puis recréées à chaque run (jamais
 * de TRUNCATE, jamais d'autre donnée du site touchée) :
 *
 *   - "living" : un parent + un enfant actif déjà existants, pour le
 *     parcours "fiche vivante" (ajout/modification/retrait d'une
 *     personne autorisée depuis Mes enfants). Créé directement en base,
 *     comme les autres seeds — inutile de repasser par l'onboarding pour
 *     ce test-là.
 *   - "onboarding" : seule l'ADRESSE E-MAIL est réservée ici (purge du
 *     parent/enfant qu'un run précédent aurait pu créer en approuvant la
 *     demande). Le spec Playwright soumet lui-même le wizard public de
 *     bout en bout — la demande n'existe pas encore au moment où ce
 *     script tourne.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-pickup-persons', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_Pickup_Persons')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $config = array(
        'living_parent_email' => 'pickup.living.e2e@example.test',
        'living_parent_nom'   => 'PickupLiving',
        'living_child_prenom' => 'Nina',
        'living_child_nom'    => 'PickupTest',
        'living_child_classe' => 'CP',
        'onboarding_email'    => 'pickup.onboarding.e2e@example.test',
    );

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_reg    = psc_table('registrations');
    $t_cy     = psc_table('child_school_years');
    $t_pickup = psc_table('pickup_persons');
    $t_pkhist = psc_table('pickup_history');
    $t_req    = psc_table('requests');

    /* ---------------------------------------------------------------- */
    /* Purge — scoping strict à l'identité du profil (jamais un TRUNCATE)*/
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Purge des données existantes…');

    foreach (array($config['living_parent_email'], $config['onboarding_email']) as $email) {
        $old_parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if ($old_parent_id) {
            $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $old_parent_id));
            if ($child_ids) {
                $ph = implode(',', array_fill(0, count($child_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_reg WHERE child_id IN ($ph)", $child_ids));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_cy WHERE child_id IN ($ph)", $child_ids));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_pkhist WHERE child_id IN ($ph)", $child_ids));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_pickup WHERE child_id IN ($ph)", $child_ids));
            }
            $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
            $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
        }
    }
    $wpdb->delete($t_req, array('email' => $config['onboarding_email']), array('%s'));

    /* ---------------------------------------------------------------- */
    /* Recréation — uniquement la moitié "living"                        */
    /* ---------------------------------------------------------------- */

    // onboarding_seen_at fixé à la création : cette spec ne teste pas la
    // popin de découverte, qui bloquerait sinon les clics Playwright sur
    // le reste du portail (cf. templates/frontend-portal.php).
    $living_parent_id = Psc_Parents::create($config['living_parent_email'], $config['living_parent_nom'], array(
        'onboarding_seen_at' => current_time('mysql'),
    ));
    if (is_wp_error($living_parent_id)) {
        WP_CLI::error('Création du parent "living" : ' . $living_parent_id->get_error_message());
    }

    $wpdb->insert($t_child, array(
        'parent_id'  => $living_parent_id,
        'nom'        => $config['living_child_nom'],
        'prenom'     => $config['living_child_prenom'],
        'statut'     => 'actif',
        'created_at' => current_time('mysql'),
    ), array('%d', '%s', '%s', '%s', '%s'));
    $living_child_id = (int) $wpdb->insert_id;

    $active_year_id = Psc_School_Years::active_id();
    if ($active_year_id) {
        Psc_School_Years::enroll($living_child_id, $active_year_id, $config['living_child_classe'], 'inscrit', current_time('mysql'));
    }

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('');
    WP_CLI::log("Parent \"living\" ......... {$config['living_parent_email']} (id $living_parent_id)");
    WP_CLI::log("  Enfant ................. {$config['living_child_prenom']} {$config['living_child_nom']} (id $living_child_id)");
    WP_CLI::log("E-mail \"onboarding\" ..... {$config['onboarding_email']} (réservé, purgé — aucune demande créée ici)");

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'living_parent_email' => $config['living_parent_email'],
        'living_parent_id'    => $living_parent_id,
        'living_child_id'     => $living_child_id,
        'living_child_prenom' => $config['living_child_prenom'],
        'living_child_nom'    => $config['living_child_nom'],
        'onboarding_email'    => $config['onboarding_email'],
    )));

    WP_CLI::success('Seed personnes autorisées prêt.');
});
