<?php
/**
 * Migration de schéma 4.22.0.
 *
 * Chargée à la demande par Psc_Installer::run_migration() pendant une
 * montée de version ou une activation — jamais à chaque requête. Son
 * exécution est jugée par Psc_Installer::run_step() : retour false ou
 * erreur SQL = étape en échec, reprise à la prochaine occasion.
 */
if (!defined('ABSPATH')) exit;

class Psc_Migration_4_22_0 {

    /**
     * 4.22.0 — pointage à trois états sur l'écran intervenants : la colonne
     * attendance.present devient nullable, NULL signifiant « à pointer »
     * (1 présent, 0 absent). dbDelta() ne modifie ni la nullité ni un
     * défaut sans guillemets d'une colonne existante : l'ALTER est donc
     * explicite. Aucune donnée n'est transformée — une ligne d'époque à 1
     * reste présente, à 0 reste absente. Idempotente : sans effet une fois
     * la colonne nullable.
     *
     * @return bool Faux : étape en échec (run_step() juge aussi les erreurs SQL).
     */
    public static function run() {
        global $wpdb;
        $t_att = psc_table('attendance');
        $nullable = $wpdb->get_var($wpdb->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $t_att, 'present'
        ));
        if ($nullable === null || $nullable === 'YES') return true;
        return false !== $wpdb->query("ALTER TABLE $t_att MODIFY present TINYINT(1) NULL DEFAULT NULL");
    }
}
