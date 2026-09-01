<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Enfants inscrits', 'periscolaire-registration'); ?></h1>

<?php
$psc_notices = array(
    'added'        => array('success', __('Enfant ajouté.', 'periscolaire-registration')),
    'deleted'      => array('success', __('Enfant supprimé, ainsi que ses inscriptions.', 'periscolaire-registration')),
    'marked_sorti' => array('success', __('Enfant marqué sorti.', 'periscolaire-registration')),
    'marked_actif' => array('success', __('Enfant marqué actif.', 'periscolaire-registration')),
    'nouser'       => array('error', __("Famille introuvable. Enregistrez-la d'abord dans l'onglet « Familles ».", 'periscolaire-registration')),
    'invalid'      => array('error', __('Merci de choisir une famille et de renseigner le nom et le prénom.', 'periscolaire-registration')),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<div class="psc-box">
<h2><?php esc_html_e('Rattacher un enfant à un parent existant', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Rattachez chaque enfant à une famille enregistrée. Les familles ne peuvent pas ajouter d'enfant elles-mêmes : c'est la mairie qui tient cette liste.", 'periscolaire-registration'); ?></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_child'); ?>
<input type="hidden" name="action" value="psc_add_child">
<table class="form-table">
<tr><th><label for="psc-parent"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></label></th><td>
<?php if (empty($parents)): ?>
  <em><?php esc_html_e("Enregistrez d'abord une famille dans l'onglet « Familles ».", 'periscolaire-registration'); ?></em>
<?php else: ?>
<select id="psc-parent" name="parent_id" required>
<option value=""><?php esc_html_e('— Choisir —', 'periscolaire-registration'); ?></option>
<?php foreach ($parents as $p): ?>
<option value="<?php echo esc_attr($p->id); ?>"><?php echo esc_html(($p->nom ? $p->nom . ' — ' : '') . $p->email); ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-nom"><?php esc_html_e("Nom de l'enfant", 'periscolaire-registration'); ?></label></th><td><input id="psc-nom" type="text" name="nom" maxlength="190" required></td></tr>
<tr><th><label for="psc-prenom"><?php esc_html_e("Prénom de l'enfant", 'periscolaire-registration'); ?></label></th><td><input id="psc-prenom" type="text" name="prenom" maxlength="190" required></td></tr>
<tr><th><label for="psc-classe"><?php esc_html_e('Classe', 'periscolaire-registration'); ?></label></th><td>
<select id="psc-classe" name="classe">
<?php foreach ($psc_classe_labels as $v => $l): ?>
<option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
<?php endforeach; ?>
</select>
<?php if (!Psc_School_Years::active_id()): ?>
<p class="description"><?php esc_html_e("Aucune année scolaire active : la classe ne pourra pas être enregistrée tant qu'une année n'est pas activée.", 'periscolaire-registration'); ?></p>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-naissance"><?php esc_html_e('Date de naissance', 'periscolaire-registration'); ?></label></th><td><input id="psc-naissance" type="date" name="naissance"></td></tr>
</table>
<?php submit_button(__('Ajouter', 'periscolaire-registration')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Liste des enfants', 'periscolaire-registration'); ?></h2>
<form method="get" style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
<input type="hidden" name="page" value="psc_children">
<label><?php esc_html_e('Année :', 'periscolaire-registration'); ?>
<select name="school_year_id" onchange="this.form.submit()">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($selected_year_id, $y->id); ?>><?php echo esc_html($y->label . ' (' . $y->statut . ')'); ?></option>
<?php endforeach; ?>
</select>
</label>
<label><input type="checkbox" name="show_sortis" value="1" <?php checked($show_sortis); ?> onchange="this.form.submit()"> <?php esc_html_e('Afficher les enfants sortis', 'periscolaire-registration'); ?></label>
</form>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Classe', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Naissance', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Régime cantine', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Famille', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Assurance', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Action', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php if (empty($children)): ?>
<tr><td colspan="9"><?php esc_html_e('Aucun enfant enregistré', 'periscolaire-registration'); ?><?php echo $selected_year_id ? ' ' . esc_html__('pour cette année', 'periscolaire-registration') : ' ' . esc_html__(" (créez d'abord une année scolaire)", 'periscolaire-registration'); ?>.</td></tr>
<?php else: foreach ($children as $c): ?>
<tr>
<td><?php echo esc_html($c->nom); ?></td>
<td><?php echo esc_html($c->prenom); ?></td>
<td><?php echo esc_html($c->classe ? ($psc_classe_labels[$c->classe] ?? $c->classe) : '—'); ?></td>
<td><?php echo $c->date_naissance ? esc_html(date_i18n('d/m/Y', strtotime($c->date_naissance))) : '—'; ?></td>
<td>
<?php
$diet = array();
if ((int) $c->sans_porc) $diet[] = __('Sans porc', 'periscolaire-registration');
if ((int) $c->vegan) $diet[] = __('Sans viande', 'periscolaire-registration');
echo $diet ? esc_html(implode(' · ', $diet)) : '—';
?>
</td>
<td><?php echo $c->statut === 'actif' ? '<span class="psc-active">' . esc_html__('Actif', 'periscolaire-registration') . '</span>' : '<em>' . esc_html__('Sorti', 'periscolaire-registration') . '</em>'; ?></td>
<td><?php echo $c->parent_email ? esc_html($c->parent_nom ?: $c->parent_email) : '<em>' . esc_html__('famille supprimée', 'periscolaire-registration') . '</em>'; ?></td>
<td>
<?php if ($c->assurance_uploaded_at): ?>
<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_assurance&child_id=' . $c->id . '&school_year_id=' . $selected_year_id), 'psc_download_assurance_' . $c->id)); ?>" target="_blank" rel="noopener">
<?php esc_html_e('Fournie le', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($c->assurance_uploaded_at))); ?>
</a>
<?php else: ?>
<em><?php esc_html_e('Manquante', 'periscolaire-registration'); ?></em>
<?php endif; ?>
</td>
<td style="white-space:nowrap">
<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=psc_pickup_persons&child_id=' . $c->id)); ?>"><?php esc_html_e('Personnes autorisées', 'periscolaire-registration'); ?></a>
<?php if ($c->statut === 'actif'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_mark_child_sorti'); ?>
<input type="hidden" name="action" value="psc_mark_child_sorti">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button" onclick="return confirm('<?php echo esc_js(__("Marquer cet enfant sorti ? Il disparaîtra des listes actives et du planning, mais son historique reste consultable.", 'periscolaire-registration')); ?>');"><?php esc_html_e('Marquer sorti', 'periscolaire-registration'); ?></button>
</form>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_mark_child_actif'); ?>
<input type="hidden" name="action" value="psc_mark_child_actif">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button"><?php esc_html_e('Marquer actif', 'periscolaire-registration'); ?></button>
</form>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('Supprimer cet enfant et toutes ses inscriptions ? Cette action est irréversible.', 'periscolaire-registration')); ?>');">
<?php wp_nonce_field('psc_delete_child'); ?>
<input type="hidden" name="action" value="psc_delete_child">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button button-link-delete"><?php esc_html_e('Supprimer', 'periscolaire-registration'); ?></button>
</form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
