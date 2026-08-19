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
    'imported'            => array('success', ((int) $imported_n) . ' jour(s) importé(s)/mis à jour depuis le calendrier officiel.'),
    'import_failed'       => array('error', 'Le calendrier officiel n\'a pas pu être téléchargé. Réessayez plus tard, ou chargez le fichier manuellement ci-dessous.'),
    'uploaded'            => array('success', ((int) $imported_n) . ' jour(s) importé(s)/mis à jour depuis le fichier envoyé.'),
    'upload_failed'       => array('error', 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un export .ics valide.'),
    'upload_invalid_type' => array('error', 'Le fichier doit être au format .ics.'),
    'upload_too_large'    => array('error', 'Le fichier dépasse la taille maximale autorisée (2 Mo).'),
    'closed'              => array('success', 'Jour(s) fermé(s).'),
    'opened'              => array('success', 'Jour réouvert.'),
    'cancelled'           => array('error', 'Fermeture annulée.'),
    'confirm_needed'      => array('error', 'Confirmation nécessaire : des inscriptions existent déjà sur cette période.'),
    'invalid_date'        => array('error', 'Date(s) invalide(s).'),
);
if (!empty($psc_msg) && isset($psc_notices[$psc_msg])):
    list($type, $text) = $psc_notices[$psc_msg]; ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
<?php endif; ?>

<?php $psc_import_return_page = 'psc_school_years'; include PSC_PATH . 'templates/partials/import-school-calendar.php'; ?>

<?php if ($pending): ?>
<div class="psc-box" style="border-left:4px solid #f5a623;">
<h2>⚠ Confirmation nécessaire</h2>
<p>
    <?php if ($pending['date_debut'] === $pending['date_fin']): ?>
    Fermer le <strong><?php echo esc_html(psc_day_label($pending['date_debut']) . ' ' . date_i18n('d/m/Y', strtotime($pending['date_debut']))); ?></strong>
    <?php else: ?>
    Fermer la période du <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending['date_debut']))); ?></strong>
    au <strong><?php echo esc_html(date_i18n('d/m/Y', strtotime($pending['date_fin']))); ?></strong>
    <?php endif; ?>
    supprimera <strong><?php echo (int) $pending_affected['registrations']; ?> inscription(s)</strong> déjà déclarée(s)
    par <strong><?php echo count($pending_affected['families']); ?> famille(s)</strong>. Ces prestations ne seront pas facturées,
    et chaque famille concernée recevra un e-mail listant ce qui a été retiré.
</p>
<table class="widefat striped" style="margin-bottom:16px;">
<thead><tr><th>Famille</th><th>Enfant(s) / prestation(s)</th></tr></thead>
<tbody>
<?php foreach ($pending_affected['families'] as $fam): ?>
<tr>
    <td><?php echo esc_html($fam['nom'] ?: $fam['email']); ?></td>
    <td>
        <?php
        $bits = array();
        $services = psc_services();
        $is_range = $pending['date_debut'] !== $pending['date_fin'];
        foreach ($fam['items'] as $item) {
            $svc_lbl = isset($services[$item->service]) ? $services[$item->service]['label'] : $item->service;
            $bit = esc_html($item->child_prenom . ' ' . $item->child_nom . ' — ' . $svc_lbl);
            if ($is_range) {
                $bit .= ' (' . esc_html(date_i18n('d/m/Y', strtotime($item->jour_date))) . ')';
            }
            $bits[] = $bit;
        }
        echo implode('<br>', $bits);
        ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
<?php wp_nonce_field('psc_close_school_day'); ?>
<input type="hidden" name="action" value="psc_close_school_day">
<input type="hidden" name="date_debut" value="<?php echo esc_attr($pending['date_debut']); ?>">
<input type="hidden" name="date_fin" value="<?php echo esc_attr($pending['date_fin']); ?>">
<input type="hidden" name="label" value="<?php echo esc_attr($pending['label']); ?>">
<input type="hidden" name="confirm" value="1">
<button type="submit" class="button button-primary">Confirmer la fermeture</button>
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
<?php wp_nonce_field('psc_cancel_school_day_close'); ?>
<input type="hidden" name="action" value="psc_cancel_school_day_close">
<button type="submit" class="button">Annuler</button>
</form>
</div>
<?php endif; ?>

