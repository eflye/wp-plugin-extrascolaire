<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-guest alignfull" data-testid="guest-root">
  <div class="psc-guest-inner">

    <?php include PSC_PATH . 'templates/guest-menu.php'; ?>

    <?php
    $psc_notices = array(
        'link_sent'     => array('ok', 'Si cette adresse est enregistrée auprès de la mairie, un lien de connexion vient d\'être envoyé. Pensez à vérifier vos courriers indésirables.'),
        'bad_email'     => array('err', 'Merci de saisir une adresse e-mail valide.'),
        'bad_token'     => array('err', 'Ce lien n\'est pas valide. Demandez-en un nouveau ci-dessous.'),
        'expired_token' => array('err', 'Ce lien a expiré. Demandez-en un nouveau ci-dessous.'),
        'logged_out'    => array('ok', 'Vous êtes déconnecté.'),
        'request_sent'  => array('ok', 'Votre demande a bien été prise en compte. Un e-mail de confirmation vient de vous être envoyé : cliquez sur le lien qu\'il contient pour la transmettre à la mairie.'),
        'verified'      => array('ok', 'Merci, votre adresse est confirmée. Votre demande a été transmise à la mairie, qui vous répondra par e-mail.'),
        'bad_verify'    => array('err', 'Ce lien de confirmation n\'est pas valide.'),
        'expired_verify'=> array('err', 'Ce lien de confirmation a expiré. Vous pouvez déposer une nouvelle demande.'),
        'need_child'    => array('err', 'Merci d\'indiquer au moins un enfant (nom et prénom).'),
        'reglement_required'      => array('err', 'Merci de prendre connaissance du règlement intérieur et de cocher la case d\'approbation.'),
        'sepa_reglement_required' => array('err', 'Merci de prendre connaissance du règlement concernant le prélèvement et de cocher la case d\'approbation.'),
        'sepa_missing'            => array('err', 'Merci de renseigner le titulaire du compte à débiter.'),
        'bad_iban'                => array('err', 'L\'IBAN saisi n\'est pas valide. Vérifiez sa saisie.'),
        'bad_bic'                 => array('err', 'Le BIC saisi n\'est pas valide. Vérifiez sa saisie.'),
    );
    // Confirmations : popin auto-masquée (cf. assets/js/frontend.js).
    // Erreurs de connexion : bandeau classique en haut de page.
    // Erreurs du wizard d'inscription (need_child, bad_iban, ...) :
    // affichées DANS le wizard lui-même (cf. templates/guest-request.php)
    // plutôt qu'ici, tout en haut — sinon le message n'a aucun lien visuel
    // avec l'étape qui vient de s'ouvrir automatiquement pour le corriger,
    // cf. Psc_Frontend::wizard_error_context().
    $psc_toast_messages = array('link_sent', 'logged_out', 'bad_token', 'expired_token', 'request_sent');
    $psc_wizard_messages = array(
        'need_child', 'reglement_required', 'sepa_reglement_required',
        'sepa_missing', 'bad_iban', 'bad_bic',
    );
    if (!empty($psc_msg) && isset($psc_notices[$psc_msg]) && !in_array($psc_msg, $psc_wizard_messages, true)):
        list($type, $text) = $psc_notices[$psc_msg];
        $is_toast = in_array($psc_msg, $psc_toast_messages, true);
    ?>
      <?php if ($is_toast): ?>
      <div class="psc-notice psc-notice-<?php echo esc_attr($type); ?> psc-toast" data-testid="notice-<?php echo esc_attr($psc_msg); ?>">
        <span class="psc-toast-text"><?php echo esc_html($text); ?></span>
        <button type="button" class="psc-toast-close" aria-label="Fermer">&times;</button>
      </div>
      <?php else: ?>
      <p class="psc-notice psc-notice-<?php echo esc_attr($type); ?>" data-testid="notice-<?php echo esc_attr($psc_msg); ?>"><?php echo esc_html($text); ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php include PSC_PATH . 'templates/guest-login.php'; ?>

    <div class="psc-guest-divider">
      <div class="psc-guest-divider-rule"></div>
      <div class="psc-guest-divider-label">Nouvelle famille</div>
      <div class="psc-guest-divider-rule"></div>
    </div>

    <?php include PSC_PATH . 'templates/guest-request.php'; ?>

  </div>
</div>
