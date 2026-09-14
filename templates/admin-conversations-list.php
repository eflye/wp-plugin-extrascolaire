<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-messages-admin">
  <h1 class="wp-heading-inline"><?php esc_html_e('Échanges familles', 'periscolaire-registration'); ?></h1>
  <hr class="wp-header-end">

  <?php if (!empty($_GET['psc_msg'])): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Action effectuée.', 'periscolaire-registration'); ?></p></div><?php endif; ?>
  <?php if ($source_message): ?>
    <div class="notice notice-info">
      <p>
        <?php printf(esc_html__('Filtré sur les conversations liées à la diffusion « %s ».', 'periscolaire-registration'), esc_html($source_message->titre)); ?>
        <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_conversations', 'filtre' => $filtre), admin_url('admin.php'))); ?>"><?php esc_html_e('Retirer ce filtre', 'periscolaire-registration'); ?></a>
      </p>
    </div>
  <?php endif; ?>

  <details id="psc-conversation-new" class="psc-box"<?php echo $new_family_id ? ' open' : ''; ?>>
    <summary><?php esc_html_e('Nouvelle conversation', 'periscolaire-registration'); ?></summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('psc_conversation_create'); ?>
      <input type="hidden" name="action" value="psc_conversation_create">
      <table class="form-table">
        <tr>
          <th><label for="psc-conv-family"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></label></th>
          <td>
            <select id="psc-conv-family" name="family_id" required>
              <option value=""><?php esc_html_e('— Choisir —', 'periscolaire-registration'); ?></option>
              <?php foreach ($families as $f): ?>
                <option value="<?php echo (int) $f->id; ?>" <?php selected($new_family_id, (int) $f->id); ?>>
                  <?php echo esc_html(trim($f->nom . ' ' . $f->prenom) . ' — ' . $f->email); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>
        <tr>
          <th><label for="psc-conv-sujet"><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></label></th>
          <td><input id="psc-conv-sujet" type="text" name="sujet" class="regular-text" maxlength="160" required></td>
        </tr>
        <tr>
          <th><label for="psc-conv-corps"><?php esc_html_e('Message', 'periscolaire-registration'); ?></label></th>
          <td><textarea id="psc-conv-corps" name="corps" rows="5" class="large-text" maxlength="2000" required></textarea></td>
        </tr>
      </table>
      <p><button type="submit" class="button button-primary"><?php esc_html_e('Envoyer', 'periscolaire-registration'); ?></button></p>
    </form>
  </details>

  <form method="get" class="psc-message-filters">
    <input type="hidden" name="page" value="psc_conversations">
    <?php if ($source_message): ?><input type="hidden" name="message_id" value="<?php echo (int) $source_message->id; ?>"><?php endif; ?>
    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Rechercher une famille ou un sujet…', 'periscolaire-registration'); ?>">
    <?php foreach (array('non_lues' => __('Non lues', 'periscolaire-registration'), 'ouvertes' => __('Ouvertes', 'periscolaire-registration'), 'closes' => __('Closes', 'periscolaire-registration'), 'toutes' => __('Toutes', 'periscolaire-registration')) as $key => $label):
        $filter_args = array('page' => 'psc_conversations', 'filtre' => $key);
        if ($source_message) $filter_args['message_id'] = (int) $source_message->id;
    ?>
      <a class="button<?php echo $filtre === $key ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg($filter_args, admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
    <button class="button"><?php esc_html_e('Rechercher', 'periscolaire-registration'); ?></button>
  </form>

  <table class="wp-list-table widefat fixed striped">
    <thead><tr>
      <th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th>
      <th><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></th>
      <th><?php esc_html_e("Diffusion d'origine", 'periscolaire-registration'); ?></th>
      <th><?php esc_html_e('Dernier message', 'periscolaire-registration'); ?></th>
      <th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th>
    </tr></thead>
    <tbody>
      <?php if (!$result['items']): ?><tr><td colspan="5"><?php esc_html_e('Aucune conversation.', 'periscolaire-registration'); ?></td></tr><?php endif; ?>
      <?php foreach ($result['items'] as $c): ?>
        <tr>
          <td>
            <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_conversation', 'id' => (int) $c->id), admin_url('admin.php'))); ?>">
              <?php echo esc_html(trim($c->prenom . ' ' . $c->nom) ?: $c->email); ?>
            </a>
            <?php if (!empty($c->non_lu)): ?><br><small class="psc-message-danger"><?php esc_html_e('Non lu', 'periscolaire-registration'); ?></small><?php endif; ?>
          </td>
          <td><?php echo esc_html($c->sujet); ?></td>
          <td>
            <?php if ($c->message_id): ?>
              <a href="<?php echo esc_url(add_query_arg(array('page' => 'psc_message_stats', 'id' => (int) $c->message_id), admin_url('admin.php'))); ?>"><?php esc_html_e('Voir le suivi', 'periscolaire-registration'); ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($c->dernier_message_at))); ?><br>
            <small><?php echo $c->dernier_auteur === 'mairie' ? esc_html__('Mairie', 'periscolaire-registration') : esc_html__('Famille', 'periscolaire-registration'); ?></small>
          </td>
          <td><?php echo $c->statut === 'close' ? esc_html__('Close', 'periscolaire-registration') : esc_html__('Ouverte', 'periscolaire-registration'); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php $total_pages = (int) ceil($result['total'] / max(1, $result['per_page'])); if ($total_pages > 1): ?>
    <div class="tablenav"><div class="tablenav-pages">
      <?php for ($p = 1; $p <= $total_pages; $p++):
          $page_args = array('page' => 'psc_conversations', 'filtre' => $filtre, 's' => $search, 'paged' => $p);
          if ($source_message) $page_args['message_id'] = (int) $source_message->id;
      ?>
        <a class="button<?php echo $p === $result['page'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg($page_args, admin_url('admin.php'))); ?>"><?php echo (int) $p; ?></a>
      <?php endfor; ?>
    </div></div>
  <?php endif; ?>
</div>
