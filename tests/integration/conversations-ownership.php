<?php
/**
 * wp eval-file tests/integration/conversations-ownership.php
 *
 * Pas de transaction englobante ici : Psc_Messages::send() et
 * Psc_Conversations::create_by_family()/reply() ouvrent et valident chacun
 * leur propre transaction (START TRANSACTION/COMMIT) — un COMMIT interne
 * validerait aussi une transaction englobante, empêchant tout ROLLBACK
 * final. Le nettoyage est donc explicite, par identifiant, dans le finally.
 */
if (!defined('WP_CLI') || !WP_CLI) return;

global $wpdb;
$checks = 0;
$assert = function ($condition, $message) use (&$checks) {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$family_id = null;
$other_family_id = null;
$message_id = null;
$message_id_locked = null;

try {
    $wpdb->insert(psc_table('parents'), array(
        'email' => 'conv-owner@example.invalid', 'nom' => 'Titulaire', 'prenom' => 'Famille',
        'active' => 1, 'created_at' => current_time('mysql'),
    ));
    $family_id = (int) $wpdb->insert_id;

    $wpdb->insert(psc_table('parents'), array(
        'email' => 'conv-other@example.invalid', 'nom' => 'Autre', 'prenom' => 'Famille',
        'active' => 1, 'created_at' => current_time('mysql'),
    ));
    $other_family_id = (int) $wpdb->insert_id;

    $conversation_id = Psc_Conversations::create_by_family($family_id, 'Une question', 'Bonjour la mairie.');
    $assert(is_int($conversation_id) && $conversation_id > 0, 'create_by_family() n’a pas renvoyé un identifiant.');

    $assert(
        Psc_Conversations::get_for_family($conversation_id, $other_family_id) === null,
        'get_for_family() retourne une conversation à une famille qui n’en est pas propriétaire.'
    );
    $assert(
        Psc_Conversations::get_for_family($conversation_id, $family_id) !== null,
        'get_for_family() refuse la propriétaire légitime.'
    );

    // Diffusion existante, mais la famille n'en est PAS destinataire (aucune ligne message_destinataires) :
    // create_by_family() doit refuser même si reponses_autorisees=1 et statut=envoye.
    $message_id = Psc_Messages::save(array(
        'titre' => 'Diffusion propriété', 'corps' => '<p>Info</p>', 'categorie' => 'information',
        'statut' => 'brouillon', 'cible_type' => 'familles', 'cible_valeur' => array('family_ids' => array($other_family_id)),
        'canaux' => array('portail' => true, 'email' => false, 'push' => false),
        'reponses_autorisees' => true, 'auteur_id' => 1,
    ));
    Psc_Messages::send($message_id); // ne cible que $other_family_id, jamais $family_id

    $result = Psc_Conversations::create_by_family($family_id, '', 'Je réponds sans y être invitée.', $message_id);
    $assert(is_wp_error($result), 'create_by_family() aurait dû refuser un message_id dont la famille n’est pas destinataire.');
    $assert(
        is_wp_error($result) && $result->get_error_code() === 'psc_conversation_not_recipient',
        'create_by_family() a refusé pour une autre raison que l’absence de destination : ' . (is_wp_error($result) ? $result->get_error_code() : 'succès')
    );

    // Diffusion sans réponses autorisées : refusée même pour son véritable destinataire.
    $message_id_locked = Psc_Messages::save(array(
        'titre' => 'Diffusion verrouillée', 'corps' => '<p>Info</p>', 'categorie' => 'information',
        'statut' => 'brouillon', 'cible_type' => 'familles', 'cible_valeur' => array('family_ids' => array($family_id)),
        'canaux' => array('portail' => true, 'email' => false, 'push' => false),
        'reponses_autorisees' => false, 'auteur_id' => 1,
    ));
    Psc_Messages::send($message_id_locked);
    $locked_result = Psc_Conversations::create_by_family($family_id, '', 'Je tente quand même.', $message_id_locked);
    $assert(
        is_wp_error($locked_result) && $locked_result->get_error_code() === 'psc_conversation_not_allowed',
        'create_by_family() aurait dû refuser une diffusion sans reponses_autorisees.'
    );

    WP_CLI::log(sprintf('OK : %d vérifications de propriété des conversations.', $checks));
} finally {
    if ($family_id) Psc_Conversations::delete_for_family($family_id);
    if ($message_id) Psc_Messages::delete($message_id);
    if ($message_id_locked) Psc_Messages::delete($message_id_locked);
    if ($family_id) $wpdb->delete(psc_table('parents'), array('id' => $family_id));
    if ($other_family_id) $wpdb->delete(psc_table('parents'), array('id' => $other_family_id));
}
