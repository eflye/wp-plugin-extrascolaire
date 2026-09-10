(function () {
    'use strict';

    var config = window.PSC_MESSAGE_NOTIFICATIONS;
    if (!config || !('Notification' in window)) return;

    var known = {};
    var timer = null;
    (config.knownIds || []).forEach(function (id) { known[String(id)] = true; });
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.psc-browser-notification-toggle'));

    function updateButtons() {
        buttons.forEach(function (button) {
            button.hidden = false;
            if (window.Notification.permission === 'granted') {
                button.textContent = config.labels.enabled;
                button.disabled = true;
            } else if (window.Notification.permission === 'denied') {
                button.textContent = config.labels.denied;
                button.disabled = true;
            } else {
                button.textContent = config.labels.enable;
                button.disabled = false;
            }
        });
    }

    function showMessage(message) {
        var notification = new window.Notification(config.labels.source, {
            body: message.title + (message.body ? ' — ' + message.body : ''),
            tag: 'psc-message-' + message.id,
        });
        notification.onclick = function () {
            window.focus();
            window.location.href = message.url;
            notification.close();
        };
    }

    function poll() {
        if (window.Notification.permission !== 'granted') return;
        var body = new URLSearchParams({
            action: 'psc_message_notifications',
            nonce: config.nonce,
            parent_nonce: config.parentNonce,
        });
        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).then(function (response) {
            if (!response.ok) throw new Error('notification_poll');
            return response.json();
        }).then(function (payload) {
            if (!payload.success || !payload.data || !payload.data.messages) return;
            payload.data.messages.forEach(function (message) {
                var id = String(message.id);
                if (!known[id]) showMessage(message);
                known[id] = true;
            });
        }).catch(function () {
            // Une indisponibilité temporaire ne doit jamais gêner le portail.
        });
    }

    function startPolling() {
        if (window.Notification.permission !== 'granted' || timer !== null) return;
        poll();
        timer = window.setInterval(poll, Math.max(15000, Number(config.pollInterval) || 30000));
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            window.Notification.requestPermission().then(function () {
                updateButtons();
                startPolling();
            });
        });
    });

    updateButtons();
    startPolling();
}());
