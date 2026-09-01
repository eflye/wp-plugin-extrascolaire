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
 * Valide un numéro de téléphone FRANÇAIS : mobile ou fixe, avec
 * séparateurs (espaces, points, tirets, parenthèses) ou indicatif
 * (+33 / 00 33). Renvoie le numéro normalisé (chiffres seuls) ou false.
 * N'est jamais appelée sur une valeur vide : le champ reste facultatif,
 * c'est à l'appelant de ne valider que si une valeur a été saisie.
 */
function psc_valid_phone($phone) {
    $digits = preg_replace('/[\s.\-()]/', '', (string) $phone);
    return preg_match('/^(?:(?:\+|00)33|0)[1-9]\d{8}$/', $digits) ? $digits : false;
}

/**
 * Valide un code postal français (5 chiffres) : métropole, DOM-TOM et
 * Monaco (980xx). Booléen, à la différence des validateurs qui
 * renvoient une valeur : il n'y a rien à normaliser.
 * N'est jamais appelée sur une valeur vide : même convention que
 * psc_valid_phone().
 */
function psc_valid_postcode($cp) {
    return (bool) preg_match('/^\d{5}$/', (string) $cp);
}

/**
 * Motif natif HTML5 (attribut pattern) des téléphones français, cohérent
 * avec psc_valid_phone() : séparateurs courants admis, indicatif +33.
 * Ne s'applique qu'aux champs non vides — sans effet sur un champ
 * facultatif laissé vide.
 */
function psc_tel_pattern() {
    return '(?:\+33|0)[1-9](?:[ .-]?[0-9]{2}){4}';
}

/**
 * Coupure d'âge enfant (attribut max des input type="date" côté client) :
 * le 1er septembre de l'année en cours moins 3 ans — même règle que
 * psc_valid_child_birthdate(), cf. son docblock pour la logique d'été.
 */
function psc_child_birthdate_max() {
    return (new DateTime(date('Y') . '-09-01'))->modify('-3 years')->format('Y-m-d');
}

/**
 * Analyse strictement une date Y-m-d (aller-retour DateTime) sans le
 * plancher d'année de psc_valid_date() : une date de naissance légitime
 * précède souvent 2000 (un parent peut avoir 45 ans).
 */
function psc_parse_birthdate($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return false;
    }
    return $d;
}

/**
 * Naissance d'un ENFANT : jamais dans le futur, et au moins 3 ans au
 * 1er septembre de l'année en cours — c'est la date de référence des
 * inscriptions, y compris pendant l'été : une famille qui s'inscrit en
 * juillet pour la rentrée doit pouvoir déclarer un enfant qui fêtera
 * ses 3 ans fin août. Renvoie la date Y-m-d ou false.
 */
function psc_valid_child_birthdate($date) {
    $d = psc_parse_birthdate($date);
    if (!$d) return false;
    $cutoff = new DateTime(date('Y') . '-09-01');
    $cutoff->modify('-3 years');
    return $d->format('Y-m-d') <= $cutoff->format('Y-m-d') ? $d->format('Y-m-d') : false;
}

/**
 * Naissance d'un ADULTE (parent, second parent, personne autorisée) :
 * jamais dans le futur, au moins 18 ans aujourd'hui. Non câblée à ce
 * jour (aucun champ naissance adulte dans le plugin) — prête pour le
 * jour où un tel champ apparaît. Renvoie la date Y-m-d ou false.
 */
function psc_valid_adult_birthdate($date) {
    $d = psc_parse_birthdate($date);
    if (!$d) return false;
    $cutoff = new DateTime(date('Y-m-d'));
    $cutoff->modify('-18 years');
    return $d->format('Y-m-d') <= $cutoff->format('Y-m-d') ? $d->format('Y-m-d') : false;
}
