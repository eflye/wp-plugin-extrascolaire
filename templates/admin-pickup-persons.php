<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Personnes autorisées — <?php echo esc_html($child->prenom . ' ' . $child->nom); ?></h1>
<p>
  <a href="<?php echo esc_url(admin_url('admin.php?page=psc_children')); ?>">&larr; Retour à Enfants</a>
  — Famille : <?php echo esc_html($child->parent_nom ?: '—'); ?>
  <?php if ($child->parent_email): ?>(<?php echo esc_html($child->parent_email); ?>)<?php endif; ?>
</p>

<div class="psc-box">
<h2>Liste courante</h2>
<p>Ces personnes peuvent venir chercher <?php echo esc_html($child->prenom); ?> en fin de garderie. Cette liste est gérée par la famille depuis son espace connecté — la mairie la consulte ici sans pouvoir la modifier.</p>
<?php if (empty($pickup_persons)): ?>
<p><em>Aucune personne autorisée déclarée pour le moment.</em></p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th>Prénom</th><th>Nom</th><th>Téléphone</th><th>Lien</th><th>Pièce d'identité</th></tr></thead>
<tbody>
<?php foreach ($pickup_persons as $p): ?>
<tr>
<td><?php echo esc_html($p->prenom); ?></td>
<td><?php echo esc_html($p->nom); ?></td>
<td><?php echo esc_html($p->telephone); ?></td>
<td><?php echo esc_html($p->lien !== '' ? $p->lien : '—'); ?></td>
<td><?php echo ((int) $p->piece_identite === 1) ? 'Oui' : '—'; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<div class="psc-box">
<h2>Historique</h2>
<p>Journal complet des ajouts, modifications et retraits — jamais modifié ni supprimé, y compris pour une personne aujourd'hui retirée de la liste courante.</p>
<?php if (empty($pickup_history)): ?>
<p><em>Aucun historique pour le moment.</em></p>
<?php else: ?>
<table class="widefat striped">
<thead><tr><th>Date</th><th>Action</th><th>Personne</th><th>Détail</th><th>Auteur</th><th>Source</th></tr></thead>
<tbody>
<?php
$psc_action_labels = array('ajout' => 'Ajout', 'modification' => 'Modification', 'retrait' => 'Retrait');
$psc_source_labels = array('parent' => 'Famille', 'mairie' => 'Mairie');
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
      if (!empty($snap['piece_identite'])) $psc_detail[] = 'pièce d\'identité';
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
