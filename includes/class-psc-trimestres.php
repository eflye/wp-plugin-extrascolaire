<?php
if (!defined('ABSPATH')) exit;

/**
 * Trimestres — l'unité opérationnelle du périscolaire.
 *
 * C'est le trimestre qui porte les présences déclarées, donc la
 * facturation. Il était pourtant le seul objet central sans classe : sa
 * logique vivait dans les traitements de formulaire, qui écrivaient
 * directement en base, là où les années scolaires, les menus ou les
 * factures passent tous par leur domaine.
 *
 * « Le trimestre actif » se redéfinissait de son côté à quatre endroits,
 * avec la même requête recopiée. Un seul point décide désormais de ce que
 * cela veut dire.
 */
class Psc_Trimestres {

    /* ---------------- Lecture ---------------- */

    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . psc_table('trimestres') . ' ORDER BY date_debut DESC');
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE id = %d', $id
        ));
    }

    /**
     * Trimestre ouvert aux familles, ou null.
     *
     * Un seul l'est à la fois (cf. activate()). Le tri par identifiant
     * décroissant n'est qu'un garde-fou : si deux lignes se retrouvaient
     * actives, la plus récente l'emporte plutôt qu'un choix arbitraire.
     */
    public static function active() {
        global $wpdb;
        return $wpdb->get_row(
            'SELECT * FROM ' . psc_table('trimestres') . ' WHERE active = 1 ORDER BY id DESC LIMIT 1'
        );
    }

    public static function active_id() {
        $t = self::active();
        return $t ? (int) $t->id : 0;
    }

    /** Nombre de présences déclarées, par trimestre — en une requête. */
    public static function registration_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT trimestre_id, COUNT(*) AS n FROM ' . psc_table('registrations') . ' GROUP BY trimestre_id'
        );
        $out = array();
        foreach ($rows as $r) {
            $out[(int) $r->trimestre_id] = (int) $r->n;
        }
        return $out;
    }

    /** Présences déclarées qui tomberaient hors des bornes indiquées. */
    public static function registrations_outside_range($trimestre_id, $debut, $fin) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . psc_table('registrations') . '
             WHERE trimestre_id = %d AND (jour_date < %s OR jour_date > %s)',
            absint($trimestre_id), $debut, $fin
        ));
    }

    /* ---------------- Écriture ---------------- */

    /**
     * Règles communes à la création et à la modification.
     *
     * Les codes d'erreur sont ceux que l'écran sait traduire : la couche
     * HTTP les relaie sans avoir à connaître le détail des règles.
     *
     * @return array{0:string,1:string,2:string}|WP_Error
     */
    protected static function validated($label, $date_debut, $date_fin) {
        $label = mb_substr(sanitize_text_field($label), 0, 190);
        $debut = psc_valid_date($date_debut);
        $fin   = psc_valid_date($date_fin);

        if ($label === '' || !$debut || !$fin) {
            return new WP_Error('invalid_dates', __('Libellé ou dates invalides.', 'periscolaire-registration'));
        }
        if (strtotime($fin) < strtotime($debut)) {
            return new WP_Error('order_dates', __('La date de fin doit être postérieure à la date de début.', 'periscolaire-registration'));
        }
        // Garde-fou : une faute de frappe sur l'année générerait des
        // millions de jours de calendrier.
        if ((strtotime($fin) - strtotime($debut)) / DAY_IN_SECONDS > psc_max_trimestre_days()) {
            return new WP_Error('too_long', __('La période est trop longue.', 'periscolaire-registration'));
        }

        return array($label, $debut, $fin);
    }

    /** @return int|WP_Error Identifiant du trimestre créé. */
    public static function create($label, $school_year_id, $date_debut, $date_fin) {
        global $wpdb;

        $checked = self::validated($label, $date_debut, $date_fin);
        if (is_wp_error($checked)) return $checked;
        list($label, $debut, $fin) = $checked;

        $wpdb->insert(psc_table('trimestres'), array(
            'label'          => $label,
            'date_debut'     => $debut,
            'date_fin'       => $fin,
            'active'         => 0,
            'school_year_id' => absint($school_year_id) ?: null,
        ), array('%s', '%s', '%s', '%d', '%d'));

        $id = (int) $wpdb->insert_id;
        Psc_Installer::generate_calendar_days($id, $debut, $fin);
        return $id;
    }

    /**
     * Corrige le libellé, les dates ou l'année de rattachement.
     *
     * Le calendrier est régénéré sur la nouvelle période, puis ce qui en
     * est sorti est retiré. L'ordre compte : generate_calendar_days()
     * ajoute des jours sans jamais en retirer, si bien qu'une purge menée
     * avant porterait sur les anciennes bornes.
     *
     * Rétrécir un trimestre supprime donc des présences déjà déclarées.
     * C'est délibéré — elles ne tomberaient plus dans aucune période
     * valide tout en restant facturées — mais l'appelant doit avoir fait
     * confirmer : registrations_outside_range() lui en donne le nombre
     * exact avant d'appeler.
     *
     * @return true|WP_Error
     */
    public static function update($id, $label, $school_year_id, $date_debut, $date_fin) {
        global $wpdb;

        $id = absint($id);
        if (!$id || !self::get($id)) {
            return new WP_Error('invalid', __('Trimestre introuvable.', 'periscolaire-registration'));
        }

        $checked = self::validated($label, $date_debut, $date_fin);
        if (is_wp_error($checked)) return $checked;
        list($label, $debut, $fin) = $checked;

        $wpdb->update(psc_table('trimestres'), array(
            'label'          => $label,
            'date_debut'     => $debut,
            'date_fin'       => $fin,
            'school_year_id' => absint($school_year_id) ?: null,
        ), array('id' => $id), array('%s', '%s', '%s', '%d'), array('%d'));

        Psc_Installer::generate_calendar_days($id, $debut, $fin);

        foreach (array('registrations', 'calendar_days') as $table) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . psc_table($table) . '
                 WHERE trimestre_id = %d AND (jour_date < %s OR jour_date > %s)',
                $id, $debut, $fin
            ));
        }

        return true;
    }

    /** Un seul trimestre actif à la fois — même principe que l'année scolaire. */
    public static function activate($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id || !self::get($id)) return false;

        $t = psc_table('trimestres');
        $wpdb->query("UPDATE $t SET active = 0");
        $wpdb->update($t, array('active' => 1), array('id' => $id), array('%d'), array('%d'));
        return true;
    }

    /**
     * Supprime un trimestre, ses jours de calendrier et les présences
     * déclarées dessus.
     *
     * Jamais le trimestre actif : il faut d'abord en activer un autre.
     * Autorisé en revanche lorsque des familles ont déjà déclaré des
     * présences — la perte est réelle, et c'est à l'appelant de l'avoir
     * fait confirmer avant d'arriver ici.
     *
     * @return true|WP_Error
     */
    public static function delete($id) {
        global $wpdb;

        $trimestre = self::get($id);
        if (!$trimestre) {
            return new WP_Error('invalid', __('Trimestre introuvable.', 'periscolaire-registration'));
        }
        if ($trimestre->active) {
            return new WP_Error('active_trimestre', __('Impossible de supprimer le trimestre actif.', 'periscolaire-registration'));
        }

        $id = (int) $trimestre->id;
        $wpdb->delete(psc_table('registrations'), array('trimestre_id' => $id), array('%d'));
        $wpdb->delete(psc_table('calendar_days'), array('trimestre_id' => $id), array('%d'));
        $wpdb->delete(psc_table('trimestres'), array('id' => $id), array('%d'));
        return true;
    }
}
