<?php
if (!defined('ABSPATH')) exit;
/**
 * Écran de consultation du journal d'audit.
 *
 * Pagination : COUNT(*) séparé (Psc_Audit::count()) plutôt que
 * SQL_CALC_FOUND_ROWS — mesuré sur les index posés (horodatage,
 * famille_id+horodatage, etc.) : un COUNT filtré reste largement sous la
 * milliseconde même à plusieurs centaines de milliers de lignes, et
 * SQL_CALC_FOUND_ROWS est déconseillé par MySQL lui-même depuis 8.0.17
 * (toujours plus coûteux qu'un COUNT(*) équivalent avec index).
 */
global $wpdb;

$categorie_labels = psc_audit_categorie_labels();
$niveau_labels    = psc_audit_niveau_labels();
$resultat_labels  = psc_audit_resultat_labels();
$acteur_labels    = psc_audit_acteur_labels();

/** Libellé d'une famille par id, pour la colonne Acteur et "pour le compte de". */
$psc_audit_family_label = function ($id) use ($wpdb) {
    if (!$id) return '';
    $row = $wpdb->get_row($wpdb->prepare('SELECT nom, prenom, email FROM ' . psc_table('parents') . ' WHERE id = %d', $id));
    if (!$row) return sprintf(__('famille #%d (supprimée)', 'periscolaire-registration'), $id);
    $name = trim($row->nom . ' ' . $row->prenom);
    return $name !== '' ? $name : $row->email;
};

$total_pages = (int) ceil($total / max(1, Psc_Admin_Audit::PER_PAGE));

