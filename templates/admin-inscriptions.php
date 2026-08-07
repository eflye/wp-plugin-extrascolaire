<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Inscriptions</h1>

<form method="get" class="psc-filters">
<input type="hidden" name="page" value="psc_inscriptions">
<label>Trimestre :
<select name="trimestre_id" onchange="this.form.submit()">
<?php foreach ($trimestres as $t): ?>
<option value="<?php echo esc_attr($t->id); ?>" <?php selected($trimestre_id, $t->id); ?>><?php echo esc_html($t->label); ?></option>
<?php endforeach; ?>
</select>
</label>
&nbsp;
<label>Enfant :
<select name="child_id" onchange="this.form.submit()">
<option value="0">Tous les enfants</option>
<?php foreach ($children as $c): ?>
<option value="<?php echo esc_attr($c->id); ?>" <?php selected($child_id, $c->id); ?>><?php echo esc_html($c->prenom . ' ' . $c->nom); ?></option>
<?php endforeach; ?>
</select>
</label>
</form>

<p>
<a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_export_csv&trimestre_id=' . $trimestre_id), 'psc_export_csv')); ?>">Exporter en CSV (Excel)</a>
</p>

<table class="widefat striped psc-recap">
<thead><tr><th>Date</th><th>Enfant</th><th>G.M.</th><th>Cant.</th><th>G.S.</th><th>Forf.</th></tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="6">Aucune inscription pour cette sélection.</td></tr>
<?php else: foreach ($rows as $row): ?>
<tr>
<td><?php echo esc_html(psc_day_label($row['date']) . ' ' . date_i18n('d/m/Y', strtotime($row['date']))); ?></td>
<td><?php echo esc_html($row['child']->prenom . ' ' . $row['child']->nom); ?></td>
<td class="psc-center"><?php echo $row['GM'] ? '✔' : ''; ?></td>
<td class="psc-center"><?php echo $row['CANT'] ? '✔' : ''; ?></td>
<td class="psc-center"><?php echo $row['GS'] ? '✔' : ''; ?></td>
<td class="psc-center"><?php echo $row['FORF'] ? '✔' : ''; ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
<?php if (!empty($rows)): ?>
<tfoot>
<tr>
<th colspan="2">Total prestations</th>
<th class="psc-center"><?php echo intval($totals['GM']); ?></th>
<th class="psc-center"><?php echo intval($totals['CANT']); ?></th>
<th class="psc-center"><?php echo intval($totals['GS']); ?></th>
<th class="psc-center"><?php echo intval($totals['FORF']); ?></th>
</tr>
</tfoot>
<?php endif; ?>
</table>
</div>
