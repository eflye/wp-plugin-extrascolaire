<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></div>
<h1 class="psc-portal-h1" data-testid="habilitations-title"><?php esc_html_e('Habilitations', 'periscolaire-registration'); ?></h1>

<?php if (!empty($psc_active_children)): ?>
<div class="psc-portal-panel psc-portal-panel--wide">
  <div class="psc-portal-panel-title"><?php esc_html_e('Personnes autorisées à récupérer les enfants — garderie du soir', 'periscolaire-registration'); ?></div>
  <p class="psc-portal-intro"><?php esc_html_e('Ces personnes peuvent venir chercher vos enfants au départ de la', 'periscolaire-registration'); ?> <strong><?php esc_html_e('garderie du soir', 'periscolaire-registration'); ?></strong> <?php esc_html_e('— cette liste ne concerne ni la cantine ni la garderie du matin. Une personne ajoutée l\'est pour tous vos enfants ; toute modification est conservée dans un historique consultable par la mairie.', 'periscolaire-registration'); ?></p>

  <button type="button" class="psc-portal-btn-sm" data-pickup-add-all-trigger data-testid="pickup-add-all" aria-label="<?php esc_attr_e('Ajouter une personne autorisée pour tous les enfants', 'periscolaire-registration'); ?>"><?php esc_html_e('+ Ajouter une personne', 'periscolaire-registration'); ?></button>

  <?php $psc_parent_rows = Psc_Pickup_Persons::parent_entries($parent); // requête unique : les deux parents sont les mêmes pour chaque enfant ?>
  <?php foreach ($psc_active_children as $c): $psc_pickups = $psc_pickup_map[$c->id] ?? array(); ?>
  <div class="psc-portal-pickup-child-block" data-testid="pickup-child-block-<?php echo esc_attr($c->id); ?>">
    <h3 class="psc-portal-pickup-child-name"><?php echo esc_html($c->prenom . ' ' . $c->nom); ?></h3>

      <div class="psc-portal-table-scroll">
      <table class="psc-portal-table" data-testid="pickup-table-<?php echo esc_attr($c->id); ?>">
        <colgroup>
          <col style="width:16%"><col style="width:16%"><col style="width:18%"><col style="width:18%"><col style="width:14%"><col style="width:18%">
        </colgroup>
        <thead><tr><th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Lien', 'periscolaire-registration'); ?></th><th><?php esc_html_e("Pièce d'identité", 'periscolaire-registration'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($psc_parent_rows as $psc_pr_i => $pr): ?>
          <tr data-testid="pickup-parent-row-<?php echo esc_attr($c->id); ?>-<?php echo esc_attr($psc_pr_i); ?>">
            <td style="font-weight:500;" data-label="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>"><?php echo esc_html($pr['prenom']); ?></td>
            <td data-label="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>"><?php echo esc_html($pr['nom']); ?></td>
            <td data-label="<?php esc_attr_e('Téléphone', 'periscolaire-registration'); ?>"><?php echo esc_html($pr['telephone'] !== '' ? $pr['telephone'] : '—'); ?></td>
            <td data-label="<?php esc_attr_e('Lien', 'periscolaire-registration'); ?>"><span class="psc-portal-pill"><?php echo esc_html($pr['role']); ?></span></td>
            <td data-label="<?php esc_attr_e("Pièce d'identité", 'periscolaire-registration'); ?>"><span class="psc-portal-muted">—</span></td>
            <td class="psc-portal-row-save"></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($psc_pickups as $p): ?>
          <tr data-testid="pickup-row-<?php echo esc_attr($p->id); ?>">
            <td style="font-weight:500;" data-label="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>"><?php echo esc_html($p->prenom); ?></td>
            <td data-label="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>"><?php echo esc_html($p->nom); ?></td>
            <td data-label="<?php esc_attr_e('Téléphone', 'periscolaire-registration'); ?>"><?php echo esc_html($p->telephone); ?></td>
            <td data-label="<?php esc_attr_e('Lien', 'periscolaire-registration'); ?>"><?php echo esc_html($p->lien !== '' ? $p->lien : '—'); ?></td>
            <td data-label="<?php esc_attr_e("Pièce d'identité", 'periscolaire-registration'); ?>">
              <?php if ((int) $p->piece_identite === 1): ?>
                <span class="psc-badge-ok"><?php esc_html_e('Oui', 'periscolaire-registration'); ?></span>
              <?php else: ?>
                <span class="psc-portal-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="psc-portal-row-save">
              <button type="button" class="psc-portal-btn-sm" data-pickup-edit-trigger data-pickup-id="<?php echo esc_attr($p->id); ?>" aria-label="<?php esc_attr_e('Modifier les coordonnées de', 'periscolaire-registration'); ?> <?php echo esc_attr($p->prenom . ' ' . $p->nom); ?>"><?php esc_html_e('Modifier', 'periscolaire-registration'); ?></button>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('psc_parent_remove_pickup_person'); psc_parent_nonce_field('psc_parent_remove_pickup_person'); ?>
                <input type="hidden" name="action" value="psc_parent_remove_pickup_person">
                <input type="hidden" name="pickup_id" value="<?php echo esc_attr($p->id); ?>">
                <button type="submit" class="psc-portal-btn-sm" onclick="return confirm('<?php echo esc_js(__('Retirer', 'periscolaire-registration')); ?> <?php echo esc_js($p->prenom . ' ' . $p->nom); ?> <?php echo esc_js(__('de la liste des personnes autorisées à récupérer', 'periscolaire-registration')); ?> <?php echo esc_js($c->prenom); ?> ?');"><?php esc_html_e('Retirer', 'periscolaire-registration'); ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
  </div>
  <?php endforeach; ?>
