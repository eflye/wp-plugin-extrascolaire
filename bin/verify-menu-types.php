<?php
/**
 * Vérifie les types de plat des menus de cantine (entrée, plat,
 * accompagnement, fromage / laitage, dessert) — schéma 4.25.0.
 *
 *   wp --require=wp-content/plugins/periscolaire-registration/bin/verify-menu-types.php verify-menu-types
 *
 * Couvre :
 *  - le schéma : une colonne `<jour>_types` par jour de cantine ;
 *  - la composition d'un jour à partir de ses plats rangés par type, et
 *    l'opération inverse pour les champs du backoffice (répartition dans
 *    l'ordre du service quand le menu n'a pas de type) ;
 *  - l'enregistrement : types écrits avec le texte, conservés quand un
 *    appelant ne transmet qu'un texte inchangé, effacés quand ce texte
 *    change ; assainissement, troncature ;
 *  - le rendu : type devant ses plats côté familles et dans l'e-mail,
 *    type sans plat jamais affiché, menu sans type rendu comme avant ;
 *  - la correction d'un menu par une note du fournisseur, par type.
 *
 * Le remplissage depuis le classeur du fournisseur (types exacts, fichier
 * réinjecté sur un menu sans type) est vérifié par bin/verify-menu-choix.php.
 *
 * Données scopées (semaine du 6 mars 2090, note « verify-menu-types »),
 * purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-menu-types', function () {

    if (!class_exists('Psc_Menus') || !class_exists('Psc_Menu_Notes')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_menu  = psc_table('menus');
    $t_notes = psc_table('menu_notes');
    $t_env   = psc_table('envois');
    $week    = '2090-03-06';

    $failures = array();
    $checks   = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $code = function ($value) { return is_wp_error($value) ? $value->get_error_code() : null; };

    $purge = function () use ($wpdb, $t_menu, $t_notes, $t_env, $week) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t_notes WHERE fichier_nom LIKE %s", $wpdb->esc_like('verify-menu-types') . '%'));
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_menu WHERE semaine_debut = %s", $week)) as $id) {
            $wpdb->delete($t_env, array('objet_type' => 'menu', 'objet_id' => (int) $id));
            $wpdb->delete($t_menu, array('id' => (int) $id));
        }
    };

    $mails   = 0;
    $capture = function ($pre) use (&$mails) { $mails++; return true; };
    add_filter('pre_wp_mail', $capture, 10);
    $purge();

    try {
        /* ---------------- 1. Schéma et référentiel ---------------- */
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $t_menu", OBJECT_K);
        foreach (Psc_Menus::JOURS as $jour) {
            $col = $columns[$jour . '_types'] ?? null;
            $check($col && strtolower($col->Type) === 'varchar(255)' && $col->Null === 'NO' && (string) $col->Default === '', "schéma : colonne {$jour}_types absente ou mal définie");
        }
        $check(Psc_Menus::TYPES === Psc_Menu_Xlsx::CATEGORIES, 'référentiel : les types des menus ne sont plus les catégories du classeur du fournisseur');
        $labels = Psc_Menus::type_labels();
        $check(array_keys($labels) === Psc_Menus::TYPES && $labels['fromage'] === 'Fromage / Laitage' && Psc_Menu_Propositions::categories() === $labels, 'référentiel : libellés des types inattendus');

        /* ---------------- 2. Composition ---------------- */
        $day = Psc_Menus::compose(array(
            'dessert' => "Pomme\r\nYaourt nature*",
            'entree'  => "  Carottes râpées*  \n\n*\n",
            'plat'    => 'Poulet fermier rôti**',
            'inconnu' => 'Ignoré',
            'fromage' => array('pas un texte'),
        ));
        $check($day['texte'] === "Carottes râpées*\nPoulet fermier rôti**\nPomme\nYaourt nature*", 'composition : texte inattendu (ordre du service, lignes vides, étoile seule)');
        $check($day['types'] === 'entree,plat,dessert,dessert', 'composition : types inattendus');
        $check(Psc_Menus::compose(array()) === array('texte' => '', 'types' => ''), 'composition : jour vide mal composé');
        $check(Psc_Menus::types_of($day['texte'], $day['types']) === array('entree', 'plat', 'dessert', 'dessert'), 'types : liste fiable refusée');
        $check(Psc_Menus::types_of($day['texte'], 'entree,plat') === array() && Psc_Menus::types_of($day['texte'], '') === array(), 'types : liste trop courte ou vide acceptée');
        $check(Psc_Menus::types_of("A\nB", 'entree,boisson') === array(), 'types : type inconnu accepté');

        $back = Psc_Menus::par_type($day['texte'], $day['types']);
        $check($back === array('entree' => 'Carottes râpées*', 'plat' => 'Poulet fermier rôti**', 'accompagnement' => '', 'fromage' => '', 'dessert' => "Pomme\nYaourt nature*"), 'champs : plats mal rangés par type');
        // Menu sans type : répartition dans l'ordre du service, surplus en dessert.
        $check(Psc_Menus::par_type("a\nb\nc\nd\ne\nf", '') === array('entree' => 'a', 'plat' => 'b', 'accompagnement' => 'c', 'fromage' => 'd', 'dessert' => "e\nf"), 'champs : menu sans type mal réparti');
        $check(Psc_Menus::par_type("a\nb", 'plat') === array('entree' => 'a', 'plat' => 'b', 'accompagnement' => '', 'fromage' => '', 'dessert' => ''), 'champs : types non fiables utilisés');
        $check(Psc_Menus::par_type('', '') === array_fill_keys(Psc_Menus::TYPES, ''), 'champs : les cinq types ne sont pas toujours présents');

        /* ---------------- 3. Enregistrement ---------------- */
        $open = Psc_Menus::open_days($week);
        $check(count($open) === 4, 'préparation : la semaine de test n’a pas ses quatre jours de cantine');
        $menu_id = Psc_Menus::save(0, $week, array(
            'lundi' => array('entree' => 'Velouté <b>de légumes</b>', 'plat' => 'Lasagnes (plat complet)', 'fromage' => 'Mimolette', 'dessert' => "Compote*\nBiscuit"),
            'mardi' => 'Salade verte' . "\n" . 'Gratin de pâtes',
            'jeudi' => array('plat' => str_repeat('Plat très long ', 200), 'dessert' => 'Fruit'),
        ), 'Origine de test');
        $menu = Psc_Menus::get($menu_id);
        $check($menu && $menu->lundi === "Velouté de légumes\nLasagnes (plat complet)\nMimolette\nCompote*\nBiscuit" && $menu->lundi_types === 'entree,plat,fromage,dessert,dessert', 'enregistrement : jour par type mal enregistré');
        $check($menu->mardi === "Salade verte\nGratin de pâtes" && $menu->mardi_types === '', 'enregistrement : types inventés pour un jour en texte seul');
        $check(mb_strlen($menu->jeudi) === 2000 && $menu->jeudi_types === 'plat', 'enregistrement : texte tronqué, types non alignés');
        $check($menu->vendredi === '' && $menu->vendredi_types === '', 'enregistrement : jour absent non vide');

        // Appelant qui ne transmet que du texte : types conservés si le texte ne change pas, effacés sinon.
        Psc_Menus::save($menu_id, $week, array('lundi' => $menu->lundi, 'mardi' => $menu->mardi, 'jeudi' => 'Autre menu', 'vendredi' => 'Soupe'));
        $menu = Psc_Menus::get($menu_id);
        $check($menu->lundi_types === 'entree,plat,fromage,dessert,dessert', 'enregistrement : types perdus alors que le texte du jour est inchangé');
        $check($menu->jeudi === 'Autre menu' && $menu->jeudi_types === '' && $menu->vendredi_types === '', 'enregistrement : types conservés sur un texte réécrit');
        $check($menu->origine_viande === 'Origine de test', 'enregistrement : origine de la viande perdue');

        // Trop de lignes pour la colonne des types : le jour reste lisible, sans type.
        Psc_Menus::save($menu_id, $week, array('lundi' => $menu->lundi, 'mardi' => array('accompagnement' => implode("\n", array_fill(0, 30, 'Riz')))));
        $menu = Psc_Menus::get($menu_id);
        $check(count(Psc_Menus::lines($menu->mardi)) === 30 && $menu->mardi_types === '', 'enregistrement : types tronqués au lieu d’être abandonnés');
        $check($menu->lundi_types === 'entree,plat,fromage,dessert,dessert', 'enregistrement : types du lundi perdus');

        /* ---------------- 4. Rendu ---------------- */
        $html = Psc_Menus::render_dishes($menu->lundi, 'frontend', $menu->lundi_types);
        $check(preg_match_all('/<dt[^>]*>([^<]*)<\/dt>/', $html, $m) === 4 && $m[1] === array('Entrée', 'Plat', 'Fromage / Laitage', 'Dessert'), 'rendu : types affichés inattendus côté familles');
        $check(strpos($html, 'Accompagnement') === false, 'rendu : un type sans plat est affiché');
        $check(substr_count($html, 'psc-menu-dish') === 5 && strpos($html, 'logo-ab.png') !== false && strpos($html, '*') === false, 'rendu : plats ou logo bio perdus');
        $check(preg_match('/<dt[^>]*>Dessert<\/dt><dd[^>]*>.*Compote.*Biscuit.*<\/dd>/s', $html) === 1, 'rendu : deux plats du même type non regroupés');
        $legacy = Psc_Menus::render_dishes($menu->lundi);
        $check(strpos($legacy, '<dt') === false && substr_count($legacy, 'psc-menu-dish') === 5, 'rendu : menu sans type modifié');
        $check(Psc_Menus::render_dishes($menu->lundi, 'frontend', 'entree,plat') === $legacy, 'rendu : types non fiables affichés');
        $email = Psc_Menus::render_dishes($menu->lundi, 'email', $menu->lundi_types);
        $check(strpos($email, 'role="presentation"') !== false && strpos($email, '>Fromage / Laitage</td>') !== false && strpos($email, 'Accompagnement') === false && strpos($email, '<dl') === false, 'rendu : e-mail sans types, ou avec un type vide');
        $check(Psc_Menus::render_dishes('<script>x</script>', 'frontend', 'entree') !== '' && strpos(Psc_Menus::render_dishes('<script>x</script>', 'frontend', 'entree'), '<script') === false, 'rendu : sortie non échappée');

        $days = Psc_Frontend_Menus::menu_days_for_week($week);
        $check(count($days) === 2 && $days[0]['types'] === $menu->lundi_types && $days[1]['types'] === '', 'portail : types non transmis à l’affichage');

        $fields = Psc_Menus::render_type_fields('lundi', 'psc-menu-lundi', Psc_Menus::par_type($menu->lundi, $menu->lundi_types), true);
        $check(substr_count($fields, '<textarea') === 5 && substr_count($fields, '<label for="psc-menu-lundi-') === 5 && strpos($fields, 'name="lundi[accompagnement]"') !== false, 'backoffice : les cinq champs ne sont pas tous affichés');

        /* ---------------- 5. E-mail aux familles ---------------- */
        $body   = '';
        $spy    = function ($args) use (&$body) { $body = (string) $args['message']; return $args; };
        add_filter('wp_mail', $spy, 10);
        Psc_Mailer::send_weekly_menu((object) array('id' => 0, 'email' => 'verify-menu-types@example.invalid', 'prenom' => 'Test', 'nom' => 'Test'), $menu);
        remove_filter('wp_mail', $spy, 10);
        $check(strpos($body, '>Entrée</td>') !== false && strpos($body, '>Fromage / Laitage</td>') !== false && strpos($body, 'Velouté de légumes') !== false, 'e-mail : types absents du menu envoyé aux familles');

        /* ---------------- 6. Correction par une note du fournisseur ---------------- */
        $wpdb->insert($t_notes, array(
            'fichier_nom' => 'verify-menu-types 06-03-90.docx', 'jour_date' => $week, 'texte' => 'La mimolette est remplacée par du brie',
            'changements_json' => wp_json_encode(array('detectes' => array())), 'statut' => Psc_Menu_Notes::A_CONFIRMER,
            'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ));
        $note_id = (int) $wpdb->insert_id;
        $check($note_id > 0, 'note : préparation en échec (' . $wpdb->last_error . ')');
        if ($note_id) {
            $check($code(Psc_Menu_Notes::apply($note_id, array('entree' => " \n", 'boisson' => 'Eau'))) === 'psc_note_vide', 'note : menu vide accepté');
            $applied = Psc_Menu_Notes::apply($note_id, array('entree' => 'Velouté de légumes', 'plat' => 'Lasagnes (plat complet)', 'accompagnement' => 'Salade', 'fromage' => 'Brie <i>', 'dessert' => "Compote*\r\nBiscuit"));
            $menu = Psc_Menus::get($menu_id);
            $check(!is_wp_error($applied) && $menu->lundi === "Velouté de légumes\nLasagnes (plat complet)\nSalade\nBrie\nCompote*\nBiscuit", 'note : menu du jour mal corrigé');
            $check($menu->lundi_types === 'entree,plat,accompagnement,fromage,dessert,dessert', 'note : types du jour corrigé non enregistrés');
        }
        $check($mails <= 1, 'note : un e-mail est parti à la correction');
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("$checks vérifications réussies.");
});
