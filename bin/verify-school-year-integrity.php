<?php
/**
 * Script de vérification autonome (P2-08) — activation d'une année et
 * passage d'année, tout ou rien. Même rôle que bin/verify-promotion-logic.php.
 *
 * Vérifie :
 *  - réactiver l'année active ne change rien ; il y a toujours exactement
 *    une année active ;
 *  - une activation qui échoue à mi-course laisse l'année d'avant active
 *    (jamais de site sans année active) ;
 *  - l'année activée a sa configuration de calendrier (school_year) ;
 *  - un passage d'année qui échoue sur un enfant n'en promeut aucun, et
 *    rejouer un passage déjà appliqué ne duplique rien.
 *
 * Usage :
 *   wp --require=bin/verify-school-year-integrity.php verify-school-year-integrity
 *
 * Crée deux années de test (libellés 2091-2092 et 2092-2093), les active
 * le temps du test puis RÉACTIVE toujours l'année active d'origine ;
 * données purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-school-year-integrity', function () {

    if (!class_exists('Psc_School_Years') || !class_exists('Psc_School_Year')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $labels   = array('2091-2092', '2092-2093');
    $email    = 'verify-school-year@example.invalid';
    $original = Psc_School_Years::active_id();

    $purge = function () use ($wpdb, $labels, $t_years, $t_cy, $t_parent, $t_child, $email, $original) {
        if ($original) Psc_School_Years::activate($original);
        foreach ($labels as $label) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_years WHERE year_key = %s", $label)) as $id) {
                if ((int) $id === (int) Psc_School_Years::active_id()) {
                    $wpdb->update($t_years, array('statut' => 'archivee'), array('id' => (int) $id));
                }
                $wpdb->delete($t_cy, array('school_year_id' => (int) $id));
                $wpdb->delete($t_years, array('id' => (int) $id));
            }
        }
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if ($pid) {
            $wpdb->delete($t_child, array('parent_id' => $pid));
            $wpdb->delete($t_parent, array('id' => $pid));
        }
        Psc_School_Year::flush_cache();
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $actives = function () use ($wpdb, $t_years) {
        return array_map('intval', $wpdb->get_col("SELECT id FROM $t_years WHERE statut = 'active'"));
    };

    try {
        $y1 = Psc_School_Years::create('2091-09-01', '2092-07-04');
        $y2 = Psc_School_Years::create('2092-09-01', '2093-07-04');
        if (is_wp_error($y1) || is_wp_error($y2)) WP_CLI::error('Années de test impossibles à créer.');

        // 1. Activation, puis réactivation sans effet.
        $check(Psc_School_Years::activate($y1) === true, 'activation refusée');
        $check(Psc_School_Years::activate($y1) === true, 'réactivation de l’année active refusée');
        $check($actives() === array((int) $y1), 'activation : années actives ' . wp_json_encode($actives()));

        // 2. Le calendrier de l'année activée est la même ligne (4.15.0).
        Psc_School_Year::flush_cache();
        $check((bool) Psc_School_Year::get($labels[0]), 'activation : configuration du calendrier absente');

        // 3. Activation en échec à mi-course : l'année d'avant reste active.
        $sabotage = function ($sql) use ($t_years) {
            return (strpos($sql, "UPDATE `$t_years` SET `statut` = 'active'") === 0) ? 'SELECT * FROM psc_table_inexistante' : $sql;
        };
        add_filter('query', $sabotage);
        $suppress = $wpdb->suppress_errors(true);
        $result = Psc_School_Years::activate($y2);
        $wpdb->suppress_errors($suppress);
        remove_filter('query', $sabotage);
        $check($result === false, 'activation en échec annoncée réussie');
        $check($actives() === array((int) $y1), 'activation en échec : site laissé avec ' . wp_json_encode($actives()) . ' comme année(s) active(s)');

        // 4. Passage d'année en échec sur le second enfant : aucun promu.
        $wpdb->insert($t_parent, array('email' => $email, 'nom' => 'VerifyYear', 'active' => 1, 'created_at' => current_time('mysql')));
        $pid = (int) $wpdb->insert_id;
        $kids = array();
        foreach (array('Lou', 'Noa') as $prenom) {
            $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'VerifyYear', 'prenom' => $prenom, 'created_at' => current_time('mysql')));
            $kids[] = (int) $wpdb->insert_id;
        }
        $plan = array(
            array('child_id' => $kids[0], 'classe_proposee' => 'CE1'),
            array('child_id' => $kids[1], 'classe_proposee' => 'CE2'),
        );
        $sabotage_enroll = function ($sql) use ($t_cy, $kids) {
            return (strpos($sql, "INSERT INTO `$t_cy`") === 0 && strpos($sql, "'CE2'") !== false) ? 'SELECT * FROM psc_table_inexistante' : $sql;
        };
        add_filter('query', $sabotage_enroll);
        $suppress = $wpdb->suppress_errors(true);
        $result = Psc_School_Years::apply_promotion($y2, $plan);
        $wpdb->suppress_errors($suppress);
        remove_filter('query', $sabotage_enroll);
        $check(is_wp_error($result), 'passage en échec annoncé réussi');
        $enrolled = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_cy WHERE school_year_id = %d", $y2));
        $check($enrolled === 0, "passage en échec : $enrolled enfant(s) promu(s) malgré l’annulation");

        // 5. Passage réussi, puis rejoué : aucun doublon.
        $check(Psc_School_Years::apply_promotion($y2, $plan) === 2, 'passage réussi : compte inexact');
        $check(Psc_School_Years::apply_promotion($y2, $plan) === 2, 'passage rejoué : compte inexact');
        $enrolled = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_cy WHERE school_year_id = %d", $y2));
        $check($enrolled === 2, "passage rejoué : $enrolled lignes au lieu de 2");
    } finally {
        $purge();
    }

    $check(Psc_School_Years::active_id() === $original, 'année active d’origine non restaurée');

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Années scolaires : $checks vérifications.");
});
