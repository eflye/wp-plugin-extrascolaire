<?php
/**
 * Modèle de déclaration « rythme + exceptions » : règles de résolution pures.
 *
 * Le rythme habituel (psc_pattern) dit ce que l'enfant fait chaque lundi,
 * mardi, jeudi et vendredi de l'année scolaire. Les exceptions (psc_exception)
 * portent les écarts ponctuels : ajout exceptionnel (value = 1) ou retrait
 * exceptionnel (value = 0). La résolution d'un triplet (enfant, date,
 * prestation) suit trois règles, dans cet ordre :
 *
 *  1. hors jour d'école (vacances, férié, mercredi, week-end, fermeture) :
 *     toujours false — un jour fermé n'est jamais déclaré, quelle que soit
 *     la donnée stockée ;
 *  2. une exception sur le triplet gagne, quelle que soit sa valeur : c'est
 *     elle qui décide ;
 *  3. sinon le pattern du jour de semaine ; à défaut, la couverture par le
 *     forfait journée (FORF), qui recouvre exactement les prestations
 *     élémentaires — un enfant au forfait est attendu matin, cantine et soir.
 *
 * Cas du créneau du midi : la cantine (CANT) et « Midi sans repas » (MSR)
 * s'excluent. Quand les deux sont actives au même endroit (pattern ou
 * exception d'ajout), l'exception explicite gagne sur le pattern de
 * l'autre ; à défaut, la cantine l'emporte sur MSR (service historique).
 * Un MSR actif masque le rythme ET le repli forfait de la cantine — le
 * forfait ne couvre pas MSR, et un enfant « midi sans repas » ne déjeune
 * pas à la cantine même si son rythme le dit. Inversement, une cantine
 * active masque le pattern MSR. MSR n'a JAMAIS de repli forfait : déclarer
 * le forfait ne rend pas l'enfant « midi sans repas ».
 *
 * Tout le reste du plugin passe par psc_is_declared() : facturation, listes
 * intervenants, effectifs cantine, exports mairie. Aucun code ne lit
 * psc_pattern ou psc_exception directement (hormis l'écran de bascule vers
 * l'autre variante et la migration, qui alimentent ces mêmes tables).
 *
 * Les deux fonctions ci-dessous sont PURES : pas de WordPress, pas de base.
 * Elles sont couvertes par tests/unit/run.php.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Résout l'état déclaré d'un triplet (enfant, date, prestation).
 *
 * @param bool $is_forfait     Résout-on le forfait lui-même (FORF) ?
 * @param bool $pattern        Pattern du (jour de semaine, prestation) — les
 *                             lignes de pattern ne portent que du « vrai » :
 *                             l'absence de ligne vaut false.
 * @param bool|null $exception Exception du (enfant, date, prestation) —
 *                             null = pas d'exception, sinon sa valeur.
 * @param bool $forf_pattern   Pattern FORF du jour de semaine.
 * @param bool|null $forf_exception Exception FORF de la date.
 * @param bool $day_open       Jour d'école (vacances, férié, mercredi,
 *                             week-end et fermeture manuelle exclus).
 * @param bool $service_open   La prestation demandée est ouverte ce jour-là.
 * @param bool $forf_open      Toutes les prestations élémentaires sont
 *                             ouvertes ce jour-là (condition de réalisabilité
 *                             du forfait, qui est indivisible).
 * @param array $slot          Données du créneau du midi, pour l'arbitrage
 *                             CANT/MSR : 'request' (code résolu), et pour
 *                             l'autre service du créneau 'cant_pattern',
 *                             'cant_exception', 'msr_pattern',
 *                             'msr_exception'. Vide hors créneau du midi.
 * @return bool
 */
