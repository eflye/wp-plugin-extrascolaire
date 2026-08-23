<?php
if (!defined('ABSPATH')) exit;

/**
 * Années scolaires : chapeautent les trimestres, historisent la classe et
 * le statut d'un enfant année par année (table wp_psc_child_school_years,
 * qui fusionne l'ancienne child_assurances — un enfant + une année porte à
 * la fois sa classe, son statut d'inscription, l'acceptation du règlement
 * et son justificatif d'assurance pour cette année-là).
 */
class Psc_School_Years {

    const TRANSIENT_PROMOTION = 'psc_pending_promotion';

    /* ---------------- Lecture ---------------- */

    public static function all() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . psc_table('school_years') . ' ORDER BY date_debut DESC');
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('school_years') . ' WHERE id = %d', $id));
    }

    /** Année scolaire active, ou null si aucune (installation neuve). */
    public static function active() {
        global $wpdb;
        return $wpdb->get_row(
            "SELECT * FROM " . psc_table('school_years') . " WHERE statut = 'active' ORDER BY id DESC LIMIT 1"
        );
    }

    public static function active_id() {
        $active = self::active();
        return $active ? (int) $active->id : 0;
    }

    /**
     * Année scolaire couvrant une date donnée (date_debut <= date <=
     * date_fin), ou l'année active à défaut. Utilisée par la commande
     * fournisseur : elle raisonne sur "la semaine demandée", pas
     * forcément la semaine en cours ni l'année active du site (mêmes
     * scripts de seed E2E que pour les trimestres, isolés sans jamais
     * toucher à l'année active réelle).
     */
    public static function for_date($date) {
        global $wpdb;
        $date = psc_valid_date($date);
        if (!$date) return self::active();

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('school_years') . ' WHERE date_debut <= %s AND date_fin >= %s ORDER BY id DESC LIMIT 1',
            $date, $date
        ));
        return $row ?: self::active();
    }

    /* ---------------- Gestion des années ---------------- */

    public static function create($label, $date_debut, $date_fin) {
        global $wpdb;
        $label = mb_substr(sanitize_text_field($label), 0, 20);
        $date_debut = psc_valid_date($date_debut);
        $date_fin   = psc_valid_date($date_fin);
        if ($label === '' || !$date_debut || !$date_fin) {
            return new WP_Error('invalid', 'Libellé ou dates invalides.');
        }
        if (strtotime($date_fin) < strtotime($date_debut)) {
            return new WP_Error('order_dates', 'La date de fin doit être après la date de début.');
        }

        $wpdb->insert(psc_table('school_years'), array(
            'label'      => $label,
            'date_debut' => $date_debut,
            'date_fin'   => $date_fin,
            'statut'     => 'preparation',
            'created_at' => current_time('mysql'),
        ), array('%s', '%s', '%s', '%s', '%s'));

        return (int) $wpdb->insert_id;
    }

    /** Corrige le libellé ou les dates d'une année existante — mêmes règles de validation que create(). */
    public static function update($id, $label, $date_debut, $date_fin) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $exists = $id ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE id = %d", $id)) : null;
        if (!$exists) return new WP_Error('invalid', 'Année scolaire introuvable.');

        $label = mb_substr(sanitize_text_field($label), 0, 20);
        $date_debut = psc_valid_date($date_debut);
        $date_fin   = psc_valid_date($date_fin);
        if ($label === '' || !$date_debut || !$date_fin) {
            return new WP_Error('invalid', 'Libellé ou dates invalides.');
        }
        if (strtotime($date_fin) < strtotime($date_debut)) {
            return new WP_Error('order_dates', 'La date de fin doit être après la date de début.');
        }

        $wpdb->update($t_years, array(
            'label'      => $label,
            'date_debut' => $date_debut,
            'date_fin'   => $date_fin,
        ), array('id' => $id), array('%s', '%s', '%s'), array('%d'));

        return true;
    }

    /**
     * Supprime une année scolaire. Jamais l'année active (casserait
     * active_id() partout ailleurs dans le plugin) : il faut d'abord en
     * activer une autre. Les trimestres qui la référencent ne sont pas
     * supprimés (ils portent inscriptions, présences, menus — bien plus
     * qu'un simple regroupement par année) : seul leur rattachement
     * (school_year_id, nullable) est détaché. Les lignes enfant × année
     * (classe, statut, justificatif d'assurance de cette année-là) sont en
     * revanche bien à cette année précise et sont purgées avec leur
     * fichier, même principe que Psc_Admin::purge_child().
     */
    public static function delete($id) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $year = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_years WHERE id = %d", $id)) : null;
        if (!$year) return new WP_Error('invalid', 'Année scolaire introuvable.');
        if ($year->statut === 'active') {
            return new WP_Error('active_year', 'Impossible de supprimer l\'année active : activez-en une autre au préalable.');
        }

        $t_cy = psc_table('child_school_years');
        $paths = $wpdb->get_col($wpdb->prepare(
            "SELECT assurance_file_path FROM $t_cy WHERE school_year_id = %d AND assurance_file_path IS NOT NULL",
            $id
        ));
        foreach ($paths as $rel_path) {
            $abs = psc_private_path($rel_path);
            if (file_exists($abs)) {
                @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        $wpdb->delete($t_cy, array('school_year_id' => $id), array('%d'));

        $wpdb->update(psc_table('trimestres'), array('school_year_id' => null), array('school_year_id' => $id), array('%s'), array('%d'));

        $wpdb->delete($t_years, array('id' => $id), array('%d'));

        return true;
    }

    /** Une seule année active à la fois — même principe que les trimestres. */
    public static function activate($id) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE id = %d", $id));
        if (!$exists) return false;

        $wpdb->query("UPDATE $t_years SET statut = 'archivee' WHERE statut = 'active'");
        $wpdb->update($t_years, array('statut' => 'active'), array('id' => $id), array('%s'), array('%d'));
        return true;
    }

    public static function archive($id) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE id = %d", $id));
        if (!$exists) return false;

        $wpdb->update($t_years, array('statut' => 'archivee'), array('id' => $id), array('%s'), array('%d'));
        return true;
    }

    /* ---------------- Inscription enfant x année ---------------- */

    /** Ligne d'inscription d'un enfant pour une année (année active par défaut). */
    public static function enrollment($child_id, $school_year_id = null) {
        global $wpdb;
        $child_id = absint($child_id);
        $school_year_id = $school_year_id ? absint($school_year_id) : self::active_id();
        if (!$child_id || !$school_year_id) return null;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('child_school_years') . ' WHERE child_id = %d AND school_year_id = %d',
            $child_id, $school_year_id
        ));
    }

    /** Classe d'un enfant pour une année (année active par défaut), ou ''. */
    public static function classe_for($child_id, $school_year_id = null) {
        $row = self::enrollment($child_id, $school_year_id);
        return $row && $row->classe ? $row->classe : '';
    }

    /**
     * Crée ou met à jour la ligne d'inscription d'un enfant pour une année
     * (classe, statut, acceptation du règlement) — n'écrase jamais les
     * champs d'assurance, gérés séparément par store_assurance_upload().
     */
    public static function enroll($child_id, $school_year_id, $classe, $statut = 'inscrit', $reglement_accepted_at = null) {
        global $wpdb;
        $child_id = absint($child_id);
        $school_year_id = absint($school_year_id);
        if (!$child_id || !$school_year_id) return false;

        $t_cy = psc_table('child_school_years');
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t_cy WHERE child_id = %d AND school_year_id = %d", $child_id, $school_year_id
        ));

        $data = array(
            'classe' => $classe !== null ? mb_substr($classe, 0, 100) : null,
            'statut' => $statut,
        );
        $format = array('%s', '%s');
        if ($reglement_accepted_at !== null) {
            $data['reglement_accepted_at'] = $reglement_accepted_at;
            $format[] = '%s';
        }

        if ($existing) {
            $wpdb->update($t_cy, $data, array('id' => $existing), $format, array('%d'));
        } else {
            $data['child_id'] = $child_id;
            $data['school_year_id'] = $school_year_id;
            $data['date_inscription'] = current_time('mysql');
            $format = array_merge($format, array('%d', '%d', '%s'));
            $wpdb->insert($t_cy, $data, $format);
        }
        return true;
    }

    /* ---------------- Statut de l'enfant (actif | sorti) ---------------- */

    public static function mark_sorti($child_id) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return false;
        return (bool) $wpdb->update(psc_table('children'), array('statut' => 'sorti'), array('id' => $child_id), array('%s'), array('%d'));
    }

    public static function mark_actif($child_id) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return false;
        return (bool) $wpdb->update(psc_table('children'), array('statut' => 'actif'), array('id' => $child_id), array('%s'), array('%d'));
    }

    /* ---------------- Passage d'année ---------------- */

    /**
     * Calcule le plan de montée de classe : pour chaque enfant actif
     * inscrit à $from_year_id, sa classe actuelle et la classe proposée
     * pour $to_year_id d'après psc_classe_progression(). 'sortie' déclenche
     * la sortie de l'enfant plutôt qu'une inscription. Un enfant sans
     * classe connue se voit proposer une classe déduite de sa date de
     * naissance (psc_classe_for_birthdate()) plutôt que d'être ignoré.
     * N'écrit rien en base — cf. apply_promotion().
     */
    public static function build_promotion_plan($from_year_id, $to_year_id) {
        global $wpdb;
        $from_year_id = absint($from_year_id);
        $to_year_id   = absint($to_year_id);
        $to_year      = self::get($to_year_id);
        $rentree_year = $to_year ? (int) date('Y', strtotime($to_year->date_debut)) : psc_rentree_year();

        // INNER JOIN, volontairement : seuls les enfants réellement inscrits
        // à $from_year_id entrent dans le plan. Un LEFT JOIN inclurait tout
        // enfant actif du site sans lien avec l'année de départ choisie, au
        // risque de le sortir par erreur (classe_actuelle vide -> déduction
        // par date de naissance -> 'sortie' si elle échoue aussi).
        $t_child = psc_table('children');
        $t_cy    = psc_table('child_school_years');
        $children = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, cy.classe AS classe_actuelle
             FROM $t_child c
             INNER JOIN $t_cy cy ON cy.child_id = c.id AND cy.school_year_id = %d
             WHERE c.statut = 'actif'
             ORDER BY c.nom, c.prenom",
            $from_year_id
        ));

        $plan = array();
        foreach ($children as $c) {
            $classe_actuelle = $c->classe_actuelle ?: '';
            $proposition = $classe_actuelle !== '' ? psc_classe_superieure($classe_actuelle) : null;
            if ($proposition === null) {
                $proposition = $c->date_naissance
                    ? psc_classe_for_birthdate($c->date_naissance, $rentree_year)
                    : '';
                if ($proposition === '') $proposition = 'sortie';
            }
            $plan[] = array(
                'child_id'         => (int) $c->id,
                'nom'              => $c->nom,
                'prenom'           => $c->prenom,
                'classe_actuelle'  => $classe_actuelle,
                'classe_proposee'  => $proposition, // code classe, ou 'sortie'
            );
        }
        return $plan;
    }

    /**
     * Applique le plan (éventuellement corrigé ligne par ligne, cf.
     * $overrides = [child_id => classe_ou_'sortie']) : inscrit chaque
     * enfant promu dans $to_year_id, sort les enfants dont la classe
     * proposée est 'sortie'.
     */
    public static function apply_promotion($to_year_id, $plan, $overrides = array()) {
        $to_year_id = absint($to_year_id);
        if (!$to_year_id) return 0;

        $count = 0;
        foreach ($plan as $row) {
            $child_id = (int) $row['child_id'];
            $classe = array_key_exists($child_id, $overrides) ? $overrides[$child_id] : $row['classe_proposee'];

            if ($classe === 'sortie' || $classe === '') {
                self::mark_sorti($child_id);
                continue;
            }
            self::enroll($child_id, $to_year_id, $classe, 'inscrit');
            $count++;
        }
        return $count;
    }

    /* ---------------- Staging (récapitulatif avant confirmation) ---------------- */
    /* Même mécanique que Psc_Admin::pending_close_key() pour la fermeture
       d'un jour de calendrier : on stocke le plan proposé, on ne l'exécute
       qu'après confirmation explicite de la mairie. */

    public static function stage_promotion($from_year_id, $to_year_id, $plan) {
        set_transient(self::TRANSIENT_PROMOTION, array(
            'from_year_id' => (int) $from_year_id,
            'to_year_id'   => (int) $to_year_id,
            'plan'         => $plan,
        ), 15 * MINUTE_IN_SECONDS);
    }

    public static function staged_promotion() {
        $data = get_transient(self::TRANSIENT_PROMOTION);
        return is_array($data) ? $data : null;
    }

    public static function clear_staged_promotion() {
        delete_transient(self::TRANSIENT_PROMOTION);
    }
}
