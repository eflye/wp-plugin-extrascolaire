<?php
if (!defined('ABSPATH')) exit;

/**
 * Personnes autorisées à récupérer un enfant en fin de garderie.
 *
 * Deux tables : wp_psc_pickup_persons (liste courante, soft-delete via
 * statut) et wp_psc_pickup_history (journal append-only). Point d'entrée
 * UNIQUE pour toute écriture sur ces deux tables — famille (portail),
 * mairie (aucune écriture dans ce périmètre, lecture seule) et
 * approbation de demande (Psc_Requests) passent tous par add()/update()/
 * remove(), qui garantissent qu'aucune modification de la liste courante
 * n'existe sans son entrée d'historique correspondante. Aucune méthode
 * d'update/delete n'existe sur pickup_history : c'est ce qui la rend
 * inviolable, pas un verrou technique.
 */
class Psc_Pickup_Persons {

    /* ---------------- Lecture ---------------- */

    /** Liste courante (active) d'un enfant. */
    public static function for_child($child_id) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return array();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . psc_table('pickup_persons') . "
             WHERE child_id = %d AND statut = 'active' ORDER BY nom, prenom",
            $child_id
        ));
    }

    /**
     * Synthétise les entrées "Parent" (le titulaire du compte, et le
     * second parent s'il est renseigné) d'une ligne wp_psc_parents — jamais
     * des lignes wp_psc_pickup_persons. Partagé par authorized_for_child()
     * (par enfant, ex. SIDSCM) et Psc_Frontend::shortcode() (vue foyer,
     * "Habilitations", qui a déjà $parent en main et n'a pas besoin de
     * repasser par un enfant particulier — un foyer sans enfant actif doit
     * quand même voir ses deux parents).
     */
    public static function parent_entries($parent) {
        $list = array(array(
            'role'      => __('Parent', 'periscolaire-registration'),
            'prenom'    => (string) $parent->prenom,
            'nom'       => (string) $parent->nom,
            'telephone' => (string) ($parent->telephone_mobile ?: $parent->telephone_fixe),
            'removable' => false,
            'id'        => null,
        ));
        if ((string) $parent->second_parent_prenom !== '' || (string) $parent->second_parent_nom !== '') {
            $list[] = array(
                'role'      => __('Parent', 'periscolaire-registration'),
                'prenom'    => (string) $parent->second_parent_prenom,
                'nom'       => (string) $parent->second_parent_nom,
                'telephone' => (string) $parent->second_parent_telephone,
                'removable' => false,
                'id'        => null,
            );
        }
        return $list;
    }

    /**
     * Liste complète des personnes autorisées à récupérer un enfant :
     * le(s) parent(s) du foyer (synthétisés depuis wp_psc_parents — jamais
     * des lignes wp_psc_pickup_persons, jamais supprimables depuis cette
     * liste, jamais dans l'historique) suivis des tiers réellement
     * enregistrés via add()/update()/remove(). Source unique pour
     * "Habilitations" (vue foyer) et l'écran SIDSCM (vue par enfant) —
     * toujours lue à la demande, jamais une copie figée à l'inscription.
     */
    public static function authorized_for_child($child_id) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return array();

        $list = array();

        $parent_id = $wpdb->get_var($wpdb->prepare(
            'SELECT parent_id FROM ' . psc_table('children') . ' WHERE id = %d', $child_id
        ));
        if ($parent_id) {
            $parent = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . psc_table('parents') . ' WHERE id = %d', $parent_id
            ));
            if ($parent) {
                $list = array_merge($list, self::parent_entries($parent));
            }
        }

        foreach (self::for_child($child_id) as $p) {
            $list[] = array(
                'role'      => $p->lien !== '' ? $p->lien : 'Autre',
                'prenom'    => $p->prenom,
                'nom'       => $p->nom,
                'telephone' => $p->telephone,
                'removable' => true,
                'id'        => (int) $p->id,
            );
        }

        return $list;
    }

    public static function get($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) return null;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('pickup_persons') . ' WHERE id = %d', $id
        ));
    }

    /** Historique complet d'un enfant, du plus récent au plus ancien. */
    public static function history_for_child($child_id) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return array();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . psc_table('pickup_history') . "
             WHERE child_id = %d ORDER BY id DESC",
            $child_id
        ));
    }

    /* ---------------- Validation ---------------- */

    /**
     * Normalise un tableau de champs POST-controlled en valeurs sûres pour
     * écriture — jamais fait confiance à la forme d'entrée, que ce soit
     * depuis le portail famille ou depuis le sous-tableau
     * personnes_autorisees d'une demande d'inscription.
     */
    protected static function sanitize_fields($fields) {
        return array(
            'nom'            => mb_substr(sanitize_text_field($fields['nom'] ?? ''), 0, 191),
            'prenom'         => mb_substr(sanitize_text_field($fields['prenom'] ?? ''), 0, 191),
            'lien'           => mb_substr(sanitize_text_field($fields['lien'] ?? ''), 0, 100),
            'telephone'      => mb_substr(sanitize_text_field($fields['telephone'] ?? ''), 0, 40),
            'piece_identite' => !empty($fields['piece_identite']) ? 1 : 0,
        );
    }

    protected static function is_valid($clean) {
        return $clean['nom'] !== '' && $clean['prenom'] !== '' && $clean['telephone'] !== ''
            && psc_valid_phone($clean['telephone']) !== false;
    }

    /* ---------------- Écriture (liste courante + historique) ---------------- */

    /**
     * Ajoute une personne autorisée. $source = 'parent' (portail famille)
     * ou 'mairie'. $actor_parent_id permet d'attribuer l'action à une
     * famille précise sans session live — cas de l'approbation d'une
     * demande (Psc_Requests::handle_approve()) : c'est la mairie qui
     * clique "Valider", mais l'information vient bien de la famille, dont
     * on connaît déjà l'identité à cet instant. Sans cet override,
     * resolve_actor() retombe sur Psc_Parents::current() (session live du
     * portail). Renvoie l'id créé, ou WP_Error.
     */
    public static function add($child_id, $fields, $source, $actor_parent_id = null) {
        global $wpdb;
        $child_id = absint($child_id);
        if (!$child_id) return new WP_Error('psc_invalid', __('Enfant invalide.', 'periscolaire-registration'));

        $clean = self::sanitize_fields($fields);
        if (!self::is_valid($clean)) {
            return new WP_Error('psc_invalid', __('Nom, prénom et téléphone sont obligatoires.', 'periscolaire-registration'));
        }

        $t_pickup = psc_table('pickup_persons');
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_pickup WHERE child_id = %d AND statut = 'active'", $child_id
        ));
        if ($count >= psc_max_pickup_persons_per_child()) {
            return new WP_Error('psc_limit', __('Nombre maximum de personnes autorisées atteint pour cet enfant.', 'periscolaire-registration'));
        }

        $now = current_time('mysql');
        $wpdb->insert($t_pickup, array_merge($clean, array(
            'child_id'   => $child_id,
            'statut'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        )), array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'));

        $person_id = (int) $wpdb->insert_id;
        if (!$person_id) return new WP_Error('psc_failed', __("Échec de l'enregistrement.", 'periscolaire-registration'));

        self::log($child_id, $person_id, 'ajout', $clean, $source, $actor_parent_id);
        return $person_id;
    }

    /**
     * Modifie en place une personne active existante. Ne s'applique
     * jamais à une personne déjà retirée (pas de "réactivation" par ce
     * chemin — cf. hors périmètre du plan). $actor_parent_id : cf. add().
     */
    public static function update($person_id, $fields, $source, $actor_parent_id = null) {
        global $wpdb;
        $person = self::get($person_id);
        if (!$person || $person->statut !== 'active') {
            return new WP_Error('psc_invalid', __('Personne introuvable ou déjà retirée.', 'periscolaire-registration'));
        }

        $clean = self::sanitize_fields($fields);
        if (!self::is_valid($clean)) {
            return new WP_Error('psc_invalid', __('Nom, prénom et téléphone sont obligatoires.', 'periscolaire-registration'));
        }

        $wpdb->update(
            psc_table('pickup_persons'),
            array_merge($clean, array('updated_at' => current_time('mysql'))),
            array('id' => (int) $person->id),
            array('%s', '%s', '%s', '%s', '%d', '%s'),
            array('%d')
        );

        self::log((int) $person->child_id, (int) $person->id, 'modification', $clean, $source, $actor_parent_id);
        return true;
    }

    /**
     * Retrait = soft-delete. La ligne pickup_persons n'est jamais
     * supprimée physiquement, seulement basculée en statut 'retiree' —
     * elle reste la cible (pickup_person_id) de toutes ses entrées
     * d'historique passées et de celle générée ici. $actor_parent_id :
     * cf. add().
     */
    public static function remove($person_id, $source, $actor_parent_id = null) {
        global $wpdb;
        $person = self::get($person_id);
        if (!$person || $person->statut !== 'active') {
            return new WP_Error('psc_invalid', __('Personne introuvable ou déjà retirée.', 'periscolaire-registration'));
        }

        $actor = self::resolve_actor($source, $actor_parent_id);
        $now = current_time('mysql');
        $wpdb->update(
            psc_table('pickup_persons'),
            array(
                'statut'      => 'retiree',
                'retiree_le'  => $now,
                'retiree_par' => $actor['label'],
                'updated_at'  => $now,
            ),
            array('id' => (int) $person->id),
            array('%s', '%s', '%s', '%s'),
            array('%d')
        );

        $snapshot = array(
            'nom'            => $person->nom,
            'prenom'         => $person->prenom,
            'lien'           => $person->lien,
            'telephone'      => $person->telephone,
            'piece_identite' => (int) $person->piece_identite,
        );
        self::log((int) $person->child_id, (int) $person->id, 'retrait', $snapshot, $source, $actor_parent_id, $actor);
        return true;
    }

    /* ---------------- Historique (append-only — aucune autre méthode d'écriture) ---------------- */

    /**
     * Résout l'acteur d'une action : le parent connecté (portail famille),
     * ou — si $parent_id_override est fourni (cas de l'approbation d'une
     * demande, sans session live) — ce parent précis, ou à défaut
     * l'utilisateur WordPress connecté (mairie). Le libellé est figé dans
     * l'historique au moment de l'action : un changement de nom/e-mail
     * ultérieur du compte ne réécrit jamais une entrée passée.
     */
    protected static function resolve_actor($source, $parent_id_override = null) {
        if ($source === 'parent') {
            $parent = $parent_id_override ? Psc_Parents::get_by_id($parent_id_override) : Psc_Parents::current();
            return array(
                'parent_id'  => $parent ? (int) $parent->id : null,
                'wp_user_id' => null,
                'label'      => $parent
                    ? trim($parent->prenom . ' ' . $parent->nom) . ' (' . $parent->email . ')'
                    : 'Famille',
            );
        }

        $user = wp_get_current_user();
        return array(
            'parent_id'  => null,
            'wp_user_id' => ($user && $user->ID) ? (int) $user->ID : null,
            'label'      => ($user && $user->ID) ? ($user->display_name ?: $user->user_login) : 'Mairie',
        );
    }

    /**
     * Seul point d'écriture sur wp_psc_pickup_history — volontairement
     * protected et jamais appelé en dehors de add()/update()/remove()
     * ci-dessus : impossible de modifier la liste courante sans que cette
     * méthode s'exécute dans la foulée. $actor déjà résolu est accepté en
     * option (remove() en a déjà besoin pour retiree_par) pour éviter de
     * résoudre l'acteur deux fois.
     */
    protected static function log($child_id, $person_id, $action, $snapshot_fields, $source, $parent_id_override = null, $actor = null) {
        global $wpdb;
        if ($actor === null) {
            $actor = self::resolve_actor($source, $parent_id_override);
        }

        $wpdb->insert(psc_table('pickup_history'), array(
            'child_id'          => $child_id,
            'pickup_person_id'  => $person_id,
            'action'            => $action,
            'person_snapshot'   => wp_json_encode($snapshot_fields),
            'source'            => $source,
            'acteur_parent_id'  => $actor['parent_id'],
            'acteur_wp_user_id' => $actor['wp_user_id'],
            'acteur_label'      => $actor['label'],
            'created_at'        => current_time('mysql'),
        ), array('%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'));
    }
}
