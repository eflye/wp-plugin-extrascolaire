<?php
/**
 * Ligne « enfant » du formulaire d'inscription public — source UNIQUE du
 * balisage (PRA-03) : rendue par PHP pour le premier enfant, et placée
 * dans un <template> pour les enfants ajoutés en JavaScript
 * (assets/js/guest.js remplace __IDX__ et __NUM__). Les noms de champs
 * sont ainsi exactement ceux que lit Psc_Requests::handle_submit().
 *
 * Variables attendues :
 *  - $psc_child_idx : index du champ (0, ou '__IDX__' dans le modèle) ;
 *  - $psc_child_num : numéro affiché (1, ou '__NUM__' dans le modèle).
 */
if (!defined('ABSPATH')) exit;

$psc_child_idx = isset($psc_child_idx) ? (string) $psc_child_idx : '0';
$psc_child_num = isset($psc_child_num) ? (string) $psc_child_num : '1';
?>
<div class="psc-wizard-child-row" data-index="<?php echo esc_attr($psc_child_idx); ?>">
  <div>
    <label class="psc-portal-field-label screen-reader-text" for="psc-cp-<?php echo esc_attr($psc_child_idx); ?>"><?php
      /* translators: %s: numéro de l'enfant dans le formulaire (1, 2…) */
      echo esc_html(sprintf(__('Prénom de l’enfant %s', 'periscolaire-registration'), $psc_child_num)); ?></label>
    <input id="psc-cp-<?php echo esc_attr($psc_child_idx); ?>" class="psc-portal-field-underline" type="text" name="child_prenom_<?php echo esc_attr($psc_child_idx); ?>" placeholder="<?php esc_attr_e('Prénom', 'periscolaire-registration'); ?>" maxlength="190" required>
  </div>
  <div>
    <label class="psc-portal-field-label screen-reader-text" for="psc-cn-<?php echo esc_attr($psc_child_idx); ?>"><?php
      /* translators: %s: numéro de l'enfant dans le formulaire (1, 2…) */
      echo esc_html(sprintf(__('Nom de l’enfant %s', 'periscolaire-registration'), $psc_child_num)); ?></label>
    <input id="psc-cn-<?php echo esc_attr($psc_child_idx); ?>" class="psc-portal-field-underline" type="text" name="child_nom_<?php echo esc_attr($psc_child_idx); ?>" placeholder="<?php esc_attr_e('Nom', 'periscolaire-registration'); ?>" maxlength="190" required>
  </div>
  <div>
    <label class="psc-portal-field-label screen-reader-text" for="psc-cc-<?php echo esc_attr($psc_child_idx); ?>"><?php
      /* translators: %s: numéro de l'enfant dans le formulaire (1, 2…) */
      echo esc_html(sprintf(__('Classe de l’enfant %s', 'periscolaire-registration'), $psc_child_num)); ?></label>
    <select id="psc-cc-<?php echo esc_attr($psc_child_idx); ?>" class="psc-portal-field-underline" name="child_classe_<?php echo esc_attr($psc_child_idx); ?>" required>
      <?php foreach (Psc_School_Years::classe_options() as $v => $l): ?>
      <option value="<?php echo esc_attr($v); ?>"><?php echo esc_html($l); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="psc-portal-field-label" for="psc-cb-<?php echo esc_attr($psc_child_idx); ?>"><?php esc_html_e('Date de naissance', 'periscolaire-registration'); ?></label>
    <input id="psc-cb-<?php echo esc_attr($psc_child_idx); ?>" class="psc-portal-field-underline" type="date" name="child_naissance_<?php echo esc_attr($psc_child_idx); ?>" max="<?php echo esc_attr(psc_child_birthdate_max()); ?>" required>
  </div>

  <div class="psc-wizard-diet-cell">
    <div class="psc-portal-field-label"><?php esc_html_e('Régime alimentaire', 'periscolaire-registration'); ?></div>
    <div class="psc-wizard-diet-group">
      <label class="psc-wizard-diet-check"><input type="checkbox" name="child_sans_porc_<?php echo esc_attr($psc_child_idx); ?>" value="1"> <?php esc_html_e('Sans porc', 'periscolaire-registration'); ?></label>
      <label class="psc-wizard-diet-check"><input type="checkbox" name="child_vegan_<?php echo esc_attr($psc_child_idx); ?>" value="1"> <?php esc_html_e('Sans viande', 'periscolaire-registration'); ?></label>
    </div>
  </div>

  <div class="psc-wizard-allergy-cell" style="grid-column: 1 / -1;">
    <label class="psc-wizard-diet-check" style="font-weight:600;"><input type="checkbox" name="child_food_signal_<?php echo esc_attr($psc_child_idx); ?>" value="1" data-testid="child-food-signal-<?php echo esc_attr($psc_child_idx); ?>"> <?php esc_html_e('La mairie doit échanger avec nous au sujet de l’alimentation de cet enfant', 'periscolaire-registration'); ?></label>
    <p class="psc-child-allergy-help" style="font-size:11px;color:#665F58;margin:6px 0 0;"><?php esc_html_e('Ne renseignez aucun détail médical ici. La mairie vous contactera oralement et décidera ensuite, si nécessaire, du statut « cantine sans repas ».', 'periscolaire-registration'); ?></p>
  </div>

  <button type="button" class="psc-wizard-remove-btn" aria-label="<?php esc_attr_e('Supprimer cet enfant', 'periscolaire-registration'); ?>" hidden><?php esc_html_e('Retirer', 'periscolaire-registration'); ?></button>
</div>
