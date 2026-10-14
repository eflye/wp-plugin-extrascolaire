/* global jQuery, tinymce, wp, PSC_MESSAGES */
(function ($) {
  'use strict';
  var $form = $('#psc-message-form');
  if (!$form.length) return;
  var $title = $('#psc-message-title');
  var i18n = (window.PSC_MESSAGES && PSC_MESSAGES.i18n) || {};
  function fmt(template, n) { return String(template || '').replace('%d', n); }
  // Règle française : 0 et 1 au singulier ; wp.i18n._n prioritaire s'il est chargé.
  function plural(pair, n) {
    n = Number(n) || 0;
    if (window.wp && wp.i18n && typeof wp.i18n._n === 'function') return fmt(wp.i18n._n(pair[0], pair[1], n, 'periscolaire-registration'), n);
    return fmt(n > 1 ? pair[1] : pair[0], n);
  }
  function bodyText() {
    if (window.tinymce && tinymce.get('psc_message_body')) return tinymce.get('psc_message_body').getContent({ format: 'text' });
    return $('<div>').html($('#psc_message_body').val() || '').text();
  }
  function refreshPreview() {
    var cat = $('input[name="categorie"]:checked').closest('label');
    $('#psc-title-count').text(fmt(i18n.titleCount, ($title.val() || '').length));
    $('#psc-preview-cat').text(cat.text().trim()).attr('style', cat.attr('style') || '');
    $('#psc-preview-title').text($title.val() || i18n.titlePlaceholder);
    var text = bodyText(); $('#psc-preview-body').text(text.length > 110 ? text.slice(0, 107) + '…' : text);
  }
  function targetData() {
    return { action: 'psc_message_count_targets', nonce: PSC_MESSAGES.countNonce, cible_type: $('input[name="cible_type"]:checked').val(), cible_ecole: $('[name="cible_ecole"]').val(), cible_service: $('[name="cible_service"]').val(), family_ids: $('[name="family_ids[]"]').val() || [] };
  }
  function refreshCount() {
    $.post(PSC_MESSAGES.ajaxUrl, targetData()).done(function (r) { if (r.success) { var $count = $('#psc-target-count').empty(); $('<strong>').text(plural(i18n.families, r.data.familles)).appendTo($count); $count.append(document.createTextNode(' · ' + plural(i18n.children, r.data.enfants))); $('[data-message-action="send"]').text(plural(i18n.sendTo, r.data.familles)); } });
  }
  function refreshTargetFields() {
    var type = $('input[name="cible_type"]:checked').val();
    $('[name="cible_ecole"]').toggle(type === 'ecole');
    $('[name="cible_service"]').toggle(type === 'service');
    $('[name="family_ids[]"]').toggle(type === 'familles');
  }
  $form.on('input change', 'input,textarea,select', function () { refreshPreview(); if ($(this).is('[name^="cible"],[name="family_ids[]"]')) { refreshTargetFields(); refreshCount(); } });
  $(document).on('tinymce-editor-init', function (_, editor) { if (editor.id === 'psc_message_body') editor.on('input change keyup', refreshPreview); });
  var frame;
  $('#psc-attachment-pick').on('click', function () { frame = frame || wp.media({ title: i18n.pickAttachment, multiple: false, library: { type: ['application/pdf', 'image/jpeg', 'image/png'] } }); frame.off('select').on('select', function () { var a = frame.state().get('selection').first().toJSON(); if (a.filesizeInBytes > 5 * 1024 * 1024) return window.alert(i18n.tooLarge); $('#psc-attachment-id').val(a.id); $('#psc-attachment-label').text(a.filename + (a.filesizeHumanReadable ? ' · ' + a.filesizeHumanReadable : '')); }); frame.open(); });
  $('#psc-attachment-remove').on('click', function () { $('#psc-attachment-id').val(''); $('#psc-attachment-label').text(''); });
  $form.on('click', '[data-message-action]', function (e) {
    e.preventDefault(); var action = $(this).data('message-action');
    var endpoint = action === 'send' ? 'psc_send_message' : (action === 'test' ? 'psc_test_message' : 'psc_save_message');
    if (action === 'send') {
      var dialog = document.getElementById('psc-send-confirm');
      if (dialog && typeof dialog.showModal === 'function') {
        $('#psc-send-summary').text($('#psc-target-count').text() + ' · ' + ($('[name="canal_email"]').is(':checked') ? i18n.channelsPortalEmail : i18n.channelsPortal) + ' · ' + ($('#psc-attachment-id').val() ? i18n.withAttachment : i18n.withoutAttachment));
        dialog.showModal(); return;
      }
      if (!window.confirm(PSC_MESSAGES.confirm + '\n\n' + $('#psc-target-count').text())) return;
    }
    $('#psc-message-action').val(endpoint); $('#psc-message-submit-action').val(action === 'schedule' ? 'schedule' : 'draft');
    $('[name="_wpnonce"]').val($form.data(action === 'send' ? 'nonce-send' : (action === 'test' ? 'nonce-test' : 'nonce-save')));
    if (action === 'send') $(this).prop('disabled', true);
    $form[0].submit();
  });
  $('#psc-send-cancel').on('click', function () { document.getElementById('psc-send-confirm').close(); });
  $('#psc-send-confirm-button').on('click', function () { $(this).prop('disabled', true); $('#psc-message-action').val('psc_send_message'); $('[name="_wpnonce"]').val($form.data('nonce-send')); $form[0].submit(); });
  refreshTargetFields(); refreshPreview();
})(jQuery);
