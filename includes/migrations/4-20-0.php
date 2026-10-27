<?php
/**
 * Migration de schéma 4.20.0.
 *
 * Chargée à la demande par Psc_Installer::run_migration() pendant une
 * montée de version ou une activation — jamais à chaque requête. Son
 * exécution est jugée par Psc_Installer::run_step() : retour false ou
 * erreur SQL = étape en échec, reprise à la prochaine occasion.
 */
if (!defined('ABSPATH')) exit;

class Psc_Migration_4_20_0 {

    /**
     * 4.20.0 (RGPD-10) — fin du texte libre des allergies, donnée de santé
     * que le parcours ne collecte plus. Avant de supprimer la colonne, tout
     * enfant dont le texte était renseigné reçoit le signalement alimentaire,
     * qui l'exclut des repas de la cantine (règle de la mairie) : aucun
     * enfant allergique ne perd son exclusion. Le texte est aussi retiré des
     * demandes d'inscription encore en base, avec la date de consentement
     * à sa collecte (colonne food_allergy_consent_at supprimée). Idempotente.
     *
     * @return bool Faux : étape en échec (run_step() juge aussi les erreurs SQL).
     */
    public static function run() {
        global $wpdb;
        $t_child = psc_table('children');
        $t_req = psc_table('requests');

        if (Psc_Installer::column_exists($t_child, 'food_allergies')) {
            $done = $wpdb->query("UPDATE $t_child SET food_allergy_signal = 1 WHERE TRIM(COALESCE(food_allergies, '')) <> ''");
            if ($done === false) return false;
            if (false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN food_allergies")) return false;
        }
        // Consentement à la collecte du texte d'allergies : sans objet une
        // fois le texte supprimé.
        if (Psc_Installer::column_exists($t_child, 'food_allergy_consent_at')) {
            if (false === $wpdb->query("ALTER TABLE $t_child DROP COLUMN food_allergy_consent_at")) return false;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, children_json FROM $t_req WHERE children_json LIKE %s OR children_json LIKE %s",
            '%' . $wpdb->esc_like('"food_allergies"') . '%',
            '%' . $wpdb->esc_like('"food_allergy_consent_at"') . '%'
        ));
        foreach ((array) $rows as $row) {
            $children = json_decode((string) $row->children_json, true);
            if (!is_array($children)) continue;
            foreach ($children as &$c) {
                if (!is_array($c)) continue;
                if (trim((string) ($c['food_allergies'] ?? '')) !== '') $c['food_allergy_signal'] = 1;
                unset($c['food_allergies'], $c['food_allergy_consent_at']);
            }
            unset($c);
            $done = $wpdb->update($t_req, array('children_json' => wp_json_encode($children)), array('id' => (int) $row->id));
            if ($done === false) return false;
        }
        return true;
    }
}
