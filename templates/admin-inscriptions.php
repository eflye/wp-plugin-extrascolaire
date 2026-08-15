<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Inscriptions</h1>

<?php if (!empty($psc_msg) && $psc_msg === 'saved'): ?>
<div class="notice notice-success is-dismissible"><p>Planning mis à jour. Un récapitulatif a été envoyé par e-mail à la famille.</p></div>
<?php elseif (!empty($psc_msg) && $psc_msg === 'invalid'): ?>
<div class="notice notice-error is-dismissible"><p>Paramètres invalides.</p></div>
<?php endif; ?>

<div class="psc-box">
<p>Sélectionnez une famille et une période pour consulter ou corriger ses inscriptions. Un e-mail de notification est envoyé à la famille à chaque enregistrement.</p>
<form method="get" class="psc-filters" id="psc-insc-filter">
<input type="hidden" name="page" value="psc_inscriptions">
<label>Famille :
<select name="parent_id" onchange="this.form.submit()">
<option value="">— Choisir —</option>
<?php foreach ($parents as $p): ?>
<option value="<?php echo esc_attr($p->id); ?>" <?php selected($parent_id, $p->id); ?>>
  <?php echo esc_html(($p->nom ? $p->nom . ' — ' : '') . $p->email); ?>
</option>
<?php endforeach; ?>
</select>
</label>
&nbsp;
<label>Période :
<select name="trimestre_id" onchange="this.form.submit()">
<?php foreach ($trimestres as $t): ?>
<option value="<?php echo esc_attr($t->id); ?>" <?php selected($trimestre_id, $t->id); ?>><?php echo esc_html($t->label); ?></option>
<?php endforeach; ?>
</select>
</label>
&nbsp;
<a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_export_csv', 'trimestre_id' => $trimestre_id), admin_url('admin-post.php')), 'psc_export_csv')); ?>">Exporter CSV</a>
</form>
</div>

<?php if (!$selected_parent): ?>
<p class="description">Choisissez une famille dans la liste ci-dessus pour afficher et modifier son planning.</p>

<?php elseif (empty($children)): ?>
<div class="psc-box">
<p>Cette famille n'a aucun enfant rattaché. <a href="<?php echo esc_url(admin_url('admin.php?page=psc_children')); ?>">Gérer les enfants</a></p>
</div>

<?php elseif (empty($days_by_month)): ?>
<div class="psc-box">
<p>Aucun jour ouvert pour cette période.</p>
</div>

<?php else: ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_admin_update_registrations'); ?>
<input type="hidden" name="action" value="psc_admin_update_registrations">
<input type="hidden" name="parent_id" value="<?php echo esc_attr($parent_id); ?>">
<input type="hidden" name="trimestre_id" value="<?php echo esc_attr($trimestre_id); ?>">

<?php $short = array('GM' => 'G.M.', 'CANT' => 'Cant.', 'GS' => 'G.S.', 'FORF' => 'Forf.'); ?>

<?php foreach ($children as $child): ?>
<div class="psc-box">
<h2><?php echo esc_html($child->prenom . ' ' . $child->nom); ?><?php if ($child->classe): ?> <span class="psc-classe">(<?php echo esc_html($child->classe); ?>)</span><?php endif; ?></h2>

<?php foreach ($days_by_month as $month_label => $days): ?>
<details class="psc-month-block" open>
<summary class="psc-month"><?php echo esc_html(ucfirst($month_label)); ?></summary>
<table class="psc-calendar widefat">
<thead>
<tr>
<th>Jour</th>
<?php foreach (psc_allowed_services() as $code): ?>
<th class="psc-center"><abbr class="psc-th-abbr" title="<?php echo esc_attr($services[$code]['label']); ?>"><?php echo esc_html($short[$code]); ?></abbr><br><small><?php echo esc_html(number_format_i18n($services[$code]['price'], 2)); ?> €</small></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($days as $d): ?>
<tr>
<td class="psc-daylabel"><?php echo esc_html(psc_day_label($d->jour_date) . ' ' . date_i18n('d/m', strtotime($d->jour_date))); ?></td>
<?php foreach (psc_allowed_services() as $s):
    $checked = isset($reg_map[$child->id . '|' . $d->jour_date . '|' . $s]);
?>
<td class="psc-cell">
<input type="checkbox"
       name="regs[<?php echo esc_attr($child->id); ?>][<?php echo esc_attr($d->jour_date); ?>][<?php echo esc_attr($s); ?>]"
       value="1"
       data-service="<?php echo esc_attr($s); ?>"
       aria-label="<?php echo esc_attr($services[$s]['label'] . ' — ' . psc_day_label($d->jour_date) . ' ' . date_i18n('d/m', strtotime($d->jour_date)) . ' — ' . $child->prenom); ?>"
       <?php checked($checked); ?>>
</td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</details>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<p>
<?php submit_button('Enregistrer et notifier la famille', 'primary', 'submit', false); ?>
<span style="margin-left:8px;color:#666;font-size:13px;">Un e-mail récapitulatif sera envoyé à <strong><?php echo esc_html($selected_parent->email); ?></strong>.</span>
</p>

</form>
<?php endif; ?>

</div>
<script>
(function () {
    function applyForfait(forf, isChecked) {
        var row = forf.closest('tr');
        if (!row) return;
        row.querySelectorAll('input[type="checkbox"][data-service]').forEach(function (c) {
            if (c === forf) return;
            if (isChecked) {
                c.checked = false;
                c.disabled = true;
            } else {
                c.disabled = false;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="checkbox"][data-service="FORF"]').forEach(function (cb) {
            if (cb.checked) applyForfait(cb, true);
            cb.addEventListener('change', function () { applyForfait(cb, cb.checked); });
        });
    });
})();
</script>
