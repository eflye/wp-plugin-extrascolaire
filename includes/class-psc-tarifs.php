<?php
if (!defined('ABSPATH')) exit;

/**
 * Grille des tarifs datés (P1-16, schéma 4.17.0) — table psc_tarifs.
 *
 * Un tarif vaut à partir de son début, jusqu'au début du tarif suivant du
 * même code (fin tenue à jour ici, pour la lecture humaine et les exports) :
 * changer un prix en octobre ne change rien aux jours de septembre, déjà
 * facturés ou non. La facture d'un mois applique à chaque jour le tarif de
 * ce jour (psc_billing_tariffs($date)).
 *
 * Montants en centimes. Seul un tarif pas encore entré en vigueur peut être
 * supprimé : ceux du passé ont servi à facturer.
 */
class Psc_Tarifs {

    /** @var array|null Lignes de la grille, cache par requête. */
    private static $rows = null;

    /** La table est-elle en place (mise à jour 4.17.0 passée) ? */
    public static function ready() {
        $version = (string) get_option('psc_db_version', '');
        return $version !== '' && version_compare($version, '4.17.0', '>=');
    }

    /** Toutes les lignes, triées par code puis début. */
    public static function rows() {
        if (self::$rows === null) {
            global $wpdb;
            $rows = $wpdb->get_results('SELECT id, code, prix_centimes, debut, fin, created_at, created_by FROM ' . psc_table('tarifs') . ' ORDER BY code, debut', ARRAY_A);
            $rows = is_array($rows) ? $rows : array();
            // La fin se déduit du début suivant du même code : elle reste
            // juste même après une écriture directe en base.
            foreach ($rows as $i => $r) {
                $next = $rows[$i + 1] ?? null;
                $rows[$i]['fin'] = ($next && $next['code'] === $r['code']) ? gmdate('Y-m-d', strtotime($next['debut'] . ' -1 day')) : null;
            }
            self::$rows = $rows;
        }
        return self::$rows;
    }

    public static function flush_cache() {
        self::$rows = null;
    }

    /** Lignes d'un code, du plus ancien au plus récent. */
    public static function history($code) {
        return array_values(array_filter(self::rows(), function ($r) use ($code) { return $r['code'] === $code; }));
    }

    /** Codes gérés : ceux de la grille facturable. */
    public static function codes() {
        return array_keys(psc_billing_tariffs());
    }

    /**
     * Enregistre un tarif à partir d'une date (remplace celui qui commence
     * le même jour), puis recalcule les fins du code.
     *
     * @return true|WP_Error
     */
    public static function set($code, $centimes, $debut) {
        global $wpdb;
        $code = (string) $code;
        $debut = psc_valid_date($debut);
        if (!in_array($code, self::codes(), true)) return new WP_Error('code', __('Prestation inconnue.', 'periscolaire-registration'));
        if (!$debut) return new WP_Error('date', __('Date de début invalide.', 'periscolaire-registration'));
        $centimes = max(0, min(100000, (int) $centimes));

        $t = psc_table('tarifs');
        $ok = $wpdb->query($wpdb->prepare(
            "INSERT INTO $t (code, prix_centimes, debut, fin, created_at, created_by) VALUES (%s, %d, %s, NULL, %s, %d)
             ON DUPLICATE KEY UPDATE prix_centimes = VALUES(prix_centimes), created_at = VALUES(created_at), created_by = VALUES(created_by)",
            $code, $centimes, $debut, current_time('mysql'), get_current_user_id()
        ));
        if ($ok === false) return new WP_Error('db', __('Enregistrement impossible.', 'periscolaire-registration'));
        self::refresh_ends($code);
        return true;
    }

    /**
     * Supprime un tarif pas encore entré en vigueur.
     *
     * @return true|WP_Error
     */
    public static function delete($id) {
        global $wpdb;
        $t = psc_table('tarifs');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id));
        if (!$row) return new WP_Error('missing', __('Tarif introuvable.', 'periscolaire-registration'));
        if ($row->debut <= psc_today()) return new WP_Error('past', __('Un tarif déjà entré en vigueur ne peut pas être supprimé : il a servi à facturer.', 'periscolaire-registration'));
        if (false === $wpdb->delete($t, array('id' => (int) $id))) return new WP_Error('db', __('Suppression impossible.', 'periscolaire-registration'));
        self::refresh_ends($row->code);
        return true;
    }

    /** fin = veille du début suivant ; la dernière ligne reste sans fin. */
    private static function refresh_ends($code) {
        global $wpdb;
        $t = psc_table('tarifs');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, debut, fin FROM $t WHERE code = %s ORDER BY debut", $code));
        $count = count($rows);
        foreach ($rows as $i => $r) {
            $fin = $i + 1 < $count ? gmdate('Y-m-d', strtotime($rows[$i + 1]->debut . ' -1 day')) : null;
            if ($fin !== $r->fin) $wpdb->update($t, array('fin' => $fin), array('id' => (int) $r->id));
        }
        self::flush_cache();
    }
}
