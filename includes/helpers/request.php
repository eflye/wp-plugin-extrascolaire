<?php
/**
 * Lecture et validation des entrées d'une requête HTTP.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Récupère et nettoie une valeur de $_POST.
 * wp_unslash() est indispensable : WordPress applique addslashes() sur les
 * superglobales, sans quoi "O'Brien" est enregistré "O\'Brien".
 */
function psc_post($key, $default = '') {
    if (!isset($_POST[$key])) return $default;
    return sanitize_text_field(wp_unslash($_POST[$key]));
}

function psc_get_int($key, $default = 0) {
    return isset($_GET[$key]) ? absint($_GET[$key]) : $default;
}

function psc_post_int($key, $default = 0) {
    return isset($_POST[$key]) ? absint($_POST[$key]) : $default;
}

/**
 * Valide strictement une date au format Y-m-d.
 * Empêche à la fois les injections et les erreurs fatales de DateTime.
 */
function psc_valid_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return false;
    }
    $year = (int) substr($date, 0, 4);
    if ($year < 2000 || $year > 2100) {
        return false;
    }
    return $date;
}

/**
 * Valide un numéro de téléphone : format volontairement large (aucun
 * numéro de téléphone existant dans le plugin — mobile, fixe — n'est
 * autrement validé), pour ne pas rejeter un numéro fixe étranger ou une
 * saisie avec indicatif. Accepte chiffres, espaces, points, tirets et
 * parenthèses ; exige 6 à 15 chiffres significatifs, + initial optionnel.
 * Renvoie le numéro normalisé (espaces/ponctuation retirés) ou false.
 * N'est jamais appelée sur une valeur vide : le champ reste facultatif,
 * c'est à l'appelant de ne valider que si une valeur a été saisie.
 */
function psc_valid_phone($phone) {
    $digits = preg_replace('/[\s.\-()]/', '', (string) $phone);
    return preg_match('/^\+?\d{6,15}$/', $digits) ? $digits : false;
}
