<?php
/**
 * Générateur du document de test
 * tests/fixtures/Note changement de menus 06-02-90.docx.
 *
 * Le document reprend la structure des notes de changement de menus du
 * fournisseur de cantine, sans leur contenu ni leur papier à en-tête :
 *  - le texte est porté par des zones de texte, que Word enregistre deux
 *    fois (mc:Choice et mc:Fallback) dans un même paragraphe ;
 *  - les remplacements sont des éléments de liste (w:numPr) ;
 *  - deux zones d'exemple du modèle Word sont restées en place ;
 *  - la date du jour concerné n'existe que dans le nom du fichier (lundi
 *    6 février 2090), le pied de page gardant une date périmée.
 *
 * Seule la structure lue par Psc_Menu_Docx est fidèle : le fichier n'a pas
 * vocation à s'ouvrir dans Word.
 *
 * Usage (PHP en ligne de commande, extension Zip) :
 *   php tests/fixtures/make-note-fournisseur.php
 * Le fichier produit est versionné : ne relancer qu'après avoir modifié ce
 * script, puis mettre à jour les attentes de bin/verify-menu-notes.php et
 * de tests/menu-notes-fournisseur.spec.ts.
 */

if (PHP_SAPI !== 'cli') exit(1);

$target = __DIR__ . '/Note changement de menus 06-02-90.docx';

$esc = function ($text) { return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
$p = function ($text, $puce = false) use ($esc) {
    return '<w:p>' . ($puce ? '<w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>' : '')
        . '<w:r><w:t xml:space="preserve">' . $esc($text) . '</w:t></w:r></w:p>';
};
$placeholder = '[Attirez l’attention du lecteur avec une citation du document ou utilisez cet espace pour mettre en valeur un point clé.]';

$zones = array(
    $p('Bien cordialement  '),
    $p('Chers Clients,')
        . $p("Suite à une rupture fournisseur nous avons dû remplacer certains produits prévus lundi\xC2\xA0: ")
        . $p('Les crèmes dessert vanille bio sont remplacées par des crèmes dessert vanille classiques (ne concerne que les clients ayant un contrat avec bio) ', true)
        . $p('Le camembert bio est remplacé par de la tomme noire', true)
        . $p('Les frites sont remplacées par des pommes vapeur', true)
        . $p('Merci de votre compréhension '),
    $p($placeholder),
    $p($placeholder),
);

// Chaque zone de texte figure deux fois, comme dans un document Word :
// la forme courante (mc:Choice) et sa copie de repli (mc:Fallback).
$runs = '';
foreach ($zones as $content) {
    $runs .= '<w:r><mc:AlternateContent>'
        . '<mc:Choice Requires="wps"><w:drawing><wp:anchor><a:graphic><a:graphicData><wps:wsp><wps:txbx>'
        . '<w:txbxContent>' . $content . '</w:txbxContent>'
        . '</wps:txbx></wps:wsp></a:graphicData></a:graphic></wp:anchor></w:drawing></mc:Choice>'
        . '<mc:Fallback><w:pict><v:shape><v:textbox><w:txbxContent>' . $content . '</w:txbxContent></v:textbox></v:shape></w:pict></mc:Fallback>'
        . '</mc:AlternateContent></w:r>';
}

$ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
    . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
    . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
    . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
    . ' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
    . ' xmlns:v="urn:schemas-microsoft-com:vml"';

$parts = array(
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
        . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>',
    'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:document ' . $ns . '><w:body><w:p>' . $runs . '</w:p><w:sectPr/></w:body></w:document>',
    // Pied de page à la date périmée : il ne doit jamais servir à dater la note.
    'word/footer1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<w:ftr ' . $ns . '>' . $p('Note d’information envoyée le 29/12/2025') . '</w:ftr>',
);

if (file_exists($target)) unlink($target);
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Impossible de créer $target\n");
    exit(1);
}
foreach ($parts as $name => $contents) $zip->addFromString($name, $contents);
$zip->close();

echo basename($target) . ' : ' . filesize($target) . " octets\n";
