<?php if (!defined('ABSPATH')) exit; $cats = Psc_Messages::get_categories(); ?>
<div class="wrap psc-messages-admin">
  <h1 class="wp-heading-inline"><?php esc_html_e('Messages', 'periscolaire-registration'); ?></h1>
  <a href="<?php echo esc_url(admin_url('admin.php?page=psc_message_edit')); ?>" class="page-title-action"><?php esc_html_e('Nouveau message', 'periscolaire-registration'); ?></a>
  <hr class="wp-header-end">
  <?php if (!empty($_GET['psc_msg'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Action effectuée.', 'periscolaire-registration'); ?></p></div><?php endif; ?>
  <div class="psc-message-tiles">
    <div><small><?php esc_html_e('Envoyés cette année', 'periscolaire-registration'); ?></small><strong><?php echo (int) $summary['sent']; ?></strong></div>
    <div><small><?php esc_html_e('Taux de lecture moyen', 'periscolaire-registration'); ?></small><strong><?php echo (int) $summary['rate']; ?> %</strong></div>
    <div><small><?php esc_html_e('Familles destinataires', 'periscolaire-registration'); ?></small><strong><?php echo (int) $summary['families']; ?></strong></div>
    <div><small><?php esc_html_e('Sans e-mail valide', 'periscolaire-registration'); ?></small><strong class="psc-message-danger"><?php echo (int) $summary['invalid']; ?></strong></div>
  </div>
  <form method="get" class="psc-message-filters">
    <input type="hidden" name="page" value="psc_messages"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Rechercher un message…', 'periscolaire-registration'); ?>">
    <?php foreach (array('' => __('Tous', 'periscolaire-registration'), 'envoye' => __('Envoyé', 'periscolaire-registration'), 'programme' => __('Programmé', 'periscolaire-registration'), 'brouillon' => __('Brouillon', 'periscolaire-registration')) as $key => $label): ?>
      <a class="button<?php echo $status === $key ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_messages', 'statut' => $key), admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?><button class="button"><?php esc_html_e('Rechercher', 'periscolaire-registration'); ?></button>
  </form>
  <table class="wp-list-table widefat fixed striped"><thead><tr><th><?php esc_html_e('Message', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Date', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Destinataires', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Lecture', 'periscolaire-registration'); ?></th></tr></thead><tbody>
  <?php if (!$messages): ?><tr><td colspan="5"><?php esc_html_e('Aucun message.', 'periscolaire-registration'); ?></td></tr><?php endif; ?>
  <?php foreach ($messages as $m): $cat = $cats[$m->categorie]; $rate = $m->destinataires ? (int) round($m->vus * 100 / $m->destinataires) : 0; ?>
    <tr><td><span class="psc-cat" style="--cat-bg:<?php echo esc_attr($cat['bg']); ?>;--cat-fg:<?php echo esc_attr($cat['fg']); ?>"><?php echo esc_html($cat['label']); ?></span><?php if ($m->epingle): ?> <span class="psc-pinned"><?php esc_html_e('Épinglé', 'periscolaire-registration'); ?></span><?php endif; ?><br><strong><a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_message_edit', 'id' => $m->id), admin_url('admin.php'))); ?>"><?php echo esc_html($m->titre); ?></a></strong><div class="description"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($m->corps), 16, '…')); ?></div></td>
    <td><span class="psc-status psc-status-<?php echo esc_attr($m->statut); ?>"><?php echo esc_html(ucfirst($m->statut)); ?></span></td>
    <td><?php $date = $m->date_envoi ?: ($m->date_envoi_prevue ?: $m->created_at); echo esc_html(date_i18n('d/m/Y', strtotime($date))); ?><br><small><?php echo esc_html(date_i18n('H:i', strtotime($date))); ?></small></td>
    <td><?php echo esc_html($m->cible_type === 'all' ? __('Toutes les familles', 'periscolaire-registration') : ucfirst($m->cible_type)); ?><br><small><?php echo (int) $m->destinataires; ?> <?php esc_html_e('familles', 'periscolaire-registration'); ?></small></td>
    <td><?php if ($m->statut === 'envoye'): ?><div class="psc-progress"><i style="width:<?php echo $rate; ?>%;background:<?php echo $rate >= 80 ? '#24405C' : '#E08A5F'; ?>"></i></div><strong><?php echo $rate; ?> %</strong><br><a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_message_stats', 'id' => $m->id), admin_url('admin.php'))); ?>"><?php esc_html_e('Voir qui a lu', 'periscolaire-registration'); ?></a><?php else: ?>—<?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
