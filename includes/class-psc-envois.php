<?php
if (!defined('ABSPATH')) exit;

/**
 * Suivi des envois d'e-mails par destinataire : menus de la semaine,
 * factures, commandes fournisseur.
 *
 * Chaque envoi unitaire (un objet, un destinataire, un lot) est RÉSERVÉ
 * en base avant le moindre mail, sous une clé d'idempotence unique. Un
 * clic d'envoi forme un lot : un double clic ou une relance retombent sur
 * les mêmes lignes et ne renvoient rien de ce qui est déjà accepté ; un
 * renvoi volontaire (nouveau clic « Renvoyer ») ouvre un nouveau lot.
 *
 * États : a_envoyer → accepte | echec. « Accepté » veut dire confié au
 * serveur d'envoi (wp_mail() a rendu vrai), pas délivré : savoir si le
 * message est arrivé demanderait de traiter les retours de la messagerie,
 * hors du plugin.
 *
 * Envoi asynchrone : lancer() réserve tout le lot, n'envoie en ligne que
 * les premiers envois (inline_max), puis confie le reste à la tâche
 * ponctuelle psc_envois_lot, qui traite le lot par paquets de LOT_TAILLE
 * et se reprogramme tant qu'il reste des envois jamais tentés. L'écran
 * affiche alors « N envois en cours » au lieu d'attendre le serveur
 * d'envoi (modèle de Psc_Messages::send()).
 *
 * Reprise : une ligne restée a_envoyer (coupure, délai dépassé, requête
 * interrompue) est reprise par la tâche planifiée psc_envois_reprise. Un
 * échec, lui, n'est relancé qu'à la main (relancer_echecs()) : il vient le
 * plus souvent d'une adresse invalide, qu'une relance automatique ne
 * ferait que répéter.
 *
 * Aucune adresse n'est stockée : elle est relue au moment de l'envoi par
 * l'expéditeur du type d'objet (cf. sender()).
 */
class Psc_Envois {

    const A_ENVOYER = 'a_envoyer';
    const ACCEPTE   = 'accepte';
    const ECHEC     = 'echec';

    /** Délai après lequel une ligne a_envoyer est considérée abandonnée. */
    const REPRISE_APRES = 600;

    /** Envois traités par passage de la tâche psc_envois_lot. */
    const LOT_TAILLE = 50;

    /** Envois traités dans la requête qui lance le lot. */
    const INLINE_MAX = 5;

    public static function init() {
        add_action('psc_envois_reprise', array(__CLASS__, 'reprendre'));
        add_action('psc_envois_lot', array(__CLASS__, 'cron_lot'), 10, 2);
        // Un menu n'est « envoyé » que lorsque tout son lot est accepté, y
        // compris quand la fin du lot est reprise par la tâche planifiée.
        add_action('psc_envoi_accepte', function ($type, $objet_id, $famille_id, $lot) {
            if ($type === 'menu' && class_exists('Psc_Menus')) Psc_Menus::sync_sent_at($objet_id, $lot);
        }, 10, 4);
        // Une mise à jour par zip ne réactive pas l'extension : la tâche
        // est (re)programmée ici si elle manque, comme Psc_Retention.
        self::ensure_crons();
    }

    public static function cron_schedules($schedules) {
        $schedules['psc_quart_heure'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Toutes les 15 minutes');
        return $schedules;
    }

    public static function ensure_crons() {
        // L'intervalle doit être connu avant toute programmation, y compris
        // à l'activation, qui précède init().
        if (!has_filter('cron_schedules', array(__CLASS__, 'cron_schedules'))) {
            add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        }
        if (!wp_next_scheduled('psc_envois_reprise')) {
            wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, 'psc_quart_heure', 'psc_envois_reprise');
        }
    }

    /**
     * Identifiant de lot sûr, reçu d'un formulaire (jeton généré à
     * l'affichage) ou généré ici. Un même formulaire soumis deux fois
     * porte le même lot.
     */
    public static function lot($raw = '') {
        $lot = preg_replace('/[^a-zA-Z0-9-]/', '', (string) $raw);
        return $lot !== '' ? substr($lot, 0, 40) : wp_generate_uuid4();
    }

