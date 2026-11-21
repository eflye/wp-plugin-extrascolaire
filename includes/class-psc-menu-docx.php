<?php
if (!defined('ABSPATH')) exit;

/**
 * Lecture du texte d'un document Word (.docx), sans dépendance : sert à
 * lire la note de changement de menus du fournisseur de cantine.
 *
 * Ces notes sont mises en page avec des zones de texte : Word enregistre
 * alors chaque zone deux fois (mc:Choice et mc:Fallback), et le paragraphe
 * qui les porte contient lui-même leurs paragraphes. Seuls les paragraphes
 * « feuilles » de la branche principale sont donc lus, une fois chacun. Les
 * zones d'exemple laissées par le modèle Word (« [Attirez l'attention du
 * lecteur…] ») sont écartées.
 */
class Psc_Menu_Docx {

    const NS_W  = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const NS_MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    /** Nombre de paragraphes retenus au plus, et longueur de chacun. */
    const MAX_LINES = 200;
    const MAX_LINE_LENGTH = 500;

    /**
     * Paragraphes du document, dans l'ordre du fichier.
     *
     * @return array|WP_Error Liste de array('t' => texte, 'puce' => bool élément de liste).
     */
    public static function read($path) {
        $zip = Psc_Ooxml::open($path);
        if (is_wp_error($zip)) return self::erreur($zip);
        $xml = Psc_Ooxml::part($zip, 'word/document.xml');
        $zip->close();
        $doc = is_wp_error($xml) ? $xml : Psc_Ooxml::load($xml);
        if (is_wp_error($doc)) return self::erreur($doc);

        // Copies de repli des zones de texte : retirées avant lecture.
        $fallbacks = array();
        foreach ($doc->getElementsByTagNameNS(self::NS_MC, 'Fallback') as $fallback) $fallbacks[] = $fallback;
        foreach ($fallbacks as $fallback) {
            if ($fallback->parentNode) $fallback->parentNode->removeChild($fallback);
        }

        $lines = array();
        foreach ($doc->getElementsByTagNameNS(self::NS_W, 'p') as $p) {
            // Un paragraphe qui en contient d'autres (zones de texte) est lu par ses feuilles.
            if ($p->getElementsByTagNameNS(self::NS_W, 'p')->length > 0) continue;
            $text = '';
            $puce = false;
            foreach ($p->getElementsByTagNameNS(self::NS_W, '*') as $node) {
                if ($node->localName === 't') {
                    $text .= $node->textContent;
                } elseif (in_array($node->localName, array('tab', 'br', 'cr'), true)) {
                    $text .= ' ';
                } elseif ($node->localName === 'numPr') {
                    $puce = true;
                }
            }
            $text = Psc_Menu_Xlsx::clean($text);
            // Zone d'exemple du modèle Word, jamais remplie par le fournisseur.
            if ($text === '' || preg_match('/^\[.*\]$/u', $text)) continue;
            $lines[] = array('t' => mb_substr($text, 0, self::MAX_LINE_LENGTH), 'puce' => $puce);
            if (count($lines) >= self::MAX_LINES) break;
        }
        if (!$lines) {
            return new WP_Error('psc_docx_vide', __('Aucun texte n’a été trouvé dans ce document.', 'periscolaire-registration'));
        }
        return $lines;
    }

    /** Erreur de Psc_Ooxml, sous le code et le message du document. */
    private static function erreur($error) {
        switch (Psc_Ooxml::reason($error)) {
            case 'extension':
                return new WP_Error('psc_docx_extension', __('Les extensions PHP Zip et DOM sont requises pour lire ce fichier.', 'periscolaire-registration'));
            case 'illisible':
                return new WP_Error('psc_docx_illisible', __('Le fichier n’a pas pu être lu.', 'periscolaire-registration'));
            case 'taille':
                return new WP_Error('psc_docx_taille', __('Le fichier est vide ou dépasse 10 Mo.', 'periscolaire-registration'));
        }
        return new WP_Error('psc_docx_format', __('Ce fichier n’est pas un document Word (.docx).', 'periscolaire-registration'));
    }
}
