<?php
if (!defined('ABSPATH')) exit;

/**
 * Cycle de vie RGPD des fiches enfants après la fin de la relation avec le
 * service périscolaire (dernière classe, déménagement, retrait manuel par
 * la famille ou par la mairie — cf. Psc_School_Years::mark_sorti()).
 *
 * Sans ce mécanisme, une fiche enfant (identité, allergies/santé, planning,
 * personnes autorisées) reste en base indéfiniment après le départ, ce
 * qu'aucune obligation légale ne justifie ici — à la différence des
 * factures, dont Psc_Privacy documente la conservation décennale distincte
 * (Code de commerce). Le délai par défaut (400 jours, filtrable) couvre,
 * quel que soit le moment de l'année où l'enfant sort, la fin de l'année
 * scolaire en cours et une marge estivale avant purge — le temps qu'un
 * différend ou une inscription tardive d'un cadet de la même famille
 * puisse encore s'appuyer sur ce dossier.
 */
class Psc_Retention {

    /** Exécute le registre en simulation par défaut, sans mutation implicite. */
    public static function run($mode = 'simulation', $now = null) {
        $mode = $mode === 'execution' ? 'execution' : 'simulation';
        $now = $now === null ? time() : (int) $now;
        $report = array('mode' => $mode, 'categories' => array(), 'examined' => 0, 'removed' => 0, 'retained' => 0, 'errors' => array(), 'next_run' => gmdate('c', $now + DAY_IN_SECONDS));

        foreach (psc_retention_policies() as $key => $policy) {
            $entry = array('key' => $key, 'label' => $policy['label'], 'examined' => 0, 'removed' => 0, 'retained' => 0, 'status' => 'retenu', 'reason' => 'durée non validée');
            if ($key === 'departed_children') {
                $entry['examined'] = self::count_departed_children($now);
                $entry['status'] = $mode === 'execution' ? 'exécuté' : 'simulation';
                $entry['reason'] = $mode === 'execution' ? 'purge des fiches sorties' : 'aucune écriture en mode simulation';
                if ($mode === 'execution' && $entry['examined'] > 0) {
                    $entry['removed'] = self::purge_departed_children($now);
                }
            } else {
                $entry['retained'] = $entry['examined'];
            }
            $report['categories'][] = $entry;
            $report['examined'] += $entry['examined'];
            $report['removed'] += $entry['removed'];
            $report['retained'] += $entry['retained'];
        }
        return $report;
    }

    public static function init() {
        add_action('psc_purge_departed_children', array(__CLASS__, 'purge_departed_children'));
        self::ensure_crons();
    }

    public static function ensure_crons() {
        if (!wp_next_scheduled('psc_purge_departed_children')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'psc_purge_departed_children');
        }
    }

    /**
     * Purge quotidienne : enfants marqués sortis depuis plus longtemps que
     * le délai de conservation. Réutilise Psc_Admin_Familles::purge_child()
     * — le même chemin, déjà exhaustif, qu'une suppression manuelle par la
     * mairie ou que l'effaceur RGPD (Psc_Privacy) — pour qu'un seul endroit
     * sache purger un enfant. La fiche famille (parents) n'est pas touchée :
     * une fratrie encore active, ou des factures à conserver, peuvent y être
     * rattachées.
     */
    public static function purge_departed_children($now = null) {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
        $now = $now === null ? current_time('timestamp') : (int) $now;
        $cutoff = gmdate('Y-m-d H:i:s', $now - $retention_days * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, parent_id, nom, prenom FROM ' . psc_table('children') . "
             WHERE statut = 'sorti' AND sorti_le IS NOT NULL AND sorti_le < %s",
            $cutoff
        ));
        if (!$rows) return 0;

        foreach ($rows as $row) {
            Psc_Admin_Familles::purge_child((int) $row->id);

            Psc_Audit::log('systeme.purge', array(
                'objet_type' => 'enfant',
                'objet_id'   => (int) $row->id,
                'famille_id' => (int) $row->parent_id,
                'enfant_id'  => (int) $row->id,
                'resume'     => sprintf(
                    __('Fiche de %1$s %2$s purgée automatiquement (sortie du service depuis plus de %3$d jours, conservation RGPD expirée).', 'periscolaire-registration'),
                    $row->prenom, $row->nom, $retention_days
                ),
            ));
        }

        return count($rows);
    }

    private static function count_departed_children($now) {
        global $wpdb;
        $retention_days = max(1, (int) apply_filters('psc_children_retention_days', 400));
        $cutoff = gmdate('Y-m-d H:i:s', (int) $now - $retention_days * DAY_IN_SECONDS);
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('children') . " WHERE statut = 'sorti' AND sorti_le IS NOT NULL AND sorti_le < %s",
            $cutoff
        ));
    }
}
