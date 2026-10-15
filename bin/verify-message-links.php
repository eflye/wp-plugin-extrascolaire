<?php
/**
 * Script de vérification autonome — liens des e-mails de diffusion
 * (RGPD-09). Même rôle que bin/verify-promotion-logic.php.
 *
 * Vérifie que Psc_Messages::recipient(), seule porte d'entrée du lien
 * ?psc_msg=ID&t=JETON :
 *   - reconnaît le destinataire d'un message envoyé récemment ;
 *   - refuse un message envoyé depuis plus de link_ttl_days() jours ;
 *   - refuse un mauvais jeton et un message non envoyé (brouillon).
 *
 * Usage :
 *   wp --require=bin/verify-message-links.php verify-message-links
 *
 * Messages et destinataires de test dédiés (titre préfixé), supprimés
 * avant et après.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-message-links', function () {

    if (!method_exists('Psc_Messages', 'link_ttl_days')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_msg = psc_table('messages');
    $t_dest = psc_table('message_destinataires');
    $prefix = 'VerifyMessageLinks';
    $family = 999999901;

    $purge = function () use ($wpdb, $t_msg, $t_dest, $prefix) {
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t_msg WHERE titre LIKE %s", $wpdb->esc_like($prefix) . '%'));
        foreach ($ids as $id) {
            $wpdb->delete($t_dest, array('message_id' => (int) $id));
            $wpdb->delete($t_msg, array('id' => (int) $id));
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Message à date d'envoi donnée (heure du site, comme Psc_Messages::send()).
    $message = function ($tag, $statut, $days_ago) use ($wpdb, $t_msg, $t_dest, $prefix, $family) {
        $sent = $days_ago === null ? null : wp_date('Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS);
        $wpdb->insert($t_msg, array(
            'titre' => "$prefix $tag", 'corps' => 'Test', 'statut' => $statut, 'date_envoi' => $sent,
            'auteur_id' => 1, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'),
        ));
        $id = (int) $wpdb->insert_id;
        $token = wp_generate_password(32, false, false);
        $wpdb->insert($t_dest, array('message_id' => $id, 'family_id' => $family, 'token' => $token));
        return array($id, $token);
    };

    try {
        $ttl = Psc_Messages::link_ttl_days();
        $check($ttl === 90, "durée de validité : $ttl jours au lieu de 90");

        list($recent, $recent_token) = $message('recent', 'envoye', 1);
        list($edge, $edge_token) = $message('limite', 'envoye', $ttl - 1);
        list($old, $old_token) = $message('ancien', 'envoye', $ttl + 1);
        list($draft, $draft_token) = $message('brouillon', 'brouillon', null);

        $row = Psc_Messages::recipient($recent, $recent_token);
        $check($row && (int) $row->family_id === $family, 'message récent : destinataire non reconnu');
        $check((bool) Psc_Messages::recipient($edge, $edge_token), 'message envoyé juste avant l’échéance : lien refusé');
        $check(!Psc_Messages::recipient($old, $old_token), 'message envoyé depuis plus de 90 jours : lien encore accepté');
        $check(!Psc_Messages::recipient($recent, $old_token), 'mauvais jeton accepté');
        $check(!Psc_Messages::recipient($draft, $draft_token), 'message non envoyé accepté');
    } finally {
        $purge();
    }

    if ($failures) {
        foreach ($failures as $failure) WP_CLI::warning($failure);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Liens des e-mails de diffusion : $checks vérifications.");
});
