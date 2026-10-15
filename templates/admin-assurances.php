<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap">
<h1><?php esc_html_e('Assurances scolaires', 'periscolaire-registration'); ?></h1>
<p><?php esc_html_e('Année scolaire active', 'periscolaire-registration'); ?> — <?php echo Psc_Assurances::manual_review() ? esc_html__('Revue par la mairie avant accès au planning.', 'periscolaire-registration') : esc_html__('Acceptation automatique après dépôt.', 'periscolaire-registration'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=psc_settings#psc-assurance-review')); ?>"><?php esc_html_e('Modifier le mode de validation', 'periscolaire-registration'); ?></a></p>
<?php if (psc_get('review') === 'saved'): ?><div class="notice notice-success"><p><?php esc_html_e('Décision enregistrée.', 'periscolaire-registration'); ?></p></div><?php endif; ?>
<?php if (psc_get('review') === 'stale'): ?><div class="notice notice-error"><p><?php esc_html_e('Le document a changé ou la décision n’a pas pu être enregistrée. Consultez le document actuel avant de réessayer.', 'periscolaire-registration'); ?></p></div><?php endif; ?>
<?php if ($selected && $selected->assurance_file_path):
$preview_url = wp_nonce_url(add_query_arg(array('action' => 'psc_download_assurance', 'child_id' => $selected->child_id, 'school_year_id' => $year_id, 'revision' => $selected->assurance_revision), admin_url('admin-post.php')), 'psc_download_assurance_' . $selected->child_id);
?>
<section class="psc-assurance-review">
<div>
<h2><?php echo esc_html($selected->prenom . ' ' . $selected->nom); ?></h2>
<p><?php echo esc_html($selected->assurance_original_filename); ?> — <strong><?php echo esc_html(Psc_Assurances::status_label(Psc_Assurances::status($selected))); ?></strong></p>
<p><a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ouvrir le document dans un nouvel onglet', 'periscolaire-registration'); ?></a></p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="psc_review_assurance">
<input type="hidden" name="child_id" value="<?php echo (int) $selected->child_id; ?>">
<input type="hidden" name="school_year_id" value="<?php echo (int) $year_id; ?>">
<input type="hidden" name="revision" value="<?php echo esc_attr($selected->assurance_revision); ?>">
<?php wp_nonce_field('psc_review_assurance_' . $selected->child_id . '_' . $year_id); ?>
<p><label for="review-note"><?php esc_html_e('Message à la famille (obligatoire en cas de refus)', 'periscolaire-registration'); ?></label><br><textarea id="review-note" name="review_note" rows="4" class="large-text"><?php echo esc_textarea($selected->assurance_review_note ?? ''); ?></textarea></p>
<p><button type="submit" class="button button-primary" name="decision" value="approved"><?php esc_html_e('Valider l’assurance', 'periscolaire-registration'); ?></button> <button type="submit" class="button" name="decision" value="rejected"><?php esc_html_e('Refuser — demander un remplacement', 'periscolaire-registration'); ?></button></p>
</form>
</div>
<iframe src="<?php echo esc_url($preview_url); ?>" title="<?php esc_attr_e('Justificatif d’assurance scolaire', 'periscolaire-registration'); ?>" class="psc-assurance-preview"></iframe>
</section>
<?php endif; ?>
<table class="widefat striped">
<caption class="screen-reader-text"><?php esc_html_e('Justificatifs d’assurance scolaire des enfants inscrits à l’année active', 'periscolaire-registration'); ?></caption>
<thead><tr><th scope="col"><?php esc_html_e('Enfant', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('État', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Date du dépôt', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Revue', 'periscolaire-registration'); ?></th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><th scope="row"><?php echo esc_html($row->nom . ' ' . $row->prenom); ?></th><td><?php echo esc_html(Psc_Assurances::status_label(Psc_Assurances::status($row))); ?></td><td><?php echo esc_html($row->assurance_uploaded_at ?? '—'); ?></td><td>
<?php if ($row->assurance_file_path): ?><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_assurances', 'child_id' => $row->child_id), admin_url('admin.php'))); ?>"><?php esc_html_e('Examiner', 'periscolaire-registration'); ?></a><?php else: ?><?php esc_html_e('À déposer par la famille', 'periscolaire-registration'); ?><?php endif; ?>
</td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4"><?php esc_html_e('Aucun enfant actif.', 'periscolaire-registration'); ?></td></tr><?php endif; ?>
</tbody></table>
</div>
