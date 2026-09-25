<?php
/**
 * Script de vérification autonome (P2-08) — une seule année par rentrée,
 * statut de l'enfant porté par l'année. Même rôle que
 * bin/verify-school-year-integrity.php : celui du test unitaire, en
 * conditions WP-CLI réelles.
 *
 * Vérifie :
 *  - clé d'année déduite de la rentrée, une seule année par rentrée
 *    (création et correction refusées), recalage par ensure() ;
 *  - calendrier et dossier sur la même ligne ; dates d'une autre rentrée
 *    refusées au calendrier ;
 *  - « enfant actif » : inscrit à l'année, ni sorti ni absent ;
 *  - réinscription : un enfant décoché déjà réinscrit perd sa ligne de
 *    l'année cible, rien n'est retiré tant qu'un fichier est invalide ;
 *  - rétention : un enfant parti (ni inscrit à l'année active ni à celle
 *    en préparation) n'est visé qu'une fois son horloge dépassée.
 *
 * La migration 4.15.0 est couverte par bin/verify-migrations.php (base
 * jetable) : un doublon de clé ne peut pas être reproduit ici, la clé
 * unique portant les clés étrangères du planning.
 *
 * Usage :
 *   wp --require=bin/verify-school-year-model.php verify-school-year-model
 *
 * Années de test 2014-2015 à 2016-2017 et 2085-2086 / 2086-2087 ; la
 * seule activée (2085-2086, le temps d'un bloc) rend la main à l'année
 * active d'origine ; données scopées par une adresse e-mail dédiée, purgées avant
 * et après. Aucune purge réelle n'est exécutée : la sélection de la
 * rétention est lue, pas appliquée.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-school-year-model', function () {

    if (!class_exists('Psc_School_Years') || !class_exists('Psc_Frontend_Reinscription')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $email   = 'verify-school-year-model@example.invalid';
    $keys    = array('2014-2015', '2015-2016', '2016-2017', '2085-2086', '2086-2087');
    $t_years = psc_table('school_years');
    $t_child = psc_table('children');
    $t_par   = psc_table('parents');

    $original = Psc_School_Years::active_id();
    $purge = function () use ($wpdb, $email, $keys, $t_years, $t_child, $t_par, $original) {
        // L'année active d'origine est rétablie avant toute suppression.
        if ($original && Psc_School_Years::active_id() !== $original) Psc_School_Years::activate($original);
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $email));
        if ($pid) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $pid)) as $cid) {
                $wpdb->delete(psc_table('child_school_years'), array('child_id' => (int) $cid));
            }
            $wpdb->delete($t_child, array('parent_id' => $pid));
            $wpdb->delete($t_par, array('id' => $pid));
        }
        foreach ($keys as $key) {
            $year = Psc_School_Years::get_by_key($key);
            if ($year && $year->statut !== 'active') Psc_School_Years::delete((int) $year->id);
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

    try {
        // 1. Une année par rentrée.
        $check(Psc_School_Years::key_for_start('2085-09-01') === '2085-2086', 'clé : rentrée de septembre');
        $check(Psc_School_Years::key_for_start('2086-03-10') === '2085-2086', 'clé : date de début en cours d’année');
        $y1 = Psc_School_Years::create('2085-09-01', '2086-07-03');
        $check(!is_wp_error($y1) && $y1 > 0, 'création refusée');
        $dup = Psc_School_Years::create('2085-10-01', '2086-06-30');
        $check(is_wp_error($dup) && $dup->get_error_code() === 'year_exists', 'seconde année pour la même rentrée acceptée');
        $y2 = Psc_School_Years::create('2086-09-01', '2087-07-02');
        $moved = Psc_School_Years::update($y2, '2085-09-15', '2086-07-02');
        $check(is_wp_error($moved) && $moved->get_error_code() === 'year_exists', 'correction vers une rentrée déjà prise acceptée');
        $check(Psc_School_Years::ensure('2085-09-02', '2086-07-04') === $y1, 'ensure : année de la rentrée non reprise');
        $check(Psc_School_Years::get($y1)->date_debut === '2085-09-02', 'ensure : dates non recalées');

        // 2. Calendrier et dossier : une seule ligne.
        $check(Psc_School_Year::save('2085-2086', '2085-09-02', '2086-07-04', '[["2085-10-20","2085-11-02"]]', 24) === true, 'calendrier : enregistrement refusé');
        Psc_School_Year::flush_cache();
        $cfg = Psc_School_Year::get('2085-2086');
        $check($cfg && (int) $cfg->id === (int) $y1 && (int) $cfg->lock_hours === 24, 'calendrier : pas la ligne de l’année');
        $check(Psc_School_Year::is_vacation('2085-10-22'), 'calendrier : vacances non lues');
        $check(is_wp_error(Psc_School_Year::save('2085-2086', '2090-09-01', '2091-07-04', '[]', 48)), 'calendrier : dates d’une autre rentrée acceptées');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_years WHERE year_key = %s", '2085-2086')) === 1, 'calendrier : seconde ligne créée');

        // 3. Enfant actif = inscrit à l'année.
        $pid = Psc_Parents::create($email, 'Modèle');
        $child = function ($prenom) use ($wpdb, $t_child, $pid) {
            $wpdb->insert($t_child, array('parent_id' => $pid, 'nom' => 'Modele', 'prenom' => $prenom, 'created_at' => current_time('mysql')));
            return (int) $wpdb->insert_id;
        };
        $a = $child('Inscrit');
        $b = $child('Sorti');
        $c = $child('Absent');
        Psc_School_Years::enroll($a, $y1, 'CP');
        Psc_School_Years::enroll($b, $y1, 'CE1');
        $check(Psc_School_Years::mark_sorti($b, $y1) && Psc_School_Years::enrollment($b, $y1)->sorti_le !== null, 'sortie : date non posée');
        $active_ids = array_map('intval', $wpdb->get_col(
            "SELECT c.id FROM $t_child c WHERE c.parent_id = " . (int) $pid . ' AND ' . Psc_School_Years::inscrit_sql('c.id', $y1)
        ));
        $check($active_ids === array($a), 'enfants actifs de l’année : ' . wp_json_encode($active_ids));
        $check(Psc_School_Years::mark_actif($b, $y1) && Psc_School_Years::is_inscrit($b, $y1) && Psc_School_Years::enrollment($b, $y1)->sorti_le === null, 'réinscription : statut ou date de sortie non rétablis');
        $check(!Psc_School_Years::is_inscrit($c, $y1), 'enfant sans ligne considéré inscrit');

        // 4. Réinscription décochée après envoi. La classe proposée se lit
        // sur l'année active : 2085-2086 l'est le temps de ce bloc.
        Psc_School_Years::activate($y1);
        Psc_School_Years::enroll($a, $y2, 'CE1', 'inscrit', current_time('mysql'));
        Psc_School_Years::enroll($b, $y2, 'CE2', 'inscrit', current_time('mysql'));
        // $b (décoché) avant $a (coché, fichier invalide) : un retrait fait
        // au fil de l'eau serait déjà écrit quand le fichier est refusé.
        $kids = array((object) array('id' => $b, 'prenom' => 'Sorti', 'nom' => 'Modele'), (object) array('id' => $a, 'prenom' => 'Inscrit', 'nom' => 'Modele'));
        $bad = array('name' => 'x.pdf', 'tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_OK, 'size' => 10);
        $retires = array();
        // $a reste coché avec un fichier invalide : rien ne doit être retiré à $b.
        $r = Psc_Frontend_Reinscription::apply_reinscription($kids, array($a => $bad), $y2, current_time('mysql'), $retires);
        $check($r === 'required' && Psc_School_Years::enrollment($b, $y2) !== null, 'retrait appliqué malgré un fichier invalide');
        // Tout décoché (hors fin de cycle) : $a et $b perdent l'année cible.
        // classe_for() lit l'année active : sans classe proposée, un enfant
        // coché serait ignoré — ici aucun n'est coché.
        $r = Psc_Frontend_Reinscription::apply_reinscription($kids, array(), $y2, current_time('mysql'), $retires);
        $check($r === 'retire', 'retrait seul : résultat ' . $r);
        $check(count($retires) === 2 && !Psc_School_Years::enrollment($a, $y2) && !Psc_School_Years::enrollment($b, $y2), 'retrait : lignes de l’année cible conservées');
        $check(Psc_School_Years::enrollment($a, $y1) !== null, 'retrait : année en cours touchée');
        if ($original) Psc_School_Years::activate($original);
        $wpdb->update($t_years, array('statut' => 'preparation'), array('id' => $y1));

        // 5. Rétention : horloge = dernière sortie ou fin de la dernière année.
        $old1 = Psc_School_Years::create('2015-09-01', '2016-07-04');
        $old2 = Psc_School_Years::create('2016-09-01', '2017-07-04');
        $wpdb->query($wpdb->prepare("UPDATE $t_years SET statut = 'archivee' WHERE id IN (%d, %d)", $old1, $old2));
        $gone = $child('Parti');       // inscrit en 2015-2016, non réinscrit
        $left = $child('SortiRecent'); // sorti hier
        $kept = $child('Reinscrit');   // année ouverte mais terminée (été avant le passage d'année)
        Psc_School_Years::enroll($gone, $old1, 'CM1');
        Psc_School_Years::enroll($left, $old2, 'CM1');
        $wpdb->update(psc_table('child_school_years'), array('statut' => 'sorti', 'sorti_le' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)), array('child_id' => $left));
        $open_old = Psc_School_Years::create('2014-09-01', '2015-07-03'); // reste « en préparation »
        Psc_School_Years::enroll($kept, $open_old, 'CP');
        $sql = new ReflectionMethod('Psc_Retention', 'departed_sql');
        $sql->setAccessible(true);
        $cutoff = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $targeted = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT c.id FROM $t_child c WHERE c.parent_id = " . (int) $pid . ' AND ' . $sql->invoke(null),
            $cutoff
        )));
        $check(in_array($gone, $targeted, true), 'rétention : enfant non réinscrit depuis longtemps épargné');
        $check(!in_array($left, $targeted, true), 'rétention : enfant sorti hier visé');
        $check(!in_array($kept, $targeted, true), 'rétention : enfant inscrit à une année ouverte visé');
        $check(!in_array($c, $targeted, true), 'rétention : enfant sans année visé');

    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Modèle d'année scolaire : $checks vérifications.");
});
