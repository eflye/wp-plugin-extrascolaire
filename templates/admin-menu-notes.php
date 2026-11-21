<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Rendu comme onglet « Notes du fournisseur » de la page « Menus »
   (cf. Psc_Admin_Menu_Notes::render_tab()) : ni wrap ni <h1> ici.
   Liste des notes reçues et dépôt d'une nouvelle note. */
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);
$psc_statuts = Psc_Menu_Notes::statuts();
?>

<div class="psc-box">
<h2><?php esc_html_e('Déposer une note de changement', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Quand un produit vient à manquer, le fournisseur de repas envoie une note Word annonçant les remplacements. Déposez-la ici telle que vous l’avez reçue, sans la renommer : la date du nom du fichier désigne le jour de repas concerné. Les remplacements reconnus vous sont proposés ; rien n’est modifié sans votre confirmation.', 'periscolaire-registration'); ?></p>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="note-import-form">
    <?php wp_nonce_field('psc_import_menu_note'); ?>
    <input type="hidden" name="action" value="psc_import_menu_note">
    <p>
        <label for="psc-note-fichier"><strong><?php esc_html_e('Note du fournisseur (.docx)', 'periscolaire-registration'); ?></strong></label><br>
        <input id="psc-note-fichier" type="file" name="fichier" required
               accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
               aria-describedby="psc-note-fichier-aide">
    </p>
    <p class="description" id="psc-note-fichier-aide"><?php
    /* translators: %s: taille maximale acceptée, par exemple « 10 Mo ». */
    echo esc_html(sprintf(__('Format Word .docx, %s au plus. Le fichier n’est pas conservé, seul son texte l’est.', 'periscolaire-registration'), size_format(Psc_Admin_Menu_Propositions::max_upload())));
    ?></p>
    <?php submit_button(__('Déposer la note', 'periscolaire-registration'), 'primary', 'submit', false, array('data-testid' => 'note-import-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Notes reçues', 'periscolaire-registration'); ?></h2>
<table class="widefat striped" data-testid="note-liste">
<caption class="screen-reader-text"><?php esc_html_e('Notes de changement de menus reçues du fournisseur', 'periscolaire-registration'); ?></caption>
<thead>
<tr>
    <th scope="col"><?php esc_html_e('Note', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('Jour concerné', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('État', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('Actions', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php if (empty($recent)): ?>
<tr><td colspan="4"><?php esc_html_e('Aucune note déposée pour le moment.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($recent as $n): ?>
<?php $psc_url = add_query_arg(array('page' => 'psc_menus', 'tab' => 'notes', 'note' => (int) $n->id), admin_url('admin.php')); ?>
<tr data-testid="note-ligne-<?php echo esc_attr($n->id); ?>">
    <td><strong><?php echo esc_html($n->fichier_nom); ?></strong><br>
        <span class="description"><?php
        /* translators: %s: date de dépôt de la note (jj/mm/aaaa). */
        echo esc_html(sprintf(__('Déposée le %s', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($n->created_at))));
        ?></span>
    </td>
    <td><?php echo $n->jour_date ? esc_html(date_i18n('l j F Y', strtotime($n->jour_date))) : esc_html__('À préciser', 'periscolaire-registration'); ?></td>
    <td>
        <?php echo esc_html($psc_statuts[$n->statut] ?? $n->statut); ?>
        <?php if ($n->applied_at): ?>
            <br><span class="description"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($n->applied_at))); ?></span>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <a class="button button-small" href="<?php echo esc_url($psc_url); ?>" data-testid="note-ouvrir-<?php echo esc_attr($n->id); ?>">
            <?php echo $n->statut === Psc_Menu_Notes::A_CONFIRMER ? esc_html__('Vérifier et appliquer', 'periscolaire-registration') : esc_html__('Consulter', 'periscolaire-registration'); ?>
            <span class="screen-reader-text"> — <?php echo esc_html($n->fichier_nom); ?></span>
        </a>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Supprimer cette note ? Le menu ne sera pas modifié.', 'periscolaire-registration')); ?>');">
            <input type="hidden" name="action" value="psc_delete_menu_note">
            <input type="hidden" name="id" value="<?php echo esc_attr($n->id); ?>">
            <?php wp_nonce_field('psc_delete_menu_note'); ?>
            <button type="submit" class="button button-small button-link-delete" data-testid="note-supprimer-<?php echo esc_attr($n->id); ?>">
                <?php esc_html_e('Supprimer', 'periscolaire-registration'); ?>
                <span class="screen-reader-text"> — <?php echo esc_html($n->fichier_nom); ?></span>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