<div class="psc-box">
<h2>Créer une année scolaire</h2>
<?php if (!empty($candidates)): ?>
<p class="description">Années scolaires détectées dans le calendrier importé (intervalle entre deux étés) — cliquez pour préremplir le formulaire ci-dessous, rien n'est créé automatiquement :</p>
<table class="widefat striped" style="margin-bottom:16px;max-width:600px;">
<thead><tr><th>Libellé proposé</th><th>Début</th><th>Fin</th><th></th></tr></thead>
<tbody>
<?php foreach ($candidates as $i => $c): ?>
<tr data-testid="year-candidate-<?php echo esc_attr($i); ?>">
    <td><?php echo esc_html($c['label']); ?></td>
    <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($c['date_debut']))); ?></td>
    <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($c['date_fin']))); ?></td>
    <td>
    <?php if ($c['exists']): ?>
        <em>Déjà créée</em>
    <?php else: ?>
        <button type="button" class="button psc-year-candidate-btn"
                data-label="<?php echo esc_attr($c['label']); ?>"
                data-debut="<?php echo esc_attr($c['date_debut']); ?>"
                data-fin="<?php echo esc_attr($c['date_fin']); ?>"
                data-testid="year-candidate-prefill-<?php echo esc_attr($i); ?>">Préremplir le formulaire</button>
    <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
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
<h2>Corriger un jour manuellement</h2>
<p>Formation des enseignants, vacances scolaires, fermeture exceptionnelle, ou correction d'un jour importé à tort. Laissez « Au » vide pour ne fermer qu'un seul jour.</p>
<div style="display:flex;gap:32px;flex-wrap:wrap;">
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_close_school_day'); ?>
<input type="hidden" name="action" value="psc_close_school_day">
<table class="form-table">
<tr>
<th><label for="psc-close-date-debut">Fermer du</label></th>
<td><input id="psc-close-date-debut" type="date" name="date_debut" required></td>
</tr>
<tr>
<th><label for="psc-close-date-fin">Au (optionnel)</label></th>
<td><input id="psc-close-date-fin" type="date" name="date_fin"></td>
</tr>
<tr>
<th><label for="psc-close-label">Motif</label></th>
<td><input id="psc-close-label" type="text" name="label" placeholder="Ex : Formation des enseignants, Vacances de la Toussaint" maxlength="100" style="width:280px;"></td>
</tr>
</table>
<?php submit_button('Fermer', 'secondary', 'submit', false); ?>
</form>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_open_school_day'); ?>
<input type="hidden" name="action" value="psc_open_school_day">
<table class="form-table"><tr>
<th><label for="psc-open-date">Réouvrir le</label></th>
<td><input id="psc-open-date" type="date" name="date" required></td>
</tr></table>
<?php submit_button('Réouvrir ce jour', 'secondary', 'submit', false); ?>
</form>
</div>
</div>

<div class="psc-box">
<h2>Jours fermés (<?php echo count($rows); ?>)</h2>
<?php if (empty($groups)): ?>
<p>Aucun jour fermé enregistré. Chargez le calendrier officiel ci-dessus.</p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th>Période</th><th>Motif</th><th>Jours</th><th>Origine</th></tr></thead>
<tbody>
<?php foreach (array_reverse($groups) as $g): ?>
<tr>
    <td>
        <?php if ($g['start'] === $g['end']): ?>
            <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['start']))); ?>
        <?php else: ?>
            <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['start']))); ?> → <?php echo esc_html(date_i18n('d/m/Y', strtotime($g['end']))); ?>
        <?php endif; ?>
    </td>
    <td><?php echo esc_html($g['label'] ?: '—'); ?></td>
    <td><?php echo (int) $g['count']; ?></td>
    <td><?php echo $g['source'] === 'manual' ? '<span style="color:#f5a623;">Manuel</span>' : 'Import officiel'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
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

<script>
(function () {
    document.querySelectorAll('.psc-year-candidate-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('psc-y-label').value = btn.getAttribute('data-label');
            document.getElementById('psc-y-debut').value = btn.getAttribute('data-debut');
            document.getElementById('psc-y-fin').value = btn.getAttribute('data-fin');
            document.getElementById('psc-y-label').scrollIntoView({ block: 'center' });
            document.getElementById('psc-y-label').focus();
        });
    });
})();
</script>
