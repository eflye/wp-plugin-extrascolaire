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

/** Tarifs par défaut (euros), avant toute grille enregistrée par la mairie. */
function psc_default_service_prices() {
    return array('GM' => 1.85, 'CANT' => 5.80, 'GS' => 4.70, 'FORF' => 11.70, 'MSR' => 1.00, 'FSR' => 9.00);
}

/** Date du jour (Europe/Paris quand WordPress est chargé). */
function psc_today() {
    return function_exists('current_time') ? current_time('Y-m-d') : gmdate('Y-m-d');
}

/**
 * Grille des tarifs datés (P1-16) : lignes {code, prix_centimes, debut,
 * fin}. Source : la table psc_tarifs (Psc_Tarifs) une fois la mise à jour
 * 4.17.0 passée ; avant elle (et dans les tests unitaires, sans base),
 * l'ancienne option psc_service_prices, vue comme une grille unique en
 * vigueur depuis toujours.
 */
function psc_tariff_rows() {
    if (class_exists('Psc_Tarifs') && Psc_Tarifs::ready()) return Psc_Tarifs::rows();
    $rows = array();
    foreach ((array) get_option('psc_service_prices', array()) as $code => $price) {
        $rows[] = array('code' => (string) $code, 'prix_centimes' => (int) round(max(0, (float) $price) * 100), 'debut' => '1970-01-01', 'fin' => null);
    }
    return $rows;
}

/**
 * Tarif de chaque code en vigueur à une date (fonction pure) : la ligne
 * dont le début est le plus récent sans dépasser la date. Une date
 * antérieure à toute la grille d'un code prend sa première ligne — la
 * grille migrée démarre à la première rentrée connue.
 *
 * @param array  $rows Lignes {code, prix_centimes, debut}.
 * @param string $date Y-m-d.
 * @return array {code => centimes}
 */
function psc_tariffs_at(array $rows, $date) {
    $best = array();
    $first = array();
    foreach ($rows as $r) {
        $r = (array) $r;
        $code = (string) $r['code'];
        $debut = (string) $r['debut'];
        if (!isset($first[$code]) || $debut < $first[$code]['debut']) $first[$code] = $r;
        if ($debut <= $date && (!isset($best[$code]) || $debut > $best[$code]['debut'])) $best[$code] = $r;
    }
    $out = array();
    foreach ($first as $code => $r) {
        $row = $best[$code] ?? $r;
        $out[$code] = (int) $row['prix_centimes'];
    }
    return $out;
}

/**
 * Services proposés et leurs tarifs EN VIGUEUR à une date (aujourd'hui par
 * défaut) — grille datée éditable dans Périscolaire › Réglages. 'price' en
 * euros pour l'affichage, 'centimes' (entier) pour tout calcul de montant.
 */
function psc_services($date = null) {
    $prices = psc_default_service_prices();
    $defaults = array(
        'GM'   => array('label' => __('Garderie Matin', 'periscolaire-registration'), 'price' => $prices['GM']),
        'CANT' => array('label' => __('Cantine', 'periscolaire-registration'), 'price' => $prices['CANT']),
        'GS'   => array('label' => __('Garderie Soir', 'periscolaire-registration'), 'price' => $prices['GS']),
        'FORF' => array('label' => __('Forfait journée', 'periscolaire-registration'), 'price' => $prices['FORF']),
        'MSR'  => array('label' => __('Cantine sans repas', 'periscolaire-registration'), 'price' => $prices['MSR']),
    );
    foreach ($defaults as $code => $row) $defaults[$code]['centimes'] = (int) round($row['price'] * 100);
    foreach (psc_tariffs_at(psc_tariff_rows(), $date ?: psc_today()) as $code => $centimes) {
        if (isset($defaults[$code])) {
            $defaults[$code]['price'] = $centimes / 100.0; // toujours un flottant (cf. instantanés JSON)
            $defaults[$code]['centimes'] = (int) $centimes;
        }
    }
    return $defaults;
}

/** Tarifs facturables à une date : FSR est une variante du forfait, pas une case du planning. */
function psc_billing_tariffs($date = null) {
    $date = $date ?: psc_today();
    $services = psc_services($date);
    $at = psc_tariffs_at(psc_tariff_rows(), $date);
    $fsr = isset($at['FSR']) ? (int) $at['FSR'] : (int) round(psc_default_service_prices()['FSR'] * 100);
    $services['FSR'] = array(
        'label'    => __('Forfait sans repas cantine', 'periscolaire-registration'),
        'price'    => $fsr / 100.0,
        'centimes' => $fsr,
    );
    return $services;
}

/**
 * Une date tombe-t-elle dans une des périodes (fonction pure) ? Périodes
 * {debut, fin} en Y-m-d, fin incluse, fin null = sans terme.
 */
function psc_period_contains(array $periods, $date) {
    foreach ($periods as $p) {
        $p = (array) $p;
        if ((string) $p['debut'] <= $date && ($p['fin'] === null || $p['fin'] === '' || $date <= (string) $p['fin'])) return true;
    }
    return false;
}

/**
 * Applique « actif à partir de $from » ($on) ou « inactif à partir de
 * $from » à une liste de périodes, et la renvoie triée et fusionnée
 * (fonction pure). Le passé avant $from n'est jamais modifié : une période
 * en cours est coupée la veille de $from, les périodes qui commencent à
 * partir de $from sont remplacées.
 *
 * @return array Liste de {debut, fin|null}.
 */
function psc_periods_apply(array $periods, $on, $from) {
    $veille = gmdate('Y-m-d', strtotime($from . ' -1 day'));
    $kept = array();
    foreach ($periods as $p) {
        $p = (array) $p;
        $debut = (string) $p['debut'];
        $fin = ($p['fin'] === null || $p['fin'] === '') ? null : (string) $p['fin'];
        if ($debut >= $from) continue;
        if ($fin === null || $fin >= $from) $fin = $veille;
        $kept[] = array('debut' => $debut, 'fin' => $fin);
    }
    if ($on) $kept[] = array('debut' => $from, 'fin' => null);
    usort($kept, function ($a, $b) { return strcmp($a['debut'], $b['debut']); });
    $merged = array();
    foreach ($kept as $p) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last]['fin'] !== null
            && gmdate('Y-m-d', strtotime($merged[$last]['fin'] . ' +1 day')) >= $p['debut']) {
            $merged[$last]['fin'] = ($p['fin'] === null || $p['fin'] > $merged[$last]['fin']) ? $p['fin'] : $merged[$last]['fin'];
            continue;
        }
        $merged[] = $p;
    }
    return $merged;
}
