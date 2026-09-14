<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Consulter un espace famille', 'periscolaire-registration'); ?></h1>

<?php if (!$family): ?>
<div class="notice notice-error" role="alert"><p><?php esc_html_e('Cette famille est introuvable ou son accès est désactivé.', 'periscolaire-registration'); ?></p></div>
<p><a class="button" data-testid="impersonate-back" href="<?php echo esc_url(add_query_arg('page', 'psc_parents', admin_url('admin.php'))); ?>"><?php esc_html_e('Retour aux familles', 'periscolaire-registration'); ?></a></p>
<?php else:
    $psc_detail_error = $psc_msg === 'motif_detail_required';
    $psc_motif_error = $psc_msg === 'motif_invalid';
?>
<?php if ($psc_msg === 'impersonation_failed'): ?>
<div class="notice notice-error" role="alert"><p><?php esc_html_e('La consultation n’a pas pu être ouverte. Réessayez.', 'periscolaire-registration'); ?></p></div>
<?php endif; ?>

<div class="psc-box">
<h2><?php echo esc_html($family->nom ?: $family->email); ?></h2>
<dl>
  <dt><strong><?php esc_html_e('Adresse e-mail', 'periscolaire-registration'); ?></strong></dt>
  <dd><?php echo esc_html($family->email); ?></dd>
  <dt><strong><?php esc_html_e('Enfants actifs', 'periscolaire-registration'); ?></strong></dt>
  <dd><?php echo esc_html(sprintf(_n('%d enfant', '%d enfants', $active_children, 'periscolaire-registration'), $active_children)); ?></dd>
</dl>
<p><?php esc_html_e('Vous ouvrirez exactement le portail de cette famille en lecture seule pendant 30 minutes. Aucune donnée ne pourra être modifiée.', 'periscolaire-registration'); ?></p>
<p><?php esc_html_e('Cette consultation sera enregistrée et pourra être visible par la famille si le réglage de transparence est activé.', 'periscolaire-registration'); ?></p>
</div>

<div class="psc-box">
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
<?php wp_nonce_field('psc_impersonate_start'); ?>
<input type="hidden" name="action" value="psc_impersonate_start">
<input type="hidden" name="family_id" value="<?php echo (int) $family->id; ?>">

<fieldset<?php echo $psc_motif_error ? ' aria-describedby="psc-motif-error"' : ''; ?>>
<legend><strong><?php esc_html_e('Motif de la consultation', 'periscolaire-registration'); ?></strong></legend>
<?php if ($psc_motif_error): ?><p id="psc-motif-error" class="notice notice-error" role="alert"><?php esc_html_e('Choisissez un motif de consultation.', 'periscolaire-registration'); ?></p><?php endif; ?>
<p><label><input type="radio" name="motif_type" value="reclamation" data-testid="impersonate-motif-reclamation" <?php checked(!$psc_detail_error); ?><?php echo $psc_motif_error ? ' autofocus' : ''; ?>> <?php esc_html_e('Problème signalé par la famille', 'periscolaire-registration'); ?></label></p>
<p><label><input type="radio" name="motif_type" value="verification" data-testid="impersonate-motif-verification"> <?php esc_html_e('Vérification avant de répondre', 'periscolaire-registration'); ?></label></p>
<p><label><input type="radio" name="motif_type" value="autre" data-testid="impersonate-motif-other" <?php checked($psc_detail_error); ?>> <?php esc_html_e('Autre', 'periscolaire-registration'); ?></label></p>
</fieldset>

<p>
  <label for="psc-motif-detail"><strong><?php esc_html_e('Précisez le motif si vous avez choisi « Autre »', 'periscolaire-registration'); ?></strong></label><br>
  <textarea id="psc-motif-detail" name="motif_detail" rows="3" class="large-text" minlength="5" maxlength="255"
            data-testid="impersonate-motif-detail" aria-describedby="psc-motif-detail-help<?php echo $psc_detail_error ? ' psc-motif-detail-error' : ''; ?>"<?php echo $psc_detail_error ? ' aria-invalid="true" autofocus' : ''; ?>></textarea>
  <span id="psc-motif-detail-help" class="description"><?php esc_html_e('De 5 à 255 caractères. Obligatoire uniquement pour le motif « Autre ».', 'periscolaire-registration'); ?></span>
  <?php if ($psc_detail_error): ?><span id="psc-motif-detail-error" class="notice notice-error" role="alert"><?php esc_html_e('Décrivez le motif en au moins 5 caractères.', 'periscolaire-registration'); ?></span><?php endif; ?>
</p>

<?php submit_button(__('Ouvrir l’espace en lecture seule', 'periscolaire-registration'), 'primary', 'submit', true, array('data-testid' => 'impersonate-submit')); ?>
</form>
</div>

<p><a data-testid="impersonate-back" href="<?php echo esc_url(add_query_arg(array('page' => 'psc_parents', 'edit' => (int) $family->id), admin_url('admin.php'))); ?>"><?php esc_html_e('Retour à la fiche famille', 'periscolaire-registration'); ?></a></p>
<?php endif; ?>
</div>