function psc_resolve_declaration($is_forfait, $pattern, $exception, $forf_pattern, $forf_exception, $day_open, $service_open = true, $forf_open = true, array $slot = array()) {
    if (!$day_open) return false;

    $forf_effectif = $forf_exception !== null ? (bool) $forf_exception : (bool) $forf_pattern;

    if ($is_forfait) {
        // Le forfait n'est déclaré que réalisable : si une de ses
        // composantes est fermée, il ne l'est pas ; les composantes encore
        // ouvertes restent déclarées par le repli ci-dessous et facturées
        // au tarif unitaire (psc_billing_services).
        return $forf_effectif && $forf_open;
    }

    // Une prestation fermée ce jour-là n'est jamais déclarée, quelle que
    // soit la donnée stockée (l'ancien modèle supprimait les lignes ; ici
    // la fermeture est soustraite au calcul).
    if (!$service_open) return false;

    // Arbitrage du créneau du midi : cantine contre « midi sans repas ».
    // L'exception de CE triplet reste maîtresse (règle 2) ; sinon l'activité
    // effective de l'autre service du créneau masque le rythme et le repli.
    $request = isset($slot['request']) ? (string) $slot['request'] : '';
    if ($request === 'CANT' || $request === 'MSR') {
        if ($exception !== null) {
            return (bool) $exception;
        }
        $cant_exc = array_key_exists('cant_exception', $slot) && $slot['cant_exception'] !== null
            ? (bool) $slot['cant_exception'] : null;
        $msr_exc  = array_key_exists('msr_exception', $slot) && $slot['msr_exception'] !== null
            ? (bool) $slot['msr_exception'] : null;
        $cant_active = $cant_exc !== null ? $cant_exc : !empty($slot['cant_pattern']);
        $msr_active  = $msr_exc !== null ? $msr_exc : !empty($slot['msr_pattern']);

        if ($request === 'CANT') {
            if ($msr_active) return false;
            return $pattern ? true : $forf_effectif;
        }
        // MSR : l'enfant est là sans repas. Jamais de repli forfait — le
        // forfait couvre le déjeuner à la cantine, pas l'inverse. SEULE
        // exception : l'enfant flagué « cantine sans repas » par la mairie
        // (slot 'msr_forf_repli') — son forfait devient un forfait SANS
        // repas (facturé FSR), son créneau du midi est une présence sans
        // repas décrite par MSR, pas par la cantine.
        if ($cant_active) return false;
        if (!empty($slot['msr_forf_repli'])) {
            return $pattern ? true : $forf_effectif;
        }
        return (bool) $pattern;
    }

    // L'exception de CE triplet gagne, quelle que soit sa valeur : un retrait
    // exceptionnel reste vrai même si le forfait couvre la prestation.
    if ($exception !== null) {
        return (bool) $exception;
    }

    if ($pattern) return true;

    // Sans pattern propre, la prestation peut être couverte par le forfait :
    // un enfant au forfait est attendu matin, cantine et soir (et la
    // prestation doit rester ouverte ce jour-là).
    return $forf_effectif;
}

/**
 * Décision d'écriture d'une exception, appliquant l'invariant :
 * JAMAIS d'exception dont la valeur égale l'état du rythme.
 *
 * Un parent qui coche puis décoche un jour doit provoquer la SUPPRESSION de
 * la ligne, pas sa mise à jour — sinon la table se remplit de bruit et un
 * futur changement de rythme ne se propage plus à ce jour.
 *
 * La base de comparaison est l'état qui prévaudrait SANS l'exception : le
 * pattern de la prestation, ou la couverture par le forfait si la prestation
 * n'a pas de pattern propre. Sur le créneau du midi, l'autre service (CANT
 * ou MSR) actif au rythme masque la base — un enfant « midi sans repas »
 * n'a pas de cantine à décocher.
 *
 * @param bool $is_forfait   Écrit-on l'exception du forfait lui-même ?
 * @param bool $pattern      Pattern du (jour de semaine, prestation).
 * @param bool $forf_pattern Pattern FORF du jour de semaine.
 * @param bool $target       État visé par le clic.
 * @param array $slot        Créneau du midi : 'request' (code visé),
 *                           'cant_pattern', 'msr_pattern' — mêmes clés que
 *                           psc_resolve_declaration(), patterns seulement.
 * @param bool|null $forf_exception Exception FORF de la date, si présente.
 * @return string 'delete' (retirer toute exception du triplet) | 'upsert' (poser/actualiser avec $target)
 */
