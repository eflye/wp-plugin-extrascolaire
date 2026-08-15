<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Passage d'année — récapitulatif</h1>

<?php if (!$from_year || !$to_year || empty($plan)): ?>
<div class="psc-box" data-testid="promotion-empty">
<p>Aucun passage d'année en attente de confirmation. <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_years')); ?>">Retour aux années scolaires</a>.</p>
</div>
<?php else: ?>

<div class="notice notice-warning"><p>
Aucune écriture n'a encore eu lieu. Vérifiez et corrigez si besoin la classe proposée pour chaque enfant, puis confirmez en bas de page pour appliquer le passage de <strong><?php echo esc_html($from_year->label); ?></strong> vers <strong><?php echo esc_html($to_year->label); ?></strong>.
</p></div>

<div class="psc-box">
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_confirm_promotion'); ?>
<input type="hidden" name="action" value="psc_confirm_promotion">

<table class="widefat striped">
<thead><tr><th>Enfant</th><th>Classe <?php echo esc_html($from_year->label); ?></th><th>Classe proposée <?php echo esc_html($to_year->label); ?></th></tr></thead>
<tbody>
<?php foreach ($plan as $row):
    $field_id = 'psc-classe-' . $row['child_id'];
?>
<tr data-testid="promotion-row-<?php echo esc_attr($row['child_id']); ?>">
<td><?php echo esc_html($row['prenom'] . ' ' . $row['nom']); ?></td>
<td><?php echo esc_html($row['classe_actuelle'] !== '' ? ($classe_options[$row['classe_actuelle']] ?? $row['classe_actuelle']) : '—'); ?></td>
<td>
<label class="screen-reader-text" for="<?php echo esc_attr($field_id); ?>">Classe proposée pour <?php echo esc_html($row['prenom']); ?></label>
<select id="<?php echo esc_attr($field_id); ?>" name="classe_<?php echo esc_attr($row['child_id']); ?>" data-testid="promotion-classe-select-<?php echo esc_attr($row['child_id']); ?>">
<?php foreach ($classe_options as $code => $label): if ($code === '') continue; ?>
<option value="<?php echo esc_attr($code); ?>" <?php selected($row['classe_proposee'], $code); ?>><?php echo esc_html($label); ?></option>
<?php endforeach; ?>
<option value="sortie" <?php selected($row['classe_proposee'], 'sortie'); ?>>Sortie (fin de cycle périscolaire)</option>
</select>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p style="margin-top:16px;">
<?php submit_button('Confirmer le passage d\'année', 'primary', 'submit', false, array('data-testid' => 'promotion-confirm-submit')); ?>
</p>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
<?php wp_nonce_field('psc_cancel_promotion'); ?>
<input type="hidden" name="action" value="psc_cancel_promotion">
<?php submit_button('Annuler', 'secondary', 'submit', false, array('data-testid' => 'promotion-cancel-submit')); ?>
</form>
</div>
<?php endif; ?>
</div>
