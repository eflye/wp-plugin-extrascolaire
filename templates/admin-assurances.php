<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
<h1>Assurances scolaires</h1>
<p>Année scolaire active — <?php echo Psc_Assurances::manual_review() ? 'Revue par la mairie avant accès au planning.' : 'Acceptation automatique après dépôt.'; ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_settings#psc-assurance-review')); ?>">Modifier le mode de validation</a></p>
<?php if (psc_get('review') === 'saved'): ?><div class="notice notice-success"><p>Décision enregistrée.</p></div><?php endif; ?>
<?php if (psc_get('review') === 'stale'): ?><div class="notice notice-error"><p>Le document a changé ou la décision n’a pas pu être enregistrée. Consultez le document actuel avant de réessayer.</p></div><?php endif; ?>
<?php if ($selected && $selected->assurance_file_path):
$preview_url = wp_nonce_url(add_query_arg(array('action' => 'psc_download_assurance', 'child_id' => $selected->child_id, 'school_year_id' => $year_id, 'revision' => $selected->assurance_revision), admin_url('admin-post.php')), 'psc_download_assurance_' . $selected->child_id);
?>
<section class="psc-assurance-review">
<div>
<h2><?php echo esc_html($selected->prenom . ' ' . $selected->nom); ?></h2>
<p><?php echo esc_html($selected->assurance_original_filename); ?> — <strong><?php echo esc_html(Psc_Assurances::status_label(Psc_Assurances::status($selected))); ?></strong></p>
<p><a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">Ouvrir le document dans un nouvel onglet</a></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="psc_review_assurance">
<input type="hidden" name="child_id" value="<?php echo (int) $selected->child_id; ?>">
<input type="hidden" name="school_year_id" value="<?php echo (int) $year_id; ?>">
<input type="hidden" name="revision" value="<?php echo esc_attr($selected->assurance_revision); ?>">
<?php wp_nonce_field('psc_review_assurance_' . $selected->child_id . '_' . $year_id); ?>
<p><label for="review-note">Message à la famille (obligatoire en cas de refus)</label><br><textarea id="review-note" name="review_note" rows="4" class="large-text"><?php echo esc_textarea($selected->assurance_review_note ?? ''); ?></textarea></p>
<p><button type="submit" class="button button-primary" name="decision" value="approved">Valider l’assurance</button> <button type="submit" class="button" name="decision" value="rejected">Refuser — demander un remplacement</button></p>
</form>
</div>
<iframe src="<?php echo esc_url($preview_url); ?>" title="Justificatif d’assurance scolaire" class="psc-assurance-preview"></iframe>
</section>
<?php endif; ?>
<table class="widefat striped"><thead><tr><th>Enfant</th><th>État</th><th>Date du dépôt</th><th>Revue</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?php echo esc_html($row->nom . ' ' . $row->prenom); ?></td><td><?php echo esc_html(Psc_Assurances::status_label(Psc_Assurances::status($row))); ?></td><td><?php echo esc_html($row->assurance_uploaded_at ?? '—'); ?></td><td>
<?php if ($row->assurance_file_path): ?><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_assurances', 'child_id' => $row->child_id), admin_url('admin.php'))); ?>">Examiner</a><?php else: ?>À déposer par la famille<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4">Aucun enfant actif.</td></tr><?php endif; ?>
</tbody></table>
</div>