function psc_exception_write_decision($is_forfait, $pattern, $forf_pattern, $target, $slot = array(), $forf_exception = null) {
    // Une unité se compare à la couverture réelle du forfait pour cette date.
    // Le forfait lui-même reste comparé à son rythme pour permettre le retour au rythme.
    if (!$is_forfait && $forf_exception !== null) $forf_pattern = (bool) $forf_exception;
    $request = isset($slot['request']) ? (string) $slot['request'] : '';
    if ($request === 'MSR') {
        $base = !empty($slot['cant_pattern']) ? false : (bool) $pattern;
    } elseif ($request === 'CANT') {
        $base = !empty($slot['msr_pattern']) ? false : ((bool) $pattern ? true : (bool) $forf_pattern);
    } else {
        $base = $is_forfait
            ? (bool) $forf_pattern
            : ((bool) $pattern ? true : (bool) $forf_pattern);
    }

    return ((bool) $target === $base) ? 'delete' : 'upsert';
}

/**
 * Enfant flagué « cantine sans repas » (colonne children.cantine_sans_repas,
 * posée par la mairie sur la fiche enfant) : chacune de ses déclarations de
 * cantine vaut « midi sans repas » — facturation au tarif MSR, aucun repas
 * compté côté fournisseur, présent sur le créneau côté intervenants. La
 * conversion s'applique à la RÉSOLUTION (is_declared / declared_map) :
 * facturation, comptages et listes la voient ; les écrans de saisie des
 * familles (month_state, month_explicit_map) restent fidèles à ce que la
 * famille a déclaré — le flag est une décision de la mairie, pas une
 * écriture dans ses données.
 *
 * Les données CANT alimentent MSR (pattern OU exception d'ajout — une
 * exception de retrait fige l'absence), les données CANT elles-mêmes sont
 * neutralisées, le forfait et les garderies ne changent pas : un enfant
 * au forfait reste déclaré au forfait, son midi y est compris. La
 * tarification applique ensuite FSR lorsque le flag mairie est actif.
 *
 * @param array $pats {service_code => bool} patterns du jour.
 * @param array $exc  {service_code => bool|null} exceptions de la date.
 * @return array [$pats, $exc] convertis.
 */
function psc_cantine_sans_repas_convert(array $pats, array $exc) {
    $msr = psc_midi_sans_repas_code();
    $cant_exc = array_key_exists('CANT', $exc) ? $exc['CANT'] : null;
    $msr_exc  = array_key_exists($msr, $exc) ? $exc[$msr] : null;

    // Une exception d'ajout (true) gagne sur un retrait (false), qui gagne
    // sur l'absence d'exception (null) — même précédence que l'arbitrage
    // du créneau dans psc_resolve_declaration().
    if ($cant_exc === true || $msr_exc === true) {
        $merged_exc = true;
    } elseif ($cant_exc === false || $msr_exc === false) {
        $merged_exc = false;
    } else {
        $merged_exc = null;
    }

    $pats[$msr]  = !empty($pats[$msr]) || !empty($pats['CANT']);
    // Clé POSÉE seulement pour une exception explicite : un null (pas
    // d'exception) doit rester une ABSENCE de clé, sinon le site d'appel
    // la transformerait en retrait (bool) null = false et tuerait le repli
    // forfait du midi sans repas. CANT, elle, est explicitement false :
    // sa neutralisation ne doit jamais laisser le repli forfait redéclarer
    // un repas de cantine.
    if ($merged_exc !== null) {
        $exc[$msr] = $merged_exc;
    } else {
        unset($exc[$msr]);
    }
    $pats['CANT'] = false;
    $exc['CANT']  = false;

    return array($pats, $exc);
}

