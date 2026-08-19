<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Trimestres</h1>

<?php
$psc_notices = array(
    'created'            => array('success', 'Trimestre créé, le calendrier a été généré.'),
    'updated'            => array('success', 'Trimestre modifié, le calendrier a été régénéré sur la nouvelle période.'),
    'trimestre_deleted'  => array('success', 'Trimestre supprimé.'),
    'activated'          => array('success', 'Trimestre activé : il est désormais visible par les familles.'),
    'invalid_dates'      => array('error', 'Dates invalides. Merci de vérifier le format et de renseigner tous les champs.'),
    'order_dates'        => array('error', 'La date de fin doit être postérieure à la date de début.'),
    'too_long'           => array('error', 'La période est trop longue (maximum ' . psc_max_trimestre_days() . ' jours). Vérifiez les années saisies.'),
    'invalid'            => array('error', 'Opération impossible : élément introuvable.'),
    'active_trimestre'   => array('error', 'Impossible de supprimer le trimestre actif : activez-en un autre au préalable.'),
    'has_registrations'  => array('error', 'Impossible de supprimer ce trimestre : des familles ont déjà déclaré des présences dessus.'),
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
<p>Modifier les dates régénère le calendrier du trimestre sur la nouvelle période : les jours fériés/vacances sont recalculés automatiquement, mais une fermeture ponctuelle que vous auriez ajoutée à la main sur un jour resté dans la période peut être réinitialisée.
Pour fermer les vacances scolaires ou toute autre période, rendez-vous sur <a href="<?php echo esc_url(admin_url('admin.php?page=psc_school_calendar')); ?>">Calendrier scolaire</a>.</p>
<table class="widefat striped">
<thead><tr><th>Libellé</th><th>Année scolaire</th><th>Début</th><th>Fin</th><th>Statut</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($trimestres)): ?>
<tr><td colspan="6">Aucun trimestre créé pour le moment.</td></tr>
<?php else: foreach ($trimestres as $t): $edit_form_id = 'trim-edit-form-' . $t->id; ?>
<tr>
<td>
  <label class="screen-reader-text" for="psc-t-label-<?php echo esc_attr($t->id); ?>">Libellé</label>
  <input id="psc-t-label-<?php echo esc_attr($t->id); ?>" type="text" form="<?php echo esc_attr($edit_form_id); ?>" name="label" value="<?php echo esc_attr($t->label); ?>" maxlength="190" required class="regular-text">
</td>
<td>
  <label class="screen-reader-text" for="psc-t-annee-<?php echo esc_attr($t->id); ?>">Année scolaire</label>
  <?php if (empty($psc_trim_years)): ?>
  —
  <?php else: ?>
  <select id="psc-t-annee-<?php echo esc_attr($t->id); ?>" form="<?php echo esc_attr($edit_form_id); ?>" name="school_year_id">
  <?php foreach ($psc_trim_years as $y): ?>
  <option value="<?php echo esc_attr($y->id); ?>" <?php selected((int) $t->school_year_id, (int) $y->id); ?>><?php echo esc_html($y->label); ?></option>
  <?php endforeach; ?>
  </select>
  <?php endif; ?>
</td>
<td>
  <label class="screen-reader-text" for="psc-t-debut-<?php echo esc_attr($t->id); ?>">Date de début</label>
  <input id="psc-t-debut-<?php echo esc_attr($t->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_debut" value="<?php echo esc_attr($t->date_debut); ?>" required>
</td>
<td>
  <label class="screen-reader-text" for="psc-t-fin-<?php echo esc_attr($t->id); ?>">Date de fin</label>
  <input id="psc-t-fin-<?php echo esc_attr($t->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_fin" value="<?php echo esc_attr($t->date_fin); ?>" required>
</td>
<td><?php echo $t->active ? '<strong class="psc-active">Actif (visible sur le site)</strong>' : '—'; ?></td>
<td style="white-space:nowrap">
<form id="<?php echo esc_attr($edit_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_update_trimestre'); ?>
<input type="hidden" name="action" value="psc_update_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button type="submit" class="button">Enregistrer</button>
</form>
<?php if (!$t->active): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_activate_trimestre'); ?>
<input type="hidden" name="action" value="psc_activate_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button class="button">Activer</button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_delete_trimestre'); ?>
<input type="hidden" name="action" value="psc_delete_trimestre">
<input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
<button class="button" onclick="return confirm('Supprimer définitivement le trimestre <?php echo esc_js($t->label); ?> ? Impossible si des familles ont déjà déclaré des présences dessus.');">Supprimer</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
</div>
