<?php
if (!defined('ABSPATH')) exit;

/**
 * Années scolaires : une ligne de psc_school_years par année, identifiée
 * par sa clé '2026-2027', porte le dossier (statut de l'année) et le
 * calendrier (lu par Psc_School_Year). La classe et le statut d'un enfant
 * sont historisés année par année (psc_child_school_years : classe,
 * statut inscrit | sorti, date de sortie, acceptation du règlement et
 * justificatif d'assurance de cette année-là). Un enfant n'a pas de
 * statut global : il est « actif » s'il est inscrit à l'année considérée.
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
     * scripts de seed E2E, isolés sans jamais
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

    /**
     * Clé d'une année d'après sa date de début : '2026-2027' pour une
     * rentrée entre août 2026 et juillet 2027 (même convention que
     * Psc_School_Year::year_key_for_date()).
     */
    public static function key_for_start($date_debut) {
        $d = psc_valid_date($date_debut);
        if (!$d) return '';
        $y = (int) substr($d, 0, 4);
        if ((int) substr($d, 5, 2) < 8) $y--;
        return $y . '-' . ($y + 1);
    }

    /** Année par sa clé ('2026-2027'), ou null. */
    public static function get_by_key($year_key) {
        global $wpdb;
        $year_key = Psc_School_Year::sanitize_key($year_key);
        if ($year_key === '') return null;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . psc_table('school_years') . ' WHERE year_key = %s', $year_key));
    }

    /**
     * Valide des bornes d'année et en déduit la clé. Une clé déjà prise par
     * une autre année est refusée : une année par rentrée.
     *
     * @return string|WP_Error La clé.
     */
    protected static function checked_key($date_debut, $date_fin, $except_id = 0) {
        $date_debut = psc_valid_date($date_debut);
        $date_fin   = psc_valid_date($date_fin);
        if (!$date_debut || !$date_fin) {
            return new WP_Error('invalid', __('Dates invalides.', 'periscolaire-registration'));
        }
        if (strtotime($date_fin) < strtotime($date_debut)) {
            return new WP_Error('order_dates', __('La date de fin doit être après la date de début.', 'periscolaire-registration'));
        }
        $key = self::key_for_start($date_debut);
        $other = self::get_by_key($key);
        if ($other && (int) $other->id !== (int) $except_id) {
            return new WP_Error('year_exists', sprintf(
                /* translators: %s: clé d'année, ex. 2026-2027 */
                __('L’année %s existe déjà : une seule année par rentrée.', 'periscolaire-registration'),
                $key
            ));
        }
        return $key;
    }

    /** @return int|WP_Error Identifiant de l'année créée (en préparation). */
    public static function create($date_debut, $date_fin) {
        global $wpdb;
        $key = self::checked_key($date_debut, $date_fin);
        if (is_wp_error($key)) return $key;

        $now = current_time('mysql');
        $inserted = $wpdb->insert(psc_table('school_years'), array(
            'year_key'   => $key,
            'date_debut' => psc_valid_date($date_debut),
            'date_fin'   => psc_valid_date($date_fin),
            'statut'     => 'preparation',
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%s', '%s', '%s', '%s', '%s', '%s'));
        if (false === $inserted) {
            return new WP_Error('psc_year_create', __('L’année scolaire n’a pas pu être créée.', 'periscolaire-registration'));
        }
        $id = (int) $wpdb->insert_id;
        Psc_School_Year::seed_holidays($key, psc_valid_date($date_debut), psc_valid_date($date_fin));
        Psc_School_Year::flush_cache();
        return $id;
    }

    /**
     * Année de la rentrée de $date_debut : créée si elle manque, recalée
     * sur ces dates sinon. Pour le peuplement des environnements de test et
     * les scripts de vérification, qui se partagent les années d'une même
     * rentrée (une seule année par rentrée).
     *
     * @return int|WP_Error Identifiant de l'année.
     */
    public static function ensure($date_debut, $date_fin) {
        $existing = self::get_by_key(self::key_for_start($date_debut));
        if (!$existing) return self::create($date_debut, $date_fin);
        $updated = self::update((int) $existing->id, $date_debut, $date_fin);
        return is_wp_error($updated) ? $updated : (int) $existing->id;
    }

    /**
     * Corrige les dates d'une année existante — mêmes règles que create().
     * Si la rentrée change, la clé suit, et avec elle rythmes et fériés
     * (clés étrangères ON UPDATE CASCADE).
     */
    public static function update($id, $date_debut, $date_fin) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $exists = $id ? $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_years WHERE id = %d", $id)) : null;
        if (!$exists) return new WP_Error('invalid', __('Année scolaire introuvable.', 'periscolaire-registration'));

        $key = self::checked_key($date_debut, $date_fin, $id);
        if (is_wp_error($key)) return $key;

        $updated = $wpdb->update($t_years, array(
            'year_key'   => $key,
            'date_debut' => psc_valid_date($date_debut),
            'date_fin'   => psc_valid_date($date_fin),
            'updated_at' => current_time('mysql'),
        ), array('id' => $id), array('%s', '%s', '%s', '%s'), array('%d'));
        Psc_School_Year::flush_cache();
        if (false === $updated) {
            return new WP_Error('psc_year_update', __('L’année scolaire n’a pas pu être modifiée.', 'periscolaire-registration'));
        }
        return true;
    }

    /**
     * Supprime une année scolaire. Jamais l'année active (casserait
     * active_id() partout ailleurs dans le plugin) : il faut d'abord en
     * activer une autre. Les lignes enfant × année (classe, statut,
     * justificatif d'assurance de cette année-là) sont bien à cette année
     * précise et sont purgées avec leur fichier, même principe que
     * Psc_Admin::purge_child().
     */
    public static function delete($id) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        $year = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_years WHERE id = %d", $id)) : null;
        if (!$year) return new WP_Error('invalid', __('Année scolaire introuvable.', 'periscolaire-registration'));
        if ($year->statut === 'active') {
            return new WP_Error('active_year', __('Impossible de supprimer l\'année active : activez-en une autre au préalable.', 'periscolaire-registration'));
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

        $wpdb->delete($t_years, array('id' => $id), array('%d'));
        Psc_School_Year::flush_cache();

        return true;
    }

    /**
     * Active une année : une seule année active à la fois, tout ou rien.
     *
     * Archiver l'ancienne puis activer la nouvelle se faisait en deux
     * écritures indépendantes : un échec entre les deux laissait le site
     * sans année active (portail des familles vide). Les deux se font
     * désormais dans une transaction, sous verrou des années, et le
     * résultat est vérifié avant validation. Réactiver l'année déjà
     * active ne change rien.
     *
     * Dossier et calendrier sont la même ligne : planning et verrous
     * voient l'année que voient classes et assurances.
     */
    public static function activate($id) {
        global $wpdb;
        $id = absint($id);
        $t_years = psc_table('school_years');
        if (!$id) return false;

        $wpdb->query('START TRANSACTION');
        $rows = $wpdb->get_results("SELECT id, statut FROM $t_years FOR UPDATE");
        $target = null;
        foreach ((array) $rows as $row) {
            if ((int) $row->id === $id) $target = $row;
        }
        if (!$target) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $ok = $wpdb->query($wpdb->prepare("UPDATE $t_years SET statut = 'archivee' WHERE statut = 'active' AND id <> %d", $id)) !== false
            && $wpdb->update($t_years, array('statut' => 'active'), array('id' => $id), array('%s'), array('%d')) !== false;
        $actives = $ok ? $wpdb->get_col("SELECT id FROM $t_years WHERE statut = 'active'") : array();
        if (!$ok || count($actives) !== 1 || (int) $actives[0] !== $id) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        Psc_School_Year::flush_cache();
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
    /**
     * Classes d'une liste d'enfants pour une année (active par défaut), en
     * UNE requête : [child_id => classe]. Les listes (SIDSCM, exports)
     * appelaient classe_for() enfant par enfant.
     */
    public static function classes_for(array $child_ids, $school_year_id = null) {
        global $wpdb;
        $child_ids = array_values(array_unique(array_filter(array_map('intval', $child_ids))));
        $school_year_id = $school_year_id ? absint($school_year_id) : self::active_id();
        $out = array_fill_keys($child_ids, '');
        if (!$child_ids || !$school_year_id) return $out;
        $ph = implode(',', array_fill(0, count($child_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT child_id, classe FROM ' . psc_table('child_school_years') . " WHERE school_year_id = %d AND child_id IN ($ph)",
            array_merge(array($school_year_id), $child_ids)
        ));
        foreach ((array) $rows as $r) {
            $out[(int) $r->child_id] = (string) $r->classe;
        }
        return $out;
    }

    public static function classe_for($child_id, $school_year_id = null) {
        $row = self::enrollment($child_id, $school_year_id);
        return $row && $row->classe ? $row->classe : '';
    }

    /**
     * Crée ou met à jour la ligne d'inscription d'un enfant pour une année
     * (classe, statut, acceptation du règlement) — n'écrase jamais les
     * champs d'assurance, gérés séparément par Psc_Assurances::store_upload().
     */
    public static function enroll($child_id, $school_year_id, $classe, $statut = 'inscrit', $reglement_accepted_at = null, $reglement_version_id = null) {
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
            // Version du règlement approuvée (P2-14) : jamais l'une sans l'autre.
            $data['reglement_version_id'] = $reglement_version_id !== null ? (int) $reglement_version_id : null;
            $format[] = '%d';
        }

        if ($existing) {
            // 0 = aucune ligne modifiée (valeurs identiques) : succès.
            return false !== $wpdb->update($t_cy, $data, array('id' => $existing), $format, array('%d'));
        }

        $data['child_id'] = $child_id;
        $data['school_year_id'] = $school_year_id;
        $data['date_inscription'] = current_time('mysql');
        $format = array_merge($format, array('%d', '%d', '%s'));

        return false !== $wpdb->insert($t_cy, $data, $format);
    }

    /* ---------------- Statut de l'enfant, année par année ---------------- */

    /**
     * Condition SQL « l'enfant $child_column est inscrit (et pas sorti) à
     * l'année $year_id » — le seul sens d'« enfant actif » depuis 4.15.0.
     * $year_id est un entier, interpolé tel quel ; 0 ne correspond à rien.
     */
    public static function inscrit_sql($child_column, $year_id) {
        return sprintf(
            "EXISTS (SELECT 1 FROM %s cyi WHERE cyi.child_id = %s AND cyi.school_year_id = %d AND cyi.statut = 'inscrit')",
            psc_table('child_school_years'), $child_column, (int) $year_id
        );
    }

    /**
     * Même condition pour les années ouvertes aux familles : l'année active
     * et celle en préparation (réinscription, enfant inscrit pour la
     * rentrée).
     */
    public static function inscrit_ouvert_sql($child_column) {
        return sprintf(
            "EXISTS (SELECT 1 FROM %s cyi INNER JOIN %s yi ON yi.id = cyi.school_year_id
                     WHERE cyi.child_id = %s AND cyi.statut = 'inscrit' AND yi.statut IN ('active', 'preparation'))",
            psc_table('child_school_years'), psc_table('school_years'), $child_column
        );
    }

    /** Identifiant de l'année couvrant une date (planning, facturation), ou 0. */
    public static function id_for_date($date) {
        $row = Psc_School_Year::for_date($date);
        return $row && isset($row->id) ? (int) $row->id : 0;
    }

    /** Vrai si l'enfant est inscrit à l'année donnée (l'année active par défaut). */
    public static function is_inscrit($child_id, $school_year_id = null) {
        $row = self::enrollment($child_id, $school_year_id);
        return $row && $row->statut === 'inscrit';
    }

    /** Vrai si l'enfant est inscrit à l'année active ou à celle en préparation. */
    public static function is_inscrit_ouvert($child_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . psc_table('children') . ' c WHERE c.id = %d AND ' . self::inscrit_ouvert_sql('c.id'),
            (int) $child_id
        ));
    }

    /**
     * Sortie d'un enfant pour une année (l'année active par défaut).
     * sorti_le démarre le délai de conservation RGPD (cf. Psc_Retention) :
     * sans cet horodatage, rien ne permettrait de distinguer un enfant
     * sorti hier d'un enfant sorti il y a cinq ans.
     */
    public static function mark_sorti($child_id, $school_year_id = null) {
        global $wpdb;
        $child_id = absint($child_id);
        $school_year_id = $school_year_id ? absint($school_year_id) : self::active_id();
        if (!$child_id || !$school_year_id) return false;
        $row = self::enrollment($child_id, $school_year_id);
        if ($row && $row->statut === 'sorti') return true; // déjà sorti : succès
        if (!$row) {
            return false !== $wpdb->insert(psc_table('child_school_years'), array(
                'child_id' => $child_id, 'school_year_id' => $school_year_id, 'statut' => 'sorti',
                'sorti_le' => current_time('mysql'), 'date_inscription' => current_time('mysql'),
            ));
        }
        return false !== $wpdb->update(
            psc_table('child_school_years'),
            array('statut' => 'sorti', 'sorti_le' => current_time('mysql')),
            array('id' => (int) $row->id), array('%s', '%s'), array('%d')
        );
    }

    /**
     * Réinscription dans l'année (l'année active par défaut) : efface
     * sorti_le pour retirer l'enfant de la file de purge automatique — une
     * famille qui revient avant l'échéance de conservation ne doit pas voir
     * la fiche de son enfant disparaître au passage du cron suivant.
     */
    public static function mark_actif($child_id, $school_year_id = null) {
        global $wpdb;
        $child_id = absint($child_id);
        $school_year_id = $school_year_id ? absint($school_year_id) : self::active_id();
        if (!$child_id || !$school_year_id) return false;
        $row = self::enrollment($child_id, $school_year_id);
        if (!$row) return self::enroll($child_id, $school_year_id, null, 'inscrit');
        return false !== $wpdb->update(
            psc_table('child_school_years'),
            array('statut' => 'inscrit', 'sorti_le' => null),
            array('id' => (int) $row->id), array('%s', '%s'), array('%d')
        );
    }

    /**
     * Retire l'inscription d'un enfant à une année (réinscription décochée
     * par la famille après envoi) : ligne et justificatif de cette année
     * supprimés. L'enfant n'est pas « sorti » : il n'est simplement pas
     * inscrit à cette année-là.
     */
    public static function unenroll($child_id, $school_year_id) {
        global $wpdb;
        $row = self::enrollment($child_id, $school_year_id);
        if (!$row) return true;
        if (false === $wpdb->delete(psc_table('child_school_years'), array('id' => (int) $row->id), array('%d'))) return false;
        if ($row->assurance_file_path) {
            $abs = psc_private_path($row->assurance_file_path);
            if (file_exists($abs)) @unlink($abs); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return true;
    }

    /* ---------------- Passage d'année ---------------- */

    /**
     * Calcule le plan de montée de classe : pour chaque enfant actif
     * inscrit à $from_year_id, sa classe actuelle et la classe proposée
     * pour $to_year_id d'après self::classe_progression(). 'sortie' déclenche
     * la sortie de l'enfant plutôt qu'une inscription. Un enfant sans
     * classe connue se voit proposer une classe déduite de sa date de
     * naissance (self::classe_for_birthdate()) plutôt que d'être ignoré.
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
             WHERE cy.statut = 'inscrit'
             ORDER BY c.nom, c.prenom",
            $from_year_id
        ));

        $plan = array();
        foreach ($children as $c) {
            $classe_actuelle = $c->classe_actuelle ?: '';
            $proposition = $classe_actuelle !== '' ? self::classe_superieure($classe_actuelle) : null;
            if ($proposition === null) {
                $proposition = $c->date_naissance
                    ? self::classe_for_birthdate($c->date_naissance, $rentree_year)
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
    /**
     * Applique un plan de passage d'année, tout ou rien : une écriture en
     * échec annule l'ensemble (aucun enfant à moitié promu) et renvoie
     * WP_Error ; le plan reste alors en attente, prêt à être rejoué. Les
     * écritures sont idempotentes (enroll() met à jour une ligne
     * existante), rejouer un plan déjà appliqué ne duplique rien.
     *
     * @return int|WP_Error Nombre d'enfants inscrits dans l'année cible.
     */
    public static function apply_promotion($to_year_id, $plan, $overrides = array(), $from_year_id = null) {
        global $wpdb;
        $to_year_id = absint($to_year_id);
        // La sortie d'un enfant en fin de cycle est inscrite sur l'année
        // qu'il quitte, jamais sur celle où il n'entre pas.
        $from_year_id = $from_year_id ? absint($from_year_id) : self::active_id();
        if (!$to_year_id || !self::get($to_year_id)) {
            return new WP_Error('psc_promotion_year', __('Année cible introuvable.', 'periscolaire-registration'));
        }

        $wpdb->query('START TRANSACTION');
        $count = 0;
        foreach ($plan as $row) {
            $child_id = (int) $row['child_id'];
            $classe = array_key_exists($child_id, $overrides) ? $overrides[$child_id] : $row['classe_proposee'];

            if ($classe === 'sortie' || $classe === '') {
                $ok = self::mark_sorti($child_id, $from_year_id) !== false;
            } else {
                $ok = self::enroll($child_id, $to_year_id, $classe, 'inscrit');
                if ($ok) $count++;
            }
            if (!$ok) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('psc_promotion_failed', __('Le passage d’année n’a pas pu être enregistré : rien n’a été modifié.', 'periscolaire-registration'));
            }
        }
        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('psc_promotion_failed', __('Le passage d’année n’a pas pu être enregistré : rien n’a été modifié.', 'periscolaire-registration'));
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

    /* ------------------------------------------------------------------
     * Niveaux de classe et progression d'une année sur l'autre
     *
     * Déplacées depuis helpers.php : ce sont des règles d'année scolaire,
     * au même titre que le passage d'année qui les consomme
     * (build_promotion_plan() juste au-dessus).
     * ------------------------------------------------------------------ */

    /**
     * Liste ordonnée des niveaux scolaires pour les menus déroulants.
     * Clé = valeur stockée en base, valeur = libellé affiché.
     */
    public static function classe_options() {
        return array(
            ''   => __('— Classe —', 'periscolaire-registration'),
            'PS' => __('Petite Section (PS)', 'periscolaire-registration'),
            'MS' => __('Moyenne Section (MS)', 'periscolaire-registration'),
            'GS' => __('Grande Section (GS)', 'periscolaire-registration'),
            'CP' => 'CP',
            'CE1'=> 'CE1',
            'CE2'=> 'CE2',
            'CM1'=> 'CM1',
            'CM2'=> 'CM2',
        );
    }

    /**
     * Classe attendue pour un enfant né le $date_naissance, à la rentrée
     * $rentree_year (âge au 31 décembre de cette année civile — règle
     * officielle française). Sert uniquement à initialiser la classe d'un
     * enfant qui n'en a pas encore : jamais utilisée pour recorriger une
     * classe déjà définie (cf. Psc_School_Years::build_promotion_plan()).
     */
    public static function classe_for_birthdate($date_naissance, $rentree_year) {
        if (!$date_naissance) return '';
        $age = $rentree_year - (int) date('Y', strtotime($date_naissance));
        $map = array(3 => 'PS', 4 => 'MS', 5 => 'GS', 6 => 'CP', 7 => 'CE1', 8 => 'CE2', 9 => 'CM1', 10 => 'CM2');
        return $map[$age] ?? '';
    }

    /**
     * Table de correspondance classe -> classe suivante (ou 'sortie'),
     * éditable depuis Périscolaire > Réglages : une école à classes
     * multi-niveaux peut avoir une progression différente du simple
     * PS→MS→GS→CP→CE1→CE2→CM1→CM2. Valeur par défaut = cette progression
     * standard, CM2 menant à la sortie.
     */
    public static function classe_progression_defaut() {
        return array(
            'PS'  => 'MS',
            'MS'  => 'GS',
            'GS'  => 'CP',
            'CP'  => 'CE1',
            'CE1' => 'CE2',
            'CE2' => 'CM1',
            'CM1' => 'CM2',
            'CM2' => 'sortie',
        );
    }

    public static function classe_progression() {
        $saved = get_option('psc_classe_progression', array());
        $defaut = self::classe_progression_defaut();
        if (!is_array($saved) || empty($saved)) return $defaut;

        $progression = array();
        foreach (array_keys(self::classe_options()) as $code) {
            if ($code === '') continue;
            $progression[$code] = isset($saved[$code]) ? $saved[$code] : ($defaut[$code] ?? 'sortie');
        }
        return $progression;
    }

    /**
     * Classe suivante pour $classe selon la table de correspondance
     * configurée (Réglages). Renvoie 'sortie' en fin de cycle, ou null si
     * $classe n'est pas une classe reconnue.
     */
    public static function classe_superieure($classe) {
        $progression = self::classe_progression();
        return $progression[$classe] ?? null;
    }

}
