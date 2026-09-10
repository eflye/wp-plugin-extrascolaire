<?php if (!defined('ABSPATH')) exit; ?>
<h1 class="psc-portal-h1">Planning cantine &amp; garderie</h1>
<section class="psc-assurance-gate" data-testid="assurance-gate">
<h2>Une assurance pour accéder au calendrier</h2>
<p>Déposez le justificatif d’assurance scolaire de chaque enfant pour l’année en cours.</p>
<p><?php echo Psc_Assurances::manual_review() ? 'Le calendrier sera accessible après validation des justificatifs par la mairie.' : 'Le calendrier sera accessible dès que tous les justificatifs auront été déposés.'; ?></p>
<?php foreach ($psc_blocked_children as $child):
    $doc = Psc_School_Years::enrollment($child->id, Psc_School_Years::active_id());
    $status = Psc_Assurances::status($doc);
?>
<article class="psc-assurance-card">
<h3><?php echo esc_html($child->prenom . ' ' . $child->nom); ?></h3>
<p><strong><?php echo esc_html(Psc_Assurances::status_label($status)); ?></strong></p>
<?php if ($status === 'rejected' && !empty($doc->assurance_review_note)): ?>
<p>Message de la mairie : <?php echo esc_html($doc->assurance_review_note); ?></p>
<?php endif; ?>
<?php if (!empty($doc->assurance_file_path)): ?>
<p><a target="_blank" rel="noopener" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_parent_download_assurance&child_id=' . (int) $child->id), 'psc_parent_download_assurance_' . $child->id)); ?>">Consulter le justificatif déposé</a></p>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<input type="hidden" name="action" value="psc_parent_upload_assurance">
<input type="hidden" name="return_tab" value="<?php echo esc_attr($psc_assurance_variant); ?>">
<input type="hidden" name="child_id" value="<?php echo (int) $child->id; ?>">
<?php wp_nonce_field('psc_parent_upload_assurance'); psc_parent_nonce_field('psc_parent_upload_assurance'); ?>
<label class="psc-portal-field-label" for="psc-assurance-<?php echo esc_attr($psc_assurance_variant); ?>-<?php echo (int) $child->id; ?>">Justificatif d’assurance scolaire (PDF, JPG ou PNG — 1 Mo maximum)</label>
<input id="psc-assurance-<?php echo esc_attr($psc_assurance_variant); ?>-<?php echo (int) $child->id; ?>" type="file" name="assurance_file" accept=".pdf,.jpg,.jpeg,.png" required>
<button class="psc-portal-btn-gold" type="submit"><?php echo $status === 'missing' ? 'Déposer le justificatif' : 'Remplacer le justificatif'; ?></button>
</form>
</article>
<?php endforeach; ?>
</section>