/**
 * SOURCE DE VÉRITÉ UNIQUE — l'état déclaré d'un triplet (enfant, date,
 * prestation). Facturation, listes intervenants, effectifs cantine, exports
 * mairie : tout passe par ici. Aucun code ne lit psc_pattern ou
 * psc_exception directement.
 *
 *  1. exception sur ce triplet ? elle gagne, quelle que soit sa valeur ;
 *  2. sinon : le pattern du jour de la semaine (à défaut, le forfait) ;
 *  3. hors jour d'école / vacances / férié : toujours false.
 */
function psc_is_declared($child_id, $date, $service_code) {
    return Psc_Planning::is_declared($child_id, $date, $service_code);
}

/*
 * CONTRATS D'UNE JOURNÉE (P3-01). Une journée d'un enfant se lit à travers
 * sa carte de déclarations effectives {code => bool} (declared_map), où le
 * forfait déclaré a déjà rendu vrais les créneaux qu'il couvre. Quatre
 * lectures en sont tirées, chacune par UNE fonction :
 *
 *  - prestation déclarée : la carte elle-même (psc_is_declared) ;
 *  - présence            : psc_day_slots() — listes des intervenants,
 *                          pointage, avis de fermeture ;
 *  - repas fourni        : psc_day_meal() — commande au fournisseur ;
 *  - prestation facturée : psc_billing_services() — factures, estimations
 *                          du portail, export CSV, effectifs du calendrier.
 *
 * Table de décision : docs/contrats-planning.md.
 */

/**
 * Présence : créneaux où l'enfant est attendu, dans l'ordre de la journée —
 * garderie du matin, midi (cantine, ou midi sans repas) et garderie du soir.
 * Le forfait n'est jamais un créneau : il se lit à travers ceux qu'il couvre,
 * et une journée au forfait ne s'annonce pas deux fois.
 *
 * @param array $day {code => bool} de la journée.
 * @return string[] Codes parmi GM, CANT, MSR, GS.
 */
function psc_day_slots(array $day) {
    $msr = psc_midi_sans_repas_code();
    $out = array();
    if (!empty($day['GM'])) $out[] = 'GM';
    if (!empty($day['CANT'])) {
        $out[] = 'CANT';
    } elseif (!empty($day[$msr])) {
        $out[] = $msr;
    }
    if (!empty($day['GS'])) $out[] = 'GS';
    return $out;
}

/**
 * Repas fourni : un repas est commandé au fournisseur quand l'enfant
 * déjeune à la cantine. Le midi sans repas n'en a pas (la conversion
 * « cantine sans repas » l'a déjà écrit en MSR), ni l'enfant allergique,
 * dont la famille fournit le repas — il reste attendu et facturé.
 *
 * @param array $day          {code => bool} de la journée.
 * @param bool  $food_allergy Allergie alimentaire déclarée.
 * @return bool
 */
function psc_day_meal(array $day, $food_allergy = false) {
    return !empty($day['CANT']) && !$food_allergy;
}

/**
 * Numéro de la règle de facturation en vigueur. Il est inscrit dans
 * l'instantané de chaque facture (champ « calcul ») : une facture déjà
 * envoyée est toujours recalculée avec la règle sous laquelle elle a été
 * émise, jamais avec une règle adoptée depuis (cf. Psc_Invoices).
 *
 *  1 — jusqu'à 5.28 : forfait facturé dès qu'il est déclaré et réalisable,
 *      unités en sus quand la cantine était retirée (cumul) ;
 *  2 — P1-15 (26/09/2026) : une journée complète au prix du forfait, sinon
 *      les créneaux consommés au tarif unitaire.
 */
