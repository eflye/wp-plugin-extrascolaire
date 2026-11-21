<?php
/**
 * Script de vérification autonome — choix des menus proposés par le
 * fournisseur (Psc_Menu_Xlsx, Psc_Menu_Propositions), en conditions
 * WP-CLI réelles. Même rôle que bin/verify-envois.php.
 *
 * Le classeur lu est tests/fixtures/menus-fournisseur.xlsx (produit par
 * tests/fixtures/make-menus-fournisseur.php). Le serveur d'envoi est
 * simulé par le filtre pre_wp_mail : aucun e-mail ne part.
 *
 * Vérifie :
 *  - lecture : semaines, jours, colonnes « Enf », catégories, groupes
 *    (proposition / végétarien / option), texte enrichi, titre sans année,
 *    feuille sans menu écartée ; intitulés de semaine ; fichiers refusés
 *    (pas un classeur, archive sans classeur, DOCTYPE, aucune semaine) ;
 *  - choix : suggestion par défaut, valeur hors proposition ignorée,
 *    « aucun », saisie partielle, semaine fermée au calendrier exclue ;
 *  - fichier rempli : croix dans la colonne « Enf » (cellule existante ou
 *    créée, dans l'ordre des colonnes), établissement, original intact,
 *    classeur relisible ;
 *  - menus : « BIO » → étoile, « Plat complet » ignoré, un plat par ligne ;
 *  - envoi : sans adresse fournisseur → refus ; mail refusé → rien n'est
 *    rempli, relance possible ; double soumission → un seul mail ; pièce
 *    jointe présente à l'envoi puis supprimée ; menus remplis seulement
 *    après l'envoi — créé, remplacé s'il n'était pas envoyé (autres jours
 *    conservés), conservé s'il était déjà envoyé aux familles, puis
 *    remplacé sur confirmation ; choix verrouillés après l'envoi ;
 *  - suppression : fichier et ligne retirés, menus conservés.
 *
 * Usage :
 *   wp --require=bin/verify-menu-choix.php verify-menu-choix
 *
 * Données scopées (semaines de janvier 2090, adresse dédiée), purgées
 * avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-menu-choix', function () {

    if (!class_exists('Psc_Menu_Propositions') || !class_exists('Psc_Menu_Xlsx')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_prop   = psc_table('menu_propositions');
    $t_menu   = psc_table('menus');
    $t_env    = psc_table('envois');
    $t_cal    = psc_table('school_calendar');
    $fixture  = PSC_PATH . 'tests/fixtures/menus-fournisseur.xlsx';
    $name     = 'verify-menu-choix.xlsx';
    $supplier = 'verify-menu-choix-fournisseur@example.invalid';
    $weeks    = array('2090-01-09', '2090-01-16', '2090-01-23');
    $closed   = array('2090-01-23', '2090-01-24', '2090-01-26', '2090-01-27');
    $tmp      = array();

    $failures = array();
    $checks   = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $code = function ($value) { return is_wp_error($value) ? $value->get_error_code() : null; };

    $purge = function () use ($wpdb, $t_prop, $t_menu, $t_env, $t_cal, $name, $weeks, $closed, &$tmp) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_prop WHERE fichier_nom = %s", $name)) as $id) {
            Psc_Menu_Propositions::delete((int) $id);
        }
        foreach ($weeks as $week) {
            foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM $t_menu WHERE semaine_debut = %s", $week)) as $id) {
                $wpdb->delete($t_env, array('objet_type' => 'menu', 'objet_id' => (int) $id));
                $wpdb->delete($t_menu, array('id' => (int) $id));
            }
        }
        foreach ($closed as $date) $wpdb->delete($t_cal, array('jour_date' => $date, 'label' => 'verify-menu-choix'));
        Psc_School_Calendar::flush_closed_cache();
        foreach ($tmp as $file) {
            if (is_file($file)) unlink($file);
        }
        $tmp = array();
    };
    $purge();

    $old_supplier = get_option('psc_supplier_email', null);
    $old_etab     = get_option('psc_supplier_etablissement', null);

    // Serveur d'envoi simulé : chaque tentative est relevée ; $refuse la fait échouer.
    $mails  = array();
    $refuse = false;
    $capture = function ($null, $atts) use (&$mails, &$refuse, $supplier) {
        if (!in_array($supplier, (array) $atts['to'], true)) return $null;
        $attachments = array();
        foreach ((array) $atts['attachments'] as $path) {
            $attachments[] = array(
                'name'   => basename((string) $path),
                'exists' => is_file($path),
                'zip'    => is_file($path) && file_get_contents($path, false, null, 0, 4) === "PK\x03\x04",
                'path'   => (string) $path,
            );
        }
        $mails[] = array('subject' => (string) $atts['subject'], 'message' => (string) $atts['message'], 'attachments' => $attachments);
        return !$refuse;
    };
    add_filter('pre_wp_mail', $capture, 10, 2);

    /** Cellules d'une feuille du classeur : array('D7' => texte en ligne), et l'ordre des colonnes par ligne. */
    $sheet_cells = function ($file, $part) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) return null;
        $xml = $zip->getFromName($part);
        $zip->close();
        $doc = new DOMDocument();
        if (!is_string($xml) || !$doc->loadXML($xml)) return null;
        $cells = array();
        $ordered = true;
        foreach ($doc->getElementsByTagName('row') as $row) {
            $last = 0;
            foreach ($row->getElementsByTagName('c') as $c) {
                $ref = Psc_Menu_Xlsx::split_ref($c->getAttribute('r'));
                if (!$ref || $ref[0] <= $last) $ordered = false;
                $last = $ref ? $ref[0] : $last;
                $cells[$c->getAttribute('r')] = $c->getAttribute('t') === 'inlineStr' ? $c->textContent : null;
            }
        }
        return array('cells' => $cells, 'ordered' => $ordered);
    };

    try {
        /* ---------------- 1. Lecture du classeur ---------------- */
        $data = Psc_Menu_Xlsx::read($fixture);
        $check(!is_wp_error($data), 'lecture : le classeur de test est refusé');
        if (is_wp_error($data)) throw new RuntimeException('classeur de test illisible : ' . $data->get_error_code());
        $check(wp_list_pluck($data['semaines'], 'semaine') === $weeks, 'lecture : semaines attendues non retrouvées');
        $check($data['ignorees'] === array(array('feuille' => 'Informations', 'motif' => 'jours_introuvables')), 'lecture : feuille sans menu non écartée');
        $s1 = $data['semaines'][0];
        $check($s1['part'] === 'xl/worksheets/sheet1.xml' && $s1['etab'] === 'I1', 'lecture : feuille ou cellule « Etablissement » mal repérée');
        $check(array_keys($s1['jours']) === array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi'), 'lecture : jours mal repérés');
        $check(wp_list_pluck($s1['jours'], 'enf') === array('lundi' => 'D', 'mardi' => 'G', 'mercredi' => 'J', 'jeudi' => 'M', 'vendredi' => 'P'), 'lecture : colonnes « Enf » mal repérées');
        $lundi = $s1['jours']['lundi']['plats'];
        $check(array_keys($lundi) === Psc_Menu_Xlsx::CATEGORIES, 'lecture : catégories mal repérées');
        $check($lundi['entree'] === array(
            array('r' => 7, 't' => 'Velouté de légumes BIO', 'g' => 'p'),
            array('r' => 8, 't' => 'Terrine de poisson', 'g' => 'p'),
            array('r' => 11, 't' => 'Œufs mayonnaise', 'g' => 'o'),
            array('r' => 12, 't' => 'Velouté de légumes (chaud)', 'g' => 'o'),
        ), 'lecture : entrées du lundi inattendues');
        $check(wp_list_pluck($lundi['plat'], 'g') === array('p', 'p', 'v', 'o') && $lundi['plat'][3]['t'] === 'Jambon blanc HVE', 'lecture : plats, végétarien et jambon blanc mal classés');
        $check(wp_list_pluck($lundi['accompagnement'], 'r') === array(20, 21, 23, 24, 25), 'lecture : accompagnements et options mal bornés');
        $check(wp_list_pluck($lundi['dessert'], 'r') === array(30, 31), 'lecture : la légende est lue comme un dessert');
        $check($s1['jours']['jeudi']['plats']['dessert'][0]['t'] === 'Yaourt sucré COMMERCE EQUITABLE', 'lecture : texte enrichi mal recollé');
        $check(wp_list_pluck($data['semaines'][1]['jours']['vendredi']['plats']['entree'], 'r') === array(7, 8, 9, 10, 11, 12), 'lecture : troisième entrée du vendredi (feuille 2) perdue');

        $week_cases = array(
            'Du 11 au 15 janvier 2027'               => array(11, null, null, 15, 1, 2027),
            'Du 1er au 5 février 2027'               => array(1, null, null, 5, 2, 2027),
            'Semaine du 18 au 22 janvier (S3)'       => array(18, null, null, 22, 1, null),
            'Du 29 mars au 2 avril 2027'             => array(29, 3, null, 2, 4, 2027),
            'Du 28 décembre 2026 au 1er janvier 2027' => array(28, 12, 2026, 1, 1, 2027),
            'Semaine du 2 au 6 août 2027 (S31)'      => array(2, null, null, 6, 8, 2027),
        );
        foreach ($week_cases as $text => $expected) {
            $got = Psc_Menu_Xlsx::parse_week($text);
            $check(is_array($got) && array_values($got) === $expected, "intitulé de semaine mal lu : $text");
        }
        $check(Psc_Menu_Xlsx::parse_week('Informations') === false && Psc_Menu_Xlsx::parse_week('Du 3 au 7 brumaire 2027') === false, 'intitulé sans semaine accepté');

        /* ---------------- 2. Fichiers refusés ---------------- */
        $make = function ($suffix, array $parts) use (&$tmp) {
            $base = tempnam(get_temp_dir(), 'psc-verify-menu-choix-');
            $file = $base . $suffix;
            $tmp[] = $base;
            $tmp[] = $file;
            $zip = new ZipArchive();
            $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($parts as $part => $xml) $zip->addFromString($part, $xml);
            $zip->close();
            return $file;
        };
        $texte = tempnam(get_temp_dir(), 'psc-verify-menu-choix-');
        $tmp[] = $texte;
        file_put_contents($texte, 'ceci est un texte, pas un classeur');
        $check($code(Psc_Menu_Xlsx::read($texte)) === 'psc_xlsx_format', 'refus : un fichier texte est accepté');
        $check($code(Psc_Menu_Xlsx::read($texte . '.absent')) === 'psc_xlsx_illisible', 'refus : un fichier absent est accepté');
        $check($code(Psc_Menu_Xlsx::read($make('.zip', array('lisezmoi.txt' => 'archive sans classeur')))) === 'psc_xlsx_format', 'refus : une archive sans classeur est acceptée');
        $ns = 'xmlns="' . Psc_Menu_Xlsx::NS_MAIN . '" xmlns:r="' . Psc_Menu_Xlsx::NS_REL . '"';
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="t" Target="worksheets/sheet1.xml"/></Relationships>';
        $check($code(Psc_Menu_Xlsx::read($make('.xlsx', array(
            'xl/workbook.xml' => '<!DOCTYPE x [<!ENTITY e "x">]><workbook ' . $ns . '><sheets><sheet name="Du 9 au 13 janvier 2090" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $rels,
        )))) === 'psc_xlsx_format', 'refus : un classeur avec DOCTYPE est accepté');
        $check($code(Psc_Menu_Xlsx::read($make('.xlsx', array(
            'xl/workbook.xml' => '<workbook ' . $ns . '><sheets><sheet name="Feuil1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $rels,
            'xl/worksheets/sheet1.xml' => '<worksheet ' . $ns . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Budget</t></is></c></row></sheetData></worksheet>',
        )))) === 'psc_xlsx_aucune_semaine', 'refus : un classeur sans menu est accepté');
        $check($code(Psc_Menu_Propositions::import($fixture, 'menus.pdf', false)) === 'psc_xlsx_format', 'refus : une extension autre que .xlsx est acceptée');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_prop WHERE fichier_nom = %s", $name)) === 0, 'refus : une proposition a été enregistrée');

        /* ---------------- 3. Dépôt et choix ---------------- */
        // Troisième semaine fermée au calendrier scolaire (vacances).
        foreach ($closed as $date) {
            $wpdb->insert($t_cal, array('jour_date' => $date, 'label' => 'verify-menu-choix', 'is_closed' => 1, 'source' => 'manual', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
        }
        Psc_School_Calendar::flush_closed_cache();

        $id = Psc_Menu_Propositions::import($fixture, $name, false);
        $check(is_int($id) && $id > 0, 'dépôt : proposition non enregistrée');
        if (!is_int($id)) throw new RuntimeException('dépôt impossible : ' . $code($id));
        $row = Psc_Menu_Propositions::get($id);
        $check($row->statut === Psc_Menu_Propositions::BROUILLON && $row->periode_debut === '2090-01-09' && $row->periode_fin === '2090-01-27', 'dépôt : statut ou période inattendus');
        $source = psc_private_path($row->fichier_path);
        $check(strpos($row->fichier_path, Psc_Menu_Propositions::DIR . '/') === 0 && is_file($source), 'dépôt : fichier absent du répertoire privé');
        $source_hash = is_file($source) ? md5_file($source) : '';

        $semaines = Psc_Menu_Propositions::semaines($row);
        $check(array_keys($semaines) === $weeks, 'choix : semaines inattendues');
        $check(array_keys($semaines['2090-01-09']['jours']) === array('lundi', 'mardi', 'jeudi', 'vendredi'), 'choix : le mercredi est proposé, ou un jour de service manque');
        $check($semaines['2090-01-23']['jours'] === array(), 'choix : une semaine fermée au calendrier est proposée');
        $choix = Psc_Menu_Propositions::choix($row);
        $check($choix['2090-01-09']['lundi'] === array('entree' => 7, 'plat' => 14, 'accompagnement' => 20, 'fromage' => 27, 'dessert' => 30), 'choix : la suggestion n’est pas le choix par défaut');
        $check(!isset($choix['2090-01-23']), 'choix : des choix existent pour une semaine fermée');

        $saved = Psc_Menu_Propositions::save_choix($id, array(
            '2090-01-09' => array(
                'lundi'    => array('entree' => 12, 'plat' => 999, 'dessert' => '31'),
                'jeudi'    => array('accompagnement' => 0),
                'mercredi' => array('entree' => 7),
            ),
            '2090-01-16' => array('jeudi' => array('entree' => 10, 'accompagnement' => 0, 'dessert' => 30)),
            '2091-05-05' => array('lundi' => array('entree' => 7)),
        ));
        $check($saved === true, 'choix : enregistrement refusé');
        $row   = Psc_Menu_Propositions::get($id);
        $choix = Psc_Menu_Propositions::choix($row);
        $check($choix['2090-01-09']['lundi'] === array('entree' => 12, 'plat' => 14, 'accompagnement' => 20, 'fromage' => 27, 'dessert' => 31), 'choix : valeur hors proposition acceptée, ou choix valide perdu');
        $check($choix['2090-01-09']['jeudi']['accompagnement'] === 0 && $choix['2090-01-09']['mardi']['entree'] === 7, 'choix : « aucun » refusé, ou saisie partielle qui efface le reste');
        $check(!isset($choix['2090-01-09']['mercredi']) && !isset($choix['2091-05-05']), 'choix : jour ou semaine hors fichier enregistré');

        /* ---------------- 4. Fichier rempli ---------------- */
        update_option('psc_supplier_etablissement', 'École de test / 95');
        $marks = Psc_Menu_Propositions::marks($row);
        // 4 jours × 5 catégories, moins un « aucun », sur deux semaines ouvertes.
        $check($marks['croix'] === 38, 'fichier : nombre de croix inattendu (' . $marks['croix'] . ')');
        $f1 = $marks['feuilles']['xl/worksheets/sheet1.xml'] ?? array();
        $check(($f1['I1'] ?? '') === 'Etablissement: École de test / 95', 'fichier : établissement non inscrit');
        $check(($f1['D12'] ?? '') === 'X' && ($f1['D14'] ?? '') === 'X' && ($f1['D31'] ?? '') === 'X' && !isset($f1['D7']) && !isset($f1['M20']) && !isset($f1['J7']), 'fichier : croix mal placées en feuille 1');
        $check(array_keys($marks['feuilles']['xl/worksheets/sheet3.xml'] ?? array()) === array('I1'), 'fichier : une semaine fermée reçoit des croix');

        $file = Psc_Menu_Propositions::build_file($row);
        $check(!is_wp_error($file) && is_file($file), 'fichier : production en échec');
        if (!is_wp_error($file)) {
            $check(basename($file) === 'verify-menu-choix-reponse.xlsx', 'fichier : nom de la pièce jointe inattendu');
            $one = $sheet_cells($file, 'xl/worksheets/sheet1.xml');
            $two = $sheet_cells($file, 'xl/worksheets/sheet2.xml');
            $check($one && $one['cells']['D12'] === 'X' && $one['cells']['D7'] === null && $one['cells']['I1'] === 'Etablissement: École de test / 95', 'fichier : feuille 1 mal remplie');
            // Feuille 2 : aucune cellule « Enf » n'existait, elles sont créées à leur place.
            $check($two && ($two['cells']['M10'] ?? null) === 'X' && ($two['cells']['D7'] ?? null) === 'X' && !isset($two['cells']['M20']) && $two['ordered'], 'fichier : cellule créée absente ou hors de l’ordre des colonnes');
            $reread = Psc_Menu_Xlsx::read($file);
            $check(!is_wp_error($reread) && wp_list_pluck($reread['semaines'], 'semaine') === $weeks, 'fichier : le classeur rempli n’est plus lisible');
            $check(md5_file($source) === $source_hash, 'fichier : l’original du fournisseur a été modifié');
            Psc_Menu_Propositions::discard_file($file);
            $check(!file_exists($file) && !is_dir(dirname($file)), 'fichier : copie temporaire non supprimée');
        }
        update_option('psc_supplier_etablissement', '');
        $empty = Psc_Menu_Propositions::marks($row);
        $check(!isset($empty['feuilles']['xl/worksheets/sheet1.xml']['I1']) && !isset($empty['feuilles']['xl/worksheets/sheet3.xml']), 'fichier : établissement inscrit alors que le réglage est vide');
        update_option('psc_supplier_etablissement', 'École de test / 95');

        /* ---------------- 5. Menus des familles ---------------- */
        $lines = array(
            'Velouté de légumes BIO'               => 'Velouté de légumes*',
            'Hachis parmentier BIO (Plat complet)' => 'Hachis parmentier (Plat complet)*',
            'Pommes de terre BIO façon piemontaise' => 'Pommes de terre façon piemontaise*',
            'Plat complet'                          => '',
            'Biscuit au citron'                     => 'Biscuit au citron',
            'Yaourt sucré COMMERCE EQUITABLE'       => 'Yaourt sucré COMMERCE EQUITABLE',
        );
        foreach ($lines as $text => $expected) {
            $check(Psc_Menu_Propositions::menu_line($text) === $expected, "menu : ligne inattendue pour « $text »");
        }
        $prevus = Psc_Menu_Propositions::menus_prevus($row);
        $check(($prevus['2090-01-09']['lundi'] ?? '') === "Velouté de légumes (chaud)\nFilet de poulet NOUVELLE AGRICULTURE Vallée d'auge\nPoêlée de légumes*\nCamembert*\nArlequin de fruits coupelle", 'menu : texte du lundi inattendu');
        $check(($prevus['2090-01-09']['jeudi'] ?? '') === "Coleslaw\nLasagnes bolognaises VBF (plat complet)\nMimolette\nYaourt sucré COMMERCE EQUITABLE", 'menu : plat complet sans accompagnement mal rendu');
        $check(($prevus['2090-01-16']['jeudi'] ?? '') === "Carottes râpées*\nGratin de pâtes ratatouille et fromage (plat complet)*\nBrie pointe*\nFruit frais*", 'menu : option bio ou plat complet bio mal rendus');
        $check(!isset($prevus['2090-01-23']), 'menu : un menu est prévu pour une semaine fermée');

        /* ---------------- 6. Envoi ---------------- */
        // Menus préexistants : un brouillon (semaine 1), un déjà envoyé aux familles (semaine 2).
        $draft_id = Psc_Menus::save(0, '2090-01-09', array('lundi' => 'Ancien menu du lundi', 'mardi' => 'Ancien menu du mardi'), 'Origine de test');
        $sent_id  = Psc_Menus::save(0, '2090-01-16', array('lundi' => 'Menu déjà envoyé'));
        $wpdb->update($t_menu, array('sent_at' => '2089-12-20 10:00:00'), array('id' => (int) $sent_id));

        update_option('psc_supplier_email', '');
        $check($code(Psc_Menu_Propositions::send($id, 'verif-choix-a')) === 'psc_no_supplier_email', 'envoi : parti sans adresse fournisseur');
        update_option('psc_supplier_email', $supplier);

        $refuse = true;
        $check($code(Psc_Menu_Propositions::send($id, 'verif-choix-b')) === 'psc_mail_failed', 'envoi : échec du mail non signalé');
        $row = Psc_Menu_Propositions::get($id);
        $check($row->statut === Psc_Menu_Propositions::BROUILLON && empty($row->sent_at), 'envoi : réponse marquée envoyée malgré le refus');
        $check(Psc_Menus::get($draft_id)->lundi === 'Ancien menu du lundi', 'envoi : menu rempli alors que la réponse n’est pas partie');
        $check(count($mails) === 1, 'envoi : nombre de tentatives inattendu');
        $refuse = false;
        Psc_Menu_Propositions::send($id, 'verif-choix-b');
        $check(count($mails) === 1, 'envoi : double soumission, le mail est retenté');

        $check(Psc_Menu_Propositions::relancer($id) === $id, 'envoi : relance en échec');
        $check(count($mails) === 2, 'envoi : la relance n’a pas renvoyé le mail une seule fois');
        $mail = end($mails);
        $check(strpos($mail['subject'], '09/01/2090 au 27/01/2090') !== false, 'envoi : la période manque au sujet');
        $check(count($mail['attachments']) === 1 && $mail['attachments'][0]['name'] === 'verify-menu-choix-reponse.xlsx' && $mail['attachments'][0]['exists'] && $mail['attachments'][0]['zip'], 'envoi : pièce jointe absente ou invalide');
        $check(!file_exists($mail['attachments'][0]['path'] ?? ''), 'envoi : pièce jointe temporaire non supprimée');
        $row = Psc_Menu_Propositions::get($id);
        $check($row->statut === Psc_Menu_Propositions::MENUS_REMPLIS && !empty($row->sent_at) && !empty($row->menus_remplis_at) && $row->supplier_email === $supplier, 'envoi : statut, dates ou destinataire non enregistrés');

        /* ---------------- 7. Menus remplis après l'envoi ---------------- */
        $draft = Psc_Menus::get($draft_id);
        $check($draft->lundi === $prevus['2090-01-09']['lundi'] && $draft->jeudi === $prevus['2090-01-09']['jeudi'], 'menus : menu non envoyé pas remplacé');
        $check($draft->origine_viande === 'Origine de test' && empty($draft->sent_at), 'menus : origine de la viande perdue, ou menu marqué envoyé');
        $kept = Psc_Menus::get($sent_id);
        $check($kept->lundi === 'Menu déjà envoyé' && (string) $kept->mardi === '', 'menus : menu déjà envoyé aux familles remplacé sans confirmation');
        $etats = Psc_Menu_Propositions::etat_menus($row);
        $check($etats['2090-01-09']['etat'] === 'a_jour' && $etats['2090-01-16']['etat'] === 'envoye_different' && $etats['2090-01-23']['etat'] === 'sans_choix', 'menus : situation par semaine inattendue');
        $check(Psc_Menus::get_by_week('2090-01-23') === null, 'menus : menu créé pour une semaine fermée');

        $forced = Psc_Menu_Propositions::remplir_menus($row, array('2090-01-16'));
        $check(($forced['2090-01-16'] ?? '') === 'remplace' && ($forced['2090-01-09'] ?? '') === 'a_jour', 'menus : remplacement confirmé non effectué');
        $kept = Psc_Menus::get($sent_id);
        $check($kept->jeudi === $prevus['2090-01-16']['jeudi'] && $kept->sent_at === '2089-12-20 10:00:00', 'menus : menu envoyé mal remplacé, ou date d’envoi perdue');

        // Types des plats : enregistrés avec le menu, dans l'ordre du service ; un plat complet n'a pas d'accompagnement.
        $draft = Psc_Menus::get($draft_id);
        $check($draft->lundi_types === 'entree,plat,accompagnement,fromage,dessert' && $draft->jeudi_types === 'entree,plat,fromage,dessert', 'types : types des plats non enregistrés au remplissage');
        $check($draft->mardi_types === implode(',', Psc_Menus::types_of($draft->mardi, $draft->mardi_types)) && $kept->jeudi_types === 'entree,plat,fromage,dessert', 'types : types incohérents avec le texte du jour');

        // Fichier réinjecté sur des menus qui n'ont pas de type (saisis avant
        // le schéma 4.25.0) : même texte, les types sont ajoutés — menu
        // envoyé compris, sans confirmation, puisque le texte ne change pas.
        $wpdb->query($wpdb->prepare("UPDATE $t_menu SET lundi_types = '', mardi_types = '', jeudi_types = '', vendredi_types = '' WHERE id IN (%d, %d)", $draft_id, $sent_id));
        $check(Psc_Menus::types_of(Psc_Menus::get($draft_id)->lundi, Psc_Menus::get($draft_id)->lundi_types) === array(), 'types : préparation du menu sans type en échec');
        $retyped = Psc_Menu_Propositions::remplir_menus($row);
        $check(($retyped['2090-01-09'] ?? '') === 'a_jour' && ($retyped['2090-01-16'] ?? '') === 'a_jour', 'types : réinjection, situation par semaine inattendue');
        $draft = Psc_Menus::get($draft_id);
        $kept  = Psc_Menus::get($sent_id);
        $check($draft->lundi_types === 'entree,plat,accompagnement,fromage,dessert' && $draft->jeudi_types === 'entree,plat,fromage,dessert', 'types : réinjection, types non ajoutés au menu existant');
        $check($draft->lundi === $prevus['2090-01-09']['lundi'] && $draft->origine_viande === 'Origine de test', 'types : réinjection, texte ou origine de la viande modifiés');
        $check($kept->jeudi_types === 'entree,plat,fromage,dessert' && $kept->sent_at === '2089-12-20 10:00:00', 'types : réinjection, menu envoyé non typé ou date d’envoi perdue');

        /* ---------------- 8. Après l'envoi ---------------- */
        $check($code(Psc_Menu_Propositions::save_choix($id, array('2090-01-09' => array('lundi' => array('entree' => 7))))) === 'psc_proposition_verrouillee', 'verrou : choix modifiables après l’envoi');
        $check($code(Psc_Menu_Propositions::send($id, 'verif-choix-c')) === 'psc_proposition_verrouillee' && count($mails) === 2, 'verrou : réponse renvoyée une seconde fois');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_env WHERE objet_type = %s AND erreur LIKE %s", Psc_Menu_Propositions::ENVOI, '%@%')) === 0, 'causes d’échec : une adresse e-mail est enregistrée');

        /* ---------------- 9. Suppression ---------------- */
        $check(Psc_Menu_Propositions::delete($id) === true, 'suppression : refusée');
        $check(Psc_Menu_Propositions::get($id) === null && !is_file($source), 'suppression : ligne ou fichier conservés');
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_env WHERE objet_type = %s AND objet_id = %d", Psc_Menu_Propositions::ENVOI, $id)) === 0, 'suppression : suivi d’envoi conservé');
        $check(Psc_Menus::get($draft_id) !== null, 'suppression : menu rempli supprimé avec la proposition');
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
        $purge();
        if ($old_supplier === null) delete_option('psc_supplier_email'); else update_option('psc_supplier_email', $old_supplier);
        if ($old_etab === null) delete_option('psc_supplier_etablissement'); else update_option('psc_supplier_etablissement', $old_etab);
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("$checks vérifications réussies.");
});
