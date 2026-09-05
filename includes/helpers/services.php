<?php
/**
 * Prestations proposées et règles qui les lient entre elles.
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Prestations élémentaires : garderie matin, cantine, garderie soir.
 *
 * Ce sont celles qu'on déclare, ferme et compte une par une. Le forfait
 * journée n'en fait pas partie — il les recouvre exactement toutes les
 * trois, ce qui est la règle métier structurante de l'extension : elle
 * décide de ce qui est facturé.
 *
 * Source unique. Cette liste était auparavant réécrite à une douzaine
 * d'endroits sous trois noms différents (deux constantes de classe, deux
 * tableaux JavaScript, six littéraux en dur). Ajouter une prestation ou
 * changer la composition du forfait imposait de tous les retrouver ; en
 * oublier un ne produisait aucune erreur, seulement une facturation fausse.
 */
function psc_unit_services() {
    return array('GM', 'CANT', 'GS');
}

/** Code du forfait journée — il couvre exactement psc_unit_services(). */
function psc_forfait_code() {
    return 'FORF';
}

/**
 * Code de « Midi sans repas » : l'enfant est présent sur le créneau du midi
 * mais n'y déjeune pas à la cantine (repas apporté par la famille). Ce n'est
 * NI une composante du forfait (le forfait inclut le repas de la cantine),
 * NI un service de restauration : la commande fournisseur ne le compte pas.
 * Il entre en conflit avec la cantine (un même midi, l'un ou l'autre) et
 * avec le forfait (qui couvre la cantine).
 */
function psc_midi_sans_repas_code() {
    return 'MSR';
}

/**
 * Prestations qu'un enregistrement peut porter : les élémentaires, le
 * forfait et « Midi sans repas ». Dérivée, pour que l'ajout d'une
 * prestation à la liste ci-dessus se propage sans qu'on ait à y penser.
 */
function psc_allowed_services() {
    return array_merge(psc_unit_services(), array(psc_forfait_code(), psc_midi_sans_repas_code()));
}

/**
 * Le forfait journée couvre-t-il cette prestation ? Seules les
 * élémentaires le sont — « Midi sans repas » n'y entre pas : un enfant au
 * forfait déjeune à la cantine, il ne peut pas être simultanément « midi
 * sans repas ».
 */
function psc_forfait_covers($service) {
    return in_array($service, psc_unit_services(), true);
}

/**
 * Vérifie qu'un code de prestation est reconnu.
 *
 * Point de contrôle unique : la liste était déjà centralisée, mais le
 * test était réécrit à chaque endroit qui écrit une inscription. Un
 * chemin d'écriture ajouté plus tard pouvait donc simplement oublier de
 * le faire, sans que rien ne le signale.
 *
 * La colonne correspondante est par ailleurs contrainte côté base
 * (cf. Psc_Installer::ensure_service_enum()) : cette fonction refuse la
 * valeur proprement, la base l'aurait de toute façon rejetée.
 */
function psc_is_valid_service($service) {
    return in_array($service, psc_allowed_services(), true);
}

/**
 * Prestations incompatibles avec celle-ci, pour un même enfant et un même
 * jour. Le forfait et ses composantes s'excluent mutuellement : déclarer le
 * forfait retire les prestations individuelles, et déclarer une prestation
 * individuelle retire le forfait. Sans quoi la journée serait comptée deux
 * fois. « Midi sans repas » s'exclut de la cantine (un même midi, l'enfant
 * déjeune à la cantine ou y est sans repas) et du forfait, qui couvre la
 * cantine — mais reste compatible avec les garderies matin et soir.
 */
function psc_conflicting_services($service) {
    $msr = psc_midi_sans_repas_code();
    if ($service === psc_forfait_code()) {
        return array_merge(psc_unit_services(), array($msr));
    }
    if ($service === $msr) {
        return array('CANT', psc_forfait_code());
    }
    if ($service === 'CANT') {
        return array(psc_forfait_code(), $msr);
    }
    if (in_array($service, psc_unit_services(), true)) {
        return array(psc_forfait_code());
    }
    return array();
}

/**
 * Une prestation est-elle fermée ce jour-là, d'après une carte de
 * fermetures déjà chargée (clés « date|code », cf. service_closures_map()) ?
 *
 * Pendant du contrôle en base côté serveur, pour les gabarits qui ont déjà
 * la carte en main et n'ont pas à réinterroger. Même règle : le forfait est
 * bloqué dès qu'une seule de ses composantes l'est, puisqu'on ne peut pas
 * en facturer une partie.
 */
function psc_service_closed_in_map(array $closures, $date, $service) {
    $codes = $service === psc_forfait_code() ? psc_unit_services() : array($service);
    foreach ($codes as $code) {
        if (isset($closures[$date . '|' . $code])) {
            return true;
        }
    }
    return false;
}

/** Libellés abrégés, pour les en-têtes de colonnes serrées. */
function psc_service_short_labels() {
    return array(
        'GM'   => __('G.M.', 'periscolaire-registration'),
        'CANT' => __('Cant.', 'periscolaire-registration'),
        'GS'   => __('G.S.', 'periscolaire-registration'),
        'FORF' => __('Forf.', 'periscolaire-registration'),
        'MSR'  => __('S. repas', 'periscolaire-registration'),
    );
}

/**
 * Services proposés et leurs tarifs (éditables depuis Périscolaire > Réglages).
 */
function psc_services() {
    $defaults = array(
        'GM'   => array('label' => __('Garderie Matin', 'periscolaire-registration'), 'price' => 1.85),
        'CANT' => array('label' => __('Cantine', 'periscolaire-registration'), 'price' => 5.80),
        'GS'   => array('label' => __('Garderie Soir', 'periscolaire-registration'), 'price' => 4.70),
        'FORF' => array('label' => __('Forfait journée', 'periscolaire-registration'), 'price' => 11.70),
        'MSR'  => array('label' => __('Midi sans repas', 'periscolaire-registration'), 'price' => 1.00),
    );
    $saved = get_option('psc_service_prices', array());
    if (is_array($saved)) {
        foreach ($saved as $code => $price) {
            if (isset($defaults[$code])) {
                $defaults[$code]['price'] = max(0, floatval($price));
            }
        }
    }
    return $defaults;
}
