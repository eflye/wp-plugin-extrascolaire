<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap psc-admin">
<h1>Modèles d'e-mails</h1>

<?php
psc_admin_notice_map(array(
    'saved'     => array('success', 'Modèles enregistrés.'),
    'reset_one' => array('success', 'Modèle réinitialisé aux valeurs par défaut.'),
    'reset_all' => array('success', 'Tous les modèles ont été réinitialisés.'),
), $psc_msg);
?>

<p style="color:#666;margin-bottom:20px;">
    Personnalisez le sujet et le corps de chaque e-mail envoyé aux familles et à la mairie.
    Les variables <code>{{entre doubles accolades}}</code> sont remplacées automatiquement à l'envoi.
    Le contenu généré automatiquement (tableaux, boutons, pièces jointes) est indiqué dans la note de chaque modèle.
</p>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="psc_save_email_templates">
    <?php wp_nonce_field('psc_save_email_templates'); ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <button type="submit" class="button button-primary button-large">Enregistrer tous les modèles</button>
        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=psc_reset_email_templates'), 'psc_reset_email_templates')); ?>"
           class="button button-link-delete"
           onclick="return confirm('Réinitialiser TOUS les modèles aux valeurs par défaut ?');">
            ↺ Tout réinitialiser
        </a>
    </div>

    <?php foreach ($templates as $key => $tpl): ?>
    <div class="psc-email-tpl-card" id="tpl-<?php echo esc_attr($key); ?>">

        <div class="psc-email-tpl-header">
            <h3>
                <?php echo esc_html($tpl['label']); ?>
                <?php if (!empty($tpl['customized'])): ?>
                <span class="psc-tpl-badge">Personnalisé</span>
                <?php endif; ?>
            </h3>
            <?php if (!empty($tpl['customized'])): ?>
            <a href="<?php echo esc_url(wp_nonce_url(
                admin_url('admin-post.php?action=psc_reset_email_template&key=' . $key),
                'psc_reset_email_template_' . $key
            )); ?>"
               class="button button-small"
               onclick="return confirm('Réinitialiser ce modèle aux valeurs par défaut ?');">
                ↺ Réinitialiser
            </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($tpl['note'])): ?>
        <p class="psc-tpl-note">
            <span class="dashicons dashicons-info-outline" style="font-size:14px;vertical-align:middle;margin-right:4px;"></span>
            <?php echo esc_html($tpl['note']); ?>
        </p>
        <?php endif; ?>

        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:120px;padding:8px 0;"><label for="tpl_<?php echo esc_attr($key); ?>_subject">Sujet</label></th>
                <td style="padding:8px 0;">
                    <input type="text"
                           id="tpl_<?php echo esc_attr($key); ?>_subject"
                           name="templates[<?php echo esc_attr($key); ?>][subject]"
                           value="<?php echo esc_attr($tpl['subject']); ?>"
                           class="large-text psc-tpl-subject">
                </td>
            </tr>
            <tr>
                <th style="padding:8px 0;vertical-align:top;"><label for="tpl_<?php echo esc_attr($key); ?>_body">Corps</label></th>
                <td style="padding:8px 0;">
                    <textarea id="tpl_<?php echo esc_attr($key); ?>_body"
                              name="templates[<?php echo esc_attr($key); ?>][body]"
                              rows="5"
                              class="large-text psc-tpl-body"
                    ><?php echo esc_textarea($tpl['body']); ?></textarea>
                    <p class="description" style="margin-top:6px;">
                        Variables disponibles :
                        <?php foreach ($tpl['vars'] as $var): ?>
                        <code class="psc-var-chip"
                              data-target="tpl_<?php echo esc_attr($key); ?>_body"
                              title="Cliquer pour insérer"
                              style="cursor:pointer;"><?php echo esc_html($var); ?></code>
                        <?php endforeach; ?>
                    </p>
                </td>
            </tr>
        </table>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:20px;">
        <button type="submit" class="button button-primary button-large">Enregistrer tous les modèles</button>
    </div>
</form>
</div>

<style>
.psc-email-tpl-card {
    background: #fff;
    border: 1px solid #e1e5eb;
    border-radius: 4px;
    padding: 16px 20px;
    margin-bottom: 16px;
}
.psc-email-tpl-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.psc-email-tpl-header h3 {
    margin: 0;
    font-size: 14px;
    color: #23478B;
    display: flex;
    align-items: center;
    gap: 8px;
}
.psc-tpl-badge {
    display: inline-block;
    background: #23478B;
    color: #fff;
    font-size: 10px;
    font-weight: normal;
    padding: 2px 7px;
    border-radius: 10px;
    vertical-align: middle;
}
.psc-tpl-note {
    color: #777;
    font-size: 12px;
    font-style: italic;
    margin: 0 0 10px;
    padding: 6px 10px;
    background: #f8f9fb;
    border-radius: 3px;
}
.psc-tpl-subject {
    font-family: monospace;
    font-size: 13px !important;
}
.psc-tpl-body {
    font-family: monospace;
    font-size: 13px !important;
    resize: vertical;
}
.psc-var-chip {
    display: inline-block;
    background: #e8edf5;
    color: #23478B;
    padding: 2px 7px;
    border-radius: 3px;
    margin: 2px;
    font-size: 12px;
    transition: background .15s;
}
.psc-var-chip:hover { background: #23478B; color: #fff; }
</style>

<script>
document.querySelectorAll('.psc-var-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
        var targetId = this.dataset.target;
        var textarea = document.getElementById(targetId);
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end   = textarea.selectionEnd;
        var val   = textarea.value;
        textarea.value = val.substring(0, start) + this.textContent + val.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + this.textContent.length;
        textarea.focus();
    });
});
</script>
