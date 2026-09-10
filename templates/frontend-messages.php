<?php
if (!defined('ABSPATH')) exit;
$data = isset($psc_messages_data) ? $psc_messages_data : array('messages' => array(), 'selected' => null, 'unread' => 0, 'standalone' => false);
$selected = $data['selected'];
$categories = Psc_Messages::get_categories();
$unread = (int) $data['unread'];
?>
<div class="psc-family-messages<?php echo !empty($data['standalone']) ? ' is-standalone' : ''; ?>">
  <header>
    <span class="psc-message-kicker"><?php esc_html_e('Informations de la mairie', 'periscolaire-registration'); ?></span>
    <h2><?php esc_html_e('Messages', 'periscolaire-registration'); ?></h2>
    <p><?php echo esc_html(sprintf(_n('%d non lu · conservé pendant toute l’année scolaire.', '%d non lus · conservés pendant toute l’année scolaire.', $unread, 'periscolaire-registration'), $unread)); ?></p>
  </header>
  <div class="psc-message-inbox">
    <?php if (empty($data['standalone'])): ?>
      <nav class="psc-message-list" aria-label="<?php esc_attr_e('Liste des messages', 'periscolaire-registration'); ?>">
        <?php if (!$data['messages']): ?><p><?php esc_html_e('Aucun message pour le moment.', 'periscolaire-registration'); ?></p><?php endif; ?>
        <?php foreach ($data['messages'] as $item):
            $category = isset($categories[$item->categorie]) ? $categories[$item->categorie] : $categories['information'];
            $url = add_query_arg(array('psc_tab' => 'messages', 'message_id' => (int) $item->id), Psc_Mailer::form_page_url());
            $is_selected = $selected && (int) $selected->id === (int) $item->id;
        ?>
          <a class="psc-message-list-item<?php echo !$item->vu_le ? ' is-unread' : ''; ?><?php echo $is_selected ? ' is-selected' : ''; ?>" href="<?php echo esc_url($url); ?>">
            <span class="psc-message-list-top"><i aria-hidden="true"></i><span class="psc-cat" style="--cat-bg:<?php echo esc_attr($category['bg']); ?>;--cat-fg:<?php echo esc_attr($category['fg']); ?>"><?php echo esc_html($category['label']); ?></span><time datetime="<?php echo esc_attr(mysql2date('c', $item->date_envoi)); ?>"><?php echo esc_html(date_i18n('d/m/Y', strtotime($item->date_envoi))); ?></time></span>
            <strong><?php echo esc_html($item->titre); ?></strong>
            <small><?php echo esc_html(wp_trim_words(wp_strip_all_tags($item->corps), 14, '…')); ?></small>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php if ($selected):
        $category = isset($categories[$selected->categorie]) ? $categories[$selected->categorie] : $categories['information'];
    ?>
      <article class="psc-message-open">
        <div class="psc-message-open-meta"><span class="psc-cat" style="--cat-bg:<?php echo esc_attr($category['bg']); ?>;--cat-fg:<?php echo esc_attr($category['fg']); ?>"><?php echo esc_html($category['label']); ?></span><small><?php printf(esc_html__('Mairie de Montgeroult · %s', 'periscolaire-registration'), esc_html(date_i18n('j F Y', strtotime($selected->date_envoi)))); ?></small></div>
        <h3><?php echo esc_html($selected->titre); ?></h3>
        <div class="psc-message-body"><?php echo wp_kses_post($selected->corps); ?></div>
        <?php if ($selected->piece_jointe_id && ($attachment_url = wp_get_attachment_url($selected->piece_jointe_id))):
            $path = get_attached_file($selected->piece_jointe_id);
            $file_name = get_the_title($selected->piece_jointe_id) ?: ($path ? basename($path) : __('Document joint', 'periscolaire-registration'));
        ?>
          <div class="psc-message-file"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#24405C" stroke-width="1.6" aria-hidden="true"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"></path><path d="M14 3v5h5"></path></svg><span><strong><?php echo esc_html($file_name); ?></strong><?php if ($path && is_file($path)): ?><small><?php echo esc_html(size_format(filesize($path))); ?></small><?php endif; ?></span><a class="psc-message-download" href="<?php echo esc_url($attachment_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Télécharger', 'periscolaire-registration'); ?></a></div>
        <?php endif; ?>
        <footer class="psc-message-transparency">
          <div><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 12 4 4L19 6"></path></svg><span><?php printf(esc_html__('Lu le %s — la mairie sait que vous avez reçu ce message.', 'periscolaire-registration'), esc_html(date_i18n('d/m', strtotime($selected->vu_le ?: current_time('mysql'))))); ?></span></div>
          <?php if (empty($data['standalone']) && $selected->accuse_requis): ?>
            <?php if (!$selected->accuse_le): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="psc_message_ack"><input type="hidden" name="message_id" value="<?php echo (int) $selected->id; ?>"><?php wp_nonce_field('psc_message_ack'); ?><button class="psc-message-ack"><?php esc_html_e("J'ai pris connaissance", 'periscolaire-registration'); ?></button></form>
            <?php else: ?><span class="psc-message-ack-sent"><?php printf(esc_html__('Accusé de lecture envoyé le %s', 'periscolaire-registration'), esc_html(date_i18n('d/m', strtotime($selected->accuse_le)))); ?></span><?php endif; ?>
          <?php endif; ?>
        </footer>
      </article>
    <?php endif; ?>
  </div>
</div>