/** Les exports respectent les filtres actuellement affichés à l'écran. */
$export_query_args = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
unset($export_query_args['page']);
$export_csv_url = wp_nonce_url(add_query_arg(array_merge($export_query_args, array('action' => 'psc_audit_export_csv')), admin_url('admin-post.php')), 'psc_audit_export_csv');
$export_ods_url = wp_nonce_url(add_query_arg(array_merge($export_query_args, array('action' => 'psc_audit_export_ods')), admin_url('admin-post.php')), 'psc_audit_export_ods');
?>
<div class="wrap psc-messages-admin">
  <h1><?php esc_html_e('Journal d\'audit', 'periscolaire-registration'); ?></h1>

  <?php if ($psc_msg === 'chain_ok'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Chaîne d’intégrité vérifiée sur les 1 000 dernières entrées : aucune rupture détectée.', 'periscolaire-registration'); ?></p></div>
  <?php elseif ($psc_msg === 'chain_broken'): ?>
    <div class="notice notice-error"><p><?php echo esc_html(sprintf(__('Rupture de chaîne détectée : la ligne #%d ne correspond plus à ce qui précède. Une altération directe en base a eu lieu.', 'periscolaire-registration'), $chain_break_id)); ?></p></div>
  <?php elseif ($psc_msg === 'health_reset'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Compteur de défaillances du journal remis à zéro.', 'periscolaire-registration'); ?></p></div>
  <?php elseif ($psc_msg === 'retention_saved'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Durées de rétention enregistrées.', 'periscolaire-registration'); ?></p></div>
  <?php endif; ?>

  <div class="psc-box">
    <h2><?php esc_html_e('Intégrité', 'periscolaire-registration'); ?></h2>
    <p><?php esc_html_e('Chaque ligne porte l’empreinte de la précédente : une modification ou une suppression directe en base casse cette chaîne, ce que la vérification ci-dessous détecte.', 'periscolaire-registration'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('psc_audit_verify'); ?>
      <input type="hidden" name="action" value="psc_audit_verify">
      <button type="submit" class="button" data-testid="audit-verify-submit"><?php esc_html_e('Vérifier la chaîne sur les 1 000 dernières entrées', 'periscolaire-registration'); ?></button>
    </form>
  </div>

  <div class="psc-box">
    <h2><?php esc_html_e('Export', 'periscolaire-registration'); ?></h2>
    <p><strong><?php esc_html_e('Le fichier exporté contient des données personnelles (noms, e-mails, résumés d’actions).', 'periscolaire-registration'); ?></strong>
      <?php esc_html_e('Ne le partagez qu’avec des personnes habilitées et supprimez-le une fois son usage terminé. L’export porte sur les filtres actuellement appliqués ci-dessous.', 'periscolaire-registration'); ?></p>
    <p>
      <a class="button" data-testid="audit-export-csv" href="<?php echo esc_url($export_csv_url); ?>"><?php esc_html_e('Exporter en CSV', 'periscolaire-registration'); ?></a>
      <a class="button" data-testid="audit-export-ods" href="<?php echo esc_url($export_ods_url); ?>"><?php esc_html_e('Exporter en ODS', 'periscolaire-registration'); ?></a>
      <?php if ($total > Psc_Admin_Audit::ODS_MAX_ROWS): ?>
        <br><span class="description"><?php echo esc_html(sprintf(__('L’export ODS est limité à %1$d lignes ; les filtres actuels en couvrent %2$s. Réduisez la période ou utilisez le CSV.', 'periscolaire-registration'), Psc_Admin_Audit::ODS_MAX_ROWS, number_format_i18n($total))); ?></span>
      <?php endif; ?>
    </p>
    <p class="description"><?php esc_html_e('Limité à 5 exports par heure et par utilisateur.', 'periscolaire-registration'); ?></p>
  </div>

  <div class="psc-box">
    <h2><?php esc_html_e('Conservation', 'periscolaire-registration'); ?></h2>
    <p><?php esc_html_e('Passé ce délai, les lignes du journal sont définitivement supprimées (purge quotidienne automatique). Ces durées sont indicatives et réglables ici selon vos besoins — elles ne constituent pas, en elles-mêmes, une obligation légale.', 'periscolaire-registration'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <?php wp_nonce_field('psc_audit_save_retention'); ?>
      <input type="hidden" name="action" value="psc_audit_save_retention">
      <table class="form-table">
        <tr>
          <th><label for="psc-retention-critique"><?php echo esc_html($niveau_labels['critique']); ?></label></th>
          <td>
            <input type="number" id="psc-retention-critique" name="retention_critique" min="30" max="3650" value="<?php echo (int) psc_audit_retention_days('critique'); ?>" class="small-text"> <?php esc_html_e('jours', 'periscolaire-registration'); ?>
            <p class="description"><?php esc_html_e('Suppressions, désactivations, connexions, bancaire, sécurité du journal lui-même.', 'periscolaire-registration'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label for="psc-retention-normal"><?php echo esc_html($niveau_labels['normal']); ?></label></th>
          <td>
            <input type="number" id="psc-retention-normal" name="retention_normal" min="30" max="3650" value="<?php echo (int) psc_audit_retention_days('normal'); ?>" class="small-text"> <?php esc_html_e('jours', 'periscolaire-registration'); ?>
            <p class="description"><?php esc_html_e('Créations, modifications courantes, documents, factures.', 'periscolaire-registration'); ?></p>
          </td>
        </tr>
        <tr>
          <th><label for="psc-retention-volumineux"><?php echo esc_html($niveau_labels['volumineux']); ?></label></th>
          <td>
            <input type="number" id="psc-retention-volumineux" name="retention_volumineux" min="30" max="3650" value="<?php echo (int) psc_audit_retention_days('volumineux'); ?>" class="small-text"> <?php esc_html_e('jours', 'periscolaire-registration'); ?>
            <p class="description"><?php esc_html_e('Ajustements de planning au jour le jour (rythme, exceptions) — le volume le plus élevé du journal.', 'periscolaire-registration'); ?></p>
          </td>
        </tr>
      </table>
      <p><button type="submit" class="button button-primary" data-testid="audit-retention-save"><?php esc_html_e('Enregistrer les durées', 'periscolaire-registration'); ?></button></p>
    </form>
  </div>

  <form method="get" class="psc-box" aria-label="<?php esc_attr_e('Filtrer le journal', 'periscolaire-registration'); ?>">
    <input type="hidden" name="page" value="psc_audit">
    <table class="form-table">
      <tr>
        <th><label for="psc-audit-du"><?php esc_html_e('Du', 'periscolaire-registration'); ?></label></th>
        <td><input type="date" id="psc-audit-du" name="du" value="<?php echo esc_attr($filters['du']); ?>"></td>
        <th><label for="psc-audit-au"><?php esc_html_e('Au', 'periscolaire-registration'); ?></label></th>
        <td><input type="date" id="psc-audit-au" name="au" value="<?php echo esc_attr($filters['au']); ?>"></td>
      </tr>
      <tr>
        <th><label for="psc-audit-acteur-type"><?php esc_html_e('Type d’acteur', 'periscolaire-registration'); ?></label></th>
        <td>
          <select id="psc-audit-acteur-type" name="acteur_type">
            <option value=""><?php esc_html_e('Tous', 'periscolaire-registration'); ?></option>
            <?php foreach ($acteur_labels as $key => $label): ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['acteur_type'], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <th><label for="psc-audit-famille"><?php esc_html_e('Famille', 'periscolaire-registration'); ?></label></th>
        <td>
          <select id="psc-audit-famille" name="famille_id">
            <option value=""><?php esc_html_e('Toutes', 'periscolaire-registration'); ?></option>
            <?php foreach ($families as $f): ?>
              <option value="<?php echo (int) $f->id; ?>" <?php selected((int) $filters['famille_id'], (int) $f->id); ?>><?php echo esc_html(trim($f->nom . ' ' . $f->prenom) . ' — ' . $f->email); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="psc-audit-categorie"><?php esc_html_e('Catégorie', 'periscolaire-registration'); ?></label></th>
        <td>
          <select id="psc-audit-categorie" name="categorie">
            <option value=""><?php esc_html_e('Toutes', 'periscolaire-registration'); ?></option>
            <?php foreach ($categorie_labels as $key => $label): ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['categorie'], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <th><label for="psc-audit-niveau"><?php esc_html_e('Niveau', 'periscolaire-registration'); ?></label></th>
        <td>
          <select id="psc-audit-niveau" name="niveau">
            <option value=""><?php esc_html_e('Tous', 'periscolaire-registration'); ?></option>
            <?php foreach ($niveau_labels as $key => $label): ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['niveau'], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="psc-audit-resultat"><?php esc_html_e('Résultat', 'periscolaire-registration'); ?></label></th>
        <td>
          <select id="psc-audit-resultat" name="resultat">
            <option value=""><?php esc_html_e('Tous', 'periscolaire-registration'); ?></option>
            <?php foreach ($resultat_labels as $key => $label): ?>
              <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['resultat'], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <th><label for="psc-audit-action"><?php esc_html_e('Code d’action', 'periscolaire-registration'); ?></label></th>
        <td><input type="text" id="psc-audit-action" name="action_code" value="<?php echo esc_attr($filters['action']); ?>" placeholder="famille.suppression"></td>
      </tr>
      <tr>
        <th><label for="psc-audit-recherche"><?php esc_html_e('Recherche libre', 'periscolaire-registration'); ?></label></th>
        <td colspan="3"><input type="search" id="psc-audit-recherche" name="recherche" value="<?php echo esc_attr($filters['recherche']); ?>" class="regular-text" placeholder="<?php esc_attr_e('Résumé ou nom d’acteur…', 'periscolaire-registration'); ?>"></td>
      </tr>
    </table>
    <p>
      <button type="submit" class="button button-primary" data-testid="audit-filter-submit"><?php esc_html_e('Filtrer', 'periscolaire-registration'); ?></button>
      <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=psc_audit')); ?>"><?php esc_html_e('Réinitialiser', 'periscolaire-registration'); ?></a>
    </p>
  </form>

  <table class="wp-list-table widefat striped">
    <caption class="screen-reader-text"><?php esc_html_e('Journal d’audit du plugin périscolaire, filtré selon les critères ci-dessus', 'periscolaire-registration'); ?></caption>
    <thead>
      <tr>
        <th scope="col"><?php esc_html_e('Horodatage', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Acteur', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Action', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Objet', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Résumé', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Résultat', 'periscolaire-registration'); ?></th>
        <th scope="col"><?php esc_html_e('Détails', 'periscolaire-registration'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="7"><?php esc_html_e('Aucune ligne pour ces filtres.', 'periscolaire-registration'); ?></td></tr>
      <?php endif; ?>
      <?php foreach ($items as $item):
          $local_time = get_date_from_gmt($item->horodatage, 'd/m/Y H:i:s');
          $utc_title  = $item->horodatage . ' UTC';
          $objet_url  = Psc_Admin_Audit::objet_url($item->objet_type, $item->objet_id);
          $details    = $item->details ? json_decode($item->details, true) : null;
      ?>
      <tr data-testid="audit-row-<?php echo (int) $item->id; ?>">
        <td><time datetime="<?php echo esc_attr(mysql2date('c', $item->horodatage)); ?>" title="<?php echo esc_attr($utc_title); ?>"><?php echo esc_html($local_time); ?></time></td>
        <td>
          <?php echo esc_html(isset($acteur_labels[$item->acteur_type]) ? $acteur_labels[$item->acteur_type] : $item->acteur_type); ?>
          — <?php echo esc_html($item->acteur_libelle); ?>
          <?php if ($item->pour_le_compte_de): ?>
            <br><small><?php echo esc_html(sprintf(__('via consultation de l’espace de %s', 'periscolaire-registration'), $psc_audit_family_label((int) $item->pour_le_compte_de))); ?></small>
          <?php endif; ?>
        </td>
        <td><?php echo esc_html(psc_audit_action_label($item->action)); ?><br><small><?php echo esc_html($item->action); ?></small></td>
        <td>
          <?php if ($objet_url): ?>
            <a href="<?php echo esc_url($objet_url); ?>"><?php echo esc_html(($item->objet_type ?: '?') . ' #' . $item->objet_id); ?></a>
          <?php elseif ($item->objet_id): ?>
            <?php echo esc_html(($item->objet_type ?: '?') . ' #' . $item->objet_id . ' ' . __('(fiche disparue)', 'periscolaire-registration')); ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?php echo esc_html($item->resume); ?></td>
        <td><?php echo esc_html(isset($resultat_labels[$item->resultat]) ? $resultat_labels[$item->resultat] : $item->resultat); ?></td>
        <td>
          <?php if ($details): ?>
            <button type="button" class="button-link" data-audit-toggle="<?php echo (int) $item->id; ?>" aria-expanded="false" aria-controls="psc-audit-detail-<?php echo (int) $item->id; ?>"><?php esc_html_e('Détails', 'periscolaire-registration'); ?></button>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($details): ?>
      <tr id="psc-audit-detail-<?php echo (int) $item->id; ?>-row" hidden>
        <td colspan="7">
          <div id="psc-audit-detail-<?php echo (int) $item->id; ?>">
            <?php if (!empty($details['avant']) || !empty($details['apres'])): ?>
              <table class="widefat">
                <caption class="screen-reader-text"><?php esc_html_e('Champs modifiés : avant et après', 'periscolaire-registration'); ?></caption>
                <thead><tr><th scope="col"><?php esc_html_e('Champ', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Avant', 'periscolaire-registration'); ?></th><th scope="col"><?php esc_html_e('Après', 'periscolaire-registration'); ?></th></tr></thead>
                <tbody>
                <?php
                $fields = array_unique(array_merge(array_keys($details['avant'] ?? array()), array_keys($details['apres'] ?? array())));
                foreach ($fields as $field):
                    $before = $details['avant'][$field] ?? null;
                    $after  = $details['apres'][$field] ?? null;
                ?>
                  <tr>
                    <td><?php echo esc_html($field); ?></td>
                    <td><?php echo esc_html(is_scalar($before) ? (string) $before : wp_json_encode($before)); ?></td>
                    <td><?php echo esc_html(is_scalar($after) ? (string) $after : wp_json_encode($after)); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
            <?php if (!empty($details['meta'])): ?>
              <p><strong><?php esc_html_e('Informations complémentaires', 'periscolaire-registration'); ?></strong></p>
              <ul>
                <?php foreach ($details['meta'] as $key => $value): ?>
                  <li><?php echo esc_html($key); ?> : <?php echo esc_html(is_scalar($value) ? (string) $value : wp_json_encode($value)); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if (!empty($details['tronque'])): ?>
              <p><em><?php esc_html_e('Détail trop volumineux : tronqué.', 'periscolaire-registration'); ?></em></p>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($total_pages > 1): ?>
    <div class="tablenav"><div class="tablenav-pages">
      <span class="displaying-num"><?php echo esc_html(sprintf(_n('%s ligne', '%s lignes', $total, 'periscolaire-registration'), number_format_i18n($total))); ?></span>
      <?php for ($p = 1; $p <= $total_pages; $p++):
          $page_args = array_filter($filters, function ($v) { return $v !== '' && $v !== null; });
          $page_args['page'] = 'psc_audit';
          $page_args['paged'] = $p;
      ?>
        <a class="button<?php echo $p === $filters['page'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg($page_args, admin_url('admin.php'))); ?>"><?php echo (int) $p; ?></a>
      <?php endfor; ?>
    </div></div>
  <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('[data-audit-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-audit-toggle');
            var row = document.getElementById('psc-audit-detail-' + id + '-row');
            if (!row) return;
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            row.hidden = expanded;
            btn.textContent = expanded
                ? <?php echo wp_json_encode(__('Détails', 'periscolaire-registration')); ?>
                : <?php echo wp_json_encode(__('Masquer', 'periscolaire-registration')); ?>;
        });
    });
})();
</script>
