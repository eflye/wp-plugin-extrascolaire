<?php
/**
 * Niveaux de classe et progression d'une année sur l'autre.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Liste ordonnée des niveaux scolaires pour les menus déroulants.
 * Clé = valeur stockée en base, valeur = libellé affiché.
 */
function psc_classe_options() {
    return array(
        ''   => '— Classe —',
        'PS' => 'Petite Section (PS)',
        'MS' => 'Moyenne Section (MS)',
        'GS' => 'Grande Section (GS)',
        'CP' => 'CP',
        'CE1'=> 'CE1',
        'CE2'=> 'CE2',
        'CM1'=> 'CM1',
        'CM2'=> 'CM2',
    );
}

/**
 * Classe attendue pour un enfant né le $date_naissance, à la rentrée
 * $rentree_year (âge au 31 décembre de cette année civile — règle
 * officielle française). Sert uniquement à initialiser la classe d'un
 * enfant qui n'en a pas encore : jamais utilisée pour recorriger une
 * classe déjà définie (cf. Psc_School_Years::build_promotion_plan()).
 */
function psc_classe_for_birthdate($date_naissance, $rentree_year) {
    if (!$date_naissance) return '';
    $age = $rentree_year - (int) date('Y', strtotime($date_naissance));
    $map = array(3 => 'PS', 4 => 'MS', 5 => 'GS', 6 => 'CP', 7 => 'CE1', 8 => 'CE2', 9 => 'CM1', 10 => 'CM2');
    return $map[$age] ?? '';
}

/**
 * Table de correspondance classe -> classe suivante (ou 'sortie'),
 * éditable depuis Périscolaire > Réglages : une école à classes
 * multi-niveaux peut avoir une progression différente du simple
 * PS→MS→GS→CP→CE1→CE2→CM1→CM2. Valeur par défaut = cette progression
 * standard, CM2 menant à la sortie.
 */
function psc_classe_progression_defaut() {
    return array(
        'PS'  => 'MS',
        'MS'  => 'GS',
        'GS'  => 'CP',
        'CP'  => 'CE1',
        'CE1' => 'CE2',
        'CE2' => 'CM1',
        'CM1' => 'CM2',
        'CM2' => 'sortie',
    );
}

function psc_classe_progression() {
    $saved = get_option('psc_classe_progression', array());
    $defaut = psc_classe_progression_defaut();
    if (!is_array($saved) || empty($saved)) return $defaut;

    $progression = array();
    foreach (array_keys(psc_classe_options()) as $code) {
        if ($code === '') continue;
        $progression[$code] = isset($saved[$code]) ? $saved[$code] : ($defaut[$code] ?? 'sortie');
    }
    return $progression;
}

/**
 * Classe suivante pour $classe selon la table de correspondance
 * configurée (Réglages). Renvoie 'sortie' en fin de cycle, ou null si
 * $classe n'est pas une classe reconnue.
 */
function psc_classe_superieure($classe) {
    $progression = psc_classe_progression();
    return $progression[$classe] ?? null;
}
