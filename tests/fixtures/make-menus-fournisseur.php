<?php
/**
 * Générateur du classeur de test tests/fixtures/menus-fournisseur.xlsx.
 *
 * Le classeur reprend la mise en forme du fichier de choix des menus du
 * fournisseur de cantine (une feuille par semaine, jours en colonnes avec
 * leurs colonnes « Ad » et « Enf », catégories annoncées dans la colonne
 * A), sans ses images ni ses données : plats inventés, semaines lointaines
 * (janvier 2090) que rien d'autre n'utilise.
 *
 * Particularités reproduites, toutes observées dans un fichier réel :
 *  - feuille 1 : mise en forme courante, titre complet ;
 *  - feuille 2 : titre sans année, semaine à thème, troisième entrée le
 *    vendredi, colonnes « Enf » sans cellule (à créer au remplissage) ;
 *  - feuille 3 : pas de titre, semaine de vacances ;
 *  - feuille 4 : feuille d'information sans menu (écartée à la lecture).
 *
 * Usage (PHP en ligne de commande, extension Zip) :
 *   php tests/fixtures/make-menus-fournisseur.php
 * Le fichier produit est versionné : ne relancer qu'après avoir modifié ce
 * script, puis mettre à jour les attentes de bin/verify-menu-choix.php et
 * de tests/menu-choix-fournisseur.spec.ts.
 */

if (PHP_SAPI !== 'cli') exit(1);

$target = __DIR__ . '/menus-fournisseur.xlsx';

$strings = array();
$s = function ($text) use (&$strings) {
    if (!isset($strings[$text])) $strings[$text] = count($strings);
    return $strings[$text];
};
// Texte enrichi (deux séquences de mise en forme), comme les mentions en
// couleur du fournisseur : index réservé, écrit à part dans la table.
$rich_index = $s("\0rich");

$days = array('LUNDI ' => 'B', 'MARDI ' => 'E', 'MERCREDI ' => 'H', 'JEUDI ' => 'K', 'VENDREDI ' => 'N');
$ad   = array('B' => 'C', 'E' => 'F', 'H' => 'I', 'K' => 'L', 'N' => 'O');
$enf  = array('B' => 'D', 'E' => 'G', 'H' => 'J', 'K' => 'M', 'N' => 'P');

