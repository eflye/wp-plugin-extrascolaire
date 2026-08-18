<?php
/**
 * Seed idempotent pour tests/school-year-promotion.spec.ts.
 *
 * Usage :
 *   wp --require=bin/seed-school-year-promotion.php seed-school-year-promotion
 *
 * Purge puis recrée les données identifiées par l'adresse e-mail des deux
 * parents de test et le libellé des deux années scolaires de test :
 * réexécutable N fois sans jamais dupliquer de parent, d'enfant ou
 * d'inscription. Ne touche à aucune autre donnée du site (jamais de
 * TRUNCATE).
 *
 * Contrairement à bin/seed-supplier-order.php, ce seed active
 * délibérément son année "A" (Psc_School_Years::activate()) : le passage
 * d'année et la réinscription lisent tous les deux l'année ACTIVE du site
 * (Psc_School_Years::active() / classe_for() par défaut), il n'existe pas
 * d'équivalent de Psc_School_Years::for_date() pour ces deux parcours —
 * cf. le commentaire de Psc_Supplier_Orders::compute_counts() pour le cas
 * où une résolution non-active était possible.
 *
 * Toute année en statut "preparation" préexistante est archivée avant
 * création de l'année "B" : Psc_Frontend::reinscription_target_year() lit
 * la plus récente en préparation site-wide (campagne unique par
 * conception, comme un seul trimestre/année actif) — sans ce nettoyage, un
 * résidu d'un run précédent ou d'un autre test pourrait devenir la cible
 * au lieu de l'année "B" de ce seed.
 *
 * Deux parents distincts pour isoler proprement les deux moitiés du
 * parcours :
 *   - "promo" (Camille en CP, Hugo en CM2) : jamais connecté en famille,
 *     sert uniquement au test admin (passage d'année). Camille sert à
 *     vérifier la correction manuelle d'une ligne du récapitulatif ; Hugo
 *     à vérifier la sortie de fin de cycle (CM2 -> sortie).
 *   - "reins" (Léa en CE1) : connecté via lien magique, sert uniquement au
 *     test famille (réinscription). Séparé de "promo" pour qu'un enfant
 *     de test n'apparaisse jamais dans le mauvais formulaire (le portail
 *     famille liste tous les enfants actifs du parent connecté).
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('seed-school-year-promotion', function ($args, $assoc_args) {

    if (!class_exists('Psc_Installer') || !class_exists('Psc_School_Years')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $tz    = wp_timezone();
    $today = new DateTime('today', $tz);
    $fmt   = function (DateTime $d) { return $d->format('Y-m-d'); };

    $config = array(
        'year_a_label'   => 'Année E2E A',
        'year_b_label'   => 'Année E2E B',
        // Fenêtres délibérément loin dans le futur (+180j et au-delà) :
        // Psc_School_Years::for_date() résout par simple recouvrement de
        // dates (le plus récemment créé gagne en cas de chevauchement), et
        // bin/seed-journey.php (-3/+45j) comme bin/seed-supplier-order.php
        // (~+90j) vivent tous les deux près d'aujourd'hui — un chevauchement
        // ferait gagner ces années-ci par erreur sur les fixtures d'un
        // autre spec au lieu des leurs (constaté : Année E2E A à +100j
        // masquait la semaine cible de la commande fournisseur).
        'year_a_debut'   => $fmt((clone $today)->modify('+180 days')),
        'year_a_fin'     => $fmt((clone $today)->modify('+270 days')),
        'year_b_debut'   => $fmt((clone $today)->modify('+271 days')),
        'year_b_fin'     => $fmt((clone $today)->modify('+360 days')),
        'promo_parent_email' => 'promo.e2e@example.test',
        'promo_parent_nom'   => 'Promo',
        'reins_parent_email' => 'reinscription.e2e@example.test',
        'reins_parent_nom'   => 'Reinscription',
    );

    global $wpdb;
    $t_parent = psc_table('parents');
    $t_child  = psc_table('children');
    $t_years  = psc_table('school_years');
    $t_cy     = psc_table('child_school_years');

    /* ---------------------------------------------------------------- */
    /* Purge — scoping strict à l'identité du profil (jamais un TRUNCATE)*/
    /* ---------------------------------------------------------------- */

    WP_CLI::log('Purge des données existantes…');

    foreach (array($config['promo_parent_email'], $config['reins_parent_email']) as $email) {
        $old_parent_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_parent WHERE email = %s", $email));
        if ($old_parent_id) {
            $child_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_child WHERE parent_id = %d", $old_parent_id));
            if ($child_ids) {
                $ph = implode(',', array_fill(0, count($child_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM $t_cy WHERE child_id IN ($ph)", $child_ids));
            }
            $wpdb->delete($t_child, array('parent_id' => $old_parent_id), array('%d'));
            $wpdb->delete($t_parent, array('id' => $old_parent_id), array('%d'));
        }
    }

    foreach (array($config['year_a_label'], $config['year_b_label']) as $label) {
        $old_year_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_years WHERE label = %s", $label));
        foreach ($old_year_ids as $year_id) {
            $wpdb->delete($t_cy, array('school_year_id' => $year_id), array('%d'));
            $wpdb->delete($t_years, array('id' => $year_id), array('%d'));
        }
    }

    /* ---------------------------------------------------------------- */
    /* Recréation                                                        */
    /* ---------------------------------------------------------------- */

    // Toute autre campagne "en préparation" est archivée : une seule
    // campagne de réinscription peut être ciblée à la fois (cf. doc-block).
    $wpdb->query("UPDATE $t_years SET statut = 'archivee' WHERE statut = 'preparation'");

    $year_a_id = Psc_School_Years::create($config['year_a_label'], $config['year_a_debut'], $config['year_a_fin']);
    if (is_wp_error($year_a_id)) {
        WP_CLI::error('Création de l\'année A : ' . $year_a_id->get_error_message());
    }
    Psc_School_Years::activate($year_a_id);

    $year_b_id = Psc_School_Years::create($config['year_b_label'], $config['year_b_debut'], $config['year_b_fin']);
    if (is_wp_error($year_b_id)) {
        WP_CLI::error('Création de l\'année B : ' . $year_b_id->get_error_message());
    }

    // onboarding_seen_at fixé à la création : ces specs ne testent pas la
    // popin de découverte, qui bloquerait sinon les clics Playwright sur
    // le reste du portail (cf. templates/frontend-portal.php).
    $promo_parent_id = Psc_Parents::create($config['promo_parent_email'], $config['promo_parent_nom'], array(
        'onboarding_seen_at' => current_time('mysql'),
    ));
    if (is_wp_error($promo_parent_id)) {
        WP_CLI::error('Création du parent "promo" : ' . $promo_parent_id->get_error_message());
    }
    $reins_parent_id = Psc_Parents::create($config['reins_parent_email'], $config['reins_parent_nom'], array(
        'onboarding_seen_at' => current_time('mysql'),
    ));
    if (is_wp_error($reins_parent_id)) {
        WP_CLI::error('Création du parent "reinscription" : ' . $reins_parent_id->get_error_message());
    }

    $make_child = function ($parent_id, $prenom, $nom, $classe) use ($wpdb, $t_child, $year_a_id) {
        $wpdb->insert($t_child, array(
            'parent_id'  => $parent_id,
            'nom'        => $nom,
            'prenom'     => $prenom,
            'statut'     => 'actif',
            'created_at' => current_time('mysql'),
        ), array('%d', '%s', '%s', '%s', '%s'));
        $child_id = (int) $wpdb->insert_id;
        Psc_School_Years::enroll($child_id, $year_a_id, $classe, 'inscrit', current_time('mysql'));
        return $child_id;
    };

    $camille_id = $make_child($promo_parent_id, 'Camille', 'Promo', 'CP');
    $hugo_id    = $make_child($promo_parent_id, 'Hugo', 'Promo', 'CM2');
    $lea_id     = $make_child($reins_parent_id, 'Léa', 'Reinscription', 'CE1');

    // Fenêtre de réinscription toujours ouverte au moment du run (large
    // marge, comme les fenêtres de trimestre des autres seeds).
    update_option('psc_reinscription_debut', $fmt((clone $today)->modify('-30 days')));
    update_option('psc_reinscription_fin', $fmt((clone $today)->modify('+30 days')));

    /* ---------------------------------------------------------------- */
    /* Sortie                                                             */
    /* ---------------------------------------------------------------- */

    WP_CLI::log('');
    WP_CLI::log("Année A (active) ....... {$config['year_a_label']} (id $year_a_id)");
    WP_CLI::log("Année B (préparation) .. {$config['year_b_label']} (id $year_b_id)");
    WP_CLI::log("Parent promo ............ {$config['promo_parent_email']} (id $promo_parent_id)");
    WP_CLI::log("  Camille Promo (CP, id $camille_id) — attend CE1, testera un override vers CE2");
    WP_CLI::log("  Hugo Promo (CM2, id $hugo_id) — attend la sortie de fin de cycle");
    WP_CLI::log("Parent réinscription .... {$config['reins_parent_email']} (id $reins_parent_id)");
    WP_CLI::log("  Léa Reinscription (CE1, id $lea_id) — attend CE2 en réinscription");

    WP_CLI::log('');
    WP_CLI::log(wp_json_encode(array(
        'year_a_id'          => $year_a_id,
        'year_a_label'       => $config['year_a_label'],
        'year_b_id'          => $year_b_id,
        'year_b_label'       => $config['year_b_label'],
        'promo_parent_email' => $config['promo_parent_email'],
        'promo_parent_id'    => $promo_parent_id,
        'camille_id'         => $camille_id,
        'hugo_id'            => $hugo_id,
        'reins_parent_email' => $config['reins_parent_email'],
        'reins_parent_id'    => $reins_parent_id,
        'lea_id'             => $lea_id,
    )));

    WP_CLI::success('Seed passage d\'année / réinscription prêt.');
});
