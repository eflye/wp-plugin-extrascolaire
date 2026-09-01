<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="reinscription-title"><?php esc_html_e('Réinscription', 'periscolaire-registration'); ?></h1>

<?php if (empty($psc_portal_reinscription['target_year'])): ?>
<p class="psc-portal-dash-menu-empty" data-testid="reinscription-no-year"><?php esc_html_e("La réinscription n'est pas encore ouverte : contactez la mairie.", 'periscolaire-registration'); ?></p>
<?php else:
  $psc_classe_labels = Psc_School_Years::classe_options();
  $psc_target_year = $psc_portal_reinscription['target_year'];
  $psc_reins_children = $psc_portal_reinscription['children'];
?>
<p class="psc-portal-intro" data-testid="reinscription-intro">
  <?php esc_html_e("Confirmez la réinscription de chaque enfant pour l'année", 'periscolaire-registration'); ?> <strong><?php echo esc_html($psc_target_year->label); ?></strong>.
  <?php esc_html_e('Un enfant décoché ne sera pas réinscrit automatiquement — vous pourrez toujours le faire plus tard tant que la fenêtre de réinscription est ouverte.', 'periscolaire-registration'); ?>
</p>

<?php if (empty($psc_reins_children)): ?>
<p class="psc-portal-dash-menu-empty" data-testid="reinscription-no-child"><?php esc_html_e('Aucun enfant actif à réinscrire.', 'periscolaire-registration'); ?></p>
<?php else: ?>
<div class="psc-portal-panel psc-portal-panel--wide">
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-testid="reinscription-form">
    <?php wp_nonce_field('psc_parent_reinscription'); psc_parent_nonce_field('psc_parent_reinscription'); ?>
    <input type="hidden" name="action" value="psc_parent_reinscription">

    <?php foreach ($psc_reins_children as $c):
      $psc_reins_id = 'psc-reins-' . $c['id'];
      $psc_sortie = $c['classe_proposee'] === null || $c['classe_proposee'] === 'sortie';
      $psc_classe_proposee_label = $psc_sortie ? __('Fin de cycle périscolaire', 'periscolaire-registration') : ($psc_classe_labels[$c['classe_proposee']] ?? $c['classe_proposee']);
    ?>
    <fieldset class="psc-portal-reins-child" data-testid="reinscription-child-<?php echo esc_attr($c['id']); ?>">
      <legend><?php echo esc_html($c['name']); ?></legend>

      <?php if ($psc_sortie): ?>
      <p class="psc-portal-dash-menu-empty"><?php esc_html_e('Fin de cycle périscolaire : cet enfant ne peut pas être réinscrit.', 'periscolaire-registration'); ?></p>
      <?php else: ?>
      <label class="psc-wizard-check-line">
        <input type="checkbox" id="<?php echo esc_attr($psc_reins_id); ?>-confirm" name="confirm_<?php echo esc_attr($c['id']); ?>" value="1" checked data-testid="reinscription-confirm-<?php echo esc_attr($c['id']); ?>">
        <?php esc_html_e('Réinscrire', 'periscolaire-registration'); ?> <?php echo esc_html($c['name']); ?> — <?php echo esc_html($c['classe_actuelle'] ? $psc_classe_labels[$c['classe_actuelle']] ?? $c['classe_actuelle'] : '—'); ?> → <?php echo esc_html($psc_classe_proposee_label); ?>
      </label>

      <label class="psc-portal-field-label" for="<?php echo esc_attr($psc_reins_id); ?>-assurance" style="margin-top:12px;"><?php esc_html_e("Nouveau justificatif d'assurance scolaire", 'periscolaire-registration'); ?></label>
      <input type="file" id="<?php echo esc_attr($psc_reins_id); ?>-assurance" name="assurance_<?php echo esc_attr($c['id']); ?>" accept=".pdf,.jpg,.jpeg,.png" class="psc-portal-field-underline" data-testid="reinscription-assurance-<?php echo esc_attr($c['id']); ?>">
      <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>

    <label class="psc-wizard-check-line" style="margin-top:16px;">
      <input type="checkbox" name="reglement_accepted" value="1" required data-testid="reinscription-reglement">
      <?php esc_html_e("J'ai pris connaissance du règlement intérieur des services périscolaires et je l'approuve dans sa totalité pour l'année", 'periscolaire-registration'); ?> <?php echo esc_html($psc_target_year->label); ?>.
    </label>

    <p style="margin-top:20px;"><button type="submit" class="psc-portal-btn-gold" data-testid="reinscription-submit"><?php esc_html_e('Confirmer la réinscription', 'periscolaire-registration'); ?></button></p>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
