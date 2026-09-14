<?php
if (!defined('ABSPATH')) exit;
/** Vue « Mes échanges » de l'onglet Messages — cf. frontend-messages.php pour le point d'inclusion. */
$conv = isset($psc_conversations_data) ? $psc_conversations_data : array(
    'conversations' => array(), 'unread' => 0, 'selected' => null, 'messages' => array(), 'reply_target' => null, 'draft' => null, 'reply_draft' => null,
);
$psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
$errors = array(
    'psc_conversation_body'          => __('Le message doit contenir entre 1 et 2000 caractères.', 'periscolaire-registration'),
    'psc_conversation_sujet'         => __('Le sujet doit contenir entre 1 et 160 caractères.', 'periscolaire-registration'),
    'psc_conversation_not_allowed'   => __('Les réponses ne sont plus autorisées pour ce message.', 'periscolaire-registration'),
    'psc_conversation_not_recipient' => __('Ce message ne vous est pas destiné.', 'periscolaire-registration'),
    'psc_conversation_duplicate'     => __('Ce message vient déjà d’être envoyé.', 'periscolaire-registration'),
    'psc_conversation_rate_limited'  => __('Trop de messages envoyés récemment : réessayez un peu plus tard.', 'periscolaire-registration'),
    'psc_conversation_missing'       => __('Cette conversation est introuvable.', 'periscolaire-registration'),
    'psc_conversation_family'        => __('Impossible d’envoyer ce message.', 'periscolaire-registration'),
    'psc_conversation_db'            => __('Impossible d’enregistrer ce message. Réessayez.', 'periscolaire-registration'),
);
$error_message = isset($errors[$psc_msg]) ? $errors[$psc_msg] : '';
?>
<div class="psc-conversations">

  <?php if ($psc_msg === 'conversation_sent'): ?>
    <p class="psc-notice psc-notice-ok" role="status" tabindex="-1" id="psc-conversation-status" data-testid="notice-conversation_sent"><?php esc_html_e('Message envoyé.', 'periscolaire-registration'); ?></p>
    <script>
    (function () {
        // La redirection porte aussi l'ancre #conversation-dernier-message :
        // le focus doit être posé APRÈS que le navigateur ait traité cette
        // ancre (sans quoi son propre traitement du fragment, qui survient
        // autour de l'évènement load, ramène le focus sur <body>).
        function focusNotice() {
            var n = document.getElementById('psc-conversation-status');
            if (n) n.focus();
        }
        if (document.readyState === 'complete') {
            setTimeout(focusNotice, 0);
        } else {
            window.addEventListener('load', function () { setTimeout(focusNotice, 0); });
        }
    })();
    </script>
  <?php endif; ?>

  <?php if ($conv['selected']):
      $c = $conv['selected'];
  ?>
    <p><a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges'), Psc_Mailer::form_page_url())); ?>">&larr; <?php esc_html_e('Mes échanges', 'periscolaire-registration'); ?></a></p>
    <h2><?php echo esc_html($c->sujet); ?></h2>

    <?php if ($error_message && $psc_msg !== 'conversation_sent'): ?>
      <p class="psc-notice psc-notice-err" role="alert" id="psc-conversation-reply-error"><?php echo esc_html($error_message); ?></p>
    <?php endif; ?>

    <ol class="psc-conversation-thread" style="list-style:none;margin:0;padding:0;">
      <?php foreach ($conv['messages'] as $m): ?>
        <li>
          <article>
            <header>
              <strong><?php echo $m->auteur_type === 'mairie' ? esc_html__('Mairie', 'periscolaire-registration') : esc_html__('Vous', 'periscolaire-registration'); ?></strong>
              <time datetime="<?php echo esc_attr(mysql2date('c', $m->created_at)); ?>"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($m->created_at))); ?></time>
            </header>
            <p><?php echo nl2br(esc_html($m->corps)); ?></p>
          </article>
        </li>
      <?php endforeach; ?>
    </ol>
    <div id="conversation-dernier-message"></div>

    <?php if ($c->statut === 'close'): ?>
      <p class="psc-conversation-closed">
        <?php printf(
            esc_html__('Échange clos par la mairie le %s. Écrire un nouveau message le rouvrira.', 'periscolaire-registration'),
            esc_html($c->close_le ? date_i18n('d/m/Y', strtotime($c->close_le)) : '')
        ); ?>
      </p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="psc-conversation-reply-form">
      <?php wp_nonce_field('psc_parent_conversation_reply'); psc_parent_nonce_field('psc_parent_conversation_reply'); ?>
      <input type="hidden" name="action" value="psc_parent_conversation_reply">
      <input type="hidden" name="conversation_id" value="<?php echo (int) $c->id; ?>">
      <p>
        <label for="psc-conversation-reply-body"><?php esc_html_e('Votre message', 'periscolaire-registration'); ?></label><br>
        <textarea id="psc-conversation-reply-body" name="corps" rows="4" maxlength="2000" required
          <?php if ($error_message) echo 'aria-describedby="psc-conversation-reply-error" aria-invalid="true"'; ?>
        ><?php echo esc_textarea($conv['reply_draft'] ?: ''); ?></textarea>
      </p>
      <p><button type="submit" class="psc-portal-btn-gold"><?php esc_html_e('Répondre', 'periscolaire-registration'); ?></button></p>
    </form>
    <script>
    (function(){
        var err = document.getElementById('psc-conversation-reply-error');
        var field = document.getElementById('psc-conversation-reply-body');
        if (err && field) field.focus();
    })();
    </script>

  <?php elseif ($conv['reply_target'] || isset($_GET['psc_new'])):
      $reply_target = $conv['reply_target'];
      $draft = $conv['draft'];
  ?>
    <p><a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges'), Psc_Mailer::form_page_url())); ?>">&larr; <?php esc_html_e('Mes échanges', 'periscolaire-registration'); ?></a></p>
    <h2><?php esc_html_e('Écrire à la mairie', 'periscolaire-registration'); ?></h2>

    <?php if ($error_message): ?>
      <p class="psc-notice psc-notice-err" role="alert" id="psc-conversation-create-error"><?php echo esc_html($error_message); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="psc-conversation-create-form">
      <?php wp_nonce_field('psc_parent_conversation_create'); psc_parent_nonce_field('psc_parent_conversation_create'); ?>
      <input type="hidden" name="action" value="psc_parent_conversation_create">
      <?php if ($reply_target): ?>
        <input type="hidden" name="message_id" value="<?php echo (int) $reply_target->id; ?>">
        <p>
          <span><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></span><br>
          <strong><?php echo esc_html(sprintf(__('Re : %s', 'periscolaire-registration'), $reply_target->titre)); ?></strong>
        </p>
      <?php else: ?>
        <p>
          <label for="psc-conversation-sujet"><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></label><br>
          <input type="text" id="psc-conversation-sujet" name="sujet" maxlength="160" required
            value="<?php echo esc_attr($draft['sujet'] ?? ''); ?>"
            <?php if ($error_message === $errors['psc_conversation_sujet']) echo 'aria-describedby="psc-conversation-create-error" aria-invalid="true"'; ?>>
        </p>
      <?php endif; ?>
      <p>
        <label for="psc-conversation-corps"><?php esc_html_e('Message', 'periscolaire-registration'); ?></label><br>
        <textarea id="psc-conversation-corps" name="corps" rows="6" maxlength="2000" required
          aria-describedby="psc-conversation-medical-warning<?php echo $error_message ? ' psc-conversation-create-error' : ''; ?>"
          <?php if ($error_message) echo 'aria-invalid="true"'; ?>
        ><?php echo esc_textarea($draft['corps'] ?? ''); ?></textarea>
        <span id="psc-conversation-count" aria-live="polite"></span>
      </p>
      <p id="psc-conversation-medical-warning" class="description">
        <?php esc_html_e("Pour une allergie, un traitement ou un régime, mettez à jour la fiche de votre enfant : n'indiquez pas d'informations médicales détaillées ici.", 'periscolaire-registration'); ?>
      </p>
      <p><button type="submit" class="psc-portal-btn-gold"><?php esc_html_e('Envoyer', 'periscolaire-registration'); ?></button></p>
    </form>
    <script>
    (function(){
        var textarea = document.getElementById('psc-conversation-corps');
        var counter = document.getElementById('psc-conversation-count');
        var err = document.getElementById('psc-conversation-create-error');
        if (textarea && counter) {
            var update = function () {
                var remaining = 2000 - textarea.value.length;
                counter.textContent = remaining < 200 ? remaining + '<?php echo esc_js(__(' caractères restants', 'periscolaire-registration')); ?>' : '';
            };
            textarea.addEventListener('input', update);
            update();
        }
        var focusTarget = document.getElementById('psc-conversation-sujet') || textarea;
        if (err && focusTarget) focusTarget.focus();
    })();
    </script>

  <?php else: ?>
    <p><a class="psc-portal-btn-gold" href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_new' => 1), Psc_Mailer::form_page_url())); ?>" data-testid="conversation-new"><?php esc_html_e('Écrire à la mairie', 'periscolaire-registration'); ?></a></p>

    <?php if (!$conv['conversations']): ?>
      <p><?php esc_html_e('Aucun échange pour le moment.', 'periscolaire-registration'); ?></p>
    <?php else: ?>
      <ul class="psc-conversation-list" style="list-style:none;margin:0;padding:0;">
        <?php foreach ($conv['conversations'] as $c): ?>
          <li>
            <a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => (int) $c->id), Psc_Mailer::form_page_url())); ?>">
              <strong><?php echo esc_html($c->sujet); ?></strong>
              <span><?php echo esc_html(date_i18n('d/m/Y', strtotime($c->dernier_message_at))); ?></span>
              <?php if (!empty($c->non_lu)): ?><span><?php esc_html_e('Nouveau message de la mairie', 'periscolaire-registration'); ?></span><?php endif; ?>
              <?php if ($c->statut === 'close'): ?><span><?php esc_html_e('Close', 'periscolaire-registration'); ?></span><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
