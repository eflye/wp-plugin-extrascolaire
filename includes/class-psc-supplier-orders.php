<?php
if (!defined('ABSPATH')) exit;

/**
 * Commande fournisseur hebdomadaire : nombre de repas de cantine par
 * classe, poussée par e-mail au prestataire sur action manuelle de
 * l'admin — jamais automatique, jamais de cron (même principe que
 * Psc_Menus). Chaque envoi archive un instantané figé (comptage et
 * e-mail effectivement envoyés) : l'historique doit rester exact même si
 * des inscriptions changent après coup.
 */
class Psc_Supplier_Orders {

    const JOURS = array('lundi', 'mardi', 'jeudi', 'vendredi');

    /** Décalage en jours depuis le lundi de la semaine. */
    const JOUR_OFFSETS = array('lundi' => 0, 'mardi' => 1, 'jeudi' => 3, 'vendredi' => 4);

    public static function jour_labels() {
        return array(
            'lundi'    => __('Lundi', 'periscolaire-registration'),
            'mardi'    => __('Mardi', 'periscolaire-registration'),
            'jeudi'    => __('Jeudi', 'periscolaire-registration'),
            'vendredi' => __('Vendredi', 'periscolaire-registration'),
        );
    }

    /**
     * Prestations qui ouvrent droit au goûter, par enfant et par jour.
     * Le goûter est servi à la garderie du soir — un enfant attendu ce
     * jour-là (déclaration directe ou forfait réalisable, cf.
     * psc_is_declared) en reçoit un. Filtrable : une structure qui sert
     * aussi (ou seulement) un goûter le matin ajuste la liste ici, sans
     * toucher au reste du comptage.
     */
    public static function gouter_services() {
        return (array) apply_filters('psc_gouter_services', array('GS'));
    }

