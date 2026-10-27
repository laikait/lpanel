// LPanel's page behaviour: /assets/core/js/app.js
//
// Every hook is a data attribute, because the CSP runs no inline script or
// handler. Each feature works without this file too: forms submit, links
// navigate, times read in UTC. This only makes them nicer.
(function () {
    'use strict';

    var root = document.documentElement;

    // data-lp-theme-toggle: light <-> dark, remembered in this browser only.
    function toggleTheme() {
        var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-bs-theme', next);
        try {
            window.localStorage.setItem('lpanel.theme', next);
        } catch (e) {
            // Storage blocked: the choice lasts for this page.
        }
    }

    // data-lp-copy="#selector": copy that element's text, and say so.
    function copy(button) {
        var target = document.querySelector(button.getAttribute('data-lp-copy'));
        if (!target || !navigator.clipboard) {
            return;
        }
        var text = target.children.length > 1
            ? Array.prototype.map.call(target.children, function (c) { return c.textContent.trim(); }).join('\n')
            : target.textContent.trim();
        navigator.clipboard.writeText(text).then(function () {
            var label = button.querySelector('span');
            var before = label ? label.textContent : '';
            button.classList.add('is-copied');
            if (label) {
                label.textContent = 'Copied';
            }
            window.setTimeout(function () {
                button.classList.remove('is-copied');
                if (label) {
                    label.textContent = before;
                }
            }, 1800);
        });
    }

    // data-lp-reveal="input-id": show or hide a password.
    function reveal(button) {
        var input = document.getElementById(button.getAttribute('data-lp-reveal'));
        if (!input) {
            return;
        }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.classList.toggle('is-on', show);
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('title', show ? 'Hide password' : 'Show password');
    }

    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-lp-theme-toggle], [data-lp-copy], [data-lp-reveal]');
        if (!el) {
            return;
        }
        if (el.hasAttribute('data-lp-theme-toggle')) {
            toggleTheme();
        } else if (el.hasAttribute('data-lp-copy')) {
            copy(el);
        } else {
            reveal(el);
        }
    });

    // form[data-lp-confirm="message"]: ask in a dialog before submitting.
    var modalEl = document.getElementById('lp-confirm');
    var pending = null;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute('data-lp-confirm') || form.getAttribute('data-lp-confirmed') === '1') {
            return;
        }
        if (!modalEl || !window.bootstrap) {
            if (!window.confirm(form.getAttribute('data-lp-confirm'))) {
                event.preventDefault();
            }
            return;
        }
        event.preventDefault();
        pending = form;
        modalEl.querySelector('[data-lp-confirm-title]').textContent = form.getAttribute('data-lp-confirm-title') || 'Are you sure?';
        modalEl.querySelector('[data-lp-confirm-text]').textContent = form.getAttribute('data-lp-confirm');
        modalEl.querySelector('[data-lp-confirm-ok]').textContent = form.getAttribute('data-lp-confirm-ok') || 'Confirm';
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    if (modalEl) {
        modalEl.querySelector('[data-lp-confirm-ok]').addEventListener('click', function () {
            if (!pending) {
                return;
            }
            var form = pending;
            pending = null;
            form.setAttribute('data-lp-confirmed', '1');
            window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            if (form.requestSubmit) {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    }

    // select[data-lp-autosubmit]: a filter applies as soon as it changes.
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-lp-autosubmit]') && event.target.form) {
            event.target.form.submit();
        }
    });

    // time[data-lp-time]: "3 min ago" for recent times, the viewer's local time otherwise.
    var MINUTE = 60e3, HOUR = 36e5, DAY = 864e5;
    var full = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' });
    var day = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    var clock = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

    function humanize(el) {
        var at = new Date(el.getAttribute('datetime'));
        if (isNaN(at.getTime())) {
            return;
        }
        var ago = Date.now() - at.getTime();
        var text;
        if (ago < 0) {
            text = day.format(at) + ', ' + clock.format(at);
        } else if (ago < MINUTE) {
            text = 'Just now';
        } else if (ago < HOUR) {
            text = Math.floor(ago / MINUTE) + ' min ago';
        } else if (ago < DAY) {
            text = Math.floor(ago / HOUR) + ' h ago';
        } else if (ago < 2 * DAY) {
            text = 'Yesterday, ' + clock.format(at);
        } else {
            text = day.format(at) + ', ' + clock.format(at);
        }
        el.textContent = text;
        el.title = full.format(at);
    }

    function humanizeAll() {
        Array.prototype.forEach.call(document.querySelectorAll('time[data-lp-time]'), humanize);
    }

    humanizeAll();
    window.setInterval(humanizeAll, MINUTE);

    // [data-lp-watch="/api/v1/operations/{id}"][data-lp-watch-status="queued"]:
    // work under way. Ask the API how it stands, every 2 s at first and every
    // 5 s after a minute, and reload once any of it has moved on. Gives up
    // after ten minutes, or when the API stops answering (signed out).
    var watched = Array.prototype.slice.call(document.querySelectorAll('[data-lp-watch]'));
    var watchStarted = Date.now();

    function poll() {
        if (watched.length === 0 || Date.now() - watchStarted > 10 * MINUTE) {
            return;
        }
        Promise.all(watched.map(function (el) {
            return fetch(el.getAttribute('data-lp-watch'), { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('status ' + response.status);
                    }
                    return response.json();
                })
                .then(function (body) {
                    return body && body.data && body.data.status !== el.getAttribute('data-lp-watch-status');
                });
        })).then(function (changed) {
            if (changed.indexOf(true) !== -1) {
                window.location.reload();
                return;
            }
            window.setTimeout(poll, Date.now() - watchStarted > MINUTE ? 5000 : 2000);
        }, function () { /* stop: the page still shows the last state it had */ });
    }

    if (watched.length > 0) {
        window.setTimeout(poll, 2000);
    }
})();