    /**
     * Réserve les envois d'un lot, envoie au plus $inline_max d'entre eux
     * dans la requête courante et confie le reste à la tâche ponctuelle
     * psc_envois_lot (cf. traiter_lot()).
     *
     * @param string     $type        'menu' | 'facture' | 'commande_fournisseur' | 'fermeture'
     * @param int        $objet_id
     * @param string     $lot         cf. lot()
     * @param array|null $famille_ids Destinataires ; null = un seul envoi sans foyer (fournisseur).
     * @param int|null   $inline_max  Envois tentés tout de suite (null = INLINE_MAX, 0 = aucun).
     * @return array Bilan du lot, cf. bilan() : a_envoyer > 0 = envois en cours.
     */
    public static function lancer($type, $objet_id, $lot, $famille_ids = null, $inline_max = null) {
        $objet_id = (int) $objet_id;
        $inline_max = $inline_max === null ? self::INLINE_MAX : max(0, (int) $inline_max);
        $targets = $famille_ids === null ? array(null) : array_values(array_unique(array_map('intval', (array) $famille_ids)));
        foreach ($targets as $famille_id) {
            self::reserver($type, $objet_id, $lot, $famille_id);
        }
        if ($inline_max > 0) {
            self::traiter($type, $objet_id, $lot, array(self::A_ENVOYER), $inline_max);
        }
        $bilan = self::bilan($type, $objet_id, $lot);
        if ($bilan[self::A_ENVOYER] > 0) {
            self::planifier($type, $lot);
        }
        return $bilan;
    }

    /**
     * Programme le traitement asynchrone d'un lot (une seule tâche en
     * attente par type et lot).
     */
    public static function planifier($type, $lot, $delai = 5) {
        $args = array((string) $type, (string) $lot);
        if (!wp_next_scheduled('psc_envois_lot', $args)) {
            wp_schedule_single_event(time() + max(0, (int) $delai), 'psc_envois_lot', $args);
        }
    }

    /** Point d'entrée de la tâche ponctuelle psc_envois_lot. */
    public static function cron_lot($type, $lot) {
        self::traiter_lot((string) $type, (string) $lot);
    }

