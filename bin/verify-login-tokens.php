<?php
/**
 * Script de vérification autonome — notifications d'échanges avec texte
 * et lien de connexion direct (schéma 4.19.0, Psc_Login_Tokens), en
 * conditions WP-CLI réelles. Les e-mails sont interceptés (pre_wp_mail).
 *
 * Vérifie :
 *  - e-mail à la famille : un par destinataire (titulaire, second parent),
 *    texte du message et sujet présents (réglage coché), nom de la pièce
 *    jointe seulement, bouton portant un jeton propre à chacun ;
 *  - jetons : haché en base, 72 h, cible « conversation:ID » ; valide,
 *    inconnu, expiré ;
 *  - arrivée : conversation de la famille ouverte ; conversation d'une
 *    autre famille jamais ouverte (liste des échanges) ;
 *  - révocation des sessions, purge quotidienne, suppression de la famille ;
 *  - réglage décoché : aucun texte ; e-mail à la mairie : texte de la famille.
 *
 * Usage :
 *   wp --require=bin/verify-login-tokens.php verify-login-tokens
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-login-tokens', function () {

    if (!class_exists('Psc_Login_Tokens')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $t_par = psc_table('parents');
    $t_tok = psc_table('login_tokens');
    $emails = array('a' => 'verify-login-a@example.invalid', 'b' => 'verify-login-b@example.invalid', 'second' => 'verify-login-second@example.invalid');
    $saved_setting = get_option('psc_conversations_contenu_email', null);
    $saved_conv_email = get_option('psc_conversations_email', '');

    $purge = function () use ($wpdb, $emails, $t_par) {
        foreach (array('a', 'b') as $k) {
            $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t_par WHERE email = %s", $emails[$k]));
            if (!$pid) continue;
            Psc_Conversations::delete_for_family($pid);
            $wpdb->delete($t_par, array('id' => $pid)); // jetons : clé étrangère
        }
    };
    $purge();

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $mails = array();
    $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    add_filter('pre_wp_mail', $capture, 10, 2);

    // Clés étrangères posées comme au premier écran d'administration (WP-CLI
    // n'en est pas un) : la suppression d'une famille emporte ses jetons.
    $constraints = new ReflectionMethod('Psc_Installer', 'store_constraints_state');
    $constraints->setAccessible(true);
    $constraints->invoke(null);

    try {
        update_option('psc_conversations_contenu_email', 1);
        update_option('psc_conversations_email', 'verify-login-mairie@example.invalid');
        $a = Psc_Parents::create($emails['a'], 'Famille A');
        $wpdb->update($t_par, array('second_parent_email' => $emails['second']), array('id' => $a));
        $b = Psc_Parents::create($emails['b'], 'Famille B');
        $ca = Psc_Conversations::create_by_mairie($a, 'Sortie du jeudi', "Bonjour,\nLa sortie est maintenue.", 1);
        $cb = Psc_Conversations::create_by_mairie($b, 'Autre famille', 'Message pour B', 1);
        $check(!is_wp_error($ca) && !is_wp_error($cb), 'données : conversations non créées');
        $wpdb->update(psc_table('conversation_messages'), array('piece_jointe_nom' => 'programme.pdf'), array('conversation_id' => $ca));

        // 1. E-mail à la famille A : deux destinataires, deux jetons.
        $mails = array();
        Psc_Mailer::send_conversation_notification(Psc_Conversations::get($ca), 'famille');
        $to = array_map(function ($m) { return is_array($m['to']) ? $m['to'][0] : $m['to']; }, $mails);
        sort($to);
        $expected_to = array($emails['a'], $emails['second']);
        sort($expected_to);
        $check($to === $expected_to, 'famille : destinataires ' . implode(',', $to));
        $tokens = array();
        foreach ($mails as $m) {
            $check(strpos($m['message'], 'La sortie est maintenue.') !== false && strpos($m['message'], 'Sortie du jeudi') !== false, 'famille : texte ou sujet absent de l’e-mail');
            $check(strpos($m['message'], 'programme.pdf') !== false, 'famille : nom de la pièce jointe absent');
            $check(preg_match('/psc_lt=([0-9a-f]{64})/', $m['message'], $mm) === 1, 'famille : bouton sans lien de connexion');
            $check(strpos($m['message'], 'conversation_id=' . $ca) !== false, 'famille : bouton sans la conversation');
            if (!empty($mm[1])) $tokens[] = $mm[1];
        }
        $check(count(array_unique($tokens)) === 2, 'famille : un même jeton pour les deux destinataires');

        // 2. En base : haché, 72 h, cible.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_tok WHERE parent_id = %d", $a));
        $check(count($rows) === 2, 'jetons : ' . count($rows) . ' en base au lieu de 2');
        foreach ($rows as $r) {
            $check(!in_array($r->token_hash, $tokens, true) && $r->cible === 'conversation:' . $ca, 'jetons : stocké en clair ou mauvaise cible');
            $ttl = strtotime($r->expires_at . ' UTC') - time();
            $check($ttl > 71 * HOUR_IN_SECONDS && $ttl <= 72 * HOUR_IN_SECONDS, 'jetons : validité ' . round($ttl / 3600, 1) . ' h au lieu de 72 h');
        }

        // 3. Consommation et page d'arrivée.
        $row = Psc_Login_Tokens::consume($tokens[0]);
        $check(!is_wp_error($row) && (int) $row->parent_id === $a, 'jeton valide refusé');
        $check($wpdb->get_var($wpdb->prepare("SELECT last_used_at FROM $t_tok WHERE token_hash = %s", psc_hash_token($tokens[0]))) !== null, 'jeton : usage non tracé');
        $check(!is_wp_error($row) && Psc_Login_Tokens::landing_args($row) === array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => (int) $ca), 'arrivée : conversation de la famille non ouverte');
        $check(!is_wp_error(Psc_Login_Tokens::consume($tokens[0])), 'jeton : non réutilisable pendant sa validité (passerelles de messagerie)');
        $foreign = (object) array('parent_id' => $a, 'cible' => 'conversation:' . $cb);
        $check(Psc_Login_Tokens::landing_args($foreign) === array('psc_tab' => 'messages', 'psc_vue' => 'echanges'), 'arrivée : conversation d’une autre famille ouverte');
        $bad = Psc_Login_Tokens::consume(str_repeat('0', 64));
        $check(is_wp_error($bad) && $bad->get_error_code() === 'bad_token', 'jeton inconnu accepté');
        $wpdb->update($t_tok, array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('token_hash' => psc_hash_token($tokens[1])));
        $exp = Psc_Login_Tokens::consume($tokens[1]);
        $check(is_wp_error($exp) && $exp->get_error_code() === 'expired_token', 'jeton expiré accepté');

        // 4. Purge, révocation, suppression de la famille.
        $check(Psc_Login_Tokens::purge_expired() >= 1 && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_tok WHERE token_hash = %s", psc_hash_token($tokens[1]))), 'purge : jeton expiré conservé');
        psc_bump_session_epoch($a);
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_tok WHERE parent_id = %d", $a)) === 0, 'révocation des sessions : jetons conservés');

        // 5. Réglage décoché : plus de texte.
        update_option('psc_conversations_contenu_email', 0);
        $mails = array();
        Psc_Mailer::send_conversation_notification(Psc_Conversations::get($ca), 'famille');
        $check($mails && strpos($mails[0]['message'], 'La sortie est maintenue.') === false, 'réglage décoché : texte encore envoyé');
        $check($mails && strpos($mails[0]['message'], 'psc_lt=') !== false, 'réglage décoché : bouton sans lien de connexion');
        update_option('psc_conversations_contenu_email', 1);

        // 6. E-mail à la mairie : texte de la famille.
        Psc_Conversations::reply($ca, 'famille', 'Merci, nous serons là.');
        $mails = array();
        Psc_Mailer::send_conversation_notification(Psc_Conversations::get($ca), 'mairie');
        $check($mails && strpos($mails[0]['message'], 'Merci, nous serons là.') !== false, 'mairie : texte de la famille absent');
        $check($mails && strpos($mails[0]['message'], 'psc_lt=') === false, 'mairie : lien de connexion famille dans l’e-mail de la mairie');

        // 7. Famille supprimée : ses jetons disparaissent.
        Psc_Login_Tokens::issue($b, $emails['b'], Psc_Login_Tokens::MOTIF_ECHANGE, 'conversation:' . $cb);
        Psc_Conversations::delete_for_family($b);
        $wpdb->delete($t_par, array('id' => $b));
        $check((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_tok WHERE parent_id = %d", $b)) === 0, 'famille supprimée : jetons conservés');
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
        $purge();
        if ($saved_setting === null) delete_option('psc_conversations_contenu_email'); else update_option('psc_conversations_contenu_email', $saved_setting);
        update_option('psc_conversations_email', $saved_conv_email);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Notifications d’échanges et liens directs : $checks vérifications.");
});
