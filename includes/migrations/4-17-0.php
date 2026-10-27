<?php
/**
 * Migration de schéma 4.17.0.
 *
 * Chargée à la demande par Psc_Installer::run_migration() pendant une
 * montée de version ou une activation — jamais à chaque requête. Son
 * exécution est jugée par Psc_Installer::run_step() : retour false ou
 * erreur SQL = étape en échec, reprise à la prochaine occasion.
 */
if (!defined('ABSPATH')) exit;

class Psc_Migration_4_17_0 {

    /**
     * 4.17.0 (P1-16) — tarifs et statut « cantine sans repas » datés.
     *
     *  1. La grille en vigueur (option psc_service_prices, sinon les tarifs
     *     par défaut) devient la première ligne de chaque code de
     *     psc_tarifs, à compter de la première rentrée connue : les
     *     factures déjà calculées retrouvent exactement leurs prix.
     *  2. Chaque enfant flagué devient une période sans terme, depuis la
     *     même date ; puis la colonne children.cantine_sans_repas est
     *     supprimée.
     *
     * Idempotente et rejouée à chaque passe de schéma (installation neuve
     * comprise) : un code qui a déjà un tarif n'en reçoit pas d'autre, une
     * période n'est créée que pour un enfant qui n'en a aucune.
     *
     * @return bool Faux : étape en échec (run_step() juge aussi les erreurs SQL).
     */
    public static function run() {
        global $wpdb;
        $t_tarifs = psc_table('tarifs');
        $t_sr = psc_table('sans_repas');
        $t_child = psc_table('children');
        $t_years = psc_table('school_years');
        $now = current_time('mysql');

        $debut = (string) $wpdb->get_var("SELECT MIN(date_debut) FROM $t_years");
        if (!psc_valid_date($debut)) $debut = psc_rentree_year() . '-09-01';

        $prices = psc_default_service_prices();
        foreach ((array) get_option('psc_service_prices', array()) as $code => $price) {
            if (isset($prices[$code])) $prices[$code] = max(0, (float) $price);
        }
        // Seules les prestations sans aucun tarif reçoivent leur première
        // ligne : une grille déjà datée n'est jamais complétée dans le passé.
        $known = (array) $wpdb->get_col("SELECT DISTINCT code FROM $t_tarifs");
        foreach ($prices as $code => $price) {
            if (in_array($code, $known, true)) continue;
            $done = $wpdb->insert($t_tarifs, array(
                'code' => $code, 'prix_centimes' => (int) round($price * 100), 'debut' => $debut, 'fin' => null, 'created_at' => $now, 'created_by' => null,
            ));
            if ($done === false) return false;
        }

        if (Psc_Installer::column_exists($t_child, 'cantine_sans_repas')) {
            $done = $wpdb->query($wpdb->prepare(
                "INSERT INTO $t_sr (child_id, debut, fin, created_at, created_by)
                 SELECT c.id, %s, NULL, %s, NULL FROM $t_child c
                 WHERE c.cantine_sans_repas = 1 AND NOT EXISTS (SELECT 1 FROM $t_sr s WHERE s.child_id = c.id)",
                $debut, $now
            ));
            if ($done === false) return false;
            if (false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN cantine_sans_repas")) return false;
        }
        delete_option('psc_service_prices');
        if (class_exists('Psc_Tarifs')) Psc_Tarifs::flush_cache();
        return true;
    }
}
