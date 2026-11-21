<?php
/**
 * Script de vérification autonome — notes de changement de menus du
 * fournisseur (Psc_Menu_Docx, Psc_Menu_Notes), en conditions WP-CLI
 * réelles. Même rôle que bin/verify-menu-choix.php.
 *
 * Le document lu est tests/fixtures/Note changement de menus 06-02-90.docx
 * (produit par tests/fixtures/make-note-fournisseur.php). Le serveur
 * d'envoi est simulé par le filtre pre_wp_mail : aucun e-mail ne part, et
 * la moindre tentative est comptée.
 *
 * Vérifie :
 *  - lecture : zones de texte lues une seule fois (copie de repli écartée),
 *    zones d'exemple du modèle ignorées, éléments de liste repérés, pied de
 *    page non lu ; fichiers refusés (pas un document, archive sans
 *    document, extension, document sans texte) ;
 *  - date du jour concerné dans le nom du fichier ; jour nommé dans le
 *    texte ;
 *  - remplacements reconnus (accords, auxiliaires, « Remplacement de … par
 *    … », parenthèse gardée en remarque, plusieurs phrases) et phrases qui
 *    n'en sont pas ;
 *  - rapprochement avec les lignes du menu (articles, pluriels, accents,
 *    mention bio) et texte proposé (« bio » → étoile, « classique »
 *    retiré) ;
 *  - menu corrigé proposé (remplacements reconnus reportés, le reste repris
 *    tel quel) ; enregistrement du menu corrigé par la mairie : refusé sans
 *    menu, hors jour de cantine, vide ou identique ; seul le jour concerné
 *    est réécrit, les autres jours et l'origine de la viande sont
 *    conservés ; un menu déjà envoyé reste daté et AUCUN e-mail ne part ;
 *    note verrouillée ensuite ;
 *  - changement de jour, classement sans suite, suppression.
 *
 * Usage :
 *   wp --require=bin/verify-menu-notes.php verify-menu-notes
 *
 * Données scopées (semaine du 6 février 2090, notes « verify-menu-notes »),
 * purgées avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-menu-notes', function () {

    if (!class_exists('Psc_Menu_Notes') || !class_exists('Psc_Menu_Docx')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_notes = psc_table('menu_notes');
    $t_menu  = psc_table('menus');
    $t_env   = psc_table('envois');
    $t_cal   = psc_table('school_calendar');
    $fixture = PSC_PATH . 'tests/fixtures/Note changement de menus 06-02-90.docx';
    $week    = '2090-02-06';
    $tmp     = array();

    $failures = array();
    $checks   = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $code = function ($value) { return is_wp_error($value) ? $value->get_error_code() : null; };

    $purge = function () use ($wpdb, $t_notes, $t_menu, $t_env, $t_cal, $week, &$tmp) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t_notes WHERE fichier_nom LIKE %s", $wpdb->esc_like('verify-menu-notes') . '%'));
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_menu WHERE semaine_debut = %s", $week)) as $id) {
            $wpdb->delete($t_env, array('objet_type' => 'menu', 'objet_id' => (int) $id));
            $wpdb->delete($t_menu, array('id' => (int) $id));
        }
        $wpdb->delete($t_cal, array('label' => 'verify-menu-notes'));
        Psc_School_Calendar::flush_closed_cache();
        foreach ($tmp as $file) {
            if (is_file($file)) unlink($file);
        }
        $tmp = array();
    };
    $purge();

    // Serveur d'envoi simulé : toute tentative est comptée (aucune n'est attendue).
    $mails = 0;
    $capture = function ($null) use (&$mails) { $mails++; return true; };
    add_filter('pre_wp_mail', $capture, 10, 1);

    try {
        /* ---------------- 1. Lecture du document ---------------- */
        $lignes = Psc_Menu_Docx::read($fixture);
        $check(!is_wp_error($lignes), 'lecture : le document de test est refusé');
        if (is_wp_error($lignes)) throw new RuntimeException('document de test illisible : ' . $lignes->get_error_code());
        $check(wp_list_pluck($lignes, 't') === array(
            'Bien cordialement',
            'Chers Clients,',
            'Suite à une rupture fournisseur nous avons dû remplacer certains produits prévus lundi :',
            'Les crèmes dessert vanille bio sont remplacées par des crèmes dessert vanille classiques (ne concerne que les clients ayant un contrat avec bio)',
            'Le camembert bio est remplacé par de la tomme noire',
            'Les frites sont remplacées par des pommes vapeur',
            'Merci de votre compréhension',
        ), 'lecture : texte inattendu (zone lue deux fois, zone d’exemple gardée, pied de page lu ?)');
        $check(wp_list_pluck($lignes, 'puce') === array(false, false, false, true, true, true, false), 'lecture : éléments de liste mal repérés');

        $make = function ($suffix, array $parts) use (&$tmp) {
            $base = tempnam(get_temp_dir(), 'psc-verify-menu-notes-');
            $file = $base . $suffix;
            $tmp[] = $base;
            $tmp[] = $file;
            $zip = new ZipArchive();
            $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($parts as $part => $xml) $zip->addFromString($part, $xml);
            $zip->close();
            return $file;
        };
        $texte = tempnam(get_temp_dir(), 'psc-verify-menu-notes-');
        $tmp[] = $texte;
        file_put_contents($texte, 'ceci est un texte, pas un document Word');
        $check($code(Psc_Menu_Docx::read($texte)) === 'psc_docx_format', 'refus : un fichier texte est accepté');
        $check($code(Psc_Menu_Docx::read($texte . '.absent')) === 'psc_docx_illisible', 'refus : un fichier absent est accepté');
        $check($code(Psc_Menu_Docx::read($make('.docx', array('lisezmoi.txt' => 'archive sans document')))) === 'psc_docx_format', 'refus : une archive sans document est acceptée');
        $w = 'xmlns:w="' . Psc_Menu_Docx::NS_W . '"';
        $check($code(Psc_Menu_Docx::read($make('.docx', array(
            'word/document.xml' => '<!DOCTYPE x [<!ENTITY e "x">]><w:document ' . $w . '><w:body><w:p><w:r><w:t>Le riz est remplacé par des pâtes</w:t></w:r></w:p></w:body></w:document>',
        )))) === 'psc_docx_format', 'refus : un document avec DOCTYPE est accepté');
        $check($code(Psc_Menu_Docx::read($make('.docx', array(
            'word/document.xml' => '<w:document ' . $w . '><w:body><w:p><w:r><w:t>[Zone d’exemple du modèle]</w:t></w:r></w:p><w:p/></w:body></w:document>',
        )))) === 'psc_docx_vide', 'refus : un document sans texte est accepté');
        $check($code(Psc_Menu_Notes::import($fixture, 'verify-menu-notes 06-02-90.doc')) === 'psc_docx_format', 'refus : une extension autre que .docx est acceptée');

        /* ---------------- 2. Jour concerné ---------------- */
        $dates = array(
            "Note d'info changement de menus 09-11-26.docx" => '2026-11-09',
            'Note changement 9.11.2026.docx'                => '2026-11-09',
            'Note du 03-11-26 menus du 09-11-26.docx'       => '2026-11-09',
            'Note changement de menus 06-02-90.docx'        => '2090-02-06',
            'Note changement S46.docx'                      => '',
            'Note 31-02-26.docx'                            => '',
            'Note 120-11-26.docx'                           => '',
        );
        foreach ($dates as $name => $expected) {
            $check(Psc_Menu_Notes::date_from_name($name) === $expected, "date du nom de fichier mal lue : $name");
        }
        $check(Psc_Menu_Notes::jour_cite('produits prévus lundi :') === 'lundi', 'jour nommé dans la note non repéré');
        $check(Psc_Menu_Notes::jour_cite('prévus lundi et mardi') === '' && Psc_Menu_Notes::jour_cite('Chers clients') === '', 'jour nommé : ambiguïté ou absence mal traitée');

        /* ---------------- 3. Remplacements reconnus ---------------- */
        $phrases = array(
            'La tomme noire est remplacée par du camembert' => array('tomme noire', 'camembert', ''),
            'Les yaourts sucrés bio sont remplacés par des yaourt sucrés classiques (ne concerne que les clients ayant un contrat avec bio) ' => array('yaourts sucrés bio', 'yaourt sucrés classiques', 'ne concerne que les clients ayant un contrat avec bio'),
            'Le poisson pané sera remplacé par des nuggets de blé.' => array('poisson pané', 'nuggets de blé', ''),
            "L'omelette a été remplacée par une quiche lorraine ;" => array('omelette', 'quiche lorraine', ''),
            'Remplacement du riz par de la semoule' => array('riz', 'semoule', ''),
            'Haricots verts remplacés par petits pois' => array('Haricots verts', 'petits pois', ''),
        );
        foreach ($phrases as $phrase => $expected) {
            $got = Psc_Menu_Notes::detecter(array(array('t' => $phrase, 'puce' => true)));
            $check(count($got) === 1 && array($got[0]['produit'], $got[0]['nouveau'], $got[0]['remarque']) === $expected, "remplacement mal reconnu : $phrase");
        }
        $check(Psc_Menu_Notes::detecter(array(
            array('t' => 'Suite à une rupture fournisseur nous avons dû remplacer certains produits prévus lundi :', 'puce' => false),
            array('t' => 'Merci de votre compréhension', 'puce' => false),
        )) === array(), 'remplacement reconnu dans une phrase qui n’en annonce pas');
        $deux = Psc_Menu_Notes::detecter(array(array('t' => 'Le riz est remplacé par de la semoule. La compote est remplacée par un fruit frais.', 'puce' => false)));
        $check(wp_list_pluck($deux, 'produit') === array('riz', 'compote') && wp_list_pluck($deux, 'nouveau') === array('semoule', 'fruit frais'), 'deux remplacements dans un même paragraphe mal séparés');
        $du_doc = Psc_Menu_Notes::detecter($lignes);
        $check(wp_list_pluck($du_doc, 'produit') === array('crèmes dessert vanille bio', 'camembert bio', 'frites'), 'remplacements du document de test inattendus');

        /* ---------------- 4. Rapprochement avec le menu ---------------- */
        $menu = array('Carottes râpées', 'Sauté de dinde', 'Riz', 'Tomme noire', 'Yaourt sucré*');
        $check(Psc_Menu_Notes::trouver($menu, 'yaourts sucrés bio') === 4, 'rapprochement : « yaourts sucrés bio » non rapproché de « Yaourt sucré* »');
        $check(Psc_Menu_Notes::trouver($menu, 'tomme noire') === 3, 'rapprochement : « tomme noire » non retrouvée');
        $check(Psc_Menu_Notes::trouver($menu, 'carotte râpée') === 0, 'rapprochement : singulier et pluriel non rapprochés');
        $check(Psc_Menu_Notes::trouver($menu, 'frites') === -1 && Psc_Menu_Notes::trouver($menu, 'tomme blanche') === -1, 'rapprochement : produit absent rapproché d’une ligne');
        $check(Psc_Menu_Notes::trouver(array('Yaourt aromatisé', 'Yaourt sucré', 'Yaourt sucré*'), 'yaourts sucrés bio') === 2, 'rapprochement : la mention bio ne départage pas deux lignes voisines');
        $check(Psc_Menu_Notes::trouver($menu, '') === -1 && Psc_Menu_Notes::trouver(array(), 'riz') === -1, 'rapprochement : cas vide mal traité');
        $lines = array(
            'yaourt sucrés classiques' => 'Yaourt sucrés',
            'camembert'                => 'Camembert',
            'compote de pommes bio'    => 'Compote de pommes*',
            'tomme noire'              => 'Tomme noire',
        );
        foreach ($lines as $nouveau => $expected) {
            $check(Psc_Menu_Notes::ligne_proposee($nouveau) === $expected, "ligne proposée inattendue pour « $nouveau »");
        }

        /* ---------------- 5. Dépôt ---------------- */
        $id = Psc_Menu_Notes::import($fixture, 'verify-menu-notes 06-02-90.docx');
        $check(is_int($id) && $id > 0, 'dépôt : note non enregistrée');
        if (!is_int($id)) throw new RuntimeException('dépôt impossible : ' . $code($id));
        $row = Psc_Menu_Notes::get($id);
        $check($row->statut === Psc_Menu_Notes::A_CONFIRMER && $row->jour_date === $week, 'dépôt : statut ou jour concerné inattendus');
        $check(strpos($row->texte, "\n" . Psc_Menu_Notes::PUCE . 'Le camembert bio est remplacé par de la tomme noire') !== false && strpos($row->texte, '29/12/2025') === false, 'dépôt : texte conservé inattendu');
        $data = Psc_Menu_Notes::changements($row);
        $check(count($data['detectes']) === 3 && $data['jour_cite'] === 'lundi' && $data['appliques'] === array(), 'dépôt : remplacements ou jour nommé non enregistrés');

        /* ---------------- 6. Application ---------------- */
        $check($code(Psc_Menu_Notes::apply($id, 'Menu saisi depuis la note')) === 'psc_note_sans_menu', 'application : acceptée sans menu enregistré');

        $before = array(
            'lundi'    => "Velouté de légumes (chaud)\nFilet de poulet\nPoêlée de légumes*\nCamembert*\nCrème dessert vanille*",
            'mardi'    => "Salade verte\nGratin de pâtes",
            'jeudi'    => 'Menu du jeudi',
            'vendredi' => 'Menu du vendredi',
        );
        $menu_id = Psc_Menus::save(0, $week, $before, 'Origine de test');
        // Menu déjà envoyé aux familles : la correction ne doit rien renvoyer.
        $wpdb->update($t_menu, array('sent_at' => '2090-01-30 10:00:00'), array('id' => (int) $menu_id));

        $row = Psc_Menu_Notes::get($id);
        $ctx = Psc_Menu_Notes::contexte($row);
        $check($ctx['date'] === $week && $ctx['semaine'] === $week && $ctx['jour'] === 'lundi' && $ctx['ouvert'] && count($ctx['lignes']) === 5, 'application : menu visé mal déterminé');
        $props = Psc_Menu_Notes::propositions($row, $ctx);
        $check(wp_list_pluck($props, 'ligne') === array(4, 3, -1), 'application : lignes proposées inattendues');
        $check(wp_list_pluck($props, 'texte') === array('Crèmes dessert vanille', 'Tomme noire', 'Pommes vapeur'), 'application : textes proposés inattendus');

        // Menu corrigé proposé : les lignes rapprochées sont remplacées, les autres reprises.
        $check(Psc_Menu_Notes::menu_propose($row, $ctx) === array('Velouté de légumes (chaud)', 'Filet de poulet', 'Poêlée de légumes*', 'Tomme noire', 'Crèmes dessert vanille'), 'application : menu corrigé proposé inattendu');

        $check($code(Psc_Menu_Notes::apply($id, $before['lundi'])) === 'psc_note_rien', 'application : menu identique accepté comme une correction');
        $check($code(Psc_Menu_Notes::apply($id, "  \n \r\n")) === 'psc_note_vide' && $code(Psc_Menu_Notes::apply($id, array('lundi'))) === 'psc_note_vide', 'application : menu vide accepté');
        $check(Psc_Menus::get($menu_id)->lundi === $before['lundi'], 'application : menu modifié par une demande refusée');

        // Menu corrigé à la main par la mairie : fins de ligne du navigateur,
        // ligne vide, balise, et une ligne ajoutée que la note n'annonçait pas.
        $applied = Psc_Menu_Notes::apply($id, "Velouté de légumes (chaud)\r\nFilet de poulet\r\n\r\nPoêlée de légumes*\r\nTomme noire\r\nCrème dessert vanille  <b>\r\nPain");
        $check($applied === 3, 'application : nombre de lignes modifiées inattendu');
        $menu = Psc_Menus::get($menu_id);
        $check($menu->lundi === "Velouté de légumes (chaud)\nFilet de poulet\nPoêlée de légumes*\nTomme noire\nCrème dessert vanille\nPain", 'application : menu du jour mal corrigé');
        $check($menu->mardi === $before['mardi'] && $menu->jeudi === $before['jeudi'] && $menu->vendredi === $before['vendredi'], 'application : un autre jour a été modifié');
        $check($menu->origine_viande === 'Origine de test' && $menu->sent_at === '2090-01-30 10:00:00', 'application : origine de la viande ou date d’envoi perdues');
        $check($mails === 0, 'application : un e-mail est parti (' . $mails . ')');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_env WHERE objet_type = 'menu' AND objet_id = %d", $menu_id)) === 0, 'application : un envoi aux familles a été mis en file');
        $row  = Psc_Menu_Notes::get($id);
        $data = Psc_Menu_Notes::changements($row);
        $check($row->statut === Psc_Menu_Notes::APPLIQUEE && !empty($row->applied_at) && $data['menu_envoye'], 'application : statut, date ou mention « menu déjà envoyé » non enregistrés');
        $check($data['appliques'] === array(
            array('avant' => 'Camembert*', 'apres' => 'Tomme noire'),
            array('avant' => 'Crème dessert vanille*', 'apres' => 'Crème dessert vanille'),
            array('avant' => '', 'apres' => 'Pain'),
        ), 'application : trace des lignes modifiées inattendue');
        $check(count($data['detectes']) === 3, 'application : remplacements annoncés par la note perdus');

        /* ---------------- 7. Note traitée ---------------- */
        $check($code(Psc_Menu_Notes::apply($id, 'Autre menu')) === 'psc_note_verrouillee', 'verrou : note appliquée deux fois');
        $check($code(Psc_Menu_Notes::set_day($id, '2090-02-07')) === 'psc_note_verrouillee' && $code(Psc_Menu_Notes::ignore($id)) === 'psc_note_verrouillee', 'verrou : note traitée encore modifiable');

        /* ---------------- 8. Jour, classement, suppression ---------------- */
        $id2 = Psc_Menu_Notes::import($fixture, 'verify-menu-notes sans date.docx');
        $row2 = Psc_Menu_Notes::get($id2);
        $check($row2 && $row2->jour_date === null, 'jour : une date est inventée quand le nom du fichier n’en porte pas');
        $check($code(Psc_Menu_Notes::apply($id2, 'Autre menu')) === 'psc_note_jour', 'jour : application acceptée sans jour concerné');
        $check($code(Psc_Menu_Notes::set_day($id2, '06/02/2090')) === 'psc_note_jour', 'jour : date invalide acceptée');
        $check(Psc_Menu_Notes::set_day($id2, '2090-02-08') === true && $code(Psc_Menu_Notes::apply($id2, 'Autre menu')) === 'psc_note_jour_ferme', 'jour : application acceptée un mercredi');
        // Mardi fermé au calendrier scolaire.
        $wpdb->insert($t_cal, array('jour_date' => '2090-02-07', 'label' => 'verify-menu-notes', 'is_closed' => 1, 'source' => 'manual', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
        Psc_School_Calendar::flush_closed_cache();
        $check(Psc_Menu_Notes::set_day($id2, '2090-02-07') === true && $code(Psc_Menu_Notes::apply($id2, 'Autre menu')) === 'psc_note_jour_ferme', 'jour : application acceptée un jour fermé');
        $check(Psc_Menus::get($menu_id)->mardi === $before['mardi'], 'jour : menu modifié malgré le refus');
        $check(Psc_Menu_Notes::ignore($id2) === true && Psc_Menu_Notes::get($id2)->statut === Psc_Menu_Notes::IGNOREE, 'classement : note non classée');
        $check($code(Psc_Menu_Notes::ignore(0)) === 'psc_note_introuvable', 'classement : note inexistante acceptée');
        $check(Psc_Menu_Notes::delete($id2) === true && Psc_Menu_Notes::get($id2) === null, 'suppression : note conservée');
        $check(Psc_Menus::get($menu_id) !== null && $mails === 0, 'suppression : menu supprimé avec la note, ou e-mail parti');
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
