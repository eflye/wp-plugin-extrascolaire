<?php
/**
 * Génération OpenDocument Spreadsheet (.ods) sans dépendance.
 *
 * Un .ods est un ZIP contenant un XML minimal : le fichier `mimetype`
 * (non compressé, première entrée), le manifeste et le contenu. Ces
 * fonctions construisent les morceaux XML purs — l'assemblage ZIP, lui,
 * vit chez l'appelant (Psc_Invoices::download_sepa()) car il a besoin des
 * en-têtes HTTP et du système de fichiers.
 *
 * LibreOffice Calc ouvre ce format nativement (c'est le sien) ; Excel le
 * lit aussi. Aucune librairie embarquée : le sous-ensemble utilisé ici
 * (table, lignes, cellules texte et numériques) est stable dans ODF 1.2.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/** Type MIME du classeur, première entrée non compressée de l'archive. */
function psc_ods_mimetype() {
    return 'application/vnd.oasis.opendocument.spreadsheet';
}

/** Manifeste minimal : la racine et le contenu. */
function psc_ods_manifest_xml() {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
        . '<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="' . psc_ods_mimetype() . '"/>'
        . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        . '</manifest:manifest>';
}

/**
 * Échappe une cellule au format XML ODF — les nouvelles lignes deviennent
 * des <text:line-break/> (un retour chariot brut dans <text:p> est
 * normalisé à l'affichage mais vaut mieux l'expliciter).
 */
function psc_ods_escape_text($value) {
    $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
    $parts = explode("\n", $value);
    $parts = array_map(function ($p) {
        return htmlspecialchars($p, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }, $parts);
    return implode('<text:line-break/>', $parts);
}

/**
 * Construit le XML de contenu : UNE feuille, en-têtes + lignes.
 *
 * @param string $sheet_name Nom de la feuille (échappé ici).
 * @param array  $headers    Cellules d'en-tête (chaînes).
 * @param array  $rows       Lignes : chaque cellule est soit une chaîne
 *                           (texte), soit un tableau numérique
 *                           array('float' => 58.5), soit null (vide).
 * @return string XML <office:document-content> complet.
 */
function psc_ods_content_xml($sheet_name, array $headers, array $rows) {
    $cell = function ($value) {
        if ($value === null || $value === '') {
            return '<table:table-cell/>';
        }
        if (is_array($value) && isset($value['float'])) {
            // Montant en 2 décimales, point décimal — le formatage local
            // (virgule) est du ressort de l'application de calcul.
            $num = number_format((float) $value['float'], 2, '.', '');
            return '<table:table-cell office:value-type="float" office:value="' . $num . '">'
                . '<text:p>' . $num . '</text:p></table:table-cell>';
        }
        return '<table:table-cell office:value-type="string"><text:p>' . psc_ods_escape_text($value) . '</text:p></table:table-cell>';
    };

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<office:document-content'
        . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
        . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
        . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
        . ' office:version="1.2">'
        . '<office:body><office:spreadsheet>'
        . '<table:table table:name="' . psc_ods_escape_text($sheet_name) . '">';

    $xml .= '<table:table-row>';
    foreach ($headers as $h) {
        $xml .= '<table:table-cell office:value-type="string"><text:p>' . psc_ods_escape_text($h) . '</text:p></table:table-cell>';
    }
    $xml .= '</table:table-row>';

    foreach ($rows as $row) {
        $xml .= '<table:table-row>';
        foreach ($row as $value) {
            $xml .= $cell($value);
        }
        $xml .= '</table:table-row>';
    }

    $xml .= '</table:table>'
        . '</office:spreadsheet></office:body>'
        . '</office:document-content>';
    return $xml;
}
