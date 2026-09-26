<?php
/**
 * Table de décision des contrats d'une journée (P3-01) — données seules,
 * partagées par tests/unit/run.php et bin/verify-channel-contracts.php.
 * Documentée dans docs/contrats-planning.md : toute ligne ajoutée ici
 * s'y reporte.
 *
 * Chaque ligne part de la carte de déclarations EFFECTIVES d'une journée
 * (après résolution : le forfait a rendu vrais les créneaux qu'il couvre,
 * le drapeau « cantine sans repas » a converti la cantine en MSR) :
 *
 *  - jour      : {code => bool} ;
 *  - sans_repas: enfant flagué « cantine sans repas » par la mairie ;
 *  - allergie  : allergie alimentaire déclarée (repas fourni par la famille) ;
 *  - presence  : psc_day_slots() attendu ;
 *  - repas     : psc_day_meal() attendu ;
 *  - complete  : forfait candidat de la journée complète (FORF, FSR) ou null ;
 *  - unites    : créneaux facturés au tarif unitaire hors journée complète.
 *
 * Facturation attendue : [complete] si son tarif ne dépasse pas la somme
 * des unités, sinon unites (psc_billing_services, règle 2).
 */

return array(
    'forfait déclaré, journée complète' => array(
        'jour' => array('FORF' => true, 'GM' => true, 'CANT' => true, 'GS' => true, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'CANT', 'GS'), 'repas' => true,
        'complete' => 'FORF', 'unites' => array('GM', 'CANT', 'GS'),
    ),
    'trois créneaux cochés séparément' => array(
        'jour' => array('FORF' => false, 'GM' => true, 'CANT' => true, 'GS' => true, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'CANT', 'GS'), 'repas' => true,
        'complete' => 'FORF', 'unites' => array('GM', 'CANT', 'GS'),
    ),
    'forfait, cantine retirée (famille ou annulation de classe)' => array(
        'jour' => array('FORF' => true, 'GM' => true, 'CANT' => false, 'GS' => true, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'GS'), 'repas' => false,
        'complete' => null, 'unites' => array('GM', 'GS'),
    ),
    'forfait, garderie du soir fermée par la mairie' => array(
        'jour' => array('FORF' => false, 'GM' => true, 'CANT' => true, 'GS' => false, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'CANT'), 'repas' => true,
        'complete' => null, 'unites' => array('GM', 'CANT'),
    ),
    'cantine seule' => array(
        'jour' => array('FORF' => false, 'GM' => false, 'CANT' => true, 'GS' => false, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('CANT'), 'repas' => true,
        'complete' => null, 'unites' => array('CANT'),
    ),
    'cantine seule, allergie alimentaire' => array(
        'jour' => array('FORF' => false, 'GM' => false, 'CANT' => true, 'GS' => false, 'MSR' => false),
        'sans_repas' => false, 'allergie' => true,
        'presence' => array('CANT'), 'repas' => false,
        'complete' => null, 'unites' => array('CANT'),
    ),
    'midi sans repas seul' => array(
        'jour' => array('FORF' => false, 'GM' => false, 'CANT' => false, 'GS' => false, 'MSR' => true),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('MSR'), 'repas' => false,
        'complete' => null, 'unites' => array('MSR'),
    ),
    'journée complète, midi sans repas déclaré' => array(
        'jour' => array('FORF' => false, 'GM' => true, 'CANT' => false, 'GS' => true, 'MSR' => true),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'MSR', 'GS'), 'repas' => false,
        'complete' => 'FSR', 'unites' => array('GM', 'MSR', 'GS'),
    ),
    'enfant « cantine sans repas » au forfait' => array(
        'jour' => array('FORF' => true, 'GM' => true, 'CANT' => false, 'GS' => true, 'MSR' => true),
        'sans_repas' => true, 'allergie' => false,
        'presence' => array('GM', 'MSR', 'GS'), 'repas' => false,
        'complete' => 'FSR', 'unites' => array('GM', 'MSR', 'GS'),
    ),
    'garderies seules' => array(
        'jour' => array('FORF' => false, 'GM' => true, 'CANT' => false, 'GS' => true, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array('GM', 'GS'), 'repas' => false,
        'complete' => null, 'unites' => array('GM', 'GS'),
    ),
    'rien de déclaré' => array(
        'jour' => array('FORF' => false, 'GM' => false, 'CANT' => false, 'GS' => false, 'MSR' => false),
        'sans_repas' => false, 'allergie' => false,
        'presence' => array(), 'repas' => false,
        'complete' => null, 'unites' => array(),
    ),
);
