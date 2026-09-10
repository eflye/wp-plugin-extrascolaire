<?php if (!defined('ABSPATH')) exit;
$data = isset($psc_messages_data) ? $psc_messages_data : array('messages'=>array(),'selected'=>null,'unread'=>0,'standalone'=>false);
$selected = $data['selected']; $cats = Psc_Messages::get_categories();
?>
<div class="psc-family-messages<?php echo !empty($data['standalone'])?' is-standalone':''; ?>">
  <header><span class="psc-message-kicker"><?php esc_html_e('Informations de la mairie','periscolaire-registration'); ?></span><h2><?php esc_html_e('Messages','periscolaire-registration'); ?></h2><p><?php printf(esc_html__('%d nouveau(x) message(s) · conservés pendant toute l’année scolaire','periscolaire-registration'),(int)$data['unread']); ?></p></header>
  <div class="psc-message-inbox">
    <?php if (empty($data['standalone'])): ?><nav class="psc-message-list" aria-label="<?php esc_attr_e('Liste des messages','periscolaire-registration'); ?>">
      <?php if (!$data['messages']): ?><p><?php esc_html_e('Aucun message pour le moment.','periscolaire-registration'); ?></p><?php endif; ?>
      <?php foreach($data['messages'] as $item): $cat=$cats[$item->categorie]; $url=add_query_arg(array('psc_tab'=>'messages','message_id'=>(int)$item->id),Psc_Mailer::form_page_url()); ?>
      <a class="psc-message-list-item<?php echo !$item->vu_le?' is-unread':''; ?><?php echo $selected&&(int)$selected->id===(int)$item->id?' is-selected':''; ?>" href="<?php echo esc_url($url); ?>">
        <span class="psc-message-list-top"><?php if(!$item->vu_le): ?><i></i><?php endif; ?><span class="psc-cat" style="--cat-bg:<?php echo esc_attr($cat['bg']); ?>;--cat-fg:<?php echo esc_attr($cat['fg']); ?>"><?php echo esc_html($cat['label']); ?></span><time><?php echo esc_html(date_i18n('d/m/Y',strtotime($item->date_envoi))); ?></time></span><strong><?php echo esc_html($item->titre); ?></strong><small><?php echo esc_html(wp_trim_words(wp_strip_all_tags($item->corps),14,'…')); ?></small>
      </a><?php endforeach; ?>
    </nav><?php endif; ?>
    <?php if($selected): $cat=$cats[$selected->categorie]; ?>
    <article class="psc-message-open"><div><span class="psc-cat" style="--cat-bg:<?php echo esc_attr($cat['bg']); ?>;--cat-fg:<?php echo esc_attr($cat['fg']); ?>"><?php echo esc_html($cat['label']); ?></span> <small><?php printf(esc_html__('Mairie de Montgeroult · %s','periscolaire-registration'),esc_html(date_i18n('d/m/Y',strtotime($selected->date_envoi)))); ?></small></div><h3><?php echo esc_html($selected->titre); ?></h3><div class="psc-message-body"><?php echo wp_kses($selected->corps,Psc_Messages::allowed_html()); ?></div>
      <?php if($selected->piece_jointe_id && ($url=wp_get_attachment_url($selected->piece_jointe_id))): $path=get_attached_file($selected->piece_jointe_id); ?><div class="psc-message-file"><span aria-hidden="true">▤</span><span><strong><?php echo esc_html(get_the_title($selected->piece_jointe_id) ?: basename($path)); ?></strong><?php if($path&&is_file($path)): ?><small><?php echo esc_html(size_format(filesize($path))); ?></small><?php endif; ?></span><a class="psc-message-download" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Télécharger','periscolaire-registration'); ?></a></div><?php endif; ?>
      <footer class="psc-message-transparency">✓ <?php printf(esc_html__('Lu le %s — la mairie sait que vous avez reçu ce message.','periscolaire-registration'),esc_html(date_i18n('d/m',strtotime($selected->vu_le ?: current_time('mysql'))))); ?><small><?php esc_html_e('La date de première consultation est conservée pendant l’année scolaire en cours + 1 an, uniquement pour vérifier la bonne réception. Aucun profilage ni réutilisation.','periscolaire-registration'); ?></small></footer>
      <?php if(empty($data['standalone']) && $selected->accuse_requis && !$selected->accuse_le): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="psc_message_ack"><input type="hidden" name="message_id" value="<?php echo (int)$selected->id; ?>"><?php wp_nonce_field('psc_message_ack'); ?><button class="psc-message-ack"><?php esc_html_e("J'ai pris connaissance",'periscolaire-registration'); ?></button></form><?php endif; ?>
    </article><?php endif; ?>
  </div>
</div>
