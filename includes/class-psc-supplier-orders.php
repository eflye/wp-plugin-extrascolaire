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
     * Codes de classe ayant au moins un enfant actif (famille active
     * comprise), dans l'ordre pédagogique de Psc_School_Years::classe_options().
     * Une classe non renseignée ('') est ajoutée en dernier, si des
     * enfants actifs sont dans ce cas.
     */
    protected static function known_classes($year_id) {
        global $wpdb;
        if (!$year_id) return array();

        $t_child = psc_table('children');
        $t_par   = psc_table('parents');
        $t_cy    = psc_table('child_school_years');
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT cy.classe FROM $t_child c
             JOIN $t_par p ON p.id = c.parent_id
             JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             WHERE c.statut = 'actif' AND p.active = 1",
            $year_id
        ));
        $existing = array_map('strval', $existing);

        $ordered = array();
        foreach (Psc_School_Years::classe_options() as $code => $label) {
            if ($code === '') continue;
            if (in_array($code, $existing, true)) $ordered[] = $code;
        }
        if (in_array('', $existing, true)) {
            $ordered[] = '';
        }
        return $ordered;
    }

    /**
     * Calcule la grille classe x jour de repas de cantine pour la semaine
     * donnée (n'importe quelle date de la semaine, ramenée au lundi). Ne
     * compte que la prestation Cantine (CANT), enfants et familles actifs,
     * via la source de vérité unique (psc_is_declared). Les enfants qui
     * apportent leur repas (allergie alimentaire déclarée) sont exclus du
     * comptage — contrepartie du « pas de menu différencié » : sans cette
     * exclusion, la commune paie des repas non consommés.
     *
     * Retourne un tableau structuré (semaine_debut, jours réels, classes,
     * comptages, totaux) ou un WP_Error si la semaine est invalide.
     */
    public static function compute_counts($semaine_debut) {
        $semaine = psc_week_start($semaine_debut);
        if (!$semaine) {
            return new WP_Error('psc_invalid_week', __('Date de semaine invalide.', 'periscolaire-registration'));
        }

        global $wpdb;
        $t_child = psc_table('children');
        $t_par   = psc_table('parents');
        $t_cy    = psc_table('child_school_years');
        // Résolue depuis la semaine demandée (pas l'année active du site) :
        // la commande fournisseur peut porter sur une semaine passée ou à
        // venir, potentiellement hors de l'année scolaire en cours.
        $year = Psc_School_Years::for_date($semaine);
        $year_id = $year ? (int) $year->id : 0;

        $classes_labels = Psc_School_Years::classe_options();
        $classes = self::known_classes($year_id);

        // Seuls les jours d'école réellement ouverts (vacances, jours fériés
        // et fermetures ponctuelles exclus) : pas de colonne à commander
        // pour un jour sans cantine.
        $jours_dates = psc_open_days($semaine);
        $jours       = array_keys($jours_dates);

        $counts = array();
        foreach ($classes as $c) $counts[$c] = array_fill_keys($jours, 0);

        // Toutes les déclarations de la semaine en un lot, puis décompte
        // par classe côté PHP : les règles de facturation (couverture par
        // le forfait, allergies alimentaires) ne sont pas exprimables en
        // une requête SQL — elles vivent dans la résolution.
        if ($year_id && !empty($jours_dates)) {
            $children = $wpdb->get_results($wpdb->prepare(
                "SELECT c.id, cy.classe, c.food_allergies
                 FROM $t_child c
                 JOIN $t_par p ON p.id = c.parent_id
                 JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
                 WHERE c.statut = 'actif' AND p.active = 1
                   AND cy.classe IS NOT NULL",
                $year_id
            ));
            if ($children) {
                $child_ids = array_map(function ($c) { return (int) $c->id; }, $children);
                $declared = Psc_Planning::declared_map($child_ids, array_values($jours_dates));

                foreach ($children as $child) {
                    $classe = (string) $child->classe;
                    if (!isset($counts[$classe])) {
                        // Classe présente en base mais absente de known_classes()
                        // (cas limite, ex. valeur historique hors liste actuelle).
                        $counts[$classe] = array_fill_keys($jours, 0);
                        $classes[] = $classe;
                    }
                    // Allergie alimentaire déclarée : l'enfant apporte son
                    // repas fourni par la famille — aucun repas à commander.
                    if (trim((string) $child->food_allergies) !== '') continue;

                    foreach ($jours_dates as $date) {
                        if (!empty($declared[$child->id][$date]['CANT'])) {
                            $counts[$classe][array_search($date, $jours_dates, true)]++;
                        }
                    }
                }
            }
        }

        $totaux_jour   = array_fill_keys($jours, 0);
        $totaux_classe = array();
        $total         = 0;
        foreach ($counts as $classe => $par_jour) {
            $totaux_classe[$classe] = array_sum($par_jour);
            $total += $totaux_classe[$classe];
            foreach ($par_jour as $jour => $n) {
                $totaux_jour[$jour] += $n;
            }
        }

        $classes_out = array();
        foreach ($classes as $c) {
            $classes_out[$c] = ($c === '') ? __('Non renseignée', 'periscolaire-registration') : ($classes_labels[$c] ?? $c);
        }

        return array(
            'semaine_debut' => $semaine,
            'jours'         => $jours_dates,
            'classes'       => $classes_out,
            'counts'        => $counts,
            'totaux_jour'   => $totaux_jour,
            'totaux_classe' => $totaux_classe,
            'total'         => $total,
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
