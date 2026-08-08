<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Menus cantine</h1>

<?php
$msgs = array(
    'saved'     => array('updated',       'Menu enregistré.'),
    'sent'      => array('updated',       'Menu envoyé aux familles.'),
    'sent_zero' => array('notice-warning', 'Aucune famille active à notifier (vérifiez qu\'il y a des enfants actifs).'),
    'deleted'   => array('updated',       'Menu supprimé.'),
    'invalid'   => array('error',         'Paramètre invalide.'),
);
if ($psc_msg && isset($msgs[$psc_msg])):
    list($cls, $txt) = $msgs[$psc_msg];
?>
<div class="notice notice-<?php echo esc_attr($cls); ?> is-dismissible"><p><?php echo esc_html($txt); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<h2><?php echo $editing ? 'Modifier le menu' : 'Saisir un menu'; ?></h2>
<p>
    Une semaine = un menu. Choisissez n'importe quelle date de la semaine
    visée, elle sera automatiquement ramenée au lundi. L'e-mail n'est
    envoyé qu'après avoir cliqué sur « Envoyer aux familles » ci-dessous —
    l'enregistrement seul reste un brouillon.
</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_save_menu'); ?>
<input type="hidden" name="action" value="psc_save_menu">
<input type="hidden" name="id" value="<?php echo esc_attr($editing ? $editing->id : 0); ?>">
<table class="form-table">
<tr>
    <th><label for="psc-menu-semaine">Semaine du</label></th>
    <td><input id="psc-menu-semaine" type="date" name="semaine_debut" value="<?php echo esc_attr($default_week); ?>" required></td>
</tr>
<?php foreach (Psc_Menus::jour_labels() as $key => $label): ?>
<tr>
    <th><label for="psc-menu-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
    <td>
        <textarea id="psc-menu-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="3" class="large-text" maxlength="2000"><?php
            echo esc_textarea($editing ? $editing->$key : '');
        ?></textarea>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php submit_button($editing ? 'Enregistrer les modifications' : 'Enregistrer le menu'); ?>
</form>
</div>

<div class="psc-box">
<h2>Menus enregistrés</h2>
<table class="widefat striped">
<thead>
<tr>
    <th>Semaine du</th>
    <th>Aperçu</th>
    <th>Envoi</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php if (empty($recent)): ?>
<tr><td colspan="4">Aucun menu enregistré pour le moment.</td></tr>
<?php else: foreach ($recent as $m): ?>
<tr>
    <td><strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($m->semaine_debut))); ?></strong></td>
    <td>
        <?php
        $preview = array();
        foreach (Psc_Menus::jour_labels() as $key => $label) {
            if (trim((string) $m->$key) !== '') $preview[] = $label;
        }
        echo $preview ? esc_html(implode(', ', $preview) . ' renseigné(s)') : '<em>vide</em>';
        ?>
    </td>
    <td>
        <?php if ($m->sent_at): ?>
            <span style="color:#46b450">✔ <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($m->sent_at))); ?></span>
        <?php else: ?>
            <span style="color:#999">Non envoyé</span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_menus', 'edit' => $m->id), admin_url('admin.php'))); ?>">
            Modifier
        </a>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="psc_send_menu">
            <input type="hidden" name="id" value="<?php echo esc_attr($m->id); ?>">
            <?php wp_nonce_field('psc_send_menu'); ?>
            <button type="submit" class="button button-small <?php echo $m->sent_at ? '' : 'button-primary'; ?>"
                    onclick="return confirm('Envoyer ce menu à toutes les familles actives ?');">
                &#9993; <?php echo $m->sent_at ? 'Renvoyer' : 'Envoyer aux familles'; ?>
            </button>
        </form>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('Supprimer ce menu ?');">
            <input type="hidden" name="action" value="psc_delete_menu">
            <input type="hidden" name="id" value="<?php echo esc_attr($m->id); ?>">
            <?php wp_nonce_field('psc_delete_menu'); ?>
            <button type="submit" class="button button-small button-link-delete">Supprimer</button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
