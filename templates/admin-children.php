<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Enfants inscrits</h1>

<?php
$psc_notices = array(
    'added'   => array('success', 'Enfant ajouté.'),
    'deleted' => array('success', 'Enfant supprimé, ainsi que ses inscriptions.'),
    'nouser'  => array('error', 'Famille introuvable. Enregistrez-la d\'abord dans l\'onglet « Familles ».'),
    'invalid' => array('error', 'Merci de choisir une famille et de renseigner le nom et le prénom.'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
<?php endif; ?>

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
<tr><th><label for="psc-classe">Classe</label></th><td><input id="psc-classe" type="text" name="classe" maxlength="100" placeholder="CP, CE1..."></td></tr>
</table>
<?php submit_button('Ajouter'); ?>
</form>
</div>

<div class="psc-box">
<h2>Liste des enfants</h2>
<table class="widefat striped">
<thead><tr><th>Nom</th><th>Prénom</th><th>Classe</th><th>Régime cantine</th><th>Famille</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($children)): ?>
<tr><td colspan="6">Aucun enfant enregistré.</td></tr>
<?php else: foreach ($children as $c): ?>
<tr>
<td><?php echo esc_html($c->nom); ?></td>
<td><?php echo esc_html($c->prenom); ?></td>
<td><?php echo esc_html($c->classe); ?></td>
<td>
<?php
$diet = array();
if ((int) $c->sans_porc) $diet[] = 'Sans porc';
if ((int) $c->vegan) $diet[] = 'Vegan';
echo $diet ? esc_html(implode(' · ', $diet)) : '—';
?>
</td>
<td><?php echo $c->parent_email ? esc_html($c->parent_nom ?: $c->parent_email) : '<em>famille supprimée</em>'; ?></td>
<td>
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