/** Plats d'une feuille : array(ligne => array(colonne du jour => texte)). */
$menus = array(
    1 => array(
        7  => array('B' => 'Velouté de légumes BIO ', 'E' => 'Œuf dur mayonnaise', 'H' => 'Salade de blé', 'K' => 'Coleslaw ', 'N' => 'Saucisson ail '),
        8  => array('B' => 'Terrine de poisson ', 'E' => 'Salade de carottes', 'H' => 'Taboulé', 'K' => 'Salade de riz niçoise(riz, tomate, thon) ', 'N' => 'Choux rouges '),
        14 => array('B' => "Filet de poulet NOUVELLE AGRICULTURE Vallée d'auge", 'E' => 'Crêpe au fromage', 'H' => 'Colombo de porc HVE ', 'K' => 'Lasagnes bolognaises VBF (plat complet)', 'N' => 'Pavé de poisson blanc MSC '),
        15 => array('B' => 'Jambon HVE sauce charcutière ', 'E' => 'Marmite de poisson MSC', 'H' => 'Tarte forestière ', 'K' => 'Cordon bleu de volaille', 'N' => 'Merguez sauce tomate'),
        16 => array('B' => 'Rousties de légumes', 'E' => 'Crêpe au fromage', 'H' => 'Tarte forestière ', 'K' => "Palet végétarien à l'italienne ", 'N' => 'Omelette'),
        20 => array('B' => 'Poêlée de légumes BIO ', 'E' => 'Tortis', 'H' => 'Semoule', 'K' => 'Plat complet ', 'N' => 'Butternuts et carottes'),
        21 => array('B' => 'Haricots blancs à la tomate', 'E' => 'Ratatouille ', 'H' => 'Salade verte ', 'K' => 'Frites', 'N' => 'Pommes de terre quartiers LOCAL'),
        27 => array('B' => 'Camembert BIO ', 'E' => 'Emmental BIO', 'H' => 'Chanteneige', 'K' => 'Mimolette ', 'N' => 'Edam'),
        28 => array('B' => 'Yaourt aromatisé', 'E' => 'Petit suisse sucré ', 'H' => 'Yaourt aromatisé', 'K' => 'Galette bretonne', 'N' => ' Petit suisse aux fruits'),
        30 => array('B' => 'Crème dessert vanille BIO ', 'E' => 'Fruit frais', 'H' => 'Compote de poires ', 'K' => "\0rich", 'N' => 'Gâteau au chocolat'),
        31 => array('B' => 'Arlequin de fruits coupelle', 'E' => 'Crème dessert caramel', 'H' => 'Gâteau basque', 'K' => 'Fruit frais', 'N' => 'Liégeois vanille'),
    ),
    2 => array(
        7  => array('B' => 'Salade basque', 'E' => 'Pamplemousse', 'H' => 'Rillettes HVE', 'K' => 'Velouté de potiron', 'N' => "Jus d'orange briquette"),
        8  => array('B' => 'Brocolis crème de curry', 'E' => 'Salade Marco Polo', 'H' => 'Chou blanc au fromage', 'K' => 'Boulgour petits pois', 'N' => 'Crêpes aux champignons'),
        9  => array('N' => 'Médaillon de surimi '),
        14 => array('B' => 'Saucisse Knack de porc HVE nature', 'E' => 'Fricassée de volaille', 'H' => 'Tajine de poisson MSC', 'K' => 'Gratin de pâtes ratatouille et fromage BIO  (plat complet) ', 'N' => 'Beignets au calamar nature '),
        15 => array('B' => 'Pilons de poulet basquaise ', 'E' => 'Croustillant fromager', 'H' => 'Bouchées à la reine ', 'K' => 'Langue de boeuf sauce piquante', 'N' => 'Rôti de porc HVE au miel '),
        16 => array('B' => 'Tarte au fromage', 'E' => 'Croustillant fromager', 'H' => 'Bouchée forestière ', 'K' => 'Gratin de pâtes ratatouille et fromage (plat complet) ', 'N' => "Nugget's de blé"),
        20 => array('B' => 'Petits pois', 'E' => 'Beignets de légumes', 'H' => 'Riz', 'K' => 'Plat complet ', 'N' => 'Epinards béchamel'),
        21 => array('B' => 'Semoule', 'E' => 'Poêlée méridionale', 'H' => 'Salade verte', 'K' => 'Printanière de légumes', 'N' => 'Lentilles cuisinées'),
        27 => array('B' => 'Bûchette lait mélange', 'E' => 'Mimolette', 'H' => 'Fripon', 'K' => 'Brie pointe BIO', 'N' => 'Vache qui rit'),
        28 => array('B' => 'Yaourt aromatisé', 'E' => 'Petit suisse aux fruits', 'H' => 'Yaourt sucré', 'K' => 'Petit suisse sucré', 'N' => 'Laitage du jour'),
        30 => array('B' => 'Liégeois chocolat', 'E' => 'Compote pomme abricot HVE', 'H' => 'Fromage blanc aux fruits', 'K' => 'Fruit frais BIO', 'N' => 'Eclair vanille'),
        31 => array('B' => 'Ananas au sirop', 'E' => 'Semoule au lait', 'H' => 'Fruit frais', 'K' => 'Yaourt aux fruits', 'N' => 'Flan nappé caramel'),
    ),
    3 => array(
        7  => array('B' => 'Salade de pâtes BIO', 'E' => 'Velouté de légumes', 'H' => 'Macédoine', 'K' => 'Œuf dur', 'N' => 'Salade chef'),
        8  => array('B' => 'Céleri rémoulade', 'E' => 'Cervelas nature', 'H' => 'Endives au bleu', 'K' => 'Taboulé de choux fleurs', 'N' => 'Crevettes'),
        14 => array('B' => 'Jambon HVE', 'E' => 'Boulettes de boeuf VBF', 'H' => 'Poisson blanc MSC', 'K' => "Tarte à l'italienne", 'N' => 'Poisson pané MSC'),
        15 => array('B' => 'Emincé de volaille', 'E' => 'Rôti de porc HVE', 'H' => 'Raviolis aux légumes', 'K' => 'Filet de poulet', 'N' => 'Parmentier de boudin noir'),
        16 => array('B' => 'Rousties de légumes', 'E' => 'Boulettes végétales', 'H' => 'Raviolis aux légumes', 'K' => "Tarte à l'italienne", 'N' => 'Oeufs durs florentine'),
        20 => array('B' => 'Haricots verts BIO', 'E' => 'Blé pilaf', 'H' => 'Semoule HVE', 'K' => 'Pâtes', 'N' => 'Epinards béchamel'),
        21 => array('B' => 'Haricots blancs', 'E' => 'Brocolis', 'H' => 'Plat complet', 'K' => 'Petits pois carottes', 'N' => 'Plat complet'),
        27 => array('B' => 'Fromage du jour BIO', 'E' => 'Cantadou', 'H' => 'Saint Paulin', 'K' => 'Petit suisse aux fruits', 'N' => 'Bûchette'),
        28 => array('B' => 'Petit suisse sucré', 'E' => 'Yaourt sucré BIO', 'H' => 'Yaourt aromatisé', 'K' => 'Camembert', 'N' => 'Yaourt sucré'),
        30 => array('B' => 'Crème dessert chocolat BIO', 'E' => 'Flan nappé caramel', 'H' => 'Poire au sirop', 'K' => 'Fruit frais', 'N' => 'Compote pomme fraise'),
        31 => array('B' => 'Fruit frais', 'E' => 'Yaourt aux fruits', 'H' => 'Gâteau basque', 'K' => 'Fromage blanc sucré', 'N' => 'Novly chocolat'),
    ),
);

