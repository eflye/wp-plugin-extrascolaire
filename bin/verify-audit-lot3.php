<?php
/**
 * Script de vérification autonome — corrections d'audit du lot 3
 * (envois, calendrier, facturation, performance, administration), en
 * conditions WP-CLI réelles. Une section par constat ; chaque section
 * crée puis supprime ses propres données.
 *
 * Usage :
 *   wp --require=bin/verify-audit-lot3.php verify-audit-lot3
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

WP_CLI::add_command('verify-audit-lot3', function () {

    if (!class_exists('Psc_Mailer')) {
        WP_CLI::error('Le plugin periscolaire-registration ne semble pas actif sur ce site.');
    }

    $failures = array();
    $checks = 0;
    $check = function ($condition, $label) use (&$failures, &$checks) {
        $checks++;
        if (!$condition) $failures[] = $label;
    };

    // Capture des e-mails : aucun envoi réel.
    $mails = array();
    $capture = function ($null, $atts) use (&$mails) { $mails[] = $atts; return true; };
    add_filter('pre_wp_mail', $capture, 10, 2);

    $sections = array();

    /* SEC-02/L3 : avis à l'ancienne adresse, modèle éditable. */
    $sections['SEC-02/L3'] = function () use ($check, &$mails) {
        $option = Psc_Email_Templates::OPTION;
        $backup = get_option($option, null);
        try {
            $defaults = Psc_Email_Templates::defaults();
            $check(isset($defaults['email_change_old']), 'SEC-02 : modèle « email_change_old » absent');
            $check(in_array('{{nouvelle_adresse}}', $defaults['email_change_old']['vars'], true), 'SEC-02 : variable {{nouvelle_adresse}} non déclarée');

            delete_option($option);
            $mails = array();
            $ok = Psc_Mailer::send_email_change_notice_old('ancienne@example.invalid', 'nouvelle@example.invalid', 'https://example.invalid/?psc_cancel=abc&x=1');
            $check($ok === true, 'SEC-02 : envoi de l\'avis en échec');
            $check(count($mails) === 1 && $mails[0]['to'] === 'ancienne@example.invalid', 'SEC-02 : l\'avis ne part pas vers l\'ancienne adresse');
            $msg = $mails ? (string) $mails[0]['message'] : '';
            $check(strpos($msg, 'nouvelle@example.invalid') !== false, 'SEC-02 : la nouvelle adresse n\'apparaît pas dans l\'avis');
            $check(strpos($msg, esc_url('https://example.invalid/?psc_cancel=abc&x=1')) !== false, 'SEC-02 : lien d\'annulation absent de l\'avis');

            // Personnalisation prise en compte.
            update_option($option, array('email_change_old' => array('subject' => 'Sujet perso {{site}}', 'body' => 'Corps perso {{nouvelle_adresse}}')));
            $mails = array();
            Psc_Mailer::send_email_change_notice_old('ancienne@example.invalid', 'nouvelle@example.invalid', 'https://example.invalid/');
            $check($mails && strpos((string) $mails[0]['subject'], 'Sujet perso') === 0, 'SEC-02 : sujet personnalisé ignoré');
            $check($mails && strpos((string) $mails[0]['message'], 'Corps perso nouvelle@example.invalid') !== false, 'SEC-02 : corps personnalisé ignoré');

            // Adresse invalide : rien n'est envoyé.
            $mails = array();
            $check(Psc_Mailer::send_email_change_notice_old('pas-une-adresse', 'nouvelle@example.invalid', 'https://example.invalid/') === false && !$mails,
                'SEC-02 : avis envoyé à une adresse invalide');
        } finally {
            if ($backup === null) delete_option($option); else update_option($option, $backup);
        }
    };

    try {
        foreach ($sections as $name => $run) {
            $run();
        }
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
    }

    if ($failures) {
        foreach ($failures as $f) WP_CLI::warning($f);
        WP_CLI::error(sprintf('%d/%d vérifications en échec.', count($failures), $checks));
    }
    WP_CLI::success(sprintf('%d vérifications OK (%s).', $checks, implode(', ', array_keys($sections))));
});
