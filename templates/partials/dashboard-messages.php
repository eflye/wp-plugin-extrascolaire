<?php
if (!defined('ABSPATH')) exit;
$digest = isset($psc_portal_dashboard['message_digest']) ? $psc_portal_dashboard['message_digest'] : array();
if (!$digest) return;
usort($digest, function ($a, $b) { return strcmp((string) $b->date_envoi, (string) $a->date_envoi); });
$unread = isset($psc_portal_dashboard['message_unread']) ? (int) $psc_portal_dashboard['message_unread'] : 0;
$urgent = !empty($psc_portal_dashboard['urgent_message']) ? $psc_portal_dashboard['urgent_message'] : null;
$limit = $urgent ? 1 : 3;
$categories = Psc_Messages::get_categories();
$messages_url = add_query_arg('psc_tab', 'messages', Psc_Mailer::form_page_url());
?>
<aside class="psc-dashboard-messages" data-testid="dashboard-messages">
  <header class="psc-dashboard-messages-head">
    <span><?php esc_html_e('Informations de la mairie', 'periscolaire-registration'); ?></span>
    <?php if ($unread > 0): ?>
      <strong><?php echo esc_html(sprintf(_n('%d non lu', '%d non lus', $unread, 'periscolaire-registration'), $unread)); ?></strong>
    <?php endif; ?>
    <a href="<?php echo esc_url($messages_url); ?>"><?php esc_html_e('Tous les messages', 'periscolaire-registration'); ?></a>
  </header>

  <?php if ($urgent):
      $urgent_url = add_query_arg(array('psc_tab' => 'messages', 'message_id' => (int) $urgent->id), Psc_Mailer::form_page_url());
      $urgent_excerpt = wp_strip_all_tags($urgent->corps);
      if (function_exists('mb_substr')) $urgent_excerpt = mb_substr($urgent_excerpt, 0, 110); else $urgent_excerpt = substr($urgent_excerpt, 0, 110);
  ?>
    <div class="psc-dashboard-message-urgent" role="alert">
      <span><?php esc_html_e('Urgent', 'periscolaire-registration'); ?></span>
      <strong><?php echo esc_html($urgent->titre); ?></strong>
      <small><?php echo esc_html($urgent_excerpt); ?></small>
      <a href="<?php echo esc_url($urgent_url); ?>"><?php esc_html_e('Lire', 'periscolaire-registration'); ?></a>
    </div>
  <?php endif; ?>

  <div class="psc-dashboard-message-digest">
    <?php foreach (array_slice($digest, 0, $limit) as $message):
        $category = isset($categories[$message->categorie]) ? $categories[$message->categorie] : $categories['information'];
        $url = add_query_arg(array('psc_tab' => 'messages', 'message_id' => (int) $message->id), Psc_Mailer::form_page_url());
    ?>
      <a href="<?php echo esc_url($url); ?>" class="<?php echo $message->vu_le ? '' : 'is-unread'; ?>">
        <i aria-hidden="true"></i>
        <span class="psc-cat" style="--cat-bg:<?php echo esc_attr($category['bg']); ?>;--cat-fg:<?php echo esc_attr($category['fg']); ?>"><?php echo esc_html($category['label']); ?></span>
        <b><?php echo esc_html($message->titre); ?></b>
        <time datetime="<?php echo esc_attr(mysql2date('c', $message->date_envoi)); ?>"><?php echo esc_html(date_i18n('j M', strtotime($message->date_envoi))); ?></time>
      </a>
    <?php endforeach; ?>
  </div>
</aside>
