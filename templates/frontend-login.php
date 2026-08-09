<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-wrap psc-login">

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
// Messages du parcours de connexion : affichés en popin, qui disparaît
// seule au bout de 3 secondes (cf. assets/js/frontend.js). Les messages
// liés au formulaire d'inscription (erreurs de saisie à corriger) restent
// des bandeaux classiques : l'utilisateur doit avoir le temps de les lire
// en corrigeant le champ concerné.
$psc_toast_messages = array('link_sent', 'logged_out', 'bad_token', 'expired_token', 'request_sent');
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
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

<div class="psc-card psc-login-card" data-testid="login-card">
  <h2>Se connecter à l'espace famille</h2>
  <p class="psc-lead">Votre famille est déjà inscrite au service périscolaire ?</p>
  <p class="psc-card-intro">Saisissez l'adresse e-mail que vous avez communiquée à la mairie : vous recevrez un lien pour accéder à votre planning et déclarer les jours de présence de vos enfants. Aucun mot de passe à créer ni à retenir.</p>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-login-form" data-testid="login-form">
    <?php wp_nonce_field('psc_request_link'); ?>
    <input type="hidden" name="action" value="psc_request_link">
    <p>
      <label for="psc-email">Adresse e-mail</label><br>
      <input id="psc-email" type="email" name="psc_email" autocomplete="email" required data-testid="login-email-input">
    </p>
    <p><button type="submit" class="psc-btn" data-testid="login-submit-button">Recevoir mon lien d'accès</button></p>
  </form>
  <p class="psc-form-hint">Vous ne recevez rien après quelques minutes ? Pensez à vérifier vos spams, ou contactez la mairie.</p>
</div>

<?php include PSC_PATH . 'templates/frontend-request.php'; ?>

</div>
