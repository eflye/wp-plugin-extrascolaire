<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Présences déclarées', 'periscolaire-registration'); ?></h1>

<?php
psc_admin_notice_map(array(
    'saved'   => array('success', __('Planning mis à jour. Un récapitulatif a été envoyé par e-mail à la famille.', 'periscolaire-registration')),
    'invalid' => array('error', __('Paramètres invalides.', 'periscolaire-registration')),
), $psc_msg);
?>

<div class="psc-box">
<p><?php esc_html_e('Sélectionnez une famille et un mois de l\'année scolaire pour consulter ou corriger ses déclarations. Un e-mail de notification est envoyé à la famille à chaque enregistrement. La mairie n\'est pas soumise au délai de 48 h.', 'periscolaire-registration'); ?></p>
<form method="get" class="psc-filters" id="psc-insc-filter">
<input type="hidden" name="page" value="psc_inscriptions">
<label><?php esc_html_e('Famille :', 'periscolaire-registration'); ?>
<select name="parent_id" onchange="this.form.submit()">
<option value=""><?php esc_html_e('— Choisir —', 'periscolaire-registration'); ?></option>
<?php foreach ($parents as $p): ?>
<option value="<?php echo esc_attr($p->id); ?>" <?php selected($parent_id, $p->id); ?>>
  <?php echo esc_html(($p->nom ? $p->nom . ' — ' : '') . $p->email); ?>
</option>
<?php endforeach; ?>
</select>
</label>
&nbsp;
<label><?php esc_html_e('Mois :', 'periscolaire-registration'); ?>
<select name="mois" onchange="this.form.submit()">
<?php foreach ($months as $m): ?>
<option value="<?php echo esc_attr($m['key']); ?>" <?php selected($mois, $m['key']); ?>><?php echo esc_html($m['label']); ?></option>
<?php endforeach; ?>
</select>
</label>
&nbsp;
<?php if ($annee): ?>
<span class="description" style="margin-left:8px;"><?php esc_html_e('Année scolaire', 'periscolaire-registration'); ?> <strong><?php echo esc_html($annee->year_key); ?></strong></span>
<?php endif; ?>
</form>
<p>
<a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'psc_export_csv', 'mois' => $mois), admin_url('admin-post.php')), 'psc_export_csv')); ?>"><?php esc_html_e('Exporter CSV (mois)', 'periscolaire-registration'); ?></a>
</p>
</div>

<?php if (!$selected_parent): ?>
<p class="description"><?php esc_html_e('Choisissez une famille dans la liste ci-dessus pour afficher et modifier son planning.', 'periscolaire-registration'); ?></p>

<?php elseif (empty($children)): ?>
<div class="psc-box">
<p><?php esc_html_e("Cette famille n'a aucun enfant rattaché.", 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_children')); ?>"><?php esc_html_e('Gérer les enfants', 'periscolaire-registration'); ?></a></p>
</div>

<?php elseif (empty($month_dates)): ?>
<div class="psc-box">
<p><?php esc_html_e('Aucun jour d\'école ce mois-ci (vacances, fériés).', 'periscolaire-registration'); ?></p>
</div>

<?php else: ?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_admin_update_registrations'); ?>
<input type="hidden" name="action" value="psc_admin_update_registrations">
<input type="hidden" name="parent_id" value="<?php echo esc_attr($parent_id); ?>">
<input type="hidden" name="mois" value="<?php echo esc_attr($mois); ?>">

<?php $short = psc_service_short_labels(); ?>

<?php foreach ($children as $child): ?>
<div class="psc-box">
<h2><?php echo esc_html($child->prenom . ' ' . $child->nom); ?><?php if (!empty($child->classe)): ?> <span class="psc-classe">(<?php echo esc_html($child->classe); ?>)</span><?php endif; ?>
<?php if (trim((string) $child->food_allergies) !== ''): ?>
<span style="color:#9E4A4A;font-weight:600;"><?php esc_html_e('· Allergies alimentaires :', 'periscolaire-registration'); ?> <?php echo esc_html($child->food_allergies); ?></span>
<?php endif; ?>
</h2>

<table class="psc-calendar widefat">
<thead>
<tr>
<th><?php esc_html_e('Jour', 'periscolaire-registration'); ?></th>
<?php foreach (psc_allowed_services() as $code): ?>
<th class="psc-center"><abbr class="psc-th-abbr" title="<?php echo esc_attr($services[$code]['label']); ?>"><?php echo esc_html($short[$code]); ?></abbr><br><small><?php echo esc_html(number_format_i18n($services[$code]['price'], 2)); ?> €</small></th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($month_dates as $date): ?>
<tr>
<td class="psc-daylabel"><?php echo esc_html(psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date))); ?></td>
<?php foreach (psc_allowed_services() as $s):
    $cell = isset($explicit[(int) $child->id][$date][$s]) ? $explicit[(int) $child->id][$date][$s] : array('explicit' => false, 'declared' => false, 'closed' => false);
    $checked = !empty($cell['explicit']);
    $closed  = !empty($cell['closed']);
?>
<td class="psc-cell">
<input type="checkbox"
       name="regs[<?php echo esc_attr($child->id); ?>][<?php echo esc_attr($date); ?>][<?php echo esc_attr($s); ?>]"
       value="1"
       data-service="<?php echo esc_attr($s); ?>"
       <?php disabled($closed); ?>
       aria-label="<?php echo esc_attr($services[$s]['label'] . ' — ' . psc_day_label($date) . ' ' . date_i18n('d/m', strtotime($date)) . ' — ' . $child->prenom . ($closed ? ' ' . __('(prestation fermée)', 'periscolaire-registration') : '')); ?>"
       <?php checked($checked); ?>>
</td>
<?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<p class="description"><?php esc_html_e('Décocher une case retirera la déclaration du jour concerné (exception de retrait), sans toucher au rythme habituel de la semaine.', 'periscolaire-registration'); ?></p>
</div>
<?php endforeach; ?>

<p>
<?php submit_button(__('Enregistrer et notifier la famille', 'periscolaire-registration'), 'primary', 'submit', false); ?>
<span style="margin-left:8px;color:#666;font-size:13px;"><?php esc_html_e('Un e-mail récapitulatif sera envoyé à', 'periscolaire-registration'); ?> <strong><?php echo esc_html($selected_parent->email); ?></strong>.</span>
</p>

</form>
<?php endif; ?>

</div>
<script>
(function () {
    // Le forfait et les prestations élémentaires restent exclusifs à
    // l'affichage d'un jour : le diff serveur écrit une exception de
    // retrait pour le jour concerné seulement.
    function applyForfait(forf, isChecked) {
        var row = forf.closest('tr');
        if (!row) return;
        row.querySelectorAll('input[type="checkbox"][data-service]').forEach(function (c) {
            if (c === forf || c.disabled) return;
            if (isChecked) c.checked = false;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="checkbox"][data-service="FORF"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (cb.checked) applyForfait(cb, true);
            });
        });
    });
})();
</script>
