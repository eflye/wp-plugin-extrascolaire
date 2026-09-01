<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Menus cantine', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'saved'     => array('updated',  __('Menu enregistré.', 'periscolaire-registration')),
    'sent'      => array('updated',  __('Menu envoyé aux familles.', 'periscolaire-registration')),
    'sent_zero' => array('warning',  __("Aucune famille active à notifier (vérifiez qu'il y a des enfants actifs).", 'periscolaire-registration')),
    'deleted'   => array('updated',  __('Menu supprimé.', 'periscolaire-registration')),
    'invalid'   => array('error',    __('Paramètre invalide.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg);
?>

<div class="psc-box">
<h2><?php echo $editing ? esc_html__('Modifier le menu', 'periscolaire-registration') : esc_html__('Saisir un menu', 'periscolaire-registration'); ?> — <?php esc_html_e('semaine du', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($target_week))); ?></h2>
<p>
    <?php esc_html_e("Une semaine = un menu. Seuls les jours d'école effectivement ouverts sont proposés ci-dessous : les vacances scolaires et les fermetures ponctuelles (Périscolaire", 'periscolaire-registration'); ?>
    &gt; <?php esc_html_e('Calendrier scolaire) sont pris en compte automatiquement.', 'periscolaire-registration'); ?>
    <?php esc_html_e("L'e-mail n'est envoyé qu'après avoir cliqué sur « Envoyer aux familles » dans la liste ci-dessous — l'enregistrement seul reste un brouillon.", 'periscolaire-registration'); ?>
</p>

<form method="get" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
    <input type="hidden" name="page" value="psc_menus">
    <label for="psc-menu-week-picker"><strong><?php esc_html_e('Semaine du', 'periscolaire-registration'); ?></strong></label>
    <input id="psc-menu-week-picker" type="date" name="semaine_debut" value="<?php echo esc_attr($target_week); ?>">
    <button type="submit" class="button"><?php esc_html_e('Changer de semaine', 'periscolaire-registration'); ?></button>
</form>

<?php if (empty($open_days)): ?>
<p><em><?php esc_html_e("Aucun jour d'école cette semaine-là (vacances scolaires ou jour férié) — pas de service cantine, rien à saisir.", 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_menu'); ?>
<input type="hidden" name="action" value="psc_save_menu">
<input type="hidden" name="id" value="<?php echo esc_attr($editing ? $editing->id : 0); ?>">
<input type="hidden" name="semaine_debut" value="<?php echo esc_attr($target_week); ?>">
<table class="form-table">
<?php foreach (Psc_Menus::jour_labels() as $key => $label): if (!isset($open_days[$key])) continue; ?>
<tr>
    <th><label for="psc-menu-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?> <span class="description">(<?php echo esc_html(date_i18n('d/m', strtotime($open_days[$key]))); ?>)</span></label></th>
    <td>
        <textarea id="psc-menu-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="3" class="large-text" maxlength="2000"><?php
            echo esc_textarea($editing ? $editing->$key : '');
        ?></textarea>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php submit_button($editing ? __('Enregistrer les modifications', 'periscolaire-registration') : __('Enregistrer le menu', 'periscolaire-registration')); ?>
</form>
<?php endif; ?>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Menus enregistrés', 'periscolaire-registration'); ?></h2>
<table class="widefat striped">
<thead>
<tr>
    <th><?php esc_html_e('Semaine du', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Aperçu', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Envoi', 'periscolaire-registration'); ?></th>
    <th><?php esc_html_e('Actions', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php if (empty($recent)): ?>
<tr><td colspan="4"><?php esc_html_e('Aucun menu enregistré pour le moment.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($recent as $m): ?>
<tr>
    <td><strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($m->semaine_debut))); ?></strong></td>
    <td>
        <?php
        $preview = array();
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            if (trim((string) $m->$key) !== '') $preview[] = $label;
        }
        echo $preview ? esc_html(implode(', ', $preview) . ' ' . __('renseigné(s)', 'periscolaire-registration')) : '<em>' . esc_html__('vide', 'periscolaire-registration') . '</em>';
        ?>
    </td>
    <td>
        <?php if ($m->sent_at): ?>
            <span style="color:#46b450">✔ <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($m->sent_at))); ?></span>
        <?php else: ?>
            <span style="color:#999"><?php esc_html_e('Non envoyé', 'periscolaire-registration'); ?></span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_menus', 'semaine_debut' => $m->semaine_debut), admin_url('admin.php'))); ?>">
            <?php esc_html_e('Modifier', 'periscolaire-registration'); ?>
        </a>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="psc_send_menu">
            <input type="hidden" name="id" value="<?php echo esc_attr($m->id); ?>">
            <?php wp_nonce_field('psc_send_menu'); ?>
            <button type="submit" class="button button-small <?php echo $m->sent_at ? '' : 'button-primary'; ?>"
                    onclick="return confirm('<?php echo esc_js(__('Envoyer ce menu à toutes les familles actives ?', 'periscolaire-registration')); ?>');">
                &#9993; <?php echo $m->sent_at ? esc_html__('Renvoyer', 'periscolaire-registration') : esc_html__('Envoyer aux familles', 'periscolaire-registration'); ?>
            </button>
        </form>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Supprimer ce menu ?', 'periscolaire-registration')); ?>');">
            <input type="hidden" name="action" value="psc_delete_menu">
            <input type="hidden" name="id" value="<?php echo esc_attr($m->id); ?>">
            <?php wp_nonce_field('psc_delete_menu'); ?>
            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
