<?php
/**
 * Calendrier scolaire : vacances, jours d'école, semaines de service.
 * Dépend des données importées (wp_psc_school_calendar).
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

function psc_is_school_vacation($date_str) {
    return Psc_School_Calendar::is_closed($date_str);
}

/**
 * Vacances scolaires de la zone C (Créteil, Montpellier, Paris, Toulouse,
 * Versailles) — chargées par la mairie depuis le calendrier officiel du
 * ministère de l'Éducation nationale (Périscolaire > Calendrier scolaire).
 * Voir Psc_School_Calendar.
 */
function psc_school_vacation_label($date_str) {
    return Psc_School_Calendar::label($date_str);
}

/**
 * Un jour est-il un jour d'école (donc de service périscolaire/cantine
 * potentiel) ? Ne dépend d'aucune configuration en base (ex : widget menu public).
 */
function psc_is_school_day($date_str) {
    if (psc_is_weekend($date_str) || psc_is_wednesday($date_str)) return false;
    if (psc_is_school_vacation($date_str)) return false;
    if (psc_is_holiday($date_str)) return false;
    return true;
}

/**
 * Décalage en jours depuis le lundi pour chacun des 4 jours de service
 * (lundi/mardi/jeudi/vendredi) — le mercredi n'est jamais un jour de
 * service périscolaire/cantine. Partagé par les menus de cantine et les
 * commandes fournisseur.
 */
function psc_service_jour_offsets() {
    return array('lundi' => 0, 'mardi' => 1, 'jeudi' => 3, 'vendredi' => 4);
}

/**
 * Jours scolaires ouverts (parmi lundi/mardi/jeudi/vendredi) de la
 * semaine donnée, sous la forme [jour => date Y-m-d]. Un jour fermé
 * (vacances, jour férié, fermeture ponctuelle) n'a pas de service
 * périscolaire/cantine ce jour-là, donc rien à saisir ni à commander.
 */
function psc_open_days($monday) {
    $monday = psc_week_start($monday);
    if (!$monday) return array();
    $open = array();
    foreach (psc_service_jour_offsets() as $jour => $offset) {
        $date = gmdate('Y-m-d', strtotime($monday . " +{$offset} days"));
        if (psc_is_school_day($date)) $open[$jour] = $date;
    }
    return $open;
}

/**
 * Premier lundi (à partir de $from_date) dont la semaine contient au
 * moins un jour scolaire ouvert — évite de proposer par défaut une
 * semaine entièrement fermée (vacances, pont...).
 */
function psc_next_open_week($from_date) {
    $monday = psc_week_start($from_date);
    if (!$monday) return false;
    for ($i = 0; $i < 26; $i++) {
        if (!empty(psc_open_days($monday))) return $monday;
        $monday = gmdate('Y-m-d', strtotime($monday . ' +7 days'));
    }
    return $monday; // garde-fou : calendrier scolaire mal configuré / jamais chargé
}

/**
 * Année de rentrée en cours (ex : 2026 du 1er septembre 2026 au 31 août
 * 2027). Sert de repère pour dater les documents (assurance, dossiers)
 * quand aucune année scolaire n'est explicitement fournie.
 */
function psc_rentree_year($timestamp = null) {
    $ts = $timestamp ?: current_time('timestamp');
    $month = (int) date('n', $ts);
    $year  = (int) date('Y', $ts);
    return $month >= 9 ? $year : $year - 1;
}
