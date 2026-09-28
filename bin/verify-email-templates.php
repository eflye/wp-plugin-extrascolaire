<?php
/**
 * Script de vérification autonome — modèles d'e-mails personnalisables
 * (Psc_Email_Templates), en conditions WP-CLI réelles.
 *
 * Vérifie :
 *  - enregistrer la page sans rien modifier (zones de texte soumises en
 *    CRLF par le navigateur) ne fige aucun modèle ;
 *  - un modèle figé dans un ancien défaut (cas du serveur avant 5.34.0 :
 *    « Aucun contenu n'est reproduit ici ») reprend le défaut actuel, sans
 *    badge « Personnalisé », et l'e-mail envoyé n'a plus l'ancienne phrase ;
 *  - une vraie personnalisation est conservée (fins de ligne unifiées), un
 *    sujet seul personnalisé laisse le corps suivre le défaut ;
 *  - pied de mail : vide personnalisé conservé, défaut non stocké.
 *
 * Usage :
 *   wp --require=bin/verify-email-templates.php verify-email-templates
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-email-templates', function () {

    if (!class_exists('Psc_Email_Templates')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    global $wpdb;
    $option  = Psc_Email_Templates::OPTION;
    $backup  = get_option($option, null);
    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };
    $crlf = function ($text) { return str_replace("\n", "\r\n", (string) $text); };
    $defaults = Psc_Email_Templates::defaults();
    $email = 'verify-templates@example.invalid';
    $purge = function () use ($wpdb, $email) {
        $pid = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . psc_table('parents') . ' WHERE email = %s', $email));
        if ($pid) {
            Psc_Conversations::delete_for_family($pid);
            $wpdb->delete(psc_table('parents'), array('id' => $pid));
        }
    };
    $purge();
    $mails = array();
    $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    add_filter('pre_wp_mail', $capture, 10, 2);

    try {
        // 1. Page enregistrée telle quelle : rien n'est stocké.
        delete_option($option);
        $input = array();
        foreach ($defaults as $key => $tpl) {
            $input[$key] = array('subject' => $tpl['subject'], 'body' => $crlf($tpl['body']));
            if (!empty($tpl['has_footer'])) $input[$key]['footer'] = $crlf(isset($tpl['footer']) ? $tpl['footer'] : '');
        }
        Psc_Email_Templates::save(wp_slash($input));
        $stored = get_option($option, array());
        $check(is_array($stored) && count($stored) === 0, 'enregistrement sans modification : ' . (is_array($stored) ? implode(', ', array_keys($stored)) : '?') . ' figé(s)');

        // 2. Modèle figé dans l'ancien défaut (serveur avant 5.34.0).
        $old = "La mairie vous a répondu dans votre espace famille.\r\n\r\nAucun contenu n'est reproduit ici : consultez le message directement dans votre espace.";
        update_option($option, array(
            'conversation_famille' => array('subject' => $defaults['conversation_famille']['subject'], 'body' => $old),
            'conversation_mairie'  => array('subject' => $defaults['conversation_mairie']['subject'], 'body' => "{{famille}} vient d'écrire dans un échange.\r\n\r\nAucun contenu n'est reproduit ici : ouvrez la conversation dans le backoffice."),
        ));
        foreach (array('conversation_famille', 'conversation_mairie') as $key) {
            $tpl = Psc_Email_Templates::get($key);
            $check($tpl['body'] === $defaults[$key]['body'], "$key : ancien défaut encore appliqué");
            $check(empty($tpl['customized']), "$key : ancien défaut affiché « Personnalisé »");
            $check(!isset($tpl['retired_bodies']), "$key : anciens défauts exposés à l'écran");
        }
        $pid = Psc_Parents::create($email, 'Modèles');
        $cid = Psc_Conversations::create_by_mairie($pid, 'Sujet de vérification', 'Texte de vérification.', 1);
        $mails = array();
        Psc_Mailer::send_conversation_notification(Psc_Conversations::get($cid), 'famille');
        $check($mails && strpos($mails[0]['message'], 'Aucun contenu') === false, 'e-mail famille : ancienne phrase « Aucun contenu » encore envoyée');
        $check($mails && strpos($mails[0]['message'], 'Texte de vérification.') !== false, 'e-mail famille : texte du message absent');

        // 3. Vraie personnalisation : conservée, fins de ligne unifiées.
        delete_option($option);
        $input = array(
            'conversation_famille' => array('subject' => $defaults['conversation_famille']['subject'], 'body' => "Bonjour,\r\nLa mairie vous a écrit."),
            'invoice'              => array('subject' => 'Votre facture {{mois}}', 'body' => $crlf($defaults['invoice']['body'])),
        );
        Psc_Email_Templates::save(wp_slash($input));
        $stored = get_option($option, array());
        $tpl = Psc_Email_Templates::get('conversation_famille');
        $check($tpl['body'] === "Bonjour,\nLa mairie vous a écrit." && !empty($tpl['customized']), 'personnalisation du corps perdue ou non unifiée');
        $check(!isset($stored['conversation_famille']['subject']), 'sujet inchangé stocké avec le corps');
        $check(isset($stored['invoice']['subject']) && !isset($stored['invoice']['body']), 'sujet seul personnalisé : corps figé avec lui');
        $check(Psc_Email_Templates::get('invoice')['body'] === $defaults['invoice']['body'], 'sujet seul personnalisé : corps ne suit plus le défaut');

        // 4. Pied de mail.
        delete_option($option);
        $so = $defaults['supplier_order'];
        Psc_Email_Templates::save(wp_slash(array('supplier_order' => array('subject' => $so['subject'], 'body' => $crlf($so['body']), 'footer' => ''))));
        $tpl = Psc_Email_Templates::get('supplier_order');
        $check($tpl['footer'] === '' && !empty($tpl['customized']), 'pied de mail vidé : non conservé');
        Psc_Email_Templates::save(wp_slash(array('supplier_order' => array('subject' => $so['subject'], 'body' => $crlf($so['body']), 'footer' => $crlf($so['footer'])))));
        $check(get_option($option, array()) === array(), 'pied de mail par défaut : modèle figé');
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
        $purge();
        if ($backup === null) delete_option($option); else update_option($option, $backup);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(count($failures) . " vérification(s) en échec sur $checks.");
    }
    WP_CLI::success("Modèles d'e-mails : $checks vérifications.");
});
