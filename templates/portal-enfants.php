<?php if (!defined('ABSPATH')) exit; ?>
<div class="psc-portal-eyebrow">Famille</div>
<h1 class="psc-portal-h1" data-testid="enfants-title">Mes enfants</h1>

<?php if (!empty($all_children)): ?>
<div class="psc-portal-table-scroll">
<table class="psc-portal-table" data-testid="portal-children-table">
  <thead>
    <tr>
      <th>Prénom</th><th>Nom</th><th>Classe</th><th>Régime</th><th>Actif</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($all_children as $c): ?>
    <tr data-testid="portal-child-row-<?php echo esc_attr($c->id); ?>">
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-child-update-form psc-portal-child-row-form">
        <?php wp_nonce_field('psc_parent_update_child'); ?>
        <input type="hidden" name="action" value="psc_parent_update_child">
        <input type="hidden" name="child_id" value="<?php echo esc_attr($c->id); ?>">
        <td style="font-weight:500;"><?php echo esc_html($c->prenom); ?></td>
        <td><?php echo esc_html($c->nom); ?></td>
        <td>
          <select name="classe" class="psc-portal-field-underline" aria-label="Classe de <?php echo esc_attr($c->prenom); ?>">
            <?php foreach (psc_classe_options() as $v => $l): ?>
            <option value="<?php echo esc_attr($v); ?>" <?php selected($c->classe, $v); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <div class="psc-portal-diet-checks">
            <label><input type="checkbox" name="sans_porc" value="1" <?php checked((int) $c->sans_porc, 1); ?>> Sans porc</label>
            <label><input type="checkbox" name="vegan" value="1" <?php checked((int) $c->vegan, 1); ?>> Sans viande</label>
          </div>
        </td>
        <td>
          <label class="psc-portal-toggle" aria-label="Activer ou désactiver <?php echo esc_attr($c->prenom); ?>">
            <input type="checkbox" name="active" value="1" <?php checked((int) $c->active, 1); ?>>
            <span class="psc-portal-toggle-track"></span>
          </label>
        </td>
        <td class="psc-portal-row-save"><button type="submit" class="psc-portal-btn-sm">Enregistrer</button></td>
      </form>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<div class="psc-portal-panel">
  <div class="psc-portal-panel-title">Ajouter un enfant</div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="psc-add-child-form">
    <?php wp_nonce_field('psc_parent_add_child'); ?>
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
      <div class="psc-portal-diet-checks">
        <label><input type="checkbox" name="new_sans_porc" value="1"> Sans porc</label>
        <label><input type="checkbox" name="new_vegan" value="1"> Sans viande</label>
      </div>
    </div>
    <button type="submit" class="psc-portal-btn-gold">Ajouter</button>
  </form>
</div>