/** Substitutions permanentes, identiques chaque jour (le lundi n'a pas de carottes râpées). */
$options = array(
    10 => 'Carottes râpées',
    11 => 'Œufs mayonnaise ',
    12 => 'Velouté de légumes (chaud) ',
    18 => 'Jambon blanc HVE',
    23 => 'Haricots verts ',
    24 => 'Purée ',
    25 => 'Pâtes ',
);

$labels = array(
    7 => 'Entrées ', 10 => 'Options', 14 => 'Plats Protidiques ', 16 => 'Végétarien ',
    20 => 'Accompagnements ', 23 => 'Options ', 27 => 'Fromage / Laitage ', 30 => 'Desserts ',
);

$sheets = array(
    1 => array('name' => 'Du 9 au 13 janvier 2090', 'titre' => 'Semaine du 9 au 13 janvier 2090 (S2) ', 'theme' => ''),
    2 => array('name' => 'Du 16 au 20 janvier 2090', 'titre' => 'Semaine du 16 au 20 janvier (S3)', 'theme' => 'Semaine Vitaminée ! '),
    3 => array('name' => 'Du 23 au 27 janvier 2090', 'titre' => '', 'theme' => 'Vacances Scolaires Zone C (Semaine 1) '),
);

$cell = function ($ref, $text, $style = 0) use ($s) {
    return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . ' t="s"><v>' . $s($text) . '</v></c>';
};
$col_num = function ($letter) { return ord($letter) - 64; };

