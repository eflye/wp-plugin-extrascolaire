<?php if (!defined('ABSPATH')) exit; ?>
<?php
/* Rendu comme onglet « Choix fournisseur » de la page « Menus »
   (cf. Psc_Admin_Menu_Propositions::render_tab()) : ni wrap ni <h1> ici.
   Liste des fichiers reçus et dépôt d'un nouveau fichier. */
psc_admin_notice_map($psc_notices, $psc_msg, $psc_msg);
$psc_statuts = Psc_Menu_Propositions::statuts();
?>

<div class="psc-box">
<h2><?php esc_html_e('Déposer le fichier du fournisseur', 'periscolaire-registration'); ?></h2>
<p><?php esc_html_e('Le fournisseur de repas envoie ses propositions de menus dans un fichier Excel, une feuille par semaine. Déposez-le ici tel que vous l’avez reçu : vous choisirez ensuite un plat par catégorie et par jour, puis la réponse partira au fournisseur avec son fichier rempli.', 'periscolaire-registration'); ?></p>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-testid="choix-import-form">
    <?php wp_nonce_field('psc_import_menu_proposition'); ?>
    <input type="hidden" name="action" value="psc_import_menu_proposition">
    <p>
        <label for="psc-choix-fichier"><strong><?php esc_html_e('Fichier du fournisseur (.xlsx)', 'periscolaire-registration'); ?></strong></label><br>
        <input id="psc-choix-fichier" type="file" name="fichier" required
               accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
               aria-describedby="psc-choix-fichier-aide">
    </p>
    <p class="description" id="psc-choix-fichier-aide"><?php
    /* translators: %s: taille maximale acceptée, par exemple « 10 Mo ». */
    echo esc_html(sprintf(__('Format Excel .xlsx, %s au plus. Rien n’est envoyé au fournisseur à cette étape.', 'periscolaire-registration'), size_format(Psc_Admin_Menu_Propositions::max_upload())));
    ?></p>
    <?php submit_button(__('Déposer le fichier', 'periscolaire-registration'), 'primary', 'submit', false, array('data-testid' => 'choix-import-submit')); ?>
</form>
</div>

<div class="psc-box">
<h2><?php esc_html_e('Fichiers reçus', 'periscolaire-registration'); ?></h2>
<table class="widefat striped" data-testid="choix-liste">
<caption class="screen-reader-text"><?php esc_html_e('Fichiers de menus reçus du fournisseur', 'periscolaire-registration'); ?></caption>
<thead>
<tr>
    <th scope="col"><?php esc_html_e('Fichier', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('Période', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('État', 'periscolaire-registration'); ?></th>
    <th scope="col"><?php esc_html_e('Actions', 'periscolaire-registration'); ?></th>
</tr>
</thead>
<tbody>
<?php if (empty($recent)): ?>
<tr><td colspan="4"><?php esc_html_e('Aucun fichier déposé pour le moment.', 'periscolaire-registration'); ?></td></tr>
<?php else: foreach ($recent as $p): ?>
<?php
$psc_bilan = $bilans[(int) $p->id] ?? null;
$psc_url   = add_query_arg(array('page' => 'psc_menus', 'tab' => 'choix', 'proposition' => (int) $p->id), admin_url('admin.php'));
?>
<tr data-testid="choix-ligne-<?php echo esc_attr($p->id); ?>">
    <td><strong><?php echo esc_html($p->fichier_nom); ?></strong><br>
        <span class="description"><?php
        /* translators: %s: date de dépôt du fichier (jj/mm/aaaa). */
        echo esc_html(sprintf(__('Déposé le %s', 'periscolaire-registration'), date_i18n('d/m/Y', strtotime($p->created_at))));
        ?></span>
    </td>
    <td><?php echo esc_html(Psc_Menu_Propositions::periode_label($p)); ?></td>
    <td>
        <?php echo esc_html($psc_statuts[$p->statut] ?? $p->statut); ?>
        <?php if ($p->sent_at): ?>
            <br><span class="description"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($p->sent_at))); ?></span>
        <?php elseif ($psc_bilan && $psc_bilan[Psc_Envois::ECHEC] > 0): ?>
            <br><strong class="psc-choix-alerte"><?php esc_html_e('Échec de l’envoi', 'periscolaire-registration'); ?></strong>
        <?php endif; ?>
    </td>
    <td style="white-space:nowrap">
        <a class="button button-small" href="<?php echo esc_url($psc_url); ?>" data-testid="choix-ouvrir-<?php echo esc_attr($p->id); ?>">
            <?php echo $p->statut === Psc_Menu_Propositions::BROUILLON ? esc_html__('Faire les choix', 'periscolaire-registration') : esc_html__('Consulter', 'periscolaire-registration'); ?>
            <span class="screen-reader-text"> — <?php echo esc_html($p->fichier_nom); ?></span>
        </a>
        &nbsp;
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Supprimer ce fichier et les choix associés ? Les menus déjà remplis sont conservés.', 'periscolaire-registration')); ?>');">
            <input type="hidden" name="action" value="psc_delete_menu_proposition">
            <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
            <?php wp_nonce_field('psc_delete_menu_proposition'); ?>
            <button type="submit" class="button button-small button-link-delete" data-testid="choix-supprimer-<?php echo esc_attr($p->id); ?>">
                <?php esc_html_e('Supprimer', 'periscolaire-registration'); ?>
                <span class="screen-reader-text"> — <?php echo esc_html($p->fichier_nom); ?></span>
            </button>
        </form>
    </td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
</div>