    /**
     * Calcule les quantités à commander pour la semaine donnée (n'importe
     * quelle date de la semaine, ramenée au lundi), via la source de vérité
     * unique (psc_is_declared). Une ligne par jour de service, ventilation
     * par régime :
     *
     *   - sans régime particulier → Standard ;
     *   - régime « sans porc » → Sans porc ;
     *   - régime « sans viande » (colonne vegan, libellé côté famille) →
     *     Végétarien — le vocabulaire du fournisseur s'applique à la
     *     génération ;
     *   - food_allergies renseigné → compté dans AUCUNE colonne repas ni
     *     goûter : l'enfant apporte les siens, mais reste sur les listes
     *     de présence avec la mention « apporte son repas ».
     *
     * Les trois régimes sont mutuellement exclusifs : chaque enfant inscrit
     * au déjeuner compte pour exactement un des trois, Total midi = somme
     * des trois. Le goûter suit la garderie du soir (gouter_services()).
     * Seuls les jours d'école ouverts figurent en ligne — un jour férié
     * n'est pas une ligne, et un jour sans inscrit affiche 0 (une
     * information de commande, pas un tiret).
     *
     * Plus aucun découpage par classe : le fournisseur livre pour
     * l'établissement.
     */
    public static function compute_counts($semaine_debut) {
        $semaine = psc_week_start($semaine_debut);
        if (!$semaine) {
            return new WP_Error('psc_invalid_week', __('Date de semaine invalide.', 'periscolaire-registration'));
        }

        global $wpdb;
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');

        // Seuls les jours d'école réellement ouverts (vacances, jours fériés
        // et fermetures ponctuelles exclus) : pas de ligne à commander pour
        // un jour sans service.
        $jours_dates = psc_open_days($semaine);
        $jours       = array_keys($jours_dates);

        $rows = array();
        foreach ($jours as $j) {
            $rows[$j] = array('standard' => 0, 'sans_porc' => 0, 'vegetarien' => 0, 'midi' => 0, 'gouter' => 0);
        }

        if (!empty($jours_dates)) {
            $children = $wpdb->get_results(
                "SELECT c.id, c.sans_porc, c.vegan, c.food_allergies
                 FROM $t_child c
                 JOIN $t_par p ON p.id = c.parent_id
                 WHERE c.statut = 'actif' AND p.active = 1"
            );
            if ($children) {
                $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
                $declared = Psc_Planning::declared_map($child_ids, array_values($jours_dates));

                foreach ($children as $child) {
                    // Allergie alimentaire déclarée : l'enfant apporte son
                    // repas ET son goûter fournis par la famille — compté
                    // dans aucune colonne.
                    if (trim((string) $child->food_allergies) !== '') continue;

                    // Les trois régimes sont mutuellement exclusifs. Sans
                    // viande (vegan) est la restriction la plus large : elle
                    // l'emporte si un jeu de données incohérent cumule les
                    // deux cases.
                    $kind = ((int) $child->vegan === 1)
                        ? 'vegetarien'
                        : (((int) $child->sans_porc === 1) ? 'sans_porc' : 'standard');

                    foreach ($jours_dates as $jour => $date) {
                        $day = isset($declared[$child->id][$date]) ? $declared[$child->id][$date] : array();
                        if (!empty($day['CANT'])) {
                            $rows[$jour][$kind]++;
                            $rows[$jour]['midi']++;
                        }
                        foreach (self::gouter_services() as $svc) {
                            if (!empty($day[$svc])) {
                                $rows[$jour]['gouter']++;
                                break; // un seul goûter par enfant et par jour
                            }
                        }
                    }
                }
            }
        }

        // Total semaine, colonne par colonne.
        $totaux = array('standard' => 0, 'sans_porc' => 0, 'vegetarien' => 0, 'midi' => 0, 'gouter' => 0);
        foreach ($rows as $row) {
            foreach ($totaux as $k => $v) $totaux[$k] += $row[$k];
        }

        return array(
            'semaine_debut'   => $semaine,
            'jours'           => $jours_dates,
            'rows'            => $rows,
            'totaux'          => $totaux,
            'total'           => $totaux['midi'],
            'total_standard'  => $totaux['standard'],
            'total_sans_porc' => $totaux['sans_porc'],
            'total_vegetarien' => $totaux['vegetarien'],
            'total_gouters'   => $totaux['gouter'],
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('supplier_orders') . ' WHERE id = %d', $id));
    }

    public static function recent($limit = 20) {
        global $wpdb;
        $limit = max(1, min(100, (int) $limit));
        return $wpdb->get_results('SELECT * FROM ' . psc_table('supplier_orders') . " ORDER BY sent_at DESC LIMIT $limit");
    }

    /**
     * Calcule, envoie au fournisseur et archive un instantané de la
     * commande de la semaine donnée. Renvoie l'id de l'entrée
     * d'historique créée, ou WP_Error (mail non envoyé => pas d'entrée
     * d'historique, l'admin peut réessayer).
     */
    public static function send($semaine_debut) {
        $data = self::compute_counts($semaine_debut);
        if (is_wp_error($data)) return $data;

        $supplier_email = get_option('psc_supplier_email', '');
        if (!$supplier_email || !is_email($supplier_email)) {
            return new WP_Error(
                'psc_no_supplier_email',
                __("Aucune adresse e-mail fournisseur n'est configurée (Périscolaire > Réglages).", 'periscolaire-registration')
            );
        }

        $rendered = Psc_Mailer::send_supplier_order($supplier_email, $data);
        if (!$rendered['sent']) {
            return new WP_Error('psc_mail_failed', __("L'envoi du mail a échoué.", 'periscolaire-registration'));
        }

        global $wpdb;
        $wpdb->insert(psc_table('supplier_orders'), array(
            'semaine_debut'  => $data['semaine_debut'],
            'counts_json'    => wp_json_encode($data),
            'total_repas'    => $data['total'],
            'supplier_email' => $supplier_email,
            'email_subject'  => $rendered['subject'],
            'email_body'     => $rendered['html'],
            'sent_at'        => current_time('mysql'),
        ), array('%s', '%s', '%d', '%s', '%s', '%s', '%s'));

        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------
     * Annulation de la cantine pour une classe entière (sortie scolaire,
     * fermeture ponctuelle...)
     * ------------------------------------------------------------------ */

    /**
     * Enfants de la classe déclarés à la cantine pour un jour donné
     * (source de vérité unique : psc_is_declared) — utilisé pour avertir
     * l'admin avant annulation.
     */
    public static function cantine_registrations_for_class_day($date, $classe) {
        $date = psc_valid_date($date);
        if (!$date) return array();
        $year = Psc_School_Years::for_date($date);
        $year_id = $year ? (int) $year->id : 0;
        if (!$year_id) return array();

        global $wpdb;
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');
        $t_cy    = psc_table('child_school_years');

        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT c.id AS child_id, c.nom AS child_nom, c.prenom AS child_prenom,
                    p.id AS parent_id, p.email, p.nom AS parent_nom
             FROM $t_child c
             JOIN $t_par p ON p.id = c.parent_id
             JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             WHERE cy.classe = %s
               AND c.statut = 'actif' AND p.active = 1
             ORDER BY p.email, c.nom",
            $year_id, $classe
        ));
        if (!$children) return array();

        $out = array();
        foreach ($children as $child) {
            // Un enfant au forfait n'a jamais eu de ligne CANT dans l'ancien
            // modèle et n'est pas annulé par cette action : le forfait est
            // indivisible (sa gestion passe par le calendrier scolaire).
            if (Psc_Planning::is_declared((int) $child->child_id, $date, psc_forfait_code())) continue;
            if (!Psc_Planning::is_declared((int) $child->child_id, $date, 'CANT')) continue;
            $out[] = $child;
        }
        return $out;
    }

    /**
     * Annule la cantine pour toute une classe un jour donné : écrit une
     * exception de RETRAIT pour chaque enfant concerné (elles ne seront
     * jamais facturées) et notifie chaque famille par e-mail avec le motif
     * indiqué. La mairie n'est pas soumise au verrou de 48 h — elle annule
     * au dernier moment. Renvoie le nombre d'enfants concernés, ou
     * WP_Error si la date est invalide.
     */
    public static function cancel_class_meals($date, $classe, $reason) {
        $date = psc_valid_date($date);
        if (!$date) {
            return new WP_Error('psc_invalid_date', __('Date invalide.', 'periscolaire-registration'));
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            return new WP_Error('psc_reason_required', __('Merci d\'indiquer un motif.', 'periscolaire-registration'));
        }

        $rows = self::cantine_registrations_for_class_day($date, $classe);
        if (empty($rows)) {
            return 0;
        }

        $by_family = array();
        foreach ($rows as $r) {
            if (!isset($by_family[$r->parent_id])) {
                $by_family[$r->parent_id] = array(
                    'email' => $r->email,
                    'nom'   => $r->parent_nom,
                    'items' => array(),
                );
            }
            $by_family[$r->parent_id]['items'][] = $r;
        }

        // Une exception de retrait par (enfant, date) : la résolution
        // repasse à false, la facturation et les listes s'ajustent seules.
        foreach ($rows as $r) {
            Psc_Planning::toggle_exception((int) $r->child_id, $date, 'CANT', false, true);
        }

        $classe_labels = Psc_School_Years::classe_options();
        $classe_label  = ($classe === '') ? __('Non renseignée', 'periscolaire-registration') : ($classe_labels[$classe] ?? $classe);

        foreach ($by_family as $fam) {
            Psc_Mailer::send_cantine_cancelled($fam, $date, $classe_label, $reason);
        }

        return count($rows);
    }
}
