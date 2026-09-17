<?php
/**
 * Registre des actions auditables du journal d'audit (Psc_Audit).
 *
 * Chargé par includes/helpers.php.
 */

if (!defined('ABSPATH')) exit;

/**
 * Politique exhaustive des actions POST/AJAX du plugin, par nom d'action
 * (sans préfixe de hook — la clé est ce qui suit admin_post_/admin_post_nopriv_/
 * wp_ajax_/wp_ajax_nopriv_).
 *
 * Chaque entrée déclare soit un triplet (action, categorie, objet) plus un
 * niveau de rétention, soit niveau => 'ignore' pour une lecture sans enjeu
 * (toujours accompagné d'un commentaire expliquant pourquoi).
 *
 * C'est ce registre que tests/integration/audit-registry.php vérifie
 * exhaustif : toute action POST/AJAX du plugin doit y figurer, sous peine
 * de faire échouer la CI. Une action ajoutée plus tard et absente d'ici
 * est journalisée sous le code 'inconnu.action' (cf. Psc_Audit::init())
 * et déclenche une notice admin — le journal signale son propre angle mort
 * plutôt que de se trouer silencieusement.
 *
 * @return array<string, array{action?: string, categorie?: string, objet?: string, niveau: string}>
 */
function psc_audit_action_registry() {
    return array(

        /* ---------------- Authentification famille ---------------- */
        'psc_request_link' => array(
            'action' => 'famille.lien_envoye', 'categorie' => 'authentification', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_logout' => array(
            'action' => 'famille.deconnexion', 'categorie' => 'authentification', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_cancel_email_change' => array(
            'action' => 'famille.email_change_annule', 'categorie' => 'authentification', 'objet' => 'famille', 'niveau' => 'normal',
        ),

        /* ---------------- Consultation d'espace famille ---------------- */
        'psc_impersonate_start' => array(
            'action' => 'consultation.ouverture', 'categorie' => 'securite', 'objet' => 'consultation', 'niveau' => 'critique',
        ),
        'psc_impersonate_stop' => array(
            'action' => 'consultation.fermeture', 'categorie' => 'securite', 'objet' => 'consultation', 'niveau' => 'critique',
        ),

        /* ---------------- Demandes d'inscription ---------------- */
        'psc_submit_request' => array(
            'action' => 'demande.soumission', 'categorie' => 'donnees_famille', 'objet' => 'demande', 'niveau' => 'normal',
        ),
        'psc_approve_request' => array(
            'action' => 'demande.validation', 'categorie' => 'donnees_famille', 'objet' => 'demande', 'niveau' => 'normal',
        ),
        'psc_reject_request' => array(
            'action' => 'demande.refus', 'categorie' => 'donnees_famille', 'objet' => 'demande', 'niveau' => 'normal',
        ),
        'psc_delete_request' => array(
            'action' => 'demande.suppression', 'categorie' => 'donnees_famille', 'objet' => 'demande', 'niveau' => 'normal',
        ),
        'psc_reconcile_request_allergies' => array(
            'action' => 'demande.allergies_reconciliees', 'categorie' => 'donnees_famille', 'objet' => 'demande', 'niveau' => 'normal',
        ),

        /* ---------------- Portail famille ---------------- */
        'psc_parent_update_profile' => array(
            'action' => 'famille.modification', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_parent_enable_sepa' => array(
            'action' => 'sepa.mandat_active', 'categorie' => 'bancaire', 'objet' => 'famille', 'niveau' => 'critique',
        ),
        'psc_parent_update_second_parent' => array(
            'action' => 'famille.second_parent_modifie', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_parent_remove_second_parent' => array(
            'action' => 'famille.second_parent_retire', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        // État d'affichage pur (message d'accueil vu/pas vu) : aucune donnée métier.
        'psc_parent_dismiss_onboarding' => array('niveau' => 'ignore'),
        'psc_parent_reinscription' => array(
            'action' => 'enfant.reinscription', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_parent_update_child_identity' => array(
            'action' => 'enfant.modification', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_parent_add_child' => array(
            'action' => 'enfant.creation', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_parent_update_pickup_person' => array(
            'action' => 'personne_autorisee.modification', 'categorie' => 'donnees_famille', 'objet' => 'personne_autorisee', 'niveau' => 'normal',
        ),
        'psc_parent_remove_pickup_person' => array(
            'action' => 'personne_autorisee.retrait', 'categorie' => 'donnees_famille', 'objet' => 'personne_autorisee', 'niveau' => 'normal',
        ),
        'psc_parent_add_household_pickup_person' => array(
            'action' => 'personne_autorisee.creation', 'categorie' => 'donnees_famille', 'objet' => 'personne_autorisee', 'niveau' => 'normal',
        ),
        'psc_parent_upload_assurance' => array(
            'action' => 'assurance.depot', 'categorie' => 'documents', 'objet' => 'assurance', 'niveau' => 'normal',
        ),
        'psc_parent_download_assurance' => array(
            'action' => 'assurance.telechargement', 'categorie' => 'documents', 'objet' => 'assurance', 'niveau' => 'normal',
        ),
        'psc_parent_download_invoice' => array(
            'action' => 'facture.telechargement', 'categorie' => 'documents', 'objet' => 'facture', 'niveau' => 'normal',
        ),
        'psc_cancel_absence' => array(
            'action' => 'planning.annulation', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'normal',
        ),

        /* ---------------- Planning (regroupement par requête, cf. Psc_Audit) ---------------- */
        'psc_toggle_exception' => array(
            'action' => 'planning.exception_modifiee', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'volumineux',
        ),
        'psc_toggle_exception_bulk' => array(
            'action' => 'planning.exception_modifiee', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'volumineux',
        ),
        'psc_toggle_pattern' => array(
            'action' => 'planning.rythme_modifie', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'volumineux',
        ),
        'psc_apply_pattern_to_siblings' => array(
            'action' => 'planning.rythme_modifie', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'volumineux',
        ),
        'psc_reset_month_exceptions' => array(
            'action' => 'planning.remise_a_zero', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'volumineux',
        ),
        // Lecture pure à chaque changement de mois/enfant (déjà classée 'lecture'
        // dans le registre de consultation d'espace famille) : capturer produirait
        // un volume disproportionné pour une valeur d'audit nulle.
        'psc_load_month' => array('niveau' => 'ignore'),
        'psc_confirm' => array(
            'action' => 'planning.confirmation', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'normal',
        ),

        /* ---------------- Autres AJAX famille/public ---------------- */
        // Affichage du menu de la semaine : lecture seule, aucun enjeu.
        'psc_menu_week' => array('niveau' => 'ignore'),
        // Sondage de notifications navigateur : lecture seule, répété toutes les 30 s.
        'psc_message_notifications' => array('niveau' => 'ignore'),
        'psc_message_ack' => array(
            'action' => 'message.accuse_lecture', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),

        /* ---------------- Intervenants (écran de pointage, code partagé) ---------------- */
        'psc_sidscm_unlock' => array(
            'action' => 'intervenant.deverrouillage', 'categorie' => 'securite', 'niveau' => 'normal',
        ),
        // Lecture de l'état du jour pour l'écran de pointage, rafraîchie en continu.
        'psc_sidscm_data' => array('niveau' => 'ignore'),
        'psc_sidscm_toggle' => array(
            'action' => 'presence.pointage', 'categorie' => 'presences', 'objet' => 'presence', 'niveau' => 'normal',
        ),
        'psc_sidscm_arrival' => array(
            'action' => 'presence.pointage', 'categorie' => 'presences', 'objet' => 'presence', 'niveau' => 'normal',
        ),
        'psc_sidscm_departure' => array(
            'action' => 'presence.pointage', 'categorie' => 'presences', 'objet' => 'presence', 'niveau' => 'normal',
        ),

        /* ---------------- Familles / enfants — admin ---------------- */
        'psc_add_child' => array(
            'action' => 'enfant.creation', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_delete_child' => array(
            'action' => 'enfant.suppression', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'critique',
        ),
        'psc_delete_family' => array(
            'action' => 'famille.suppression', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'critique',
        ),
        'psc_mark_child_sorti' => array(
            'action' => 'enfant.sortie', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_mark_child_actif' => array(
            'action' => 'enfant.reactivation', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_toggle_cantine_sans_repas' => array(
            'action' => 'enfant.modification', 'categorie' => 'donnees_famille', 'objet' => 'enfant', 'niveau' => 'normal',
        ),
        'psc_add_parent' => array(
            'action' => 'famille.creation', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_toggle_parent' => array(
            'action' => 'famille.desactivation', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'critique',
        ),
        'psc_send_link' => array(
            'action' => 'famille.lien_envoye', 'categorie' => 'authentification', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_edit_parent' => array(
            'action' => 'famille.modification', 'categorie' => 'donnees_famille', 'objet' => 'famille', 'niveau' => 'normal',
        ),
        'psc_download_assurance' => array(
            'action' => 'assurance.telechargement', 'categorie' => 'documents', 'objet' => 'assurance', 'niveau' => 'normal',
        ),

        /* ---------------- Messages descendants ---------------- */
        'psc_save_message' => array(
            'action' => 'message.creation', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        'psc_send_message' => array(
            'action' => 'message.envoi', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        'psc_test_message' => array(
            'action' => 'message.envoi', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        'psc_delete_message' => array(
            'action' => 'message.suppression', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        'psc_resend_message' => array(
            'action' => 'message.envoi', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        'psc_export_message' => array(
            'action' => 'message.export', 'categorie' => 'communication', 'objet' => 'message', 'niveau' => 'normal',
        ),
        // Comptage en direct pendant la rédaction (aperçu du nombre de destinataires) :
        // rien n'est modifié ni consulté de façon sensible.
        'psc_message_count_targets' => array('niveau' => 'ignore'),

        /* ---------------- Conversations famille <-> mairie ---------------- */
        'psc_conversation_reply' => array(
            'action' => 'conversation.reponse', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_parent_conversation_reply' => array(
            'action' => 'conversation.reponse', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_conversation_close' => array(
            'action' => 'conversation.cloture', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_conversation_reopen' => array(
            'action' => 'conversation.reouverture', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_conversation_create' => array(
            'action' => 'conversation.creation', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_parent_conversation_create' => array(
            'action' => 'conversation.creation', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),
        'psc_parent_download_conversation_attachment' => array(
            'action' => 'conversation.piece_jointe_telechargement', 'categorie' => 'communication', 'objet' => 'conversation', 'niveau' => 'normal',
        ),

        /* ---------------- Cantine ---------------- */
        'psc_save_menu' => array(
            'action' => 'menu.enregistrement', 'categorie' => 'configuration', 'objet' => 'menu', 'niveau' => 'normal',
        ),
        'psc_send_menu' => array(
            'action' => 'menu.envoi', 'categorie' => 'communication', 'objet' => 'menu', 'niveau' => 'normal',
        ),
        'psc_delete_menu' => array(
            'action' => 'menu.suppression', 'categorie' => 'configuration', 'objet' => 'menu', 'niveau' => 'normal',
        ),
        'psc_send_supplier_order' => array(
            'action' => 'commande_fournisseur.envoi', 'categorie' => 'communication', 'objet' => 'commande_fournisseur', 'niveau' => 'normal',
        ),
        'psc_cancel_class_meals' => array(
            'action' => 'repas.annulation_classe', 'categorie' => 'presences', 'objet' => 'presence', 'niveau' => 'normal',
        ),
        // Masque une bannière d'alerte admin : aucune donnée métier modifiée.
        'psc_dismiss_cancel_class_meals' => array('niveau' => 'ignore'),

        /* ---------------- Calendrier v2 ---------------- */
        // Aperçu en lecture seule avant confirmation : aucune écriture.
        'psc_cal_v2_preview_close_day' => array('niveau' => 'ignore'),
        'psc_cal_v2_preview_close_service' => array('niveau' => 'ignore'),
        'psc_cal_v2_close_day' => array(
            'action' => 'calendrier.jour_ferme', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_cal_v2_open_day' => array(
            'action' => 'calendrier.jour_ouvert', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_cal_v2_close_service' => array(
            'action' => 'calendrier.service_ferme', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_cal_v2_open_service' => array(
            'action' => 'calendrier.service_ouvert', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),

        /* ---------------- Années scolaires ---------------- */
        'psc_add_school_year' => array(
            'action' => 'annee.creation', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_activate_school_year' => array(
            'action' => 'annee.activation', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_archive_school_year' => array(
            'action' => 'annee.archivage', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_update_school_year' => array(
            'action' => 'annee.modification', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_delete_school_year' => array(
            'action' => 'annee.suppression', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_stage_promotion' => array(
            'action' => 'annee.passage_prepare', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_confirm_promotion' => array(
            'action' => 'annee.passage', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_cancel_promotion' => array(
            'action' => 'annee.passage_annule', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_import_school_calendar' => array(
            'action' => 'calendrier.import', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_upload_school_calendar' => array(
            'action' => 'calendrier.import', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_close_school_day' => array(
            'action' => 'calendrier.jour_ferme', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'critique',
        ),
        'psc_cancel_school_day_close' => array(
            'action' => 'calendrier.jour_ferme_annule', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_open_school_day' => array(
            'action' => 'calendrier.jour_ouvert', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        // Code distinct de reglage.modification (dates de vacances, délai
        // de prévenance) : nettement moins sensible que les réglages
        // globaux (bancaires, codes d'accès) portés par psc_save_settings,
        // qui partage la catégorie mais pas le niveau de rétention.
        'psc_save_school_year_config' => array(
            'action' => 'annee.reglage_modifie', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_add_school_holiday' => array(
            'action' => 'calendrier.vacances_modifiees', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),
        'psc_remove_school_holiday' => array(
            'action' => 'calendrier.vacances_modifiees', 'categorie' => 'configuration', 'objet' => 'annee', 'niveau' => 'normal',
        ),

        /* ---------------- Facturation / SEPA ---------------- */
        'psc_download_general' => array(
            'action' => 'facture.export', 'categorie' => 'facturation', 'niveau' => 'critique',
        ),
        'psc_payment_received' => array(
            'action' => 'facture.paiement_enregistre', 'categorie' => 'bancaire', 'objet' => 'facture', 'niveau' => 'critique',
        ),
        'psc_generate_invoices' => array(
            'action' => 'facture.generation', 'categorie' => 'facturation', 'objet' => 'facture', 'niveau' => 'critique',
        ),
        'psc_send_invoice' => array(
            'action' => 'facture.envoi', 'categorie' => 'communication', 'objet' => 'facture', 'niveau' => 'normal',
        ),
        'psc_send_all_invoices' => array(
            'action' => 'facture.envoi', 'categorie' => 'communication', 'objet' => 'facture', 'niveau' => 'normal',
        ),
        'psc_download_invoice' => array(
            'action' => 'facture.telechargement', 'categorie' => 'documents', 'objet' => 'facture', 'niveau' => 'normal',
        ),
        'psc_delete_invoices' => array(
            'action' => 'facture.suppression', 'categorie' => 'facturation', 'objet' => 'facture', 'niveau' => 'critique',
        ),
        'psc_download_pain008' => array(
            'action' => 'sepa.export', 'categorie' => 'bancaire', 'niveau' => 'critique',
        ),
        'psc_download_sepa' => array(
            'action' => 'sepa.export', 'categorie' => 'bancaire', 'niveau' => 'critique',
        ),

        /* ---------------- Assurances ---------------- */
        'psc_review_assurance' => array(
            'action' => 'assurance.validation', 'categorie' => 'documents', 'objet' => 'assurance', 'niveau' => 'normal',
        ),
        'psc_download_pending_assurance' => array(
            'action' => 'assurance.telechargement', 'categorie' => 'documents', 'objet' => 'assurance', 'niveau' => 'normal',
        ),

        /* ---------------- Présences déclarées ---------------- */
        'psc_admin_update_registrations' => array(
            'action' => 'planning.correction_mairie', 'categorie' => 'planning', 'objet' => 'planning', 'niveau' => 'normal',
        ),
        'psc_export_csv' => array(
            'action' => 'planning.export', 'categorie' => 'documents', 'objet' => 'planning', 'niveau' => 'normal',
        ),

        /* ---------------- Réglages ---------------- */
        'psc_save_settings' => array(
            'action' => 'reglage.modification', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'critique',
        ),
        // Code distinct, même logique que psc_save_email_templates
        // ci-dessous : l'adresse du fournisseur n'a pas la sensibilité
        // d'un réglage bancaire/code d'accès (niveau normal, pas
        // critique). Déplacé depuis Réglages vers Commande fournisseur >
        // Réglages (réorganisation du menu, §6) — action dédiée créée à
        // cette occasion, l'option elle-même ne change pas.
        'psc_save_supplier_settings' => array(
            'action' => 'reglage.fournisseur', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'normal',
        ),
        // Code distinct de reglage.modification : une modification de
        // modèle d'e-mail n'a pas la sensibilité d'un changement de
        // réglages bancaires/codes d'accès, donc pas la même rétention
        // (niveau normal, contre critique pour psc_save_settings juste
        // au-dessus — les deux ne peuvent pas partager un même code
        // d'action tout en ayant des niveaux différents).
        'psc_save_email_templates' => array(
            'action' => 'reglage.modele_email', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'normal',
        ),
        'psc_reset_email_template' => array(
            'action' => 'reglage.modele_email', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'normal',
        ),
        'psc_reset_email_templates' => array(
            'action' => 'reglage.modele_email', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'normal',
        ),

        /* ---------------- Journal d'audit lui-même (étape 4) ---------------- */
        'psc_audit_verify' => array(
            'action' => 'audit.verification', 'categorie' => 'securite', 'niveau' => 'normal',
        ),
        'psc_audit_reset_failures' => array(
            'action' => 'audit.sante_reinitialisee', 'categorie' => 'securite', 'niveau' => 'normal',
        ),
        'psc_audit_export_csv' => array(
            'action' => 'audit.export', 'categorie' => 'securite', 'niveau' => 'critique',
        ),
        'psc_audit_export_ods' => array(
            'action' => 'audit.export', 'categorie' => 'securite', 'niveau' => 'critique',
        ),
        'psc_audit_save_retention' => array(
            'action' => 'reglage.modification', 'categorie' => 'configuration', 'objet' => 'reglage', 'niveau' => 'critique',
        ),
    );
}

/**
 * Catégorie d'un code d'action, dérivée du registre ci-dessus et complétée
 * par les codes purement sémantiques qui n'ont pas de hook admin_post/ajax
 * propre (sous-événements d'une même action HTTP, ex. connexion réussie vs
 * échouée à l'intérieur de la consommation d'un même lien magique) ainsi
 * que les codes du journal d'audit lui-même.
 *
 * Psc_Audit::log() est appelé avec un CODE D'ACTION, jamais un nom de hook :
 * c'est cette fonction, pas le registre indexé par hook, qui lui donne sa
 * catégorie.
 */
function psc_audit_categorie_for_action($action_code) {
    $extra = psc_audit_semantic_extra();
    if (isset($extra[$action_code]['categorie'])) return $extra[$action_code]['categorie'];

    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (psc_audit_action_registry() as $entry) {
            if (!empty($entry['action']) && !empty($entry['categorie'])) {
                $map[$entry['action']] = $entry['categorie'];
            }
        }
    }
    return isset($map[$action_code]) ? $map[$action_code] : 'systeme';
}

/**
 * Codes d'action purement sémantiques, sans hook 1:1 dans
 * psc_audit_action_registry() (déclenchés directement par Psc_Audit::log()
 * depuis plusieurs points d'appel, ou consommant une ligne générique dont
 * le hook porte un tout autre nom) : la seule source de leur catégorie et
 * de leur niveau. psc_audit_categorie_for_action(), psc_audit_niveau_for_action()
 * et psc_audit_actions_by_niveau() s'y réfèrent tous les trois, pour ne
 * jamais désaccorder ces deux fonctions (cf. tests/integration/audit-registry.php).
 */
function psc_audit_semantic_extra() {
    return array(
        'famille.connexion'             => array('categorie' => 'authentification', 'niveau' => 'normal'),
        'famille.connexion_echouee'     => array('categorie' => 'authentification', 'niveau' => 'normal'),
        'famille.email_change_demande'  => array('categorie' => 'authentification', 'niveau' => 'normal'),
        'famille.email_change_confirme' => array('categorie' => 'authentification', 'niveau' => 'normal'),
        'audit.consultation'            => array('categorie' => 'securite', 'niveau' => 'critique'),
        'audit.export'                  => array('categorie' => 'securite', 'niveau' => 'critique'),
        'privacy.export'                => array('categorie' => 'donnees_famille', 'niveau' => 'critique'),
        'privacy.effacement'            => array('categorie' => 'donnees_famille', 'niveau' => 'critique'),
        'audit.purge'                   => array('categorie' => 'systeme', 'niveau' => 'critique'),
        // Angle mort signalé (cf. Psc_Audit::capture_generic()) : classé au
        // niveau le plus prudent puisque sa nature réelle est inconnue.
        'inconnu.action'                => array('categorie' => 'systeme', 'niveau' => 'critique'),
        'systeme.montee_de_version'     => array('categorie' => 'systeme', 'niveau' => 'normal'),
        'systeme.purge'                 => array('categorie' => 'systeme', 'niveau' => 'normal'),
    );
}

/** Niveau (durée de rétention, cf. psc_audit_retention_days()) d'un code d'action. */
function psc_audit_niveau_for_action($action_code) {
    $extra = psc_audit_semantic_extra();
    if (isset($extra[$action_code]['niveau'])) return $extra[$action_code]['niveau'];

    static $map = null;
    if ($map === null) {
        $map = array();
        foreach (psc_audit_action_registry() as $entry) {
            if (!empty($entry['action']) && isset($entry['niveau']) && $entry['niveau'] !== 'ignore') {
                $map[$entry['action']] = $entry['niveau'];
            }
        }
    }
    return isset($map[$action_code]) ? $map[$action_code] : 'normal';
}

/** Codes d'action distincts portant un niveau donné (filtre de l'écran d'audit, purge par rétention). */
function psc_audit_actions_by_niveau($niveau) {
    $actions = array();
    foreach (psc_audit_action_registry() as $entry) {
        if (!empty($entry['action']) && isset($entry['niveau']) && $entry['niveau'] === $niveau) {
            $actions[$entry['action']] = true;
        }
    }
    foreach (psc_audit_semantic_extra() as $action => $entry) {
        if (isset($entry['niveau']) && $entry['niveau'] === $niveau) {
            $actions[$action] = true;
        }
    }
    return array_keys($actions);
}

/** Durées de rétention par défaut (jours) — indicatives, cf. aide de l'écran de réglages du journal : ce ne sont pas une obligation légale. */
function psc_audit_retention_defaults() {
    return array('critique' => 1095, 'normal' => 365, 'volumineux' => 180);
}

/** Durée de rétention effective (jours) pour un niveau, bornée [30, 3650] jours. */
function psc_audit_retention_days($niveau) {
    $defaults = psc_audit_retention_defaults();
    $default = isset($defaults[$niveau]) ? $defaults[$niveau] : 365;
    $days = (int) get_option('psc_audit_retention_' . $niveau, $default);
    return max(30, min(3650, $days > 0 ? $days : $default));
}

/**
 * Vrai si $resume contient l'un des textes fournis (nom, prénom, e-mail
 * d'une famille ou de ses enfants) — décide, lors de l'anonymisation d'une
 * famille supprimée, si un résumé doit être réécrit en version générique
 * ou peut rester tel quel (cf. Psc_Audit::forget_family()). Comparaison
 * insensible à la casse ; les aiguilles vides ou à un seul caractère sont
 * ignorées pour éviter les faux positifs.
 */
function psc_audit_resume_needs_redaction($resume, array $needles) {
    $resume = (string) $resume;
    if ($resume === '') return false;
    foreach ($needles as $needle) {
        $needle = trim((string) $needle);
        if (mb_strlen($needle) < 2) continue;
        if (mb_stripos($resume, $needle) !== false) return true;
    }
    return false;
}

/**
 * Politique de champs auditables par type d'objet — liste blanche stricte
 * (jamais de liste noire) : un champ absent d'une des trois listes
 * ci-dessous n'entre JAMAIS dans le journal, même s'il existe dans les
 * tableaux avant/apres fournis par l'appelant.
 *
 *  - enregistres : valeur consignée telle quelle (après masquage éventuel
 *    ailleurs, cf. psc_audit_redact_diff()).
 *  - masques     : valeur consignée masquée (psc_mask_iban()).
 *  - exclus      : le FAIT qu'un changement a eu lieu est consigné
 *    ("modifié"), jamais la valeur — jetons, IBAN complet, BIC, données de
 *    santé, corps de message.
 *
 * Un type d'objet absent de ce tableau n'enregistre aucun détail (fail
 * closed) : seul le résumé reste.
 */
function psc_audit_field_policy() {
    return array(
        'famille' => array(
            'enregistres' => array(
                'email', 'nom', 'prenom', 'telephone_mobile', 'telephone_fixe',
                'adresse', 'code_postal', 'ville', 'active', 'payment_mode',
                'second_parent_nom', 'second_parent_prenom', 'second_parent_email',
                'second_parent_telephone',
            ),
            'masques' => array('sepa_iban'),
            'exclus'  => array(
                'token_hash', 'token_expires', 'pending_email_token_hash',
                'sepa_bic', 'sepa_titulaire',
            ),
        ),
        'enfant' => array(
            'enregistres' => array('nom', 'prenom', 'date_naissance', 'statut', 'classe', 'cantine_sans_repas'),
            'exclus'      => array('allergies', 'regime', 'remarques'),
        ),
    );
}

/**
 * Différence avant/apres restreinte à la liste blanche d'un type d'objet,
 * avec masquage et rédaction. Ne retourne que les champs qui ont
 * effectivement changé — un champ identique dans avant et apres n'apparaît
 * pas, même s'il est déclaré.
 *
 * @return array{avant?: array, apres?: array} Vide si le type est inconnu
 *         de la politique, ou si rien n'a changé parmi les champs déclarés.
 */
function psc_audit_redact_diff($objet_type, $avant, $apres) {
    $policy = psc_audit_field_policy();
    if (!isset($policy[$objet_type])) return array();

    $enregistres = isset($policy[$objet_type]['enregistres']) ? $policy[$objet_type]['enregistres'] : array();
    $masques     = isset($policy[$objet_type]['masques']) ? $policy[$objet_type]['masques'] : array();
    $exclus      = isset($policy[$objet_type]['exclus']) ? $policy[$objet_type]['exclus'] : array();
    $declares    = array_merge($enregistres, $masques, $exclus);

    $avant = is_array($avant) ? $avant : array();
    $apres = is_array($apres) ? $apres : array();

    $out_avant = array();
    $out_apres = array();

    foreach ($declares as $field) {
        $in_before = array_key_exists($field, $avant);
        $in_after  = array_key_exists($field, $apres);
        if (!$in_before && !$in_after) continue;

        $before_val = $in_before ? $avant[$field] : null;
        $after_val  = $in_after ? $apres[$field] : null;
        if ($before_val === $after_val) continue; // champ inchangé : hors du détail

        if (in_array($field, $exclus, true)) {
            // Le fait qu'il ait changé est utile à l'audit ; sa valeur ne l'est jamais.
            if ($in_before) $out_avant[$field] = 'modifié';
            if ($in_after) $out_apres[$field] = 'modifié';
            continue;
        }

        if (in_array($field, $masques, true)) {
            if ($in_before && $before_val !== null) $before_val = psc_mask_iban((string) $before_val);
            if ($in_after && $after_val !== null) $after_val = psc_mask_iban((string) $after_val);
        }

        if ($in_before) $out_avant[$field] = $before_val;
        if ($in_after) $out_apres[$field] = $after_val;
    }

    $result = array();
    if ($out_avant) $result['avant'] = $out_avant;
    if ($out_apres) $result['apres'] = $out_apres;
    return $result;
}

/**
 * Sérialise avant/apres/meta en JSON, plafonné à 8 Ko. Au-delà, la ligne
 * ne perd pas son résumé (déjà stocké séparément) mais son détail est
 * remplacé par un simple marqueur — jamais de JSON tronqué à la volée,
 * qui produirait une valeur invalide à la relecture.
 */
function psc_audit_build_details($avant, $apres, $meta, $max_bytes = 8192) {
    $payload = array();
    if (!empty($avant)) $payload['avant'] = $avant;
    if (!empty($apres)) $payload['apres'] = $apres;
    if (!empty($meta)) $payload['meta'] = $meta;
    if (!$payload) return null;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) return null;
    if (strlen($json) > $max_bytes) {
        return json_encode(array('tronque' => true), JSON_UNESCAPED_UNICODE);
    }
    return $json;
}

/** Résumé tronqué à 255 caractères (largeur de la colonne). */
function psc_audit_truncate_resume($resume) {
    $resume = (string) $resume;
    return function_exists('mb_substr') ? mb_substr($resume, 0, 255) : substr($resume, 0, 255);
}

/**
 * Résumé par défaut quand l'appelant n'en fournit pas (capture générique,
 * étape 3.1) : dérivé du code d'action lui-même, faute de contexte plus
 * riche. Les points d'appel sémantiques (étape 3.2) fournissent presque
 * toujours un résumé explicite plus utile.
 */
function psc_audit_default_resume($action_code) {
    $parts = explode('.', (string) $action_code, 2);
    $lisible = str_replace('_', ' ', isset($parts[1]) ? $parts[1] : $action_code);
    $objet = str_replace('_', ' ', $parts[0]);
    return ucfirst($objet) . ' — ' . $lisible;
}

/**
 * Libellé lisible d'un code d'action pour l'écran de consultation (étape 4) —
 * même dérivation que le résumé par défaut : un code stable comme
 * « famille.suppression » n'a pas besoin d'une table de correspondance
 * distincte pour rester lisible, la dérivation suffit et ne peut pas
 * désynchroniser de la nomenclature réelle du registre.
 */
function psc_audit_action_label($action_code) {
    return psc_audit_default_resume($action_code);
}

/** Libellés lisibles des catégories (filtre et colonnes de l'écran d'audit). */
function psc_audit_categorie_labels() {
    return array(
        'authentification' => __('Authentification', 'periscolaire-registration'),
        'donnees_famille'  => __('Données famille', 'periscolaire-registration'),
        'planning'         => __('Planning', 'periscolaire-registration'),
        'facturation'      => __('Facturation', 'periscolaire-registration'),
        'bancaire'         => __('Bancaire', 'periscolaire-registration'),
        'documents'        => __('Documents', 'periscolaire-registration'),
        'communication'    => __('Communication', 'periscolaire-registration'),
        'presences'        => __('Présences', 'periscolaire-registration'),
        'configuration'    => __('Configuration', 'periscolaire-registration'),
        'securite'         => __('Sécurité', 'periscolaire-registration'),
        'systeme'          => __('Système', 'periscolaire-registration'),
    );
}

/** Libellés lisibles des niveaux (filtre de l'écran d'audit et aide de Réglages). */
function psc_audit_niveau_labels() {
    return array(
        'critique'   => __('Critique', 'periscolaire-registration'),
        'normal'     => __('Normal', 'periscolaire-registration'),
        'volumineux' => __('Volumineux (planning)', 'periscolaire-registration'),
    );
}

/** Libellés lisibles des résultats (colonne Résultat, jamais rendue par la seule couleur). */
function psc_audit_resultat_labels() {
    return array(
        'succes'    => __('Succès', 'periscolaire-registration'),
        'refus'     => __('Refusé', 'periscolaire-registration'),
        'erreur'    => __('Erreur', 'periscolaire-registration'),
        'tentative' => __('Tentative', 'periscolaire-registration'),
    );
}

/** Libellés lisibles des types d'acteur (colonne Acteur, filtre de l'écran d'audit). */
function psc_audit_acteur_labels() {
    return array(
        'agent'       => __('Agent', 'periscolaire-registration'),
        'famille'     => __('Famille', 'periscolaire-registration'),
        'intervenant' => __('Intervenant', 'periscolaire-registration'),
        'systeme'     => __('Système', 'periscolaire-registration'),
        'public'      => __('Public', 'periscolaire-registration'),
    );
}

/**
 * Empreinte de chaînage d'une ligne — cf. psc_audit_verify_chain_rows()
 * pour la vérification. Volontairement une fonction pure, séparée de
 * Psc_Audit::log() : testable sans base de données (tests/unit/run.php).
 *
 * ⚠ Limite documentée : un acteur disposant d'un accès SQL complet peut
 * recalculer toute la chaîne après une altération. Ce chaînage détecte une
 * altération accidentelle ou opportuniste, ce n'est pas une preuve
 * opposable au sens d'un horodatage qualifié.
 */
function psc_audit_compute_hash($empreinte_precedente, $horodatage, $action, $acteur_type, $acteur_id, $objet_type, $objet_id, $resume) {
    return hash('sha256', $empreinte_precedente . '|' . $horodatage . '|' . $action . '|'
        . $acteur_type . ':' . (int) $acteur_id . '|'
        . (string) $objet_type . ':' . (int) $objet_id . '|'
        . $resume);
}

/**
 * Vérifie le chaînage d'une série ORDONNÉE (id croissant) de lignes déjà
 * chargées. La première ligne du lot sert d'ancre (son empreinte est
 * prise pour acquise : on ne peut pas la revérifier sans la ligne
 * précédente, hors du lot) ; chaque ligne suivante doit produire, à partir
 * de l'empreinte stockée de la précédente, la même empreinte que celle
 * qu'elle a elle-même stockée.
 *
 * @param array<int, object|array> $rows Chaque élément expose horodatage,
 *        action, acteur_type, acteur_id, objet_type, objet_id, resume,
 *        empreinte, id (objet ou tableau associatif, les deux sont acceptés).
 * @return int|null L'id de la première ligne dont l'empreinte ne
 *         correspond plus, ou null si la chaîne est intacte.
 */
function psc_audit_verify_chain_rows($rows) {
    $get = function ($row, $field) {
        return is_array($row) ? (isset($row[$field]) ? $row[$field] : null) : (isset($row->$field) ? $row->$field : null);
    };
    $count = count($rows);
    for ($i = 1; $i < $count; $i++) {
        $previous = $rows[$i - 1];
        $current  = $rows[$i];
        $expected = psc_audit_compute_hash(
            (string) $get($previous, 'empreinte'),
            $get($current, 'horodatage'),
            $get($current, 'action'),
            $get($current, 'acteur_type'),
            $get($current, 'acteur_id'),
            $get($current, 'objet_type'),
            $get($current, 'objet_id'),
            $get($current, 'resume')
        );
        if (!hash_equals($expected, (string) $get($current, 'empreinte'))) {
            return (int) $get($current, 'id');
        }
    }
    return null;
}
