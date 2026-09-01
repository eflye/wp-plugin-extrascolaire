<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Enfants inscrits</h1>

<?php
$psc_notices = array(
    'added'        => array('success', 'Enfant ajouté.'),
    'deleted'      => array('success', 'Enfant supprimé, ainsi que ses inscriptions.'),
    'marked_sorti' => array('success', 'Enfant marqué sorti.'),
    'marked_actif' => array('success', 'Enfant marqué actif.'),
    'nouser'       => array('error', 'Famille introuvable. Enregistrez-la d\'abord dans l\'onglet « Familles ».'),
    'invalid'      => array('error', 'Merci de choisir une famille et de renseigner le nom et le prénom.'),
);
psc_admin_notice_map($psc_notices, $psc_msg); ?>

<div class="psc-box">
<h2>Rattacher un enfant à un parent existant</h2>
<p>Rattachez chaque enfant à une famille enregistrée. Les familles ne peuvent pas ajouter d'enfant elles-mêmes : c'est la mairie qui tient cette liste.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_child'); ?>
<input type="hidden" name="action" value="psc_add_child">
<table class="form-table">
<tr><th><label for="psc-parent">Famille</label></th><td>
<?php if (empty($parents)): ?>
  <em>Enregistrez d'abord une famille dans l'onglet « Familles ».</em>
<?php else: ?>
<select id="psc-parent" name="parent_id" required>
<option value="">— Choisir —</option>
<?php foreach ($parents as $p): ?>
<option value="<?php echo esc_attr($p->id); ?>"><?php echo esc_html(($p->nom ? $p->nom . ' — ' : '') . $p->email); ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-nom">Nom de l'enfant</label></th><td><input id="psc-nom" type="text" name="nom" maxlength="190" required></td></tr>
<tr><th><label for="psc-prenom">Prénom de l'enfant</label></th><td><input id="psc-prenom" type="text" name="prenom" maxlength="190" required></td></tr>
<tr><th><label for="psc-classe">Classe</label></th><td>
<select id="psc-classe" name="classe">
<?php foreach ($psc_classe_labels as $v => $l): ?>
<option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
<?php endforeach; ?>
</select>
<?php if (!Psc_School_Years::active_id()): ?>
<p class="description">Aucune année scolaire active : la classe ne pourra pas être enregistrée tant qu'une année n'est pas activée.</p>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-naissance">Date de naissance</label></th><td><input id="psc-naissance" type="date" name="naissance"></td></tr>
</table>
<?php submit_button('Ajouter'); ?>
</form>
</div>

<div class="psc-box">
<h2>Liste des enfants</h2>
<form method="get" style="display:flex;align-items:center;gap:16px;margin-bottom:12px;">
<input type="hidden" name="page" value="psc_children">
<label>Année :
<select name="school_year_id" onchange="this.form.submit()">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($selected_year_id, $y->id); ?>><?php echo esc_html($y->label . ' (' . $y->statut . ')'); ?></option>
<?php endforeach; ?>
</select>
</label>
<label><input type="checkbox" name="show_sortis" value="1" <?php checked($show_sortis); ?> onchange="this.form.submit()"> Afficher les enfants sortis</label>
</form>
<table class="widefat striped">
<thead><tr><th>Nom</th><th>Prénom</th><th>Classe</th><th>Naissance</th><th>Régime cantine</th><th>Statut</th><th>Famille</th><th>Assurance</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($children)): ?>
<tr><td colspan="9">Aucun enfant enregistré<?php echo $selected_year_id ? ' pour cette année' : ' (créez d\'abord une année scolaire)'; ?>.</td></tr>
<?php else: foreach ($children as $c): ?>
<tr>
<td><?php echo esc_html($c->nom); ?></td>
<td><?php echo esc_html($c->prenom); ?></td>
<td><?php echo esc_html($c->classe ? ($psc_classe_labels[$c->classe] ?? $c->classe) : '—'); ?></td>
<td><?php echo $c->date_naissance ? esc_html(date_i18n('d/m/Y', strtotime($c->date_naissance))) : '—'; ?></td>
<td>
<?php
$diet = array();
if ((int) $c->sans_porc) $diet[] = 'Sans porc';
if ((int) $c->vegan) $diet[] = 'Sans viande';
echo $diet ? esc_html(implode(' · ', $diet)) : '—';
?>
</td>
<td><?php echo $c->statut === 'actif' ? '<span class="psc-active">Actif</span>' : '<em>Sorti</em>'; ?></td>
<td><?php echo $c->parent_email ? esc_html($c->parent_nom ?: $c->parent_email) : '<em>famille supprimée</em>'; ?></td>
<td>
<?php if ($c->assurance_uploaded_at): ?>
<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_download_assurance&child_id=' . $c->id . '&school_year_id=' . $selected_year_id), 'psc_download_assurance_' . $c->id)); ?>" target="_blank" rel="noopener">
Fournie le <?php echo esc_html(date_i18n('d/m/Y', strtotime($c->assurance_uploaded_at))); ?>
</a>
<?php else: ?>
<em>Manquante</em>
<?php endif; ?>
</td>
<td style="white-space:nowrap">
<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=psc_pickup_persons&child_id=' . $c->id)); ?>">Personnes autorisées</a>
<?php if ($c->statut === 'actif'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_mark_child_sorti'); ?>
<input type="hidden" name="action" value="psc_mark_child_sorti">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button" onclick="return confirm('Marquer cet enfant sorti ? Il disparaîtra des listes actives et du planning, mais son historique reste consultable.');">Marquer sorti</button>
</form>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_mark_child_actif'); ?>
<input type="hidden" name="action" value="psc_mark_child_actif">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button">Marquer actif</button>
</form>
<?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Supprimer cet enfant et toutes ses inscriptions ? Cette action est irréversible.');">
<?php wp_nonce_field('psc_delete_child'); ?>
<input type="hidden" name="action" value="psc_delete_child">
<input type="hidden" name="id" value="<?php echo esc_attr($c->id); ?>">
<button class="button button-link-delete">Supprimer</button>
</form>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
