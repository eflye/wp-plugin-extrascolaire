<?php
if (!defined('ABSPATH')) exit;
/** Vue « Mes échanges » de l'onglet Messages — cf. frontend-messages.php pour le point d'inclusion. */
$conv = isset($psc_conversations_data) ? $psc_conversations_data : array(
    'conversations' => array(), 'unread' => 0, 'filtre' => 'tous', 'selected' => null, 'messages' => array(),
    'reply_target' => null, 'draft' => null, 'reply_draft' => null, 'enfants' => array(), 'delai_note' => '', 'objets' => array(),
);
$psc_msg = isset($_GET['psc_msg']) ? sanitize_key(wp_unslash($_GET['psc_msg'])) : '';
$errors = array(
    'psc_conversation_body'                     => __('Le message doit contenir entre 1 et 2000 caractères.', 'periscolaire-registration'),
    'psc_conversation_sujet'                     => __('Le sujet doit contenir entre 1 et 160 caractères.', 'periscolaire-registration'),
    'psc_conversation_not_allowed'               => __('Les réponses ne sont plus autorisées pour ce message.', 'periscolaire-registration'),
    'psc_conversation_not_recipient'             => __('Ce message ne vous est pas destiné.', 'periscolaire-registration'),
    'psc_conversation_duplicate'                 => __('Ce message vient déjà d’être envoyé.', 'periscolaire-registration'),
    'psc_conversation_rate_limited'              => __('Trop de messages envoyés récemment : réessayez un peu plus tard.', 'periscolaire-registration'),
    'psc_conversation_missing'                   => __('Cette conversation est introuvable.', 'periscolaire-registration'),
    'psc_conversation_family'                    => __('Impossible d’envoyer ce message.', 'periscolaire-registration'),
    'psc_conversation_db'                        => __('Impossible d’enregistrer ce message. Réessayez.', 'periscolaire-registration'),
    'conversation_attachment_too_large'          => __('Le fichier joint dépasse 5 Mo.', 'periscolaire-registration'),
    'conversation_attachment_invalid_type'       => __('Type de fichier non accepté (PDF, JPG ou PNG uniquement).', 'periscolaire-registration'),
    'conversation_attachment_partial'            => __('Le fichier joint a été transmis de façon incomplète : réessayez.', 'periscolaire-registration'),
    'conversation_attachment_failed'             => __('Impossible d’enregistrer le fichier joint.', 'periscolaire-registration'),
);
$error_message = isset($errors[$psc_msg]) ? $errors[$psc_msg] : '';
$base_url = Psc_Mailer::form_page_url();
$list_url = add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges'), $base_url);
$planning_variants = psc_planning_variants();
$planning_tab = $planning_variants ? $planning_variants[0] : 'cantine';
?>
<div class="psc-conversations">

  <?php if ($psc_msg === 'conversation_sent'): ?>
    <p class="psc-notice psc-notice-ok" role="status" tabindex="-1" id="psc-conversation-status" data-testid="notice-conversation_sent"><?php esc_html_e('Message envoyé.', 'periscolaire-registration'); ?></p>
    <script>
    (function () {
        // La redirection porte aussi l'ancre #conversation-dernier-message :
        // le focus doit être posé APRÈS que le navigateur ait traité cette
        // ancre (sans quoi son propre traitement du fragment, qui survient
        // autour de l'évènement load, ramène le focus sur <body>).
        function focusNotice() {
            var n = document.getElementById('psc-conversation-status');
            if (n) n.focus();
        }
        if (document.readyState === 'complete') {
            setTimeout(focusNotice, 0);
        } else {
            window.addEventListener('load', function () { setTimeout(focusNotice, 0); });
        }
    })();
    </script>
  <?php endif; ?>

  <?php if ($conv['selected']):
      $c = $conv['selected'];
  ?>
    <a class="psc-conv-back" href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Mes échanges', 'periscolaire-registration'); ?></a>

    <?php if ($error_message && $psc_msg !== 'conversation_sent'): ?>
      <p class="psc-notice psc-notice-err" role="alert" id="psc-conversation-reply-error"><?php echo esc_html($error_message); ?></p>
    <?php endif; ?>

    <div class="psc-conv-thread-card">
      <div class="psc-conv-thread-head">
        <div class="psc-conv-thread-head-left">
          <div class="psc-conv-thread-badges">
            <span class="psc-conv-status psc-conv-status-<?php echo esc_attr($c->display_status_key); ?>"><?php echo esc_html($c->display_status_label); ?></span>
            <?php if ($c->display_objet_label !== ''): ?><span class="psc-conv-objet"><?php echo esc_html($c->display_objet_label); ?></span><?php endif; ?>
            <?php if ($c->display_enfant_label !== ''): ?><span>&middot; <?php echo esc_html($c->display_enfant_label); ?></span><?php endif; ?>
          </div>
          <h2 class="psc-conv-thread-subject"><?php echo esc_html($c->sujet); ?></h2>
        </div>
        <div class="psc-conv-thread-meta">
          <div><?php printf(esc_html__('Ouvert le %s', 'periscolaire-registration'), esc_html($c->display_ouvert_le)); ?></div>
          <div><?php echo esc_html($c->display_interlocuteur); ?></div>
        </div>
      </div>

      <div class="psc-conv-thread-body">
        <?php foreach ($conv['messages'] as $m): ?>
          <div class="psc-conv-bubble-wrap <?php echo $m->display_mine ? 'is-famille' : 'is-mairie'; ?>">
            <div class="psc-conv-bubble-meta"><strong><?php echo esc_html($m->display_auteur); ?></strong><span> &middot; <?php echo esc_html($m->display_horodatage); ?></span></div>
            <div class="psc-conv-bubble"><?php echo nl2br(esc_html($m->corps)); ?></div>
            <?php if (!empty($m->display_attachment_url)): ?>
              <div class="psc-conv-attachment">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#24405C" stroke-width="1.6" aria-hidden="true"><path d="M7 3h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"></path><path d="M14 3v5h5"></path></svg>
                <span><?php echo esc_html($m->display_attachment_label); ?></span>
                <a href="<?php echo esc_url($m->display_attachment_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Ouvrir', 'periscolaire-registration'); ?></a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div id="conversation-dernier-message"></div>
      </div>

      <?php if ($c->statut === 'close'): ?>
        <div class="psc-conv-closed-note">
          <?php esc_html_e('Cet échange est clos.', 'periscolaire-registration'); ?>
          <a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_new' => 1), $base_url)); ?>"><?php esc_html_e('Ouvrir une nouvelle demande', 'periscolaire-registration'); ?></a>
        </div>
      <?php else: ?>
        <div class="psc-conv-reply-zone">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="psc-conversation-reply-form" enctype="multipart/form-data">
            <?php wp_nonce_field('psc_parent_conversation_reply'); psc_parent_nonce_field('psc_parent_conversation_reply'); ?>
            <input type="hidden" name="action" value="psc_parent_conversation_reply">
            <input type="hidden" name="conversation_id" value="<?php echo (int) $c->id; ?>">
            <label for="psc-conversation-reply-body"><?php esc_html_e('Votre réponse', 'periscolaire-registration'); ?></label>
            <textarea id="psc-conversation-reply-body" name="corps" rows="3" maxlength="2000" required
              placeholder="<?php esc_attr_e('Écrivez votre réponse…', 'periscolaire-registration'); ?>"
              <?php if ($error_message) echo 'aria-describedby="psc-conversation-reply-error" aria-invalid="true"'; ?>
            ><?php echo esc_textarea($conv['reply_draft'] ?: ''); ?></textarea>
            <div class="psc-conv-reply-actions">
              <label class="psc-btn-secondary psc-file-input-label">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#24405C" stroke-width="1.8" aria-hidden="true"><path d="M21 12.5 12.5 21a4.9 4.9 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L10 18.9"></path></svg>
                <?php esc_html_e('Joindre un fichier', 'periscolaire-registration'); ?>
                <input type="file" name="piece_jointe" class="psc-file-input-visually-hidden" accept=".pdf,.jpg,.jpeg,.png">
              </label>
              <span class="psc-file-chosen" aria-live="polite"></span>
              <div class="psc-conv-reply-actions-right">
                <span class="psc-conv-char-count" id="psc-conversation-reply-count" aria-live="polite"></span>
                <button type="submit" class="psc-btn-primary"><?php esc_html_e('Envoyer la réponse', 'periscolaire-registration'); ?></button>
              </div>
            </div>
          </form>
        </div>
        <script>
        (function () {
            var ta = document.getElementById('psc-conversation-reply-body');
            var form = document.getElementById('psc-conversation-reply-form');
            var btn = form ? form.querySelector('button[type="submit"]') : null;
            var count = document.getElementById('psc-conversation-reply-count');
            function update() {
                if (!ta || !btn) return;
                var len = ta.value.length;
                if (count) count.textContent = len ? len + '<?php echo esc_js(__(' caractères', 'periscolaire-registration')); ?>' : '';
                var filled = ta.value.trim().length > 0;
                btn.disabled = !filled;
                btn.classList.toggle('is-disabled', !filled);
            }
            if (ta) { ta.addEventListener('input', update); update(); }
            var fileInput = form ? form.querySelector('input[type="file"]') : null;
            var fileChosen = form ? form.querySelector('.psc-file-chosen') : null;
            if (fileInput && fileChosen) {
                fileInput.addEventListener('change', function () {
                    fileChosen.textContent = fileInput.files[0] ? fileInput.files[0].name : '';
                });
            }
            var err = document.getElementById('psc-conversation-reply-error');
            if (err && ta) ta.focus();
        })();
        </script>
      <?php endif; ?>
    </div>

  <?php elseif ($conv['reply_target'] || isset($_GET['psc_new'])):
      $reply_target = $conv['reply_target'];
      $draft = $conv['draft'];
      $default_objet = $reply_target ? Psc_Conversations_Frontend::objet_from_categorie($reply_target->categorie) : 'cantine';
      $current_objet = ($draft && !empty($draft['objet'])) ? $draft['objet'] : $default_objet;
      $current_enfant = ($draft && isset($draft['enfant_id'])) ? (int) $draft['enfant_id'] : 0;
      $default_sujet = $reply_target ? sprintf(__('Au sujet de : %s', 'periscolaire-registration'), $reply_target->titre) : '';
  ?>
    <a class="psc-conv-back" href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Mes échanges', 'periscolaire-registration'); ?></a>

    <div class="psc-conv-compose-grid">
      <div class="psc-conv-compose-main">
        <h2><?php esc_html_e('Écrire à la mairie', 'periscolaire-registration'); ?></h2>
        <p class="psc-conv-compose-subtitle"><?php echo esc_html($conv['delai_note']); ?></p>

        <?php if ($error_message): ?>
          <p class="psc-notice psc-notice-err" role="alert" id="psc-conversation-create-error"><?php echo esc_html($error_message); ?></p>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="psc-conversation-create-form" enctype="multipart/form-data">
          <?php wp_nonce_field('psc_parent_conversation_create'); psc_parent_nonce_field('psc_parent_conversation_create'); ?>
          <input type="hidden" name="action" value="psc_parent_conversation_create">
          <?php if ($reply_target): ?><input type="hidden" name="message_id" value="<?php echo (int) $reply_target->id; ?>"><?php endif; ?>

          <span class="psc-conv-field-label"><?php esc_html_e('Objet de la demande', 'periscolaire-registration'); ?></span>
          <div class="psc-conv-chip-group" role="radiogroup" aria-label="<?php esc_attr_e('Objet de la demande', 'periscolaire-registration'); ?>">
            <?php foreach ($conv['objets'] as $o): ?>
              <label class="psc-chip">
                <input type="radio" name="objet" value="<?php echo esc_attr($o['value']); ?>" <?php checked($current_objet, $o['value']); ?> required>
                <span><?php echo esc_html($o['label']); ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <?php if ($conv['enfants']): ?>
          <span class="psc-conv-field-label"><?php esc_html_e('Enfant concerné', 'periscolaire-registration'); ?></span>
          <div class="psc-conv-chip-group" role="radiogroup" aria-label="<?php esc_attr_e('Enfant concerné', 'periscolaire-registration'); ?>">
            <?php foreach ($conv['enfants'] as $e): ?>
              <label class="psc-chip">
                <input type="radio" name="enfant_id" value="<?php echo esc_attr($e['value']); ?>" <?php checked($current_enfant, (int) $e['value']); ?>>
                <span><?php echo esc_html($e['label']); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($reply_target): ?>
            <span class="psc-conv-field-label"><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></span>
            <p><strong><?php echo esc_html($default_sujet); ?></strong></p>
          <?php else: ?>
            <label class="psc-conv-field-label" for="psc-conversation-sujet"><?php esc_html_e('Sujet', 'periscolaire-registration'); ?></label>
            <input type="text" id="psc-conversation-sujet" name="sujet" maxlength="160"
              placeholder="<?php esc_attr_e('En quelques mots', 'periscolaire-registration'); ?>"
              value="<?php echo esc_attr($draft['sujet'] ?? ''); ?>" required
              <?php if ($error_message === $errors['psc_conversation_sujet']) echo 'aria-describedby="psc-conversation-create-error" aria-invalid="true"'; ?>>
            <div class="psc-conv-field-count" id="psc-conversation-sujet-count"></div>
          <?php endif; ?>

          <label class="psc-conv-field-label" for="psc-conversation-corps"><?php esc_html_e('Message', 'periscolaire-registration'); ?></label>
          <textarea id="psc-conversation-corps" name="corps" rows="7" maxlength="2000" required
            placeholder="<?php esc_attr_e('Décrivez votre demande…', 'periscolaire-registration'); ?>"
            aria-describedby="psc-conversation-medical-warning<?php echo $error_message ? ' psc-conversation-create-error' : ''; ?>"
            <?php if ($error_message) echo 'aria-invalid="true"'; ?>
          ><?php echo esc_textarea($draft['corps'] ?? ''); ?></textarea>

          <label class="psc-btn-secondary psc-file-input-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#24405C" stroke-width="1.8" aria-hidden="true"><path d="M21 12.5 12.5 21a4.9 4.9 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L10 18.9"></path></svg>
            <?php esc_html_e('Joindre un fichier', 'periscolaire-registration'); ?>
            <input type="file" name="piece_jointe" class="psc-file-input-visually-hidden" accept=".pdf,.jpg,.jpeg,.png">
          </label>
          <span class="psc-file-chosen" aria-live="polite"></span>

          <div class="psc-conv-compose-footer">
            <button type="submit" class="psc-btn-primary" id="psc-conversation-submit"><?php esc_html_e('Envoyer', 'periscolaire-registration'); ?></button>
            <a class="psc-conv-cancel-link" href="<?php echo esc_url($list_url); ?>"><?php esc_html_e('Annuler', 'periscolaire-registration'); ?></a>
            <span class="psc-conv-char-count psc-conv-count-push" id="psc-conversation-count" aria-live="polite"></span>
          </div>
        </form>

        <p id="psc-conversation-medical-warning" class="screen-reader-text">
          <?php esc_html_e("Pour une allergie, un traitement ou un régime, mettez à jour la fiche de votre enfant : n'indiquez pas d'informations médicales détaillées ici.", 'periscolaire-registration'); ?>
        </p>
        <script>
        (function () {
            var form = document.getElementById('psc-conversation-create-form');
            var sujet = document.getElementById('psc-conversation-sujet');
            var sujetCount = document.getElementById('psc-conversation-sujet-count');
            var corps = document.getElementById('psc-conversation-corps');
            var corpsCount = document.getElementById('psc-conversation-count');
            var btn = document.getElementById('psc-conversation-submit');
            function update() {
                if (sujet && sujetCount) {
                    sujetCount.textContent = sujet.value.length + '<?php echo esc_js(__(' / 160 caractères', 'periscolaire-registration')); ?>';
                }
                if (corps && corpsCount) {
                    corpsCount.textContent = corps.value.length ? corps.value.length + '<?php echo esc_js(__(' caractères', 'periscolaire-registration')); ?>' : '';
                }
                if (btn) {
                    var filled = (!sujet || sujet.value.trim().length > 0) && corps && corps.value.trim().length > 0;
                    btn.disabled = !filled;
                    btn.classList.toggle('is-disabled', !filled);
                }
            }
            if (sujet) sujet.addEventListener('input', update);
            if (corps) corps.addEventListener('input', update);
            update();
            var fileInput = form ? form.querySelector('input[type="file"]') : null;
            var fileChosen = form ? form.querySelector('.psc-file-chosen') : null;
            if (fileInput && fileChosen) {
                fileInput.addEventListener('change', function () {
                    fileChosen.textContent = fileInput.files[0] ? fileInput.files[0].name : '';
                });
            }
            var err = document.getElementById('psc-conversation-create-error');
            var focusTarget = sujet || corps;
            if (err && focusTarget) focusTarget.focus();
        })();
        </script>
      </div>

      <div class="psc-conv-side">
        <div class="psc-conv-side-card psc-conv-side-warning">
          <div class="psc-conv-side-warning-head">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#9E4A4A" stroke-width="1.8" aria-hidden="true"><path d="M12 3 2.5 20h19L12 3Z"></path><path d="M12 9v5M12 17h.01"></path></svg>
            <span><?php esc_html_e('Données de santé', 'periscolaire-registration'); ?></span>
          </div>
          <p><?php printf(
              /* translators: %s: lien vers la fiche de l'enfant */
              esc_html__('Pour une allergie, un traitement ou un régime, mettez à jour la %s : n’indiquez pas d’informations médicales détaillées dans un message.', 'periscolaire-registration'),
              '<a href="' . esc_url(add_query_arg(array('psc_tab' => 'enfants'), $base_url)) . '" style="font-weight:600">' . esc_html__('fiche de votre enfant', 'periscolaire-registration') . '</a>'
          ); ?></p>
        </div>

        <div class="psc-conv-side-card psc-conv-side-shortcuts">
          <div><?php esc_html_e('Plus rapide sans écrire', 'periscolaire-registration'); ?></div>
          <div class="psc-conv-shortcut-list">
            <a href="<?php echo esc_url(add_query_arg(array('psc_tab' => $planning_tab), $base_url)); ?>">
              <span>&rarr;</span>
              <span><strong><?php esc_html_e('Annuler un repas', 'periscolaire-registration'); ?></strong> — <?php esc_html_e('modifiable jusqu’à la veille 9 h dans Planning', 'periscolaire-registration'); ?></span>
            </a>
            <a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'enfants'), $base_url)); ?>">
              <span>&rarr;</span>
              <span><strong><?php esc_html_e('Allergie ou régime', 'periscolaire-registration'); ?></strong> — <?php esc_html_e('à renseigner dans la fiche de l’enfant', 'periscolaire-registration'); ?></span>
            </a>
            <a href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'habilitations'), $base_url)); ?>">
              <span>&rarr;</span>
              <span><strong><?php esc_html_e('Habiliter quelqu’un', 'periscolaire-registration'); ?></strong> — <?php esc_html_e('ajout immédiat dans Habilitations', 'periscolaire-registration'); ?></span>
            </a>
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <div class="psc-conv-toolbar">
      <div class="psc-conv-filters">
        <?php
        $filter_labels = array(
            'tous'    => __('Tous', 'periscolaire-registration'),
            'encours' => __('En cours', 'periscolaire-registration'),
            'clos'    => __('Clos', 'periscolaire-registration'),
        );
        foreach ($filter_labels as $key => $label):
        ?>
          <a class="psc-chip-link<?php echo $conv['filtre'] === $key ? ' is-active' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_conv_filtre' => $key), $base_url)); ?>">
            <?php echo esc_html($label); ?>
          </a>
        <?php endforeach; ?>
      </div>
      <a class="psc-btn-primary" data-testid="conversation-new" href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_new' => 1), $base_url)); ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1A1A1A" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
        <?php esc_html_e('Écrire à la mairie', 'periscolaire-registration'); ?>
      </a>
    </div>

    <div class="psc-conv-delay">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#24405C" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8h.01M11 12h1v5h1"></path></svg>
      <span><?php echo esc_html($conv['delai_note']); ?></span>
    </div>

    <?php if ($conv['conversations']): ?>
      <div class="psc-conv-list">
        <?php foreach ($conv['conversations'] as $c): ?>
          <a class="psc-conv-row<?php echo !empty($c->non_lu) ? ' is-unread' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'conversation_id' => (int) $c->id), $base_url)); ?>">
            <span class="psc-conv-rail" aria-hidden="true"></span>
            <span class="psc-conv-row-body">
              <span class="psc-conv-row-line1">
                <span class="psc-conv-subject"><?php echo esc_html($c->sujet); ?></span>
                <span class="psc-conv-status psc-conv-status-<?php echo esc_attr($c->display_status_key); ?>"><?php echo esc_html($c->display_status_label); ?></span>
                <span class="psc-conv-date"><?php echo esc_html($c->display_date); ?></span>
              </span>
              <?php if ($c->display_excerpt !== ''): ?>
              <span class="psc-conv-row-line2">
                <span class="psc-conv-author"><?php echo esc_html($c->display_author_prefix); ?></span>
                <span class="psc-conv-excerpt"><?php echo esc_html($c->display_excerpt); ?></span>
              </span>
              <?php endif; ?>
              <span class="psc-conv-row-line3">
                <?php if ($c->display_objet_label !== ''): ?><span class="psc-conv-objet"><?php echo esc_html($c->display_objet_label); ?></span><?php endif; ?>
                <?php if ($c->display_enfant_label !== ''): ?><span>&middot; <?php echo esc_html($c->display_enfant_label); ?></span><?php endif; ?>
                <span>&middot; <?php echo esc_html($c->display_count_label); ?></span>
              </span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="psc-conv-empty">
        <svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="#C9BCAE" stroke-width="1.4" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.8 8.8 0 0 1-3.8-.8L3 21l1.9-5.1A8.4 8.4 0 0 1 12.5 3 8.4 8.4 0 0 1 21 11.5Z"></path></svg>
        <h3><?php esc_html_e('Aucun échange pour le moment', 'periscolaire-registration'); ?></h3>
        <p><?php esc_html_e('Une question sur la cantine, le planning ou une facture ? Écrivez au service périscolaire : la conversation restera visible ici, avec les réponses de la mairie.', 'periscolaire-registration'); ?></p>
        <a class="psc-btn-primary" href="<?php echo esc_url(add_query_arg(array('psc_tab' => 'messages', 'psc_vue' => 'echanges', 'psc_new' => 1), $base_url)); ?>"><?php esc_html_e('Écrire à la mairie', 'periscolaire-registration'); ?></a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
