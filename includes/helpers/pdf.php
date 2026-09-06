<?php
/**
 * Géométrie d'insertion d'images dans les PDF (FPDF).
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Dimensions DESSINÉES d'un logo : il tient dans la boîte max_w × max_h
 * en conservant strictement ses proportions.
 *
 * FPDF placé avec une largeur seule ($h = 0) dessine à hauteur
 * proportionnelle : un logo portrait (blason, armoiries) dépasse alors
 * largement la hauteur d'un en-tête et recouvre le bloc d'adresse de la
 * famille. Le ratio vient des pixels réels de l'image (getimagesize) —
 * seule leur PROPORTION compte, jamais leur définition.
 *
 * @param string $w_px  Largeur en pixels de l'image source.
 * @param string $h_px  Hauteur en pixels de l'image source.
 * @param float  $max_w Largeur maximale de la boîte, en mm.
 * @param float  $max_h Hauteur maximale de la boîte, en mm.
 * @return array{0:float,1:float} [largeur, hauteur] en mm.
 */
function psc_logo_fit_dimensions($w_px, $h_px, $max_w, $max_h) {
    $w_px = (float) $w_px;
    $h_px = (float) $h_px;
    if ($w_px <= 0 || $h_px <= 0) {
        // Image indéterminable : la boîte entière — mieux vaut un logo
        // carré légèrement déformé qu'un débordement imprévisible.
        return array((float) $max_w, (float) $max_h);
    }
    $h = min($max_h, $max_w * $h_px / $w_px);
    $w = $h * $w_px / $h_px;
    return array(round($w, 2), round($h, 2));
}