    /**
     * Tâche psc_envois_lot : traite un paquet d'envois jamais tentés du lot
     * (tous objets confondus — un envoi groupé de factures partage un même
     * lot), puis se reprogramme s'il en reste. Une ligne déjà tentée et
     * restée a_envoyer (processus interrompu) relève de reprendre() : elle
     * n'est pas rejouée en boucle ici.
     *
     * Appelée aussi en ligne, avec une petite $limite, par un envoi groupé
     * qui a réservé ses envois sans les tenter (lancer(..., 0)).
     *
     * @param string   $type
     * @param string   $lot
     * @param int|null $limite Envois tentés par passage (null = LOT_TAILLE).
     * @return int Nombre d'envois tentés.
     */
    public static function traiter_lot($type, $lot, $limite = null) {
        global $wpdb;
        $t = psc_table('envois');
        $lot = self::lot($lot);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE objet_type = %s AND lot = %s AND statut = %s AND tentatives = 0 ORDER BY id LIMIT %d",
            (string) $type, $lot, self::A_ENVOYER, $limite === null ? self::LOT_TAILLE : max(1, (int) $limite)
        ));
        foreach ((array) $rows as $row) {
            self::tenter($row);
        }
        $reste = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE objet_type = %s AND lot = %s AND statut = %s AND tentatives = 0",
            (string) $type, $lot, self::A_ENVOYER
        ));
        if ($reste > 0) {
            // L'événement courant est déjà retiré de la file : on peut reprogrammer.
            self::planifier($type, $lot, 5);
        }
        return count((array) $rows);
    }

    /**
     * Bilan d'un lot tous objets confondus (envoi groupé de factures) :
     * ['lot', 'total', 'accepte', 'echec', 'a_envoyer'].
     */
    public static function bilan_lot($type, $lot) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT statut, COUNT(*) AS n FROM ' . psc_table('envois') . ' WHERE objet_type = %s AND lot = %s GROUP BY statut',
            (string) $type, (string) $lot
        ));
        $bilan = array('lot' => (string) $lot, 'total' => 0, self::ACCEPTE => 0, self::ECHEC => 0, self::A_ENVOYER => 0);
        foreach ((array) $rows as $r) {
            $bilan[$r->statut] = (int) $r->n;
            $bilan['total'] += (int) $r->n;
        }
        return $bilan;
    }

    /**
     * Relance les seuls échecs du dernier lot d'un objet : les premiers en
     * ligne, le reste par la tâche psc_envois_lot.
     */
    public static function relancer_echecs($type, $objet_id) {
        global $wpdb;
        $lot = self::dernier_lot($type, $objet_id);
        if ($lot === null) return self::bilan_vide();
        $debut = gmdate('Y-m-d H:i:s');
        self::traiter($type, (int) $objet_id, $lot, array(self::ECHEC), self::INLINE_MAX);
        // Échecs non tentés ci-dessus remis en file, comme jamais tentés :
        // la tâche psc_envois_lot les reprend. Ceux qui viennent d'échouer
        // à nouveau restent en échec.
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . psc_table('envois') . ' SET statut = %s, tentatives = 0, updated_at = %s WHERE objet_type = %s AND objet_id = %d AND lot = %s AND statut = %s AND updated_at < %s',
            self::A_ENVOYER, gmdate('Y-m-d H:i:s'), $type, (int) $objet_id, $lot, self::ECHEC, $debut
        ));
        $bilan = self::bilan($type, (int) $objet_id, $lot);
        if ($bilan[self::A_ENVOYER] > 0) self::planifier($type, $lot);
        return $bilan;
    }

    /**
     * Bilan d'un lot (le dernier de l'objet si $lot est null) :
     * ['lot', 'total', 'accepte', 'echec', 'a_envoyer'].
     */
    public static function bilan($type, $objet_id, $lot = null) {
        global $wpdb;
        if ($lot === null) $lot = self::dernier_lot($type, $objet_id);
        if ($lot === null) return self::bilan_vide();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT statut, COUNT(*) AS n FROM ' . psc_table('envois') . ' WHERE objet_type = %s AND objet_id = %d AND lot = %s GROUP BY statut',
            $type, (int) $objet_id, $lot
        ));
        $bilan = array('lot' => $lot, 'total' => 0, self::ACCEPTE => 0, self::ECHEC => 0, self::A_ENVOYER => 0);
        foreach ((array) $rows as $r) {
            $bilan[$r->statut] = (int) $r->n;
            $bilan['total'] += (int) $r->n;
        }
        return $bilan;
    }

    /** Bilans des derniers lots d'une liste d'objets, en une requête. [objet_id => bilan]. */
    public static function bilans($type, array $objet_ids) {
        global $wpdb;
        $objet_ids = array_values(array_unique(array_filter(array_map('intval', $objet_ids))));
        if (!$objet_ids) return array();
        $t = psc_table('envois');
        $ph = implode(',', array_fill(0, count($objet_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.objet_id, e.lot, e.statut, COUNT(*) AS n FROM $t e
             INNER JOIN (SELECT objet_id, MAX(id) AS last_id FROM $t WHERE objet_type = %s AND objet_id IN ($ph) GROUP BY objet_id) d
                 ON d.objet_id = e.objet_id
             INNER JOIN $t l ON l.id = d.last_id AND l.lot = e.lot
             WHERE e.objet_type = %s
             GROUP BY e.objet_id, e.lot, e.statut",
            array_merge(array($type), $objet_ids, array($type))
        ));
        $out = array();
        foreach ((array) $rows as $r) {
            $id = (int) $r->objet_id;
            if (!isset($out[$id])) $out[$id] = array('lot' => $r->lot, 'total' => 0, self::ACCEPTE => 0, self::ECHEC => 0, self::A_ENVOYER => 0);
            $out[$id][$r->statut] = (int) $r->n;
            $out[$id]['total'] += (int) $r->n;
        }
        return $out;
    }

    /**
     * Tâche planifiée : reprend les envois restés a_envoyer au-delà du
     * délai (requête interrompue, serveur d'envoi injoignable).
     */
    public static function reprendre() {
        global $wpdb;
        $seuil = gmdate('Y-m-d H:i:s', time() - self::REPRISE_APRES);
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('envois') . ' WHERE statut = %s AND updated_at < %s ORDER BY id LIMIT 50',
            self::A_ENVOYER, $seuil
        ));
        foreach ((array) $rows as $row) {
            self::tenter($row);
        }
        return count((array) $rows);
    }

    /** Cause du dernier échec d'un lot (code, jamais une adresse), ou null. */
    public static function derniere_erreur($type, $objet_id, $lot) {
        global $wpdb;
        $err = $wpdb->get_var($wpdb->prepare(
            'SELECT erreur FROM ' . psc_table('envois') . ' WHERE objet_type = %s AND objet_id = %d AND lot = %s AND statut = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
            $type, (int) $objet_id, $lot, self::ECHEC
        ));
        return $err === null ? null : (string) $err;
    }

    /* ------------------------------------------------------------------ */

    private static function bilan_vide() {
        return array('lot' => null, 'total' => 0, self::ACCEPTE => 0, self::ECHEC => 0, self::A_ENVOYER => 0);
    }

    private static function dernier_lot($type, $objet_id) {
        global $wpdb;
        $lot = $wpdb->get_var($wpdb->prepare(
            'SELECT lot FROM ' . psc_table('envois') . ' WHERE objet_type = %s AND objet_id = %d ORDER BY id DESC LIMIT 1',
            $type, (int) $objet_id
        ));
        return $lot === null ? null : (string) $lot;
    }

    private static function cle($type, $objet_id, $lot, $famille_id) {
        return $type . ':' . (int) $objet_id . ':' . $lot . ':' . ($famille_id === null ? '-' : (int) $famille_id);
    }

    /** Réservation idempotente : la clé unique absorbe un second passage. */
    private static function reserver($type, $objet_id, $lot, $famille_id) {
        global $wpdb;
        $t   = psc_table('envois');
        $now = gmdate('Y-m-d H:i:s');
        $cle = self::cle($type, $objet_id, $lot, $famille_id);
        if ($famille_id === null) {
            return $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $t (objet_type, objet_id, lot, famille_id, cle, statut, tentatives, created_at, updated_at)
                 VALUES (%s, %d, %s, NULL, %s, %s, 0, %s, %s)",
                $type, (int) $objet_id, $lot, $cle, self::A_ENVOYER, $now, $now
            ));
        }
        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t (objet_type, objet_id, lot, famille_id, cle, statut, tentatives, created_at, updated_at)
             VALUES (%s, %d, %s, %d, %s, %s, 0, %s, %s)",
            $type, (int) $objet_id, $lot, (int) $famille_id, $cle, self::A_ENVOYER, $now, $now
        ));
    }

    private static function traiter($type, $objet_id, $lot, array $statuts, $limit = 0) {
        global $wpdb;
        $ph = implode(',', array_fill(0, count($statuts), '%s'));
        $sql = 'SELECT * FROM ' . psc_table('envois') . " WHERE objet_type = %s AND objet_id = %d AND lot = %s AND statut IN ($ph) ORDER BY id";
        $args = array_merge(array($type, (int) $objet_id, $lot), $statuts);
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $args[] = (int) $limit;
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $args));
        foreach ((array) $rows as $row) {
            self::tenter($row);
        }
    }

    /**
     * Une tentative sur une ligne : réclamation atomique (verrou optimiste
     * sur le compteur de tentatives, qu'un autre processus aurait déjà
     * incrémenté), envoi, puis enregistrement du résultat. Une ligne déjà
     * acceptée n'est jamais renvoyée.
     */
    private static function tenter($row) {
        global $wpdb;
        $t = psc_table('envois');
        $now = gmdate('Y-m-d H:i:s');
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $t SET tentatives = tentatives + 1, statut = %s, updated_at = %s WHERE id = %d AND tentatives = %d AND statut <> %s",
            self::A_ENVOYER, $now, (int) $row->id, (int) $row->tentatives, self::ACCEPTE
        ));
        if ((int) $claimed !== 1) return;

        $sender = self::sender($row->objet_type);
        $result = $sender ? call_user_func($sender, (int) $row->objet_id, $row->famille_id === null ? null : (int) $row->famille_id) : new WP_Error('psc_envoi_type', 'Type d’envoi inconnu');

        if ($result === true) {
            $wpdb->update($t,
                array('statut' => self::ACCEPTE, 'erreur' => null, 'accepte_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')),
                array('id' => (int) $row->id)
            );
            do_action('psc_envoi_accepte', $row->objet_type, (int) $row->objet_id, $row->famille_id === null ? null : (int) $row->famille_id, (string) $row->lot);
            return;
        }

        // Cause sans donnée personnelle : un code, jamais une adresse.
        $erreur = is_wp_error($result) ? $result->get_error_code() : 'mail_refuse';
        $wpdb->update($t,
            array('statut' => self::ECHEC, 'erreur' => substr((string) $erreur, 0, 191), 'updated_at' => gmdate('Y-m-d H:i:s')),
            array('id' => (int) $row->id)
        );
    }

    /**
     * Expéditeur d'un type d'objet : callable($objet_id, $famille_id) qui
     * relit ce qu'il faut (adresse comprise) et renvoie true si le mail est
     * accepté, sinon false ou WP_Error. Filtrable pour les tests.
     */
    private static function sender($type) {
        $senders = array(
            'menu'                 => array('Psc_Menus', 'deliver'),
            'facture'              => array('Psc_Invoices', 'deliver'),
            'commande_fournisseur' => array('Psc_Supplier_Orders', 'deliver'),
        );
        $senders = apply_filters('psc_envoi_senders', $senders);
        return isset($senders[$type]) && is_callable($senders[$type]) ? $senders[$type] : null;
    }
}
