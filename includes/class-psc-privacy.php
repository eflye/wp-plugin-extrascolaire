<?php
if (!defined('ABSPATH')) exit;

/**
 * Intégration à l'outil natif WordPress « Exporter les données
 * personnelles » (Outils > Exporter les données personnelles).
 *
 * Les familles ne sont pas des comptes WordPress (Psc_Parents::current(),
 * jamais is_user_logged_in()) : l'outil natif retrouve donc le foyer par
 * adresse e-mail, comme il le fait déjà pour un compte utilisateur ou un
 * commentateur. Un agent autorisé (administrateur) déclenche l'export
 * depuis wp-admin ; ce n'est pas une action que la famille fait elle-même.
 *
 * Cette classe couvre également l'outil natif « Effacer les données
 * personnelles » (Outils > Effacer les données personnelles). Le texte
 * suggéré pour la page de confidentialité (wp_add_privacy_policy_content)
 * fait l'objet d'une étape suivante du plan validé.
 */
class Psc_Privacy {
    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'register_eraser'));
    }

    public static function register_exporter($exporters) {
        $exporters['periscolaire-registration-familles'] = array(
            'exporter_friendly_name' => __('Périscolaire — données de la famille', 'periscolaire-registration'),
            'callback'               => array(__CLASS__, 'export_family_data'),
        );
        return $exporters;
    }

    /**
     * Le volume de données d'un foyer (quelques enfants, une année
     * scolaire de planning, quelques factures et échanges) reste borné :
     * tout est renvoyé en une seule page plutôt que d'implémenter une
     * pagination incrémentale — done=true dès le premier appel.
     */
    public static function export_family_data($email_address, $page = 1) {
        $email_address = trim((string) wp_unslash($email_address));
        $parent = self::find_family_by_email($email_address);
        if (!$parent) {
            return array('data' => array(), 'done' => true);
        }

        $items = array();

        $items[] = array(
            'group_id'    => 'psc-famille-profil',
            'group_label' => __('Périscolaire — profil de la famille', 'periscolaire-registration'),
            'item_id'     => 'psc-famille-' . $parent->id,
            'data'        => self::profile_fields($parent),
        );

        foreach (self::children_of_parent($parent->id) as $child) {
            $items[] = array(
                'group_id'    => 'psc-enfants',
                'group_label' => __('Périscolaire — enfants', 'periscolaire-registration'),
                'item_id'     => 'psc-enfant-' . $child->id,
                'data'        => self::child_fields($child),
            );

            foreach (self::enrollments_of_child($child->id) as $enrollment_data) {
                $items[] = array(
                    'group_id'    => 'psc-scolarite',
                    'group_label' => __('Périscolaire — scolarité par année', 'periscolaire-registration'),
                    'item_id'     => 'psc-scolarite-' . $enrollment_data['item_key'],
                    'data'        => $enrollment_data['fields'],
                );
            }

            $planning = self::planning_fields_for_child($child->id);
            if ($planning) {
                $items[] = array(
                    'group_id'    => 'psc-planning',
                    'group_label' => __('Périscolaire — planning (rythme et exceptions)', 'periscolaire-registration'),
                    'item_id'     => 'psc-planning-' . $child->id,
                    'data'        => $planning,
                );
            }

            foreach (self::pickup_persons_of_child($child->id) as $pp) {
                $items[] = array(
                    'group_id'    => 'psc-personnes-autorisees',
                    'group_label' => __('Périscolaire — personnes autorisées à récupérer un enfant', 'periscolaire-registration'),
                    'item_id'     => 'psc-personne-' . $pp->id,
                    'data'        => self::pickup_person_fields($pp, $child),
                );
            }
        }

        if (class_exists('Psc_Invoices')) {
            foreach (Psc_Invoices::get_for_parent($parent->id) as $invoice) {
                $items[] = array(
                    'group_id'    => 'psc-factures',
                    'group_label' => __('Périscolaire — factures', 'periscolaire-registration'),
                    'item_id'     => 'psc-facture-' . $invoice->id,
                    'data'        => self::invoice_fields($invoice),
                );
            }
        }

        if (class_exists('Psc_Conversations')) {
            foreach (Psc_Conversations::list_for_family($parent->id) as $conversation) {
                $messages = Psc_Conversations::messages($conversation->id);
                $items[] = array(
                    'group_id'    => 'psc-echanges',
                    'group_label' => __('Périscolaire — échanges avec la mairie', 'periscolaire-registration'),
                    'item_id'     => 'psc-echange-' . $conversation->id,
                    'data'        => self::conversation_fields($conversation, $messages),
                );
            }
        }

        return array('data' => $items, 'done' => true);
    }

    public static function register_eraser($erasers) {
        $erasers['periscolaire-registration-familles'] = array(
            'eraser_friendly_name' => __('Périscolaire — données de la famille', 'periscolaire-registration'),
            'callback'             => array(__CLASS__, 'erase_family_data'),
        );
        return $erasers;
    }

    /**
     * Le second parent n'a jamais de ligne propre : c'est un jeu de champs
     * dénormalisés sur la ligne du parent titulaire. Une demande visant
     * l'adresse du second parent n'efface donc que ces quatre champs — le
     * reste du foyer (parent titulaire, enfants, planning, factures,
     * échanges) reste le service en cours de l'autre parent.
     *
     * Une demande visant l'adresse du parent titulaire déclenche l'effacement
     * complet du foyer, à une exception près : les factures. L'article 17§3-b
     * du RGPD écarte le droit à l'effacement quand le traitement reste
     * nécessaire au respect d'une obligation légale — ici la conservation
     * décennale des pièces comptables imposée par le Code de commerce. La
     * ligne « parents » n'est donc pas supprimée mais anonymisée sur place
     * (même logique que Psc_Audit::forget_family) : les factures gardent un
     * parent_id qui pointe vers une ligne réelle, ni orpheline ni ambiguë,
     * plutôt que vers un identifiant qui n'existerait plus.
     */
    public static function erase_family_data($email_address, $page = 1) {
        $email_address = trim((string) wp_unslash($email_address));
        $parent = self::find_family_by_email($email_address);
        if (!$parent) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        $email = sanitize_email(strtolower($email_address));
        $is_second_parent_request = ($email !== strtolower((string) $parent->email))
            && $parent->second_parent_email
            && $email === strtolower((string) $parent->second_parent_email);

        if ($is_second_parent_request) {
            return self::erase_second_parent($parent);
        }

        return self::erase_household($parent);
    }

    private static function erase_second_parent($parent) {
        global $wpdb;

        $wpdb->update(
            psc_table('parents'),
            array(
                'second_parent_prenom'   => null,
                'second_parent_nom'      => null,
                'second_parent_email'    => null,
                'second_parent_telephone' => null,
            ),
            array('id' => $parent->id)
        );

        return array(
            'items_removed'  => true,
            'items_retained' => true,
            'messages'       => array(
                __('Les coordonnées du second parent ont été supprimées. Le reste des données du foyer est conservé : elles relèvent du suivi en cours du parent titulaire.', 'periscolaire-registration'),
            ),
            'done' => true,
        );
    }

    private static function erase_household($parent) {
        global $wpdb;

        $needles = array_filter(array(
            (string) $parent->nom,
            (string) $parent->prenom,
            (string) $parent->email,
            trim($parent->prenom . ' ' . $parent->nom),
            (string) $parent->second_parent_email,
            trim((string) $parent->second_parent_prenom . ' ' . (string) $parent->second_parent_nom),
        ));

        $children = self::children_of_parent($parent->id);
        foreach ($children as $child) {
            $needles[] = (string) $child->nom;
            $needles[] = (string) $child->prenom;
            if (class_exists('Psc_Admin_Familles')) {
                Psc_Admin_Familles::purge_child($child->id);
            }
        }
        $needles = array_values(array_unique(array_filter($needles)));

        if (class_exists('Psc_Conversations')) {
            Psc_Conversations::delete_for_family($parent->id);
        }
        if (class_exists('Psc_Impersonation')) {
            Psc_Impersonation::delete_for_family($parent->id);
        }
        if (class_exists('Psc_Audit')) {
            Psc_Audit::forget_family($parent->id, $needles);
        }

        $wpdb->update(
            psc_table('parents'),
            array(
                'email'                       => 'famille-supprimee-' . $parent->id . '@invalide.local',
                'nom'                         => null,
                'prenom'                      => null,
                'telephone_mobile'            => null,
                'telephone_fixe'              => null,
                'adresse'                     => null,
                'code_postal'                 => null,
                'ville'                       => null,
                'pending_email'               => null,
                'pending_email_token_hash'    => null,
                'pending_email_token_expires' => null,
                'token_hash'                  => null,
                'token_expires'               => null,
                'last_login'                  => null,
                'active'                      => 0,
                'sepa_iban'                   => null,
                'sepa_bic'                    => null,
                'sepa_titulaire'              => null,
                'sepa_adresse'                => null,
                'sepa_code_postal'            => null,
                'sepa_ville'                  => null,
                'sepa_mandate_ref'            => null,
                'second_parent_prenom'        => null,
                'second_parent_nom'           => null,
                'second_parent_email'         => null,
                'second_parent_telephone'     => null,
            ),
            array('id' => $parent->id)
        );

        return array(
            'items_removed'  => true,
            'items_retained' => true,
            'messages'       => array(
                __('Les données du foyer (enfants, planning, personnes autorisées, échanges) ont été supprimées. Les factures sont conservées, comme le prévoit l’obligation légale de conservation des pièces comptables (10 ans).', 'periscolaire-registration'),
            ),
            'done' => true,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Lecture (requêtes directes : cette classe n'hérite d'aucun socle    */
    /* frontend/admin existant, elle ne fait que lire pour l'export)       */
    /* ------------------------------------------------------------------ */

    /**
     * Volontairement distinct de Psc_Parents::get_by_email() : celle-ci ne
     * retrouve que les foyers actifs (correct pour une connexion), alors
     * qu'une demande RGPD doit aboutir même pour un foyer désactivé mais
     * dont les données existent toujours en base — les deux cas ont des
     * exigences différentes, pas une régression de l'un vers l'autre.
     */
    private static function find_family_by_email($email) {
        global $wpdb;
        $email = sanitize_email($email);
        if (!is_email($email)) return null;
        $email = strtolower($email);
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . psc_table('parents') . ' WHERE email = %s OR second_parent_email = %s',
            $email, $email
        ));
    }

    private static function children_of_parent($parent_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('children') . ' WHERE parent_id = %d ORDER BY prenom',
            $parent_id
        ));
    }

    private static function enrollments_of_child($child_id) {
        if (!class_exists('Psc_School_Years')) return array();
        $out = array();
        foreach (Psc_School_Years::all() as $year) {
            $enrollment = Psc_School_Years::enrollment($child_id, $year->id);
            if (!$enrollment) continue;
            $out[] = array(
                'item_key' => $child_id . '-' . $year->id,
                'fields'   => array(
                    array('name' => __('Année scolaire', 'periscolaire-registration'), 'value' => $year->label),
                    array('name' => __('Classe', 'periscolaire-registration'), 'value' => (string) $enrollment->classe),
                    array('name' => __('Statut d’inscription', 'periscolaire-registration'), 'value' => (string) $enrollment->statut),
                    array('name' => __('Date d’inscription', 'periscolaire-registration'), 'value' => (string) $enrollment->date_inscription),
                    array('name' => __('Règlement intérieur accepté le', 'periscolaire-registration'), 'value' => (string) $enrollment->reglement_accepted_at),
                    array('name' => __('Justificatif d’assurance déposé', 'periscolaire-registration'), 'value' => $enrollment->assurance_original_filename ? (string) $enrollment->assurance_original_filename : __('Aucun', 'periscolaire-registration')),
                    array('name' => __('Assurance déposée le', 'periscolaire-registration'), 'value' => (string) $enrollment->assurance_uploaded_at),
                    array('name' => __('Statut de l’assurance', 'periscolaire-registration'), 'value' => (string) $enrollment->assurance_status),
                ),
            );
        }
        return $out;
    }

    /**
     * Rythme habituel (une ligne par jour/service coché) et exceptions
     * ponctuelles, toutes années confondues — un enfant garde rarement
     * plus de quelques dizaines de lignes au total, pas besoin de
     * pagination dédiée.
     */
    private static function planning_fields_for_child($child_id) {
        global $wpdb;
        $fields = array();

        $pattern_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('pattern') . ' WHERE child_id = %d ORDER BY school_year, weekday',
            $child_id
        ));
        $jours = array(1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche');
        foreach ($pattern_rows as $row) {
            $fields[] = array(
                'name'  => sprintf(__('Rythme %1$s (%2$s)', 'periscolaire-registration'), $row->school_year, $jours[(int) $row->weekday] ?? $row->weekday),
                'value' => $row->service_code,
            );
        }

        $exception_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('exception') . ' WHERE child_id = %d ORDER BY jour_date',
            $child_id
        ));
        foreach ($exception_rows as $row) {
            $fields[] = array(
                'name'  => sprintf(__('Exception du %s', 'periscolaire-registration'), $row->jour_date),
                'value' => sprintf('%s : %s', $row->service_code, $row->value ? __('ajouté', 'periscolaire-registration') : __('retiré', 'periscolaire-registration')),
            );
        }

        return $fields;
    }

    private static function pickup_persons_of_child($child_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . psc_table('pickup_persons') . ' WHERE child_id = %d ORDER BY id',
            $child_id
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Mise en forme des groupes de champs (format attendu par l'outil WP) */
    /* ------------------------------------------------------------------ */

    private static function profile_fields($parent) {
        $fields = array(
            array('name' => __('E-mail', 'periscolaire-registration'), 'value' => (string) $parent->email),
            array('name' => __('Nom', 'periscolaire-registration'), 'value' => (string) $parent->nom),
            array('name' => __('Prénom', 'periscolaire-registration'), 'value' => (string) $parent->prenom),
            array('name' => __('Téléphone mobile', 'periscolaire-registration'), 'value' => (string) $parent->telephone_mobile),
            array('name' => __('Téléphone fixe', 'periscolaire-registration'), 'value' => (string) $parent->telephone_fixe),
            array('name' => __('Adresse', 'periscolaire-registration'), 'value' => (string) $parent->adresse),
            array('name' => __('Code postal', 'periscolaire-registration'), 'value' => (string) $parent->code_postal),
            array('name' => __('Ville', 'periscolaire-registration'), 'value' => (string) $parent->ville),
            array('name' => __('Second parent — prénom', 'periscolaire-registration'), 'value' => (string) $parent->second_parent_prenom),
            array('name' => __('Second parent — nom', 'periscolaire-registration'), 'value' => (string) $parent->second_parent_nom),
            array('name' => __('Second parent — e-mail', 'periscolaire-registration'), 'value' => (string) $parent->second_parent_email),
            array('name' => __('Second parent — téléphone', 'periscolaire-registration'), 'value' => (string) $parent->second_parent_telephone),
            array('name' => __('Mode de paiement', 'periscolaire-registration'), 'value' => (string) $parent->payment_mode),
            array('name' => __('Règlement intérieur accepté le', 'periscolaire-registration'), 'value' => (string) $parent->reglement_accepted_at),
            array('name' => __('Compte actif', 'periscolaire-registration'), 'value' => $parent->active ? __('oui', 'periscolaire-registration') : __('non', 'periscolaire-registration')),
            array('name' => __('Dernière connexion', 'periscolaire-registration'), 'value' => (string) $parent->last_login),
            array('name' => __('Compte créé le', 'periscolaire-registration'), 'value' => (string) $parent->created_at),
        );

        // Prélèvement SEPA : l'IBAN est chiffré au repos (psc_encrypt) mais
        // c'est bien la donnée de la famille elle-même qui la demande via
        // cet export — on la déchiffre, on ne renvoie jamais le chiffré tel
        // quel, illisible et sans valeur pour elle.
        if (!empty($parent->sepa_iban) && function_exists('psc_decrypt')) {
            $fields[] = array('name' => __('IBAN', 'periscolaire-registration'), 'value' => (string) psc_decrypt($parent->sepa_iban));
            $fields[] = array('name' => __('BIC', 'periscolaire-registration'), 'value' => (string) $parent->sepa_bic);
            $fields[] = array('name' => __('Titulaire du compte', 'periscolaire-registration'), 'value' => (string) $parent->sepa_titulaire);
            $fields[] = array('name' => __('Référence du mandat SEPA', 'periscolaire-registration'), 'value' => (string) $parent->sepa_mandate_ref);
            $fields[] = array('name' => __('Mandat SEPA accepté le', 'periscolaire-registration'), 'value' => (string) $parent->sepa_reglement_accepted_at);
        }

        return $fields;
    }

    private static function child_fields($child) {
        return array(
            array('name' => __('Prénom', 'periscolaire-registration'), 'value' => (string) $child->prenom),
            array('name' => __('Nom', 'periscolaire-registration'), 'value' => (string) $child->nom),
            array('name' => __('Date de naissance', 'periscolaire-registration'), 'value' => (string) $child->date_naissance),
            array('name' => __('Statut', 'periscolaire-registration'), 'value' => (string) $child->statut),
            array('name' => __('Régime sans porc', 'periscolaire-registration'), 'value' => $child->sans_porc ? __('oui', 'periscolaire-registration') : __('non', 'periscolaire-registration')),
            array('name' => __('Régime végétalien', 'periscolaire-registration'), 'value' => $child->vegan ? __('oui', 'periscolaire-registration') : __('non', 'periscolaire-registration')),
            array('name' => __('Cantine sans repas fourni', 'periscolaire-registration'), 'value' => $child->cantine_sans_repas ? __('oui', 'periscolaire-registration') : __('non', 'periscolaire-registration')),
            array('name' => __('Allergies alimentaires déclarées', 'periscolaire-registration'), 'value' => (string) $child->food_allergies),
            array('name' => __('Fiche créée le', 'periscolaire-registration'), 'value' => (string) $child->created_at),
        );
    }

    private static function pickup_person_fields($pp, $child) {
        return array(
            array('name' => __('Enfant concerné', 'periscolaire-registration'), 'value' => trim($child->prenom . ' ' . $child->nom)),
            array('name' => __('Prénom', 'periscolaire-registration'), 'value' => (string) $pp->prenom),
            array('name' => __('Nom', 'periscolaire-registration'), 'value' => (string) $pp->nom),
            array('name' => __('Lien avec l’enfant', 'periscolaire-registration'), 'value' => (string) $pp->lien),
            array('name' => __('Téléphone', 'periscolaire-registration'), 'value' => (string) $pp->telephone),
            array('name' => __('Statut', 'periscolaire-registration'), 'value' => (string) $pp->statut),
            array('name' => __('Ajoutée le', 'periscolaire-registration'), 'value' => (string) $pp->created_at),
            array('name' => __('Retirée le', 'periscolaire-registration'), 'value' => (string) $pp->retiree_le),
        );
    }

    private static function invoice_fields($invoice) {
        return array(
            array('name' => __('Mois', 'periscolaire-registration'), 'value' => class_exists('Psc_Invoices') ? Psc_Invoices::month_label($invoice->mois) : (string) $invoice->mois),
            array('name' => __('Montant', 'periscolaire-registration'), 'value' => number_format_i18n((float) $invoice->total, 2) . ' €'),
            array('name' => __('Envoyée le', 'periscolaire-registration'), 'value' => (string) $invoice->sent_at),
            array('name' => __('Paiement reçu le', 'periscolaire-registration'), 'value' => (string) $invoice->payment_received_at),
        );
    }

    private static function conversation_fields($conversation, $messages) {
        $fields = array(
            array('name' => __('Sujet', 'periscolaire-registration'), 'value' => (string) $conversation->sujet),
            array('name' => __('Statut', 'periscolaire-registration'), 'value' => (string) $conversation->statut),
            array('name' => __('Ouvert le', 'periscolaire-registration'), 'value' => (string) $conversation->created_at),
        );
        foreach ($messages as $i => $message) {
            $fields[] = array(
                'name'  => sprintf(__('Message %1$d (%2$s, %3$s)', 'periscolaire-registration'), $i + 1, $message->auteur_type, $message->created_at),
                'value' => (string) $message->corps,
            );
        }
        return $fields;
    }
}
