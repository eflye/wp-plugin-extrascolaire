<?php
/**
 * Dates et jours fériés français. Aucune connaissance de l'école : ce qui
 * dépend du calendrier scolaire vit dans helpers/school-calendar.php.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Lundi de la semaine contenant $date (une "semaine" de menu de cantine
 * commence toujours un lundi, quelle que soit la date saisie par l'admin).
 */
function psc_week_start($date) {
    $date = psc_valid_date($date);
    if (!$date) return false;
    $d = new DateTime($date);
    $dow = (int) $d->format('N'); // 1 (lundi) .. 7 (dimanche)
    if ($dow > 1) {
        $d->modify('-' . ($dow - 1) . ' days');
    }
    return $d->format('Y-m-d');
}

function psc_is_weekend($date_str) {
    $dow = (int) date('N', strtotime($date_str)); // 1 (lundi) .. 7 (dimanche)
    return $dow >= 6;
}

/**
 * Le mercredi n'est pas un jour de service (pas de périscolaire ni de cantine).
 */
function psc_is_wednesday($date_str) {
    return (int) date('N', strtotime($date_str)) === 3;
}

function psc_day_label($date_str) {
    $jours = array(
        __('Lundi', 'periscolaire-registration'), __('Mardi', 'periscolaire-registration'), __('Mercredi', 'periscolaire-registration'), __('Jeudi', 'periscolaire-registration'),
        __('Vendredi', 'periscolaire-registration'), __('Samedi', 'periscolaire-registration'), __('Dimanche', 'periscolaire-registration')
    );
    $dow = (int) date('N', strtotime($date_str));
    return isset($jours[$dow - 1]) ? $jours[$dow - 1] : '';
}

/**
 * Libellé compact d'un jour de planning, maquette Family Portal v3 :
 * « Mar. 08/09 ». Abréviation figurent dans la grille des exceptions —
 * la largeur min-content du tableau en dépend.
 */
function psc_day_short($date_str) {
    $abrs = array(
        __('Lun.', 'periscolaire-registration'), __('Mar.', 'periscolaire-registration'), __('Mer.', 'periscolaire-registration'), __('Jeu.', 'periscolaire-registration'),
        __('Ven.', 'periscolaire-registration'), __('Sam.', 'periscolaire-registration'), __('Dim.', 'periscolaire-registration')
    );
    $dow = (int) date('N', strtotime($date_str));
    $abr = isset($abrs[$dow - 1]) ? $abrs[$dow - 1] : '';
    return trim($abr . ' ' . date_i18n('d/m', strtotime($date_str)));
}

/**
 * Date de Pâques (algorithme de Gauss/Meeus), sans dépendance à l'extension calendar.
 */
function psc_easter_date($year) {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/**
 * Liste des jours fériés français (métropole) pour une année donnée, format Y-m-d.
 */
function psc_french_holidays($year) {
    $year = (int) $year;
    $easter = psc_easter_date($year);
    $holidays = array();
    $holidays[] = "$year-01-01";
    $holidays[] = (clone $easter)->modify('+1 day')->format('Y-m-d');   // Lundi de Paques
    $holidays[] = "$year-05-01";
    $holidays[] = "$year-05-08";
    $holidays[] = (clone $easter)->modify('+39 days')->format('Y-m-d'); // Ascension
    $holidays[] = (clone $easter)->modify('+50 days')->format('Y-m-d'); // Lundi de Pentecote
    $holidays[] = "$year-07-14";
    $holidays[] = "$year-08-15";
    $holidays[] = "$year-11-01";
    $holidays[] = "$year-11-11";
    $holidays[] = "$year-12-25";
    return $holidays;
}

function psc_is_holiday($date_str) {
    $year = (int) substr($date_str, 0, 4);
    return in_array($date_str, psc_french_holidays($year), true);
}
