<?php
if (!defined('ABSPATH')) exit;

/**
 * Menus de cantine : une ligne par semaine (identifiée par son lundi),
 * saisie par la mairie et poussée aux familles par e-mail sur action
 * manuelle de l'admin — jamais automatique, jamais de cron. Le routage
 * HTTP (nonces, permissions, redirections) vit dans Psc_Admin, comme pour
 * Psc_Invoices et Psc_Requests ; cette classe ne fait que la logique
 * métier.
 */
class Psc_Menus {

    const JOURS = array('lundi', 'mardi', 'jeudi', 'vendredi');

    /** Décalage en jours depuis le lundi de la semaine. */
    const JOUR_OFFSETS = array('lundi' => 0, 'mardi' => 1, 'jeudi' => 3, 'vendredi' => 4);

    public static function jour_labels() {
        return array(
            'lundi'     => 'Lundi',
            'mardi'     => 'Mardi',
            'jeudi'     => 'Jeudi',
            'vendredi'  => 'Vendredi',
        );
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menus') . ' WHERE id = %d', $id));
    }

    public static function get_by_week($monday) {
        global $wpdb;
        $monday = psc_valid_date($monday);
        if (!$monday) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('menus') . ' WHERE semaine_debut = %s', $monday));
    }

    public static function recent($limit = 12) {
        global $wpdb;
        $limit = max(1, min(52, (int) $limit));
        return $wpdb->get_results('SELECT * FROM ' . psc_table('menus') . " ORDER BY semaine_debut DESC LIMIT $limit");
    }

    /** Voir psc_open_days() — un jour fermé n'a pas de menu à saisir. */
    public static function open_days($monday) {
        return psc_open_days($monday);
    }

    /** Voir psc_next_open_week(). */
    public static function next_open_week($from_date) {
        return psc_next_open_week($from_date);
    }

    /**
     * Crée ou met à jour le menu d'une semaine. Une semaine = une seule
     * ligne : si $id ne correspond pas à la semaine visée mais qu'une
     * ligne existe déjà pour cette semaine-là, c'est CETTE ligne qui est
     * mise à jour — évite un doublon si l'admin déplace la date d'un menu
     * en édition vers une semaine déjà saisie par ailleurs.
     *
     * Renvoie l'id du menu, ou un WP_Error si la semaine est invalide.
     */
    public static function save($id, $semaine_debut, array $jours) {
        global $wpdb;
        $t = psc_table('menus');

        $semaine = psc_week_start($semaine_debut);
        if (!$semaine) {
            return new WP_Error('psc_invalid_week', 'Date de semaine invalide.');
        }

        // Un jour fermé (vacances, férié, fermeture ponctuelle) n'a jamais de
        // contenu, quoi que le formulaire ait pu envoyer — appliqué ici aussi
        // (pas seulement à l'affichage) car c'est la seule garantie fiable.
        $open   = self::open_days($semaine);
        $data   = array('semaine_debut' => $semaine, 'updated_at' => current_time('mysql'));
        $format = array('%s', '%s');
        foreach (self::JOURS as $jour) {
            $data[$jour] = (isset($open[$jour]) && isset($jours[$jour]))
                ? mb_substr(sanitize_textarea_field($jours[$jour]), 0, 2000)
                : '';
            $format[]    = '%s';
        }

        $existing = self::get_by_week($semaine);
        if ($existing) {
            $id = (int) $existing->id;
        }
        $id = absint($id);

        if ($id) {
            $wpdb->update($t, $data, array('id' => $id), $format, array('%d'));
        } else {
            $data['created_at'] = current_time('mysql');
            $format[]            = '%s';
            $wpdb->insert($t, $data, $format);
            $id = (int) $wpdb->insert_id;
        }

        return $id;
    }

    public static function delete($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return false;
        return $wpdb->delete(psc_table('menus'), array('id' => $id), array('%d'));
    }

    /**
     * Familles concernées par l'envoi : parents actifs ayant au moins un
     * enfant actif. Les préférences sans_porc/vegan ne filtrent RIEN ici :
     * le menu envoyé est le même pour tout le monde, ces informations
     * servent la cuisine (visible côté admin > Enfants), pas ce message.
     */
    public static function recipients() {
        global $wpdb;
        $t_parent = psc_table('parents');
        $t_child  = psc_table('children');
        return $wpdb->get_results(
            "SELECT DISTINCT p.* FROM $t_parent p
             INNER JOIN $t_child c ON c.parent_id = p.id
             WHERE p.active = 1 AND c.statut = 'actif'"
        );
    }

    /**
     * Envoie le menu à toutes les familles concernées et marque sent_at.
     * Renvoie le nombre d'e-mails effectivement envoyés.
     */
    public static function send($menu) {
        global $wpdb;

        $sent_count = 0;
        foreach (self::recipients() as $parent) {
            if (Psc_Mailer::send_weekly_menu($parent, $menu)) {
                $sent_count++;
            }
        }

        $wpdb->update(
            psc_table('menus'),
            array('sent_at' => current_time('mysql')),
            array('id' => (int) $menu->id),
            array('%s'),
            array('%d')
        );

        return $sent_count;
    }
}
