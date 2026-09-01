<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1><?php esc_html_e('Personnes autorisées (garderie du soir) —', 'periscolaire-registration'); ?> <?php echo esc_html($child->prenom . ' ' . $child->nom); ?></h1>
<p>
  <a href="<?php echo esc_url(admin_url('admin.php?page=psc_children')); ?>">&larr; <?php esc_html_e('Retour à Enfants', 'periscolaire-registration'); ?></a>
  — <?php esc_html_e('Famille :', 'periscolaire-registration'); ?> <?php echo esc_html($child->parent_nom ?: '—'); ?>
  <?php if ($child->parent_email): ?>(<?php echo esc_html($child->parent_email); ?>)<?php endif; ?>
</p>

<div class="psc-box">
<h2><?php esc_html_e('Liste courante', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Ces personnes peuvent venir chercher', 'periscolaire-registration'); ?> <?php echo esc_html($child->prenom); ?> <?php esc_html_e('au départ de la', 'periscolaire-registration'); ?> <strong><?php esc_html_e('garderie du soir', 'periscolaire-registration'); ?></strong> <?php esc_html_e('(cette liste ne concerne ni la cantine ni la garderie du matin). Elle est gérée par la famille depuis son espace connecté — la mairie la consulte ici sans pouvoir la modifier.', 'periscolaire-registration'); ?></p>
<?php if (empty($pickup_parent_rows) && empty($pickup_persons)): ?>
<p><em><?php esc_html_e('Aucune personne autorisée déclarée pour le moment.', 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Prénom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Nom', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Téléphone', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Lien', 'periscolaire-registration'); ?></th><th><?php esc_html_e("Pièce d'identité", 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php foreach ($pickup_parent_rows as $pr): ?>
<tr>
<td><?php echo esc_html($pr['prenom']); ?></td>
<td><?php echo esc_html($pr['nom']); ?></td>
<td><?php echo esc_html($pr['telephone'] !== '' ? $pr['telephone'] : '—'); ?></td>
<td><?php echo esc_html($pr['role']); ?></td>
<td>—</td>
</tr>
<?php endforeach; ?>
<?php foreach ($pickup_persons as $p): ?>
<tr>
<td><?php echo esc_html($p->prenom); ?></td>
<td><?php echo esc_html($p->nom); ?></td>
<td><?php echo esc_html($p->telephone); ?></td>
<td><?php echo esc_html($p->lien !== '' ? $p->lien : '—'); ?></td>
<td><?php echo ((int) $p->piece_identite === 1) ? esc_html__('Oui', 'periscolaire-registration') : '—'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Historique', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e("Journal complet des ajouts, modifications et retraits — jamais modifié ni supprimé, y compris pour une personne aujourd'hui retirée de la liste courante.", 'periscolaire-registration'); ?></p>
<?php if (empty($pickup_history)): ?>
<p><em><?php esc_html_e('Aucun historique pour le moment.', 'periscolaire-registration'); ?></em></p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th><?php esc_html_e('Date', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Action', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Personne', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Détail', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Auteur', 'periscolaire-registration'); ?></th><th><?php esc_html_e('Source', 'periscolaire-registration'); ?></th></tr></thead>
<tbody>
<?php
$psc_action_labels = array('ajout' => __('Ajout', 'periscolaire-registration'), 'modification' => __('Modification', 'periscolaire-registration'), 'retrait' => __('Retrait', 'periscolaire-registration'));
$psc_source_labels = array('parent' => __('Famille', 'periscolaire-registration'), 'mairie' => __('Mairie', 'periscolaire-registration'));
foreach ($pickup_history as $h):
    $snap = json_decode($h->person_snapshot, true);
    $snap = is_array($snap) ? $snap : array();
?>
<tr>
  <td><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($h->created_at))); ?></td>
  <td><?php echo esc_html($psc_action_labels[$h->action] ?? $h->action); ?></td>
  <td><?php echo esc_html(trim(($snap['prenom'] ?? '') . ' ' . ($snap['nom'] ?? ''))); ?></td>
  <td>
    <?php
      $psc_detail = array();
      if (!empty($snap['telephone'])) $psc_detail[] = $snap['telephone'];
      if (!empty($snap['lien'])) $psc_detail[] = $snap['lien'];
      if (!empty($snap['piece_identite'])) $psc_detail[] = __("pièce d'identité", 'periscolaire-registration');
      echo esc_html(implode(' · ', $psc_detail));
    ?>
  </td>
  <td><?php echo esc_html($h->acteur_label); ?></td>
  <td><?php echo esc_html($psc_source_labels[$h->source] ?? $h->source); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</div>
