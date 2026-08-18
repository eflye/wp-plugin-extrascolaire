<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Années scolaires</h1>

<?php
$psc_notices = array(
    'created'             => array('success', 'Année scolaire créée.'),
    'updated'             => array('success', 'Année scolaire modifiée.'),
    'year_deleted'        => array('success', 'Année scolaire supprimée.'),
    'activated'           => array('success', 'Année activée : c\'est désormais celle visible par les familles.'),
    'archived'            => array('success', 'Année archivée.'),
    'promoted'            => array('success', 'Passage d\'année effectué.'),
    'promotion_cancelled' => array('success', 'Passage d\'année annulé.'),
    'invalid'             => array('error', 'Opération impossible : élément introuvable ou invalide.'),
    'order_dates'         => array('error', 'La date de fin doit être postérieure à la date de début.'),
    'active_year'         => array('error', 'Impossible de supprimer l\'année active : activez-en une autre au préalable.'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<h2>Créer une année scolaire</h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_add_school_year'); ?>
<input type="hidden" name="action" value="psc_add_school_year">
<table class="form-table">
<tr><th><label for="psc-y-label">Libellé</label></th><td><input id="psc-y-label" type="text" name="label" class="regular-text" placeholder="2026-2027" maxlength="20" required data-testid="year-label-input"></td></tr>
<tr><th><label for="psc-y-debut">Date de début</label></th><td><input id="psc-y-debut" type="date" name="date_debut" required data-testid="year-debut-input"></td></tr>
<tr><th><label for="psc-y-fin">Date de fin</label></th><td><input id="psc-y-fin" type="date" name="date_fin" required data-testid="year-fin-input"></td></tr>
</table>
<?php submit_button('Créer l\'année', 'primary', 'submit', true, array('data-testid' => 'year-create-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2>Années existantes</h2>
<table class="widefat striped">
<thead><tr><th>Libellé</th><th>Début</th><th>Fin</th><th>Statut</th><th>Action</th></tr></thead>
<tbody>
<?php if (empty($years)): ?>
<tr><td colspan="5">Aucune année scolaire créée pour le moment.</td></tr>
<?php else: foreach ($years as $y): $edit_form_id = 'year-edit-form-' . $y->id; ?>
<tr data-testid="year-row-<?php echo esc_attr($y->id); ?>">
<td>
  <label class="screen-reader-text" for="psc-y-label-<?php echo esc_attr($y->id); ?>">Libellé</label>
  <input id="psc-y-label-<?php echo esc_attr($y->id); ?>" type="text" form="<?php echo esc_attr($edit_form_id); ?>" name="label" value="<?php echo esc_attr($y->label); ?>" maxlength="20" required class="regular-text" data-testid="year-edit-label-<?php echo esc_attr($y->id); ?>">
</td>
<td>
  <label class="screen-reader-text" for="psc-y-debut-<?php echo esc_attr($y->id); ?>">Date de début</label>
  <input id="psc-y-debut-<?php echo esc_attr($y->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_debut" value="<?php echo esc_attr($y->date_debut); ?>" required data-testid="year-edit-debut-<?php echo esc_attr($y->id); ?>">
</td>
<td>
  <label class="screen-reader-text" for="psc-y-fin-<?php echo esc_attr($y->id); ?>">Date de fin</label>
  <input id="psc-y-fin-<?php echo esc_attr($y->id); ?>" type="date" form="<?php echo esc_attr($edit_form_id); ?>" name="date_fin" value="<?php echo esc_attr($y->date_fin); ?>" required data-testid="year-edit-fin-<?php echo esc_attr($y->id); ?>">
</td>
<td data-testid="year-statut-<?php echo esc_attr($y->id); ?>">
<?php if ($y->statut === 'active'): ?><strong class="psc-active">Active (visible sur le site)</strong>
<?php elseif ($y->statut === 'preparation'): ?>En préparation
<?php else: ?>Archivée
<?php endif; ?>
</td>
<td style="white-space:nowrap">
<form id="<?php echo esc_attr($edit_form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_update_school_year'); ?>
<input type="hidden" name="action" value="psc_update_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button type="submit" class="button" data-testid="year-save-<?php echo esc_attr($y->id); ?>">Enregistrer</button>
</form>
<?php if ($y->statut !== 'active'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_activate_school_year'); ?>
<input type="hidden" name="action" value="psc_activate_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" data-testid="year-activate-<?php echo esc_attr($y->id); ?>">Activer</button>
</form>
<?php endif; ?>
<?php if ($y->statut !== 'archivee'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_archive_school_year'); ?>
<input type="hidden" name="action" value="psc_archive_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" onclick="return confirm('Archiver cette année ? Elle restera consultable en lecture seule.');" data-testid="year-archive-<?php echo esc_attr($y->id); ?>">Archiver</button>
</form>
<?php endif; ?>
<?php if ($y->statut !== 'active'): ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
<?php wp_nonce_field('psc_delete_school_year'); ?>
<input type="hidden" name="action" value="psc_delete_school_year">
<input type="hidden" name="id" value="<?php echo esc_attr($y->id); ?>">
<button class="button" onclick="return confirm('Supprimer définitivement l\'année <?php echo esc_js($y->label); ?> ? Les inscriptions des enfants pour cette année (classe, justificatif d\'assurance) seront supprimées. Les trimestres qui lui sont rattachés seront conservés, seulement détachés de cette année.');" data-testid="year-delete-<?php echo esc_attr($y->id); ?>">Supprimer</button>
</form>
<?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>

<div class="psc-box">
<h2>Passage à l'année suivante</h2>
<p>Prépare la montée de classe en masse des enfants actifs, de l'année en cours vers une année cible : un récapitulatif s'affiche pour correction avant toute écriture en base. Rien n'est modifié tant que vous n'avez pas confirmé sur l'écran suivant.</p>
<?php if (count($years) < 2): ?>
<p><em>Créez d'abord au moins deux années scolaires (l'année en cours et la suivante).</em></p>
<?php else: ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_stage_promotion'); ?>
<input type="hidden" name="action" value="psc_stage_promotion">
<table class="form-table">
<tr><th><label for="psc-from-year">Depuis l'année</label></th><td>
<select id="psc-from-year" name="from_year_id" required data-testid="promotion-from-select">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'active'); ?>><?php echo esc_html($y->label); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
<tr><th><label for="psc-to-year">Vers l'année</label></th><td>
<select id="psc-to-year" name="to_year_id" required data-testid="promotion-to-select">
<?php foreach ($years as $y): ?>
<option value="<?php echo esc_attr($y->id); ?>" <?php selected($y->statut, 'preparation'); ?>><?php echo esc_html($y->label); ?></option>
<?php endforeach; ?>
</select>
</td></tr>
</table>
<?php submit_button('Préparer le passage d\'année', 'primary', 'submit', true, array('data-testid' => 'promotion-stage-submit')); ?>
</form>
<?php endif; ?>
</div>
</div>
