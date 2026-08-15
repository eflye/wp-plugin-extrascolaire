<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Famille</div>
<h1 class="psc-portal-h1" data-testid="reinscription-title">Réinscription</h1>

<?php if (empty($psc_portal_reinscription['target_year'])): ?>
<p class="psc-portal-dash-menu-empty" data-testid="reinscription-no-year">La réinscription n'est pas encore ouverte : contactez la mairie.</p>
<?php else:
  $psc_classe_labels = psc_classe_options();
  $psc_target_year = $psc_portal_reinscription['target_year'];
  $psc_reins_children = $psc_portal_reinscription['children'];
?>
<p class="psc-portal-intro" data-testid="reinscription-intro">
  Confirmez la réinscription de chaque enfant pour l'année <strong><?php echo esc_html($psc_target_year->label); ?></strong>.
  Un enfant décoché ne sera pas réinscrit automatiquement — vous pourrez toujours le faire plus tard tant que la fenêtre de réinscription est ouverte.
</p>

<?php if (empty($psc_reins_children)): ?>
<p class="psc-portal-dash-menu-empty" data-testid="reinscription-no-child">Aucun enfant actif à réinscrire.</p>
<?php else: ?>
<div class="psc-portal-panel psc-portal-panel--wide">
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-testid="reinscription-form">
    <?php wp_nonce_field('psc_parent_reinscription'); ?>
    <input type="hidden" name="action" value="psc_parent_reinscription">

    <?php foreach ($psc_reins_children as $c):
      $psc_reins_id = 'psc-reins-' . $c['id'];
      $psc_sortie = $c['classe_proposee'] === null || $c['classe_proposee'] === 'sortie';
      $psc_classe_proposee_label = $psc_sortie ? 'Fin de cycle périscolaire' : ($psc_classe_labels[$c['classe_proposee']] ?? $c['classe_proposee']);
    ?>
    <fieldset class="psc-portal-reins-child" data-testid="reinscription-child-<?php echo esc_attr($c['id']); ?>">
      <legend><?php echo esc_html($c['name']); ?></legend>

      <?php if ($psc_sortie): ?>
      <p class="psc-portal-dash-menu-empty">Fin de cycle périscolaire : cet enfant ne peut pas être réinscrit.</p>
      <?php else: ?>
      <label class="psc-wizard-check-line">
        <input type="checkbox" id="<?php echo esc_attr($psc_reins_id); ?>-confirm" name="confirm_<?php echo esc_attr($c['id']); ?>" value="1" checked data-testid="reinscription-confirm-<?php echo esc_attr($c['id']); ?>">
        Réinscrire <?php echo esc_html($c['name']); ?> — <?php echo esc_html($c['classe_actuelle'] ? $psc_classe_labels[$c['classe_actuelle']] ?? $c['classe_actuelle'] : '—'); ?> → <?php echo esc_html($psc_classe_proposee_label); ?>
      </label>

      <label class="psc-portal-field-label" for="<?php echo esc_attr($psc_reins_id); ?>-assurance" style="margin-top:12px;">Nouveau justificatif d'assurance scolaire</label>
      <input type="file" id="<?php echo esc_attr($psc_reins_id); ?>-assurance" name="assurance_<?php echo esc_attr($c['id']); ?>" accept=".pdf,.jpg,.jpeg,.png" class="psc-portal-field-underline" data-testid="reinscription-assurance-<?php echo esc_attr($c['id']); ?>">
      <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>

    <label class="psc-wizard-check-line" style="margin-top:16px;">
      <input type="checkbox" name="reglement_accepted" value="1" required data-testid="reinscription-reglement">
      J'ai pris connaissance du règlement intérieur des services périscolaires et je l'approuve dans sa totalité pour l'année <?php echo esc_html($psc_target_year->label); ?>.
    </label>

    <p style="margin-top:20px;"><button type="submit" class="psc-portal-btn-gold" data-testid="reinscription-submit">Confirmer la réinscription</button></p>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
