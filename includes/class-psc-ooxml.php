<?php
if (!defined('ABSPATH')) exit;

/**
 * Ouverture prudente d'un fichier Office Open XML (.xlsx, .docx) déposé
 * par la mairie : une archive ZIP de parties XML, lue sans dépendance
 * (ZipArchive + DOM) et sans jamais rien extraire sur le disque.
 *
 * Partagé par Psc_Menu_Xlsx (classeur de choix des menus du fournisseur)
 * et Psc_Menu_Docx (note de changement de menus). Les codes d'erreur sont
 * génériques (psc_ooxml_*) : chaque lecteur les traduit dans les siens,
 * avec un message qui nomme le fichier attendu.
 */
class Psc_Ooxml {

    /** Taille maximale du fichier déposé. */
    const MAX_FILE_SIZE = 10485760; // 10 Mo

    /** Taille maximale (décompressée) d'une partie XML lue : garde-fou contre une archive piégée. */
    const MAX_XML_SIZE = 8388608; // 8 Mo

    /**
     * Ouvre l'archive après les contrôles de base (taille, signature ZIP).
     *
     * @return ZipArchive|WP_Error psc_ooxml_extension, psc_ooxml_illisible, psc_ooxml_taille ou psc_ooxml_format.
     */
    public static function open($path) {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return new WP_Error('psc_ooxml_extension', 'Extensions PHP Zip et DOM requises');
        }
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return new WP_Error('psc_ooxml_illisible', 'Fichier illisible');
        }
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            return new WP_Error('psc_ooxml_taille', 'Fichier vide ou trop volumineux');
        }
        if ((string) file_get_contents($path, false, null, 0, 4) !== "PK\x03\x04") {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        return $zip;
    }

    /**
     * Contenu d'une partie de l'archive, bornée en taille décompressée.
     *
     * @return string|WP_Error psc_ooxml_format ou psc_ooxml_taille.
     */
    public static function part(ZipArchive $zip, $name) {
        $stat = $zip->statName($name);
        if (!$stat) {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        if ((int) $stat['size'] > self::MAX_XML_SIZE) {
            return new WP_Error('psc_ooxml_taille', 'Fichier vide ou trop volumineux');
        }
        $xml = $zip->getFromName($name);
        if (!is_string($xml) || $xml === '') {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        return $xml;
    }

    /**
     * Charge une partie XML. Une déclaration DOCTYPE n'a rien à faire dans
     * un document Office : refusée d'office (entités externes, expansion).
     *
     * @return DOMDocument|WP_Error psc_ooxml_format.
     */
    public static function load($xml) {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok || !$doc->documentElement) {
            return new WP_Error('psc_ooxml_format', 'Format inattendu');
        }
        return $doc;
    }

    /** Suffixe du code d'une erreur de cette classe : 'extension', 'illisible', 'taille' ou 'format'. */
    public static function reason($error) {
        return is_wp_error($error) ? (string) str_replace('psc_ooxml_', '', (string) $error->get_error_code()) : '';
    }
}