function psc_billing_rule_version() {
    return 2;
}

/**
 * Prestations à FACTURER pour un (enfant, date), d'après sa carte de
 * déclarations effectives (psc_is_declared / declared_map). Règle unique,
 * partagée par factures, estimations du portail, récapitulatifs, export
 * CSV et comptages (P1-15, règle 2) :
 *
 *  - une journée est COMPLÈTE quand ses trois créneaux sont consommés :
 *    garderie du matin, midi (cantine, ou midi sans repas) et garderie du
 *    soir — quelle que soit la façon dont ils ont été déclarés (forfait,
 *    cases séparées, retrait ou fermeture ensuite) ;
 *  - une journée complète est facturée au prix du forfait — le forfait
 *    sans repas (FSR) quand le midi est sans repas — sans jamais dépasser
 *    la somme de ses créneaux au tarif unitaire ;
 *  - sinon, chaque créneau consommé est facturé au tarif unitaire. Un
 *    retrait par la famille et une fermeture par la mairie se traitent
 *    donc de la même façon, et rien ne se cumule jamais.
 *
 * @param array    $declared           {code => bool} de la journée.
 * @param bool     $cantine_sans_repas Enfant flagué par la mairie : son midi
 *                                     est « sans repas » (la résolution l'a
 *                                     déjà converti ; filet de sécurité).
 * @param int|null $regle              Règle à appliquer (null : en vigueur).
 * @param string|null $date            Jour facturé : le plafond compare les
 *                                     tarifs EN VIGUEUR ce jour-là (P1-16) ;
 *                                     null : aujourd'hui.
 * @return string[] Codes à facturer (tarifs : psc_billing_tariffs($date)).
 */
function psc_billing_services(array $declared, $cantine_sans_repas = false, $regle = null, $date = null) {
    if ((int) $regle === 1) return psc_billing_services_regle1($declared, $cantine_sans_repas);

    $msr   = psc_midi_sans_repas_code();
    $gm    = !empty($declared['GM']);
    $gs    = !empty($declared['GS']);
    $cant  = !empty($declared['CANT']) && !$cantine_sans_repas;
    $sans  = !$cant && (!empty($declared[$msr]) || (!empty($declared['CANT']) && $cantine_sans_repas));

    $units = array();
    if ($gm) $units[] = 'GM';
    if ($cant) $units[] = 'CANT';
    if ($sans) $units[] = $msr;
    if ($gs) $units[] = 'GS';

    if (!$gm || !$gs || (!$cant && !$sans)) return $units;

    $tariffs = psc_billing_tariffs($date);
    $package = $cant ? psc_forfait_code() : 'FSR';
    // Comparaison en centimes entiers : exacte, sans marge de tolérance.
    $sum = 0;
    foreach ($units as $code) $sum += isset($tariffs[$code]) ? (int) $tariffs[$code]['centimes'] : 0;
    if (!isset($tariffs[$package])) return $units;
    return (int) $tariffs[$package]['centimes'] <= $sum ? array($package) : $units;
}

/**
 * Règle 1 (jusqu'à 5.28), conservée pour recalculer à l'identique les
 * factures envoyées sous cette règle. Ne pas utiliser ailleurs.
 */
function psc_billing_services_regle1(array $declared, $cantine_sans_repas = false) {
    if ($cantine_sans_repas && !empty($declared[psc_forfait_code()])) {
        return array('FSR');
    }
    $forf = psc_forfait_code();
    $out = array();
    if (!empty($declared[$forf])) {
        $out[] = $forf;
        if (!empty($declared['CANT']) && empty($declared[psc_midi_sans_repas_code()])) {
            return $out;
        }
    }
    foreach (psc_unit_services() as $svc) {
        if (!empty($declared[$svc])) $out[] = $svc;
    }
    $msr = psc_midi_sans_repas_code();
    if (!empty($declared[$msr])) $out[] = $msr;
    return $out;
}
