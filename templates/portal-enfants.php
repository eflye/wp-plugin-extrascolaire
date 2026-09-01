<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="enfants-title"><?php esc_html_e('Mes enfants', 'periscolaire-registration'); ?></h1>

<?php if (!empty($all_children)): ?>
<div class="psc-portal-table-scroll">
<table class="psc-portal-table" data-testid="portal-children-table">
  <colgroup>
    <col style="width:13%"><col style="width:22%"><col style="width:10%"><col style="width:12%">
    <col style="width:15%"><col style="width:10%"><col style="width:18%">
  </colgroup>
  <thead>
    <tr>
      <th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Classe', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Naissance', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Régime', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Actif', 'periscolaire-registration'); ?></th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php $psc_classe_labels = Psc_School_Years::classe_options(); ?>
  <?php foreach ($all_children as $c): ?>
    <tr data-testid="portal-child-row-<?php echo esc_attr($c->id); ?>">
      <td style="font-weight:500;" data-label="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>"><?php echo esc_html($c->prenom); ?></td>
      <td data-label="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>"><?php echo esc_html($c->nom); ?></td>
      <?php $psc_c_classe = Psc_School_Years::classe_for($c->id); ?>
      <td data-label="<?php esc_attr_e('Classe', 'periscolaire-registration'); ?>"><?php echo esc_html($psc_c_classe ? ($psc_classe_labels[$psc_c_classe] ?? $psc_c_classe) : '—'); ?></td>
      <td data-label="<?php esc_attr_e('Naissance', 'periscolaire-registration'); ?>"><?php echo $c->date_naissance ? esc_html(date_i18n('d/m/Y', strtotime($c->date_naissance))) : '—'; ?></td>
      <td data-label="<?php esc_attr_e('Régime', 'periscolaire-registration'); ?>">
        <?php
          $psc_diet = array();
          if ((int) $c->sans_porc === 1) $psc_diet[] = __('Sans porc', 'periscolaire-registration');
          if ((int) $c->vegan === 1) $psc_diet[] = __('Sans viande', 'periscolaire-registration');
        ?>
        <?php if ($psc_diet): ?>
          <span class="psc-badge"><?php echo esc_html(implode(', ', $psc_diet)); ?></span>
        <?php else: ?>
          <span class="psc-portal-muted">—</span>
        <?php endif; ?>
      </td>
      <td data-label="<?php esc_attr_e('Actif', 'periscolaire-registration'); ?>">
        <?php if ($c->statut === 'actif'): ?>
          <span class="psc-badge-ok"><?php esc_html_e('Actif', 'periscolaire-registration'); ?></span>
        <?php else: ?>
          <span class="psc-badge-warn"><?php esc_html_e('Inactif', 'periscolaire-registration'); ?></span>
        <?php endif; ?>
      </td>
      <td class="psc-portal-row-save">
        <button type="button" class="psc-portal-btn-sm" data-child-edit-trigger data-child-id="<?php echo esc_attr($c->id); ?>" aria-label="<?php esc_attr_e('Corriger le prénom, le nom ou la date de naissance de', 'periscolaire-registration'); ?> <?php echo esc_attr($c->prenom); ?>"><?php esc_html_e('Modifier', 'periscolaire-registration'); ?></button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="psc-child-edit-modal" class="psc-portal-modal-overlay" hidden data-testid="child-edit-modal">
  <div class="psc-portal-modal" role="dialog" aria-modal="true" aria-labelledby="psc-child-edit-modal-title" tabindex="-1">
    <h3 class="psc-portal-modal-title" id="psc-child-edit-modal-title"><?php esc_html_e('Corriger les informations', 'periscolaire-registration'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="child-edit-form">
      <?php wp_nonce_field('psc_parent_update_child_identity'); psc_parent_nonce_field('psc_parent_update_child_identity'); ?>
      <input type="hidden" name="action" value="psc_parent_update_child_identity">
      <input type="hidden" name="child_id" id="psc-child-edit-id" value="">

      <label class="psc-portal-field-label" for="psc-child-edit-prenom"><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></label>
      <input type="text" id="psc-child-edit-prenom" name="prenom" maxlength="190" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-child-edit-nom" style="margin-top:16px;"><?php esc_html_e('Nom', 'periscolaire-registration'); ?></label>
      <input type="text" id="psc-child-edit-nom" name="nom" maxlength="190" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-child-edit-naissance" style="margin-top:16px;"><?php esc_html_e('Date de naissance', 'periscolaire-registration'); ?></label>
      <input type="date" id="psc-child-edit-naissance" name="naissance" max="<?php echo esc_attr(psc_child_birthdate_max()); ?>" class="psc-portal-field-underline">

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-child-edit-close><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="child-edit-submit"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="psc-child-edit-data"><?php
  $psc_child_edit_data = array();
  foreach ($all_children as $c) {
      $psc_child_edit_data[$c->id] = array(
          'prenom'    => $c->prenom,
          'nom'       => $c->nom,
          'naissance' => $c->date_naissance,
      );
  }
  echo wp_json_encode($psc_child_edit_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?></script>
<?php endif; ?>

<?php
$psc_active_children = array_filter($all_children, function ($c) { return $c->statut === 'actif'; });
$psc_active_year = Psc_School_Years::active();
if ($psc_active_year) {
    $psc_assurance_year_label = $psc_active_year->label;
} else {
    // Aucune année scolaire active (installation neuve) : repli sur l'année
    // de rentrée déduite de la date du jour.
    $psc_rentree_debut = psc_rentree_year();
    $psc_assurance_year_label = $psc_rentree_debut . '–' . ($psc_rentree_debut + 1);
}
?>
<?php if (!empty($psc_active_children)): ?>
<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title"><?php esc_html_e('Assurance scolaire', 'periscolaire-registration'); ?> <?php echo esc_html($psc_assurance_year_label); ?></div>
  <p class="psc-portal-intro"><?php esc_html_e('Un justificatif à jour est nécessaire pour pouvoir déclarer des jours de cantine ou de garderie pour chaque enfant.', 'periscolaire-registration'); ?></p>
  <div class="psc-portal-table-scroll">
  <table class="psc-portal-table" data-testid="portal-assurance-table">
    <colgroup>
      <col style="width:13%"><col style="width:22%"><col style="width:47%"><col style="width:18%">
    </colgroup>
    <thead><tr><th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Statut', 'periscolaire-registration'); ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($psc_active_children as $c): $psc_a = $psc_assurance_map[$c->id] ?? null; ?>
      <tr data-testid="assurance-row-<?php echo esc_attr($c->id); ?>">
        <td style="font-weight:500;" data-label="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>"><?php echo esc_html($c->prenom); ?></td>
        <td data-label="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>"><?php echo esc_html($c->nom); ?></td>
        <td data-label="<?php esc_attr_e('Statut', 'periscolaire-registration'); ?>">
          <span class="psc-portal-cell-value">
          <?php if ($psc_a): ?>
            <span class="psc-badge-ok" data-testid="assurance-status-<?php echo esc_attr($c->id); ?>"><?php esc_html_e('Fournie', 'periscolaire-registration'); ?></span>
            <?php esc_html_e('le', 'periscolaire-registration'); ?> <?php echo esc_html(date_i18n('d/m/Y', strtotime($psc_a->uploaded_at))); ?>
            — <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_parent_download_assurance&child_id=' . $c->id), 'psc_parent_download_assurance_' . $c->id)); ?>" target="_blank" rel="noopener" data-testid="assurance-view-<?php echo esc_attr($c->id); ?>"><?php esc_html_e('Voir le fichier', 'periscolaire-registration'); ?></a>
          <?php else: ?>
            <span class="psc-badge-warn" data-testid="assurance-status-<?php echo esc_attr($c->id); ?>"><?php esc_html_e('Manquante', 'periscolaire-registration'); ?></span>
          <?php endif; ?>
          </span>
        </td>
        <td>
          <button type="button" class="psc-portal-btn-sm" data-assurance-upload-trigger data-child-id="<?php echo esc_attr($c->id); ?>" aria-label="<?php echo esc_attr(($psc_a ? __('Remplacer le justificatif d\'assurance de', 'periscolaire-registration') : __('Ajouter le justificatif d\'assurance de', 'periscolaire-registration')) . ' ' . $c->prenom); ?>"><?php echo esc_html($psc_a ? __('Remplacer', 'periscolaire-registration') : __('Uploader', 'periscolaire-registration')); ?></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="psc-assurance-upload-modal" class="psc-portal-modal-overlay" hidden data-testid="assurance-upload-modal">
  <div class="psc-portal-modal" role="dialog" aria-modal="true" aria-labelledby="psc-assurance-upload-title" tabindex="-1">
    <h3 class="psc-portal-modal-title" id="psc-assurance-upload-title"><?php esc_html_e("Justificatif d'assurance scolaire", 'periscolaire-registration'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-testid="assurance-upload-form">
      <?php wp_nonce_field('psc_parent_upload_assurance'); psc_parent_nonce_field('psc_parent_upload_assurance'); ?>
      <input type="hidden" name="action" value="psc_parent_upload_assurance">
      <input type="hidden" name="child_id" id="psc-assurance-upload-child-id" value="">

      <div class="psc-portal-file-field">
        <label for="psc-assurance-upload-file" class="psc-portal-file-field-btn"><?php esc_html_e('Choisir un fichier', 'periscolaire-registration'); ?></label>
        <input type="file" id="psc-assurance-upload-file" name="assurance_file" accept=".pdf,.jpg,.jpeg,.png" required class="psc-portal-file-field-input" data-testid="assurance-upload-file">
        <span class="psc-portal-file-field-name" data-psc-file-name></span>
      </div>

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-assurance-upload-close><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="assurance-upload-submit"><?php esc_html_e('Envoyer', 'periscolaire-registration'); ?></button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="psc-assurance-upload-data"><?php
  $psc_assurance_upload_data = array();
  foreach ($psc_active_children as $c) {
      $psc_a = $psc_assurance_map[$c->id] ?? null;
      $psc_assurance_upload_data[$c->id] = array(
          'label' => $c->prenom . ' — ' . ($psc_a ? __('remplacer le justificatif', 'periscolaire-registration') : __('ajouter le justificatif', 'periscolaire-registration')),
      );
  }
  echo wp_json_encode($psc_assurance_upload_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?></script>
<?php endif; ?>


<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title"><?php esc_html_e('Ajouter un enfant', 'periscolaire-registration'); ?></div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="psc-add-child-form">
    <?php wp_nonce_field('psc_parent_add_child'); psc_parent_nonce_field('psc_parent_add_child'); ?>
    <input type="hidden" name="action" value="psc_parent_add_child">
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></div>
        <input type="text" name="new_prenom" placeholder="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>" maxlength="190" required class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Nom', 'periscolaire-registration'); ?></div>
        <input type="text" name="new_nom" placeholder="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>" maxlength="190" required class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Classe', 'periscolaire-registration'); ?></div>
        <select name="new_classe" class="psc-portal-field-underline">
          <?php foreach (Psc_School_Years::classe_options() as $v => $l): ?>
          <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Date de naissance', 'periscolaire-registration'); ?></div>
        <input type="date" name="new_naissance" max="<?php echo esc_attr(psc_child_birthdate_max()); ?>" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e("Justificatif d'assurance scolaire", 'periscolaire-registration'); ?></div>
        <input type="file" name="new_assurance_file" accept=".pdf,.jpg,.jpeg,.png" required class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label"><?php esc_html_e('Régime alimentaire', 'periscolaire-registration'); ?></div>
        <div class="psc-portal-diet-checks">
          <label><input type="checkbox" name="new_sans_porc" value="1"> <?php esc_html_e('Sans porc', 'periscolaire-registration'); ?></label>
          <label><input type="checkbox" name="new_vegan" value="1"> <?php esc_html_e('Sans viande', 'periscolaire-registration'); ?></label>
        </div>
      </div>
    </div>
    <button type="submit" class="psc-portal-btn-gold"><?php esc_html_e('Ajouter', 'periscolaire-registration'); ?></button>
  </form>
</div>
