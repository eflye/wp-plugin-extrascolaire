<?php
if (!defined('ABSPATH')) exit;

/**
 * Statut « cantine sans repas » daté (P1-16, schéma 4.17.0) — table
 * psc_sans_repas, une ligne par période {debut, fin|null} et par enfant.
 *
 * Décision de la mairie : pendant une période, les déclarations de cantine
 * de l'enfant valent « midi sans repas » (cf. psc_cantine_sans_repas_convert)
 * et sa journée complète se facture au forfait sans repas. Hors période,
 * rien ne change : poser le statut en octobre ne modifie ni la résolution
 * ni la facture des jours de septembre.
 */
class Psc_Sans_Repas {

    /** @var array<int, array> Périodes par enfant, cache par requête. */
    private static $cache = array();

    public static function flush_cache() {
        self::$cache = array();
    }

    /**
     * Périodes d'une liste d'enfants, en une requête.
     *
     * @return array<int, array> {child_id => [{debut, fin}]}
     */
    public static function periods(array $child_ids) {
        $child_ids = array_values(array_unique(array_filter(array_map('intval', $child_ids))));
        $missing = array_values(array_filter($child_ids, function ($c) { return !array_key_exists($c, self::$cache); }));
        if ($missing && Psc_Tarifs::ready()) {
            global $wpdb;
            foreach ($missing as $cid) self::$cache[$cid] = array();
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT child_id, debut, fin FROM ' . psc_table('sans_repas') . ' WHERE child_id IN (' . implode(',', array_fill(0, count($missing), '%d')) . ') ORDER BY child_id, debut',
                $missing
            ));
            foreach ((array) $rows as $r) {
                self::$cache[(int) $r->child_id][] = array('debut' => $r->debut, 'fin' => $r->fin);
            }
        } else {
            foreach ($missing as $cid) self::$cache[$cid] = array();
        }
        $out = array();
        foreach ($child_ids as $cid) $out[$cid] = self::$cache[$cid];
        return $out;
    }

    /** L'enfant est-il « cantine sans repas » à cette date (aujourd'hui par défaut) ? */
    public static function on($child_id, $date = null) {
        $child_id = (int) $child_id;
        if (!$child_id) return false;
        $p = self::periods(array($child_id));
        return psc_period_contains($p[$child_id], $date ?: psc_today());
    }

    /**
     * Pose ou lève le statut à partir d'une date. Le passé avant $from n'est
     * jamais réécrit (cf. psc_periods_apply).
     *
     * @return array|WP_Error Périodes avant / après, pour le journal d'audit.
     */
    public static function set($child_id, $on, $from) {
        global $wpdb;
        $child_id = (int) $child_id;
        $from = psc_valid_date($from);
        if (!$child_id || !$from) return new WP_Error('invalid', __('Date invalide.', 'periscolaire-registration'));

        $t = psc_table('sans_repas');
        $before = self::periods(array($child_id))[$child_id];
        $after = psc_periods_apply($before, (bool) $on, $from);

        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->delete($t, array('child_id' => $child_id)) !== false;
        foreach ($after as $p) {
            $ok = $ok && $wpdb->insert($t, array(
                'child_id'   => $child_id,
                'debut'      => $p['debut'],
                'fin'        => $p['fin'],
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id() ?: null,
            )) !== false;
        }
        $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        self::flush_cache();
        if (class_exists('Psc_Planning')) Psc_Planning::flush_cache();
        if (!$ok) return new WP_Error('db', __('Enregistrement impossible.', 'periscolaire-registration'));
        return array('avant' => $before, 'apres' => $after);
    }

    /**
     * Ajoute la propriété virtuelle cantine_sans_repas (statut du jour) à
     * des lignes d'enfants : les écrans qui l'affichaient depuis l'ancienne
     * colonne children.cantine_sans_repas la retrouvent inchangée.
     */
    public static function annotate($children, $date = null) {
        $list = is_array($children) ? $children : array($children);
        $ids = array();
        foreach ($list as $c) if (is_object($c) && isset($c->id)) $ids[] = (int) $c->id;
        $periods = self::periods($ids);
        $date = $date ?: psc_today();
        foreach ($list as $c) {
            if (is_object($c) && isset($c->id)) $c->cantine_sans_repas = psc_period_contains($periods[(int) $c->id] ?? array(), $date) ? 1 : 0;
        }
        return $children;
    }
}
