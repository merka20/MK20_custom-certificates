(function () {
    'use strict';

    var config = window.mk20CertSync;
    if (!config || !config.endpoint) {
        return;
    }

    var status = document.getElementById('mk20-cert-sync-status');
    if (!status) {
        return;
    }

    var text = status.querySelector('.mk20-cert-sync__text');
    var bar = status.querySelector('.mk20-cert-sync__bar span');
    var failures = 0;

    function setText(value) {
        if (text && value) {
            text.textContent = value;
        }
    }

    function setProgress(pending, total) {
        if (!bar) {
            return;
        }
        if (total > 0) {
            var done = Math.max(0, total - pending);
            bar.style.width = Math.min(100, Math.round((done / total) * 100)) + '%';
        }
    }

    function hideSoon() {
        window.setTimeout(function () {
            status.classList.add('is-hidden');
        }, 2500);
    }

    function request() {
        setText(config.strings.syncing);

        window.fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            body: JSON.stringify({ user_id: config.userId })
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        }).then(function (data) {
            failures = 0;

            if (Number(data.imported) > 0) {
                status.dataset.changed = '1';
            }

            setProgress(Number(data.pending) || 0, Number(data.total) || 0);

            if (data.done) {
                if (status.dataset.changed === '1') {
                    setText(config.strings.reload);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 800);
                } else {
                    setText(config.strings.ready);
                    hideSoon();
                }
                return;
            }

            window.setTimeout(request, data.busy ? 3000 : 300);
        }).catch(function () {
            failures += 1;

            if (failures >= 5) {
                setText(config.strings.error);
                return;
            }

            window.setTimeout(request, Math.min(15000, 2000 * failures));
        });
    }

    window.setTimeout(request, 400);
}());