$sheet_xml = array();
foreach ($sheets as $n => $info) {
    $rows = array();
    $rows[1] = array('A' => $cell('A1', 'AU '), 'C' => $cell('C1', 'MENU'), 'I' => $cell('I1', 'Etablissement: …......................................', 1));
    $rows[2] = array('K' => $cell('K2', 'MENUS 1 REPAS BIO PAR SEMAINE '));
    if ($info['titre'] !== '') $rows[3] = array('A' => $cell('A3', $info['titre'], 1));
    if ($info['theme'] !== '') $rows[4] = array('B' => $cell('B4', $info['theme']));
    foreach ($days as $label => $col) {
        $rows[6][$col]       = $cell($col . '6', $label, 1);
        $rows[6][$ad[$col]]  = $cell($ad[$col] . '6', 'Ad');
        $rows[6][$enf[$col]] = $cell($enf[$col] . '6', $col === 'B' ? 'Enf' : 'Enf ');
    }
    foreach ($labels as $row => $label) $rows[$row]['A'] = $cell('A' . $row, $label, 1);
    $dishes = $menus[$n];
    foreach ($options as $row => $text) {
        foreach ($days as $col) {
            if ($row === 10 && $col === 'B') continue;
            $dishes[$row][$col] = ($n === 2 && $row === 10 && $col === 'K') ? 'Carottes râpées BIO ' : $text;
        }
    }
    foreach ($dishes as $row => $cols) {
        foreach ($cols as $col => $text) {
            $rows[$row][$col] = $cell($col . $row, $text);
            // Cellules de réponse vides mais stylées, sauf en feuille 2
            // (le remplissage doit alors créer la cellule).
            if ($n !== 2) {
                $rows[$row][$ad[$col]]  = '<c r="' . $ad[$col] . $row . '" s="1"/>';
                $rows[$row][$enf[$col]] = '<c r="' . $enf[$col] . $row . '" s="1"/>';
            }
        }
    }
    // Légende, sous le tableau, dans les colonnes des jours.
    $rows[36] = array('B' => $cell('B36', 'Suggestion de notre diététicienne ', 1), 'E' => $cell('E36', 'BIO '), 'H' => $cell('H36', 'Fournisseurs locaux '), 'N' => $cell('N36', 'Plat végétarien '));
    $rows[38] = array('B' => $cell('B38', 'Contient du porc '), 'H' => $cell('H38', 'Elaboré dans notre cuisine'));

    ksort($rows);
    $data = '';
    foreach ($rows as $r => $cols) {
        uksort($cols, function ($a, $b) use ($col_num) { return $col_num($a) - $col_num($b); });
        $data .= '<row r="' . $r . '">' . implode('', $cols) . '</row>';
    }
    $sheet_xml[$n] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetData>' . $data . '</sheetData>'
        . '<mergeCells count="3"><mergeCell ref="I1:P1"/><mergeCell ref="A3:P3"/><mergeCell ref="B4:P4"/></mergeCells>'
        . '</worksheet>';
}
// Feuille d'information : aucun jour, aucun menu.
$sheets[4] = array('name' => 'Informations');
$sheet_xml[4] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
    . '<row r="1">' . $cell('A1', 'Merci de nous retourner vos choix avant la date indiquée.') . '</row>'
    . '</sheetData></worksheet>';

$esc = function ($text) { return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };

$sst = '';
foreach (array_keys($strings) as $text) {
    if ($text === "\0rich") {
        $sst .= '<si><r><rPr><b/></rPr><t xml:space="preserve">Yaourt sucré </t></r><r><rPr><color rgb="FFFF0000"/></rPr><t>COMMERCE EQUITABLE</t></r></si>';
    } else {
        $sst .= '<si><t xml:space="preserve">' . $esc($text) . '</t></si>';
    }
}
$sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">' . $sst . '</sst>';

$wb_sheets = '';
$wb_rels   = '';
$types     = '';
foreach ($sheets as $n => $info) {
    $wb_sheets .= '<sheet name="' . $esc($info['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $wb_rels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    $types     .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
}
$next = count($sheets) + 1;

$parts = array(
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $types
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $wb_sheets . '</sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wb_rels
        . '<Relationship Id="rId' . $next . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId' . ($next + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>',
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf></cellXfs>'
        . '</styleSheet>',
    'xl/sharedStrings.xml' => $sst,
);
foreach ($sheet_xml as $n => $xml) $parts['xl/worksheets/sheet' . $n . '.xml'] = $xml;

if (file_exists($target)) unlink($target);
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Impossible de créer $target\n");
    exit(1);
}
foreach ($parts as $name => $contents) $zip->addFromString($name, $contents);
$zip->close();

echo basename($target) . ' : ' . filesize($target) . " octets, " . count($sheets) . " feuilles\n";