</div>

<div id="psc-pickup-modal" class="psc-portal-modal-overlay" hidden data-testid="pickup-modal">
  <div class="psc-portal-modal" role="dialog" aria-modal="true" aria-labelledby="psc-pickup-modal-title" tabindex="-1">
    <h3 class="psc-portal-modal-title" id="psc-pickup-modal-title"><?php esc_html_e('Personne autorisée', 'periscolaire-registration'); ?></h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="pickup-form">
      <?php wp_nonce_field('psc_parent_pickup_person'); psc_parent_nonce_field('psc_parent_pickup_person'); ?>
      <!-- Défaut : ajout pour tous les enfants (foyer) ; la popin est
           toujours ouverte en JavaScript, qui bascule l'action vers
           psc_parent_update_pickup_person en mode édition. -->
      <input type="hidden" name="action" id="psc-pickup-form-action" value="psc_parent_add_household_pickup_person">
      <input type="hidden" name="child_id" id="psc-pickup-child-id" value="">
      <input type="hidden" name="pickup_id" id="psc-pickup-id" value="">

      <label class="psc-portal-field-label" for="psc-pickup-prenom"><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></label>
      <input type="text" id="psc-pickup-prenom" name="prenom" maxlength="191" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-nom" style="margin-top:16px;"><?php esc_html_e('Nom', 'periscolaire-registration'); ?></label>
      <input type="text" id="psc-pickup-nom" name="nom" maxlength="191" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-telephone" style="margin-top:16px;"><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></label>
      <input type="tel" id="psc-pickup-telephone" name="telephone" maxlength="40" required class="psc-portal-field-underline">

      <label class="psc-portal-field-label" for="psc-pickup-lien" style="margin-top:16px;"><?php esc_html_e("Lien avec l'enfant", 'periscolaire-registration'); ?></label>
      <input type="text" id="psc-pickup-lien" name="lien" maxlength="100" list="psc-pickup-lien-suggestions-portal" class="psc-portal-field-underline">
      <datalist id="psc-pickup-lien-suggestions-portal">
        <?php foreach (psc_pickup_lien_suggestions() as $psc_lien): ?>
        <option value="<?php echo esc_attr($psc_lien); ?>">
        <?php endforeach; ?>
      </datalist>

      <label class="psc-wizard-check-line" style="margin-top:16px;">
        <input type="checkbox" id="psc-pickup-piece-identite" name="piece_identite" value="1"> <?php esc_html_e("Présentera une pièce d'identité", 'periscolaire-registration'); ?>
      </label>

      <div class="psc-portal-modal-actions">
        <button type="button" class="psc-portal-btn-outline-ink" data-pickup-modal-close><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></button>
        <button type="submit" class="psc-portal-btn-gold" data-testid="pickup-submit"><?php esc_html_e('Enregistrer', 'periscolaire-registration'); ?></button>
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
