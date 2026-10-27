<?php
/**
 * Migration de schéma 4.16.0.
 *
 * Chargée à la demande par Psc_Installer::run_migration() pendant une
 * montée de version ou une activation — jamais à chaque requête. Son
 * exécution est jugée par Psc_Installer::run_step() : retour false ou
 * erreur SQL = étape en échec, reprise à la prochaine occasion.
 */
if (!defined('ABSPATH')) exit;

class Psc_Migration_4_16_0 {

    /**
     * Chaînage v2 du journal d'audit (4.16.0, P1-11) : l'empreinte du
     * contenu de chaque ligne est gardée à part, pour qu'une ligne expirée
     * puisse être vidée par la purge sans casser la chaîne.
     *
     *  1. Seuil : premier id chaîné en v2 = id suivant la dernière ligne ;
     *     les lignes antérieures gardent leur chaînage v1, jamais réécrit.
     *  2. Empreinte de contenu des lignes v1, calculée en SQL par lots —
     *     même formule que psc_audit_content_hash().
     *
     * Idempotente : le seuil n'est posé qu'une fois, le calcul ne vise que
     * les lignes antérieures qui n'ont pas encore d'empreinte de contenu.
     *
     * @return bool Faux : étape en échec (run_step() juge aussi les erreurs SQL).
     */
    public static function run() {
        global $wpdb;
        $table = psc_table('audit_log');
        $seuil = (int) get_option('psc_audit_chain_v2_from', 0);
        if ($seuil <= 0) {
            $seuil = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1 FROM $table");
            if ($wpdb->last_error) return false;
            update_option('psc_audit_chain_v2_from', $seuil, false);
        }
        do {
            $done = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET empreinte_contenu = SHA2(CONCAT(
                    DATE_FORMAT(horodatage, '%%Y-%%m-%%d %%H:%%i:%%s'), '|', action, '|',
                    acteur_type, ':', COALESCE(acteur_id, 0), '|',
                    COALESCE(objet_type, ''), ':', COALESCE(objet_id, 0), '|', resume), 256)
                 WHERE id < %d AND empreinte_contenu IS NULL AND purgee_le IS NULL
                 LIMIT 5000",
                $seuil
            ));
            if ($done === false) return false;
        } while ($done === 5000);
        return true;
    }
}
