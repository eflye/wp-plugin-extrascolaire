<?php
/**
 * Script de vérification autonome pour Psc_Pickup_Persons — pas de
 * harnais PHPUnit dans ce projet (Playwright seul pour les tests E2E),
 * même rôle que bin/verify-promotion-logic.php : ce script joue celui du
 * test unitaire, en conditions WP-CLI réelles.
 *
 * Vérifie l'exigence centrale de la feature : un ajout, une modification
 * et un retrait produisent CHACUN exactement une entrée d'historique, et
 * aucune entrée déjà écrite n'est jamais modifiée par une action
 * ultérieure (re-vérifié explicitement après l'update()).
 *
 * Usage :
 *   wp --require=bin/verify-pickup-history.php verify-pickup-history
 *
 * Toutes les données créées sont scopées par l'adresse e-mail d'un
 * parent de test dédié, purgées avant ET après le run : ne laisse rien
 * derrière lui.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-pickup-history', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_Pickup_Persons')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_pickup = psc_table('pickup_persons');
    $t_pkhist = psc_table('pickup_history');

    $email = 'verif.pickup@example.test';
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

    $purge = function () use ($wpdb, $t_parent, $t_child, $t_pickup, $t_pkhist, $email) {
        $parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if ($parent_id) {
            $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $parent_id));
            if ($child_ids) {
                $ph = implode(',', array_fill(0, count($child_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_pkhist WHERE child_id IN ($ph)", $child_ids));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_pickup WHERE child_id IN ($ph)", $child_ids));
            }
            $wpdb->delete($t_child, array('parent_id' => $parent_id), array('%d'));
            $wpdb->delete($t_parent, array('id' => $parent_id), array('%d'));
        }
    };

    WP_CLI::log('Purge préalable…');
    $purge();

    $parent_id = Psc_Parents::create($email, 'Vérification');
    if (is_wp_error($parent_id)) {
        WP_CLI::error('Création du parent de test : ' . $parent_id->get_error_message());
    }

    $wpdb->insert($t_child, array(
        'parent_id'  => $parent_id,
        'nom'        => 'Test',
        'prenom'     => 'Verif',
        'statut'     => 'actif',
        'created_at' => current_time('mysql'),
    ), array('%d', '%s', '%s', '%s', '%s'));
    $child_id = (int) $wpdb->insert_id;

    $count_history = function () use ($wpdb, $t_pkhist, $child_id) {
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_pkhist WHERE child_id = %d", $child_id));
    };
    $latest_history = function () use ($wpdb, $t_pkhist, $child_id) {
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_pkhist WHERE child_id = %d ORDER BY id DESC LIMIT 1", $child_id
        ));
    };

    /* ---------------------------------------------------------------- */
    /* 1. add() : une personne + une entrée 'ajout'                      */
    /* ---------------------------------------------------------------- */

    WP_CLI::log("Vérification de add()…");

    $person_id = Psc_Pickup_Persons::add($child_id, array(
        'nom' => 'Dupont', 'prenom' => 'Jean', 'telephone' => '0600000000', 'lien' => 'Voisin',
    ), 'mairie');
    $assert('add() renvoie un id positif', is_int($person_id) && $person_id > 0, true);
    $assert("1 entrée d'historique après add()", $count_history(), 1);
    $assert('1 personne dans la liste courante après add()', count(Psc_Pickup_Persons::for_child($child_id)), 1);

    $hist1 = $latest_history();
    $assert("action de l'entrée 1 = 'ajout'", $hist1 ? $hist1->action : null, 'ajout');
    $snap1 = $hist1 ? json_decode($hist1->person_snapshot, true) : array();
    $assert('snapshot 1 : nom correct', $snap1['nom'] ?? null, 'Dupont');
    $assert('snapshot 1 : téléphone correct', $snap1['telephone'] ?? null, '0600000000');

    /* ---------------------------------------------------------------- */
    /* 2. update() : même personne, une entrée 'modification' de plus,   */
    /*    et l'entrée 1 ne doit JAMAIS avoir changé.                     */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Vérification de update() et de l\'immutabilité de l\'entrée précédente…');

    $update_result = Psc_Pickup_Persons::update($person_id, array(
        'nom' => 'Dupont', 'prenom' => 'Jean', 'telephone' => '0611111111', 'lien' => 'Voisin',
    ), 'mairie');
    $assert('update() réussit', $update_result === true, true);
    $assert("2 entrées d'historique après update()", $count_history(), 2);
    $assert(
        "toujours 1 personne (même ligne modifiée en place, pas d'id supplémentaire)",
        count(Psc_Pickup_Persons::for_child($child_id)), 1
    );

    $hist2 = $latest_history();
    $assert("action de l'entrée 2 = 'modification'", $hist2 ? $hist2->action : null, 'modification');
    $snap2 = $hist2 ? json_decode($hist2->person_snapshot, true) : array();
    $assert('snapshot 2 : téléphone mis à jour', $snap2['telephone'] ?? null, '0611111111');

    // Ré-interroge explicitement l'entrée 1 par son id : aucune méthode de
    // Psc_Pickup_Persons ne doit jamais l'avoir touchée.
    $hist1_recheck = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_pkhist WHERE id = %d", $hist1->id));
    $assert("entrée 1 toujours 'ajout' après update()", $hist1_recheck ? $hist1_recheck->action : null, 'ajout');
    $snap1_recheck = $hist1_recheck ? json_decode($hist1_recheck->person_snapshot, true) : array();
    $assert(
        "snapshot de l'entrée 1 jamais réécrit (ancien téléphone conservé)",
        $snap1_recheck['telephone'] ?? null, '0600000000'
    );

    /* ---------------------------------------------------------------- */
    /* 3. remove() : soft-delete + une entrée 'retrait' de plus           */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Vérification de remove() (soft-delete)…');

    $remove_result = Psc_Pickup_Persons::remove($person_id, 'mairie');
    $assert('remove() réussit', $remove_result === true, true);
    $assert("3 entrées d'historique après remove()", $count_history(), 3);
    $assert('0 personne dans la liste courante après remove()', count(Psc_Pickup_Persons::for_child($child_id)), 0);

    $person_row = Psc_Pickup_Persons::get($person_id);
    $assert('la ligne pickup_persons existe toujours (jamais de DELETE physique)', $person_row !== null, true);
    $assert("statut de la ligne = 'retiree'", $person_row ? $person_row->statut : null, 'retiree');
    $assert('retiree_le renseigné', empty($person_row->retiree_le), false);

    $hist3 = $latest_history();
    $assert("action de l'entrée 3 = 'retrait'", $hist3 ? $hist3->action : null, 'retrait');

    // Les 3 entrées précédentes doivent toutes exister encore, intactes.
    $all_actions = $wpdb->get_col($wpdb->prepare(
        "SELECT action FROM $t_pkhist WHERE child_id = %d ORDER BY id ASC", $child_id
    ));
    $assert("séquence complète de l'historique", $all_actions, array('ajout', 'modification', 'retrait'));

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
        WP_CLI::error('La logique de personnes autorisées / historique ne correspond pas au comportement attendu.');
    }

    WP_CLI::success('Historique des personnes autorisées conforme.');
});
