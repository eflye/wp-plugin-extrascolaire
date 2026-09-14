<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-messages-admin">
  <h1 class="wp-heading-inline"><?php echo esc_html($conversation->sujet); ?></h1>
  <a href="<?php echo esc_url(admin_url('admin.php?page=psc_conversations')); ?>" class="page-title-action"><?php esc_html_e('Retour aux échanges', 'periscolaire-registration'); ?></a>
  <hr class="wp-header-end">

  <?php if (!empty($_GET['psc_msg'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Action effectuée.', 'periscolaire-registration'); ?></p></div><?php endif; ?>

  <div class="psc-box">
    <p>
      <strong><?php echo esc_html($family ? (trim($family->prenom . ' ' . $family->nom) ?: $family->email) : '#' . (int) $conversation->family_id); ?></strong>
      <?php if ($family): ?> — <?php echo esc_html($family->email); ?><?php endif; ?>
    </p>
    <p>
      <?php if ($family): ?>
      <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_parents', 'edit' => (int) $family->id), admin_url('admin.php'))); ?>"><?php esc_html_e('Éditer la famille', 'periscolaire-registration'); ?></a>
      <?php if ($family->active && current_user_can('psc_impersonate_family')): ?>
      <a class="button button-secondary" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_impersonate', 'family_id' => (int) $family->id), admin_url('admin.php'))); ?>"><?php esc_html_e('Voir son espace', 'periscolaire-registration'); ?></a>
      <?php endif; ?>
      <?php endif; ?>
    </p>
    <?php if ($source_message): ?>
      <p><?php esc_html_e('Réponse à la diffusion :', 'periscolaire-registration'); ?>
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_message_stats', 'id' => (int) $source_message->id), admin_url('admin.php'))); ?>"><?php echo esc_html($source_message->titre); ?></a>
      </p>
    <?php endif; ?>
    <p><?php echo $conversation->statut === 'close' ? esc_html__('Statut : close', 'periscolaire-registration') : esc_html__('Statut : ouverte', 'periscolaire-registration'); ?></p>
  </div>

  <div class="psc-box">
    <ol class="psc-conversation-thread" style="list-style:none;margin:0;padding:0;">
      <?php foreach ($messages as $m): $author = $m->auteur_type === 'mairie' ? get_userdata((int) $m->auteur_user_id) : null; ?>
        <li style="margin-bottom:16px;">
          <article>
            <header>
              <strong><?php echo $m->auteur_type === 'mairie' ? esc_html($author ? $author->display_name : __('Mairie', 'periscolaire-registration')) : esc_html(sprintf(__('Famille %s', 'periscolaire-registration'), $family ? trim($family->nom) : '')); ?></strong>
              <time datetime="<?php echo esc_attr(mysql2date('c', $m->created_at)); ?>"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($m->created_at))); ?></time>
            </header>
            <p><?php echo nl2br(esc_html($m->corps)); ?></p>
          </article>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <div class="psc-box">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('psc_conversation_reply'); ?>
      <input type="hidden" name="action" value="psc_conversation_reply">
      <input type="hidden" name="id" value="<?php echo (int) $conversation->id; ?>">
      <p>
        <label for="psc-conversation-reply-body"><?php esc_html_e('Répondre', 'periscolaire-registration'); ?></label><br>
        <textarea id="psc-conversation-reply-body" name="corps" rows="5" class="large-text" maxlength="2000" required></textarea>
      </p>
      <p>
        <button type="submit" name="submit_action" value="reply" class="button button-primary"><?php esc_html_e('Répondre', 'periscolaire-registration'); ?></button>
        <?php if ($conversation->statut !== 'close'): ?>
          <button type="submit" name="submit_action" value="reply_close" class="button"><?php esc_html_e('Répondre et clore', 'periscolaire-registration'); ?></button>
        <?php else: ?>
          <button type="submit" name="submit_action" value="reply_reopen" class="button"><?php esc_html_e('Répondre et rouvrir', 'periscolaire-registration'); ?></button>
        <?php endif; ?>
      </p>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
      <?php wp_nonce_field($conversation->statut === 'close' ? 'psc_conversation_reopen' : 'psc_conversation_close'); ?>
      <input type="hidden" name="action" value="<?php echo $conversation->statut === 'close' ? 'psc_conversation_reopen' : 'psc_conversation_close'; ?>">
      <input type="hidden" name="id" value="<?php echo (int) $conversation->id; ?>">
      <button type="submit" class="button"><?php echo $conversation->statut === 'close' ? esc_html__('Rouvrir', 'periscolaire-registration') : esc_html__('Clore', 'periscolaire-registration'); ?></button>
    </form>
  </div>
</div>
