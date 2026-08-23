<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Famille</div>
<h1 class="psc-portal-h1" data-testid="enfants-title">Mes enfants</h1>

<?php if (!empty($all_children)): ?>
<div class="psc-portal-table-scroll">
<table class="psc-portal-table" data-testid="portal-children-table">
  <colgroup>
    <col style="width:13%"><col style="width:22%"><col style="width:10%"><col style="width:12%">
    <col style="width:15%"><col style="width:10%"><col style="width:18%">
  </colgroup>
  <thead>
    <tr>
      <th>Prénom</th><th>Nom</th><th>Classe</th><th>Naissance</th><th>Régime</th><th>Actif</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php $psc_classe_labels = psc_classe_options(); ?>
  <?php foreach ($all_children as $c): ?>
    <tr data-testid="portal-child-row-<?php echo esc_attr($c->id); ?>">
      <td style="font-weight:500;" data-label="Prénom"><?php echo esc_html($c->prenom); ?></td>
      <td data-label="Nom"><?php echo esc_html($c->nom); ?></td>
      <?php $psc_c_classe = Psc_School_Years::classe_for($c->id); ?>
      <td data-label="Classe"><?php echo esc_html($psc_c_classe ? ($psc_classe_labels[$psc_c_classe] ?? $psc_c_classe) : '—'); ?></td>
      <td data-label="Naissance"><?php echo $c->date_naissance ? esc_html(date_i18n('d/m/Y', strtotime($c->date_naissance))) : '—'; ?></td>
      <td data-label="Régime">
        <?php
          $psc_diet = array();
          if ((int) $c->sans_porc === 1) $psc_diet[] = 'Sans porc';
          if ((int) $c->vegan === 1) $psc_diet[] = 'Sans viande';
        ?>
        <?php if ($psc_diet): ?>
          <span class="psc-badge"><?php echo esc_html(implode(', ', $psc_diet)); ?></span>
        <?php else: ?>
          <span class="psc-portal-muted">—</span>
        <?php endif; ?>
      </td>
      <td data-label="Actif">
        <?php if ($c->statut === 'actif'): ?>
          <span class="psc-badge-ok">Actif</span>
        <?php else: ?>
          <span class="psc-badge-warn">Inactif</span>
        <?php endif; ?>
      </td>
      <td class="psc-portal-row-save">
        <button type="button" class="psc-portal-btn-sm" data-child-edit-trigger data-child-id="<?php echo esc_attr($c->id); ?>" aria-label="Corriger le prénom, le nom ou la date de naissance de <?php echo esc_attr($c->prenom); ?>">Modifier</button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div id="psc-child-edit-modal" class="psc-portal-modal-overlay" hidden data-testid="child-edit-modal">
  <div class="psc-portal-modal">
    <h3 class="psc-portal-modal-title">Corriger les informations</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="child-edit-form">
      <?php wp_nonce_field('psc_parent_update_child_identity'); psc_parent_nonce_field('psc_parent_update_child_identity'); ?>
      <input type="hidden" name="action" value="psc_parent_update_child_identity">
      <input type="hidden" name="child_id" id="psc-child-edit-id" value="">

      <label class="psc-portal-field-label" for="psc-child-edit-prenom">Prénom</label>
      <input type="text" id="psc-child-edit-prenom" name="prenom" maxlength="190" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-child-edit-nom" style="margin-top:16px;">Nom</label>
      <input type="text" id="psc-child-edit-nom" name="nom" maxlength="190" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-child-edit-naissance" style="margin-top:16px;">Date de naissance</label>
      <input type="date" id="psc-child-edit-naissance" name="naissance" class="psc-portal-field-underline">

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-child-edit-close>Annuler</button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="child-edit-submit">Enregistrer</button>
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
  <div class="psc-portal-panel-title">Assurance scolaire <?php echo esc_html($psc_assurance_year_label); ?></div>
  <p class="psc-portal-intro">Un justificatif à jour est nécessaire pour pouvoir déclarer des jours de cantine ou de garderie pour chaque enfant.</p>
  <div class="psc-portal-table-scroll">
  <table class="psc-portal-table" data-testid="portal-assurance-table">
    <colgroup>
      <col style="width:13%"><col style="width:22%"><col style="width:47%"><col style="width:18%">
    </colgroup>
    <thead><tr><th>Prénom</th><th>Nom</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($psc_active_children as $c): $psc_a = $psc_assurance_map[$c->id] ?? null; ?>
      <tr data-testid="assurance-row-<?php echo esc_attr($c->id); ?>">
        <td style="font-weight:500;" data-label="Prénom"><?php echo esc_html($c->prenom); ?></td>
        <td data-label="Nom"><?php echo esc_html($c->nom); ?></td>
        <td data-label="Statut">
          <span class="psc-portal-cell-value">
          <?php if ($psc_a): ?>
            <span class="psc-badge-ok" data-testid="assurance-status-<?php echo esc_attr($c->id); ?>">Fournie</span>
            le <?php echo esc_html(date_i18n('d/m/Y', strtotime($psc_a->uploaded_at))); ?>
            — <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_parent_download_assurance&child_id=' . $c->id), 'psc_parent_download_assurance_' . $c->id)); ?>" target="_blank" rel="noopener" data-testid="assurance-view-<?php echo esc_attr($c->id); ?>">Voir le fichier</a>
          <?php else: ?>
            <span class="psc-badge-warn" data-testid="assurance-status-<?php echo esc_attr($c->id); ?>">Manquante</span>
          <?php endif; ?>
          </span>
        </td>
        <td>
          <button type="button" class="psc-portal-btn-sm" data-assurance-upload-trigger data-child-id="<?php echo esc_attr($c->id); ?>" aria-label="<?php echo esc_attr($psc_a ? 'Remplacer le justificatif d\'assurance de ' . $c->prenom : 'Ajouter le justificatif d\'assurance de ' . $c->prenom); ?>"><?php echo $psc_a ? 'Remplacer' : 'Uploader'; ?></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div id="psc-assurance-upload-modal" class="psc-portal-modal-overlay" hidden data-testid="assurance-upload-modal">
  <div class="psc-portal-modal">
    <h3 class="psc-portal-modal-title" id="psc-assurance-upload-title">Justificatif d'assurance scolaire</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-testid="assurance-upload-form">
      <?php wp_nonce_field('psc_parent_upload_assurance'); psc_parent_nonce_field('psc_parent_upload_assurance'); ?>
      <input type="hidden" name="action" value="psc_parent_upload_assurance">
      <input type="hidden" name="child_id" id="psc-assurance-upload-child-id" value="">

      <div class="psc-portal-file-field">
        <label for="psc-assurance-upload-file" class="psc-portal-file-field-btn">Choisir un fichier</label>
        <input type="file" id="psc-assurance-upload-file" name="assurance_file" accept=".pdf,.jpg,.jpeg,.png" required class="psc-portal-file-field-input" data-testid="assurance-upload-file">
        <span class="psc-portal-file-field-name" data-psc-file-name></span>
      </div>

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-assurance-upload-close>Annuler</button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="assurance-upload-submit">Envoyer</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="psc-assurance-upload-data"><?php
  $psc_assurance_upload_data = array();
  foreach ($psc_active_children as $c) {
      $psc_a = $psc_assurance_map[$c->id] ?? null;
      $psc_assurance_upload_data[$c->id] = array(
          'label' => $c->prenom . ($psc_a ? ' — remplacer le justificatif' : ' — ajouter le justificatif'),
      );
  }
  echo wp_json_encode($psc_assurance_upload_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?></script>
<?php endif; ?>

<?php if (!empty($psc_active_children)): ?>
<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title">Personnes autorisées à récupérer les enfants — garderie du soir</div>
  <p class="psc-portal-intro">Ces personnes peuvent venir chercher vos enfants au départ de la <strong>garderie du soir</strong> — cette liste ne concerne ni la cantine ni la garderie du matin. Toute modification est conservée dans un historique consultable par la mairie.</p>

  <?php foreach ($psc_active_children as $c): $psc_pickups = $psc_pickup_map[$c->id] ?? array(); $psc_parent_rows = Psc_Pickup_Persons::parent_entries($parent); ?>
  <div class="psc-portal-pickup-child-block" data-testid="pickup-child-block-<?php echo esc_attr($c->id); ?>">
    <h3 class="psc-portal-pickup-child-name"><?php echo esc_html($c->prenom . ' ' . $c->nom); ?></h3>

      <div class="psc-portal-table-scroll">
      <table class="psc-portal-table" data-testid="pickup-table-<?php echo esc_attr($c->id); ?>">
        <colgroup>
          <col style="width:16%"><col style="width:16%"><col style="width:18%"><col style="width:18%"><col style="width:14%"><col style="width:18%">
        </colgroup>
        <thead><tr><th>Prénom</th><th>Nom</th><th>Téléphone</th><th>Lien</th><th>Pièce d'identité</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($psc_parent_rows as $psc_pr_i => $pr): ?>
          <tr data-testid="pickup-parent-row-<?php echo esc_attr($c->id); ?>-<?php echo esc_attr($psc_pr_i); ?>">
            <td style="font-weight:500;" data-label="Prénom"><?php echo esc_html($pr['prenom']); ?></td>
            <td data-label="Nom"><?php echo esc_html($pr['nom']); ?></td>
            <td data-label="Téléphone"><?php echo esc_html($pr['telephone'] !== '' ? $pr['telephone'] : '—'); ?></td>
            <td data-label="Lien"><span class="psc-portal-pill"><?php echo esc_html($pr['role']); ?></span></td>
            <td data-label="Pièce d'identité"><span class="psc-portal-muted">—</span></td>
            <td class="psc-portal-row-save"></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($psc_pickups as $p): ?>
          <tr data-testid="pickup-row-<?php echo esc_attr($p->id); ?>">
            <td style="font-weight:500;" data-label="Prénom"><?php echo esc_html($p->prenom); ?></td>
            <td data-label="Nom"><?php echo esc_html($p->nom); ?></td>
            <td data-label="Téléphone"><?php echo esc_html($p->telephone); ?></td>
            <td data-label="Lien"><?php echo esc_html($p->lien !== '' ? $p->lien : '—'); ?></td>
            <td data-label="Pièce d'identité">
              <?php if ((int) $p->piece_identite === 1): ?>
                <span class="psc-badge-ok">Oui</span>
              <?php else: ?>
                <span class="psc-portal-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="psc-portal-row-save">
              <button type="button" class="psc-portal-btn-sm" data-pickup-edit-trigger data-pickup-id="<?php echo esc_attr($p->id); ?>" aria-label="Modifier les coordonnées de <?php echo esc_attr($p->prenom . ' ' . $p->nom); ?>">Modifier</button>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('psc_parent_remove_pickup_person'); psc_parent_nonce_field('psc_parent_remove_pickup_person'); ?>
                <input type="hidden" name="action" value="psc_parent_remove_pickup_person">
                <input type="hidden" name="pickup_id" value="<?php echo esc_attr($p->id); ?>">
                <button type="submit" class="psc-portal-btn-sm" onclick="return confirm('Retirer <?php echo esc_js($p->prenom . ' ' . $p->nom); ?> de la liste des personnes autorisées à récupérer <?php echo esc_js($c->prenom); ?> ?');">Retirer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

    <button type="button" class="psc-portal-btn-sm" data-pickup-add-trigger data-child-id="<?php echo esc_attr($c->id); ?>" aria-label="Ajouter une personne autorisée à récupérer <?php echo esc_attr($c->prenom); ?>">+ Ajouter une personne</button>
  </div>
  <?php endforeach; ?>
</div>

<div id="psc-pickup-modal" class="psc-portal-modal-overlay" hidden data-testid="pickup-modal">
  <div class="psc-portal-modal">
    <h3 class="psc-portal-modal-title" id="psc-pickup-modal-title">Personne autorisée</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="pickup-form">
      <?php wp_nonce_field('psc_parent_pickup_person'); psc_parent_nonce_field('psc_parent_pickup_person'); ?>
      <input type="hidden" name="action" id="psc-pickup-form-action" value="psc_parent_add_pickup_person">
      <input type="hidden" name="child_id" id="psc-pickup-child-id" value="">
      <input type="hidden" name="pickup_id" id="psc-pickup-id" value="">

      <label class="psc-portal-field-label" for="psc-pickup-prenom">Prénom</label>
      <input type="text" id="psc-pickup-prenom" name="prenom" maxlength="191" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-nom" style="margin-top:16px;">Nom</label>
      <input type="text" id="psc-pickup-nom" name="nom" maxlength="191" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-telephone" style="margin-top:16px;">Téléphone</label>
      <input type="tel" id="psc-pickup-telephone" name="telephone" maxlength="40" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-lien" style="margin-top:16px;">Lien avec l'enfant</label>
      <input type="text" id="psc-pickup-lien" name="lien" maxlength="100" list="psc-pickup-lien-suggestions-portal" class="psc-portal-field-underline">
      <datalist id="psc-pickup-lien-suggestions-portal">
        <?php foreach (psc_pickup_lien_suggestions() as $psc_lien): ?>
        <option value="<?php echo esc_attr($psc_lien); ?>">
        <?php endforeach; ?>
      </datalist>

      <label class="psc-wizard-check-line" style="margin-top:16px;">
        <input type="checkbox" id="psc-pickup-piece-identite" name="piece_identite" value="1"> Présentera une pièce d'identité
      </label>

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-pickup-modal-close>Annuler</button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="pickup-submit">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="psc-pickup-data"><?php
  $psc_pickup_js_data = array('children' => array(), 'persons' => array());
  foreach ($psc_active_children as $c) {
      $psc_pickup_js_data['children'][$c->id] = array('name' => $c->prenom);
  }
  foreach ($psc_pickup_map as $psc_pm_child_id => $psc_pm_persons) {
      foreach ($psc_pm_persons as $p) {
          $psc_pickup_js_data['persons'][$p->id] = array(
              'child_id'       => (int) $p->child_id,
              'prenom'         => $p->prenom,
              'nom'            => $p->nom,
              'telephone'      => $p->telephone,
              'lien'           => $p->lien,
              'piece_identite' => (int) $p->piece_identite,
          );
      }
  }
  echo wp_json_encode($psc_pickup_js_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?></script>
<?php endif; ?>

<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title">Ajouter un enfant</div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="psc-add-child-form">
    <?php wp_nonce_field('psc_parent_add_child'); psc_parent_nonce_field('psc_parent_add_child'); ?>
    <input type="hidden" name="action" value="psc_parent_add_child">
    <div class="psc-portal-field-grid">
      <div>
        <div class="psc-portal-field-label">Prénom</div>
        <input type="text" name="new_prenom" placeholder="Prénom" maxlength="190" required class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Nom</div>
        <input type="text" name="new_nom" placeholder="Nom" maxlength="190" required class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Classe</div>
        <select name="new_classe" class="psc-portal-field-underline">
          <?php foreach (psc_classe_options() as $v => $l): ?>
          <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <div class="psc-portal-field-label">Date de naissance</div>
        <input type="date" name="new_naissance" class="psc-portal-field-underline">
      </div>
      <div>
        <div class="psc-portal-field-label">Justificatif d'assurance scolaire</div>
        <input type="file" name="new_assurance_file" accept=".pdf,.jpg,.jpeg,.png" required class="psc-portal-field-underline">
      </div>
      <div class="psc-portal-diet-checks">
        <label><input type="checkbox" name="new_sans_porc" value="1"> Sans porc</label>
        <label><input type="checkbox" name="new_vegan" value="1"> Sans viande</label>
      </div>
    </div>
    <button type="submit" class="psc-portal-btn-gold">Ajouter</button>
  </form>
</div>
