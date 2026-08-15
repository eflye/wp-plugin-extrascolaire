<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Trimestres</h1>

<?php
$psc_notices = array(
    'created'       => array('success', 'Trimestre créé, le calendrier a été généré.'),
    'activated'     => array('success', 'Trimestre activé : il est désormais visible par les familles.'),
    'closed'        => array('success', 'Période fermée.'),
    'invalid_dates' => array('error', 'Dates invalides. Merci de vérifier le format et de renseigner tous les champs.'),
    'order_dates'   => array('error', 'La date de fin doit être postérieure à la date de début.'),
    'too_long'      => array('error', 'La période est trop longue (maximum ' . psc_max_trimestre_days() . ' jours). Vérifiez les années saisies.'),
    'invalid'       => array('error', 'Opération impossible : élément introuvable.'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<h2>Créer un trimestre</h2>
<p>Génère automatiquement le calendrier du trimestre : week-ends et jours fériés fermés par défaut, tout le reste ouvert.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_trimestre'); ?>
<input type="hidden" name="action" value="psc_add_trimestre">
<table class="form-table">
<tr><th><label for="psc-label">Libellé</label></th><td><input id="psc-label" type="text" name="label" class="regular-text" placeholder="3ème trimestre 2025/2026" maxlength="190" required></td></tr>
<tr><th><label for="psc-annee">Année scolaire</label></th><td>
<?php $psc_trim_years = Psc_School_Years::all(); ?>
<?php if (empty($psc_trim_years)): ?>
<em>Créez d'abord une <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_years')); ?>">année scolaire</a>.</em>
<?php else: ?>
<select id="psc-annee" name="school_year_id" required>
<?php foreach ($psc_trim_years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'active'); ?>><?php echo esc_html($y->label . ' (' . $y->statut . ')'); ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
</td></tr>
<tr><th><label for="psc-debut">Date de début</label></th><td><input id="psc-debut" type="date" name="date_debut" required></td></tr>
<tr><th><label for="psc-fin">Date de fin</label></th><td><input id="psc-fin" type="date" name="date_fin" required></td></tr>
</table>
<?php submit_button('Créer le trimestre'); ?>
</form>
</div>

<div class="psc-box">
<h2>Trimestres existants</h2>
<table class="widefat striped">
<thead><tr><th>Libellé</th><th>Début</th><th>Fin</th><th>Statut</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($trimestres)): ?>
<tr><td colspan="5">Aucun trimestre créé pour le moment.</td></tr>
<?php else: foreach ($trimestres as $t): ?>
<tr>
<td><?php echo esc_html($t->label); ?></td>
<td><?php echo esc_html(date_i18n('d/m/Y', strtotime($t->date_debut))); ?></td>
<td><?php echo esc_html(date_i18n('d/m/Y', strtotime($t->date_fin))); ?></td>
<td><?php echo $t->active ? '<strong class="psc-active">Actif (visible sur le site)</strong>' : '—'; ?></td>
<td>
<?php if (!$t->active): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_activate_trimestre'); ?>
<input type="hidden" name="action" value="psc_activate_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button class="button">Activer</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="psc-box">
<h2>Fermer une période (vacances scolaires, fermeture exceptionnelle...)</h2>
<p>Les week-ends et jours fériés sont déjà fermés automatiquement à la création du trimestre. Utilisez ce formulaire pour les vacances scolaires ou toute fermeture ponctuelle.</p>
<?php if (empty($trimestres)): ?>
<p><em>Créez d'abord un trimestre.</em></p>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_close_range'); ?>
<input type="hidden" name="action" value="psc_close_range">
<table class="form-table">
<tr><th><label for="psc-trim">Trimestre</label></th><td>
<select id="psc-trim" name="trimestre_id">
<?php foreach ($trimestres as $t): ?>
<option value="<?php echo esc_attr($t->id); ?>"><?php echo esc_html($t->label); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
<tr><th><label for="psc-cd">Du</label></th><td><input id="psc-cd" type="date" name="date_debut" required></td></tr>
<tr><th><label for="psc-cf">Au</label></th><td><input id="psc-cf" type="date" name="date_fin" required></td></tr>
<tr><th><label for="psc-cl">Libellé</label></th><td><input id="psc-cl" type="text" name="label" value="Vacances" maxlength="100"></td></tr>
</table>
<?php submit_button('Fermer cette période'); ?>
</form>
<?php endif; ?>
</div>
</div>
