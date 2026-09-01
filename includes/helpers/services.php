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
 * Prestations qu'un enregistrement peut porter : les élémentaires, plus le
 * forfait. Dérivée, pour que l'ajout d'une prestation à la liste ci-dessus
 * se propage sans qu'on ait à y penser.
 */
function psc_allowed_services() {
    return array_merge(psc_unit_services(), array(psc_forfait_code()));
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
 * fois.
 */
function psc_conflicting_services($service) {
    if ($service === psc_forfait_code()) {
        return psc_unit_services();
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
