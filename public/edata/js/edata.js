/* E-Data 360 interactions. Plain JavaScript with no build step, so it deploys as is. */
(function () {
    'use strict';

    var root = document.documentElement;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function $(selector, scope) { return (scope || document).querySelector(selector); }
    function $$(selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); }
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    /* ---------- Toasts ---------- */
    function toast(message, type) {
        var host = $('#edToasts');
        if (!host || !message) { return; }
        var el = document.createElement('div');
        el.className = 'ed-toast' + (type === 'error' ? ' is-error' : '');
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = '<i class="ti ' + (type === 'error' ? 'ti-alert-circle' : 'ti-circle-check') + '"></i><span></span>';
        el.querySelector('span').textContent = message;
        host.appendChild(el);
        setTimeout(function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 220);
        }, type === 'error' ? 6500 : 3800);
    }
    window.edToast = toast;
    // Screens that were not rebuilt call show_toastr; they get the same toast.
    window.show_toastr = function (type, message) {
        var text = String(message || '').replace(/<[^>]*>/g, ' ').trim();
        toast(text, String(type).toLowerCase() === 'error' ? 'error' : 'success');
    };

    /* ---------- Sidebar ---------- */
    function storageGet(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
    function storageSet(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* private window */ } }

    var collapse = $('#edCollapse');
    if (collapse) {
        collapse.addEventListener('click', function () {
            var small = root.classList.toggle('side-small');
            storageSet('edSideSmall', small ? '1' : '0');
        });
    }
    var toggle = $('#edMenuToggle');
    if (toggle) {
        toggle.addEventListener('click', function () { root.classList.toggle('side-open'); });
    }
    var scrim = $('#edScrim');
    if (scrim) {
        scrim.addEventListener('click', function () { root.classList.remove('side-open'); });
    }
    $$('[data-side-more]').forEach(function (button) {
        var panel = document.getElementById(button.getAttribute('aria-controls'));
        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.classList.toggle('is-open', open);
            storageSet('edSideMore', open ? '1' : '0');
        });
        if (storageGet('edSideMore') === '1' && !panel.classList.contains('is-open')) {
            button.setAttribute('aria-expanded', 'true');
            panel.classList.add('is-open');
        }
    });

    /* ---------- Popovers ---------- */
    function closePops(except) {
        $$('.ed-pop.is-open').forEach(function (pop) {
            if (pop !== except) {
                pop.classList.remove('is-open');
                var trigger = $('[data-pop]', pop);
                if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
            }
        });
    }
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-pop]');
        if (trigger) {
            var pop = trigger.closest('.ed-pop');
            closePops(pop);
            var open = pop.classList.toggle('is-open');
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && pop.id === 'edBell') { loadBell(); }
            return;
        }
        if (!event.target.closest('.ed-pop__panel')) { closePops(); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closePops(); root.classList.remove('side-open'); }
    });

    /* ---------- Notification centre ---------- */
    var bell = $('#edBell');
    var bellDot = $('#edBellDot');
    function setUnread(count) {
        if (!bellDot) { return; }
        bellDot.textContent = count > 99 ? '99+' : count;
        bellDot.hidden = !count;
    }
    function loadBell() {
        var list = $('#edBellList');
        if (!list) { return; }
        fetch(bell.dataset.feed, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setUnread(data.unread);
                if (!data.items.length) {
                    list.innerHTML = '<div class="ed-empty ed-empty--sm"><i class="ti ti-bell-off"></i>' + escapeHtml(bell.dataset.empty) + '</div>';
                    return;
                }
                list.innerHTML = data.items.map(function (n) {
                    return '<a class="ed-bell__row' + (n.read ? '' : ' is-new') + '" href="' + escapeHtml(n.url) + '"><b>' + escapeHtml(n.title) + '</b>' +
                        (n.body ? '<span>' + escapeHtml(n.body) + '</span>' : '') + '<small>' + escapeHtml(n.when) + '</small></a>';
                }).join('');
            })
            .catch(function () {
                list.innerHTML = '<div class="ed-empty ed-empty--sm">' + escapeHtml(bell.dataset.failed) + '</div>';
            });
    }
    var readAll = $('#edBellReadAll');
    if (readAll) {
        readAll.addEventListener('click', function () {
            fetch(readAll.dataset.url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function () { setUnread(0); loadBell(); });
        });
    }
    if (bell) {
        // Light poll so a new assignment shows up without a reload. Pauses in a hidden tab.
        setInterval(function () {
            if (document.hidden) { return; }
            fetch(bell.dataset.feed, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { if (data) { setUnread(data.unread); } })
                .catch(function () {});
        }, 90000);
    }

    /* ---------- Confirm dialog ---------- */
    var dialog = $('#edConfirm');
    var pending = null;
    function askConfirm(form, trigger) {
        if (!dialog || typeof dialog.showModal !== 'function') {
            return window.confirm(trigger.dataset.confirm);
        }
        $('#edConfirmTitle', dialog).textContent = trigger.dataset.confirm;
        $('#edConfirmText', dialog).textContent = trigger.dataset.confirmText || '';
        var yes = $('#edConfirmYes', dialog);
        yes.textContent = trigger.dataset.confirmYes || yes.dataset.default;
        yes.className = 'ed-btn ' + (trigger.dataset.confirmSafe !== undefined ? 'ed-btn--primary' : 'ed-btn--solid-danger');
        var reasonWrap = $('#edConfirmReasonWrap', dialog);
        var reason = $('#edConfirmReason', dialog);
        var needsReason = trigger.dataset.confirmReason !== undefined;
        // An optional note (for example the end-of-day report) reuses the same box.
        var note = trigger.dataset.confirmNote;
        var label = $('#edConfirmReasonLabel', dialog);
        var hint = $('#edConfirmReasonHint', dialog);
        reasonWrap.hidden = !needsReason && !note;
        reason.value = '';
        reason.required = needsReason;
        reason.minLength = needsReason ? 3 : 0;
        reason.maxLength = note ? 2000 : 400;
        reason.rows = note ? 4 : 2;
        reason.placeholder = note ? (trigger.dataset.confirmNotePlaceholder || '') : '';
        reason.dataset.field = note || 'reason';
        if (label) { label.textContent = note ? trigger.dataset.confirmNoteLabel : label.dataset.default; }
        if (hint) { hint.textContent = note ? (trigger.dataset.confirmNoteHint || '') : hint.dataset.default; }
        var star = $('#edConfirmReasonStar', dialog);
        if (star) { star.hidden = !needsReason; }
        pending = form;
        dialog.showModal();
        return false;
    }
    if (dialog) {
        $('#edConfirmNo', dialog).addEventListener('click', function () { pending = null; dialog.close(); });
        $('#edConfirmForm', dialog).addEventListener('submit', function (event) {
            event.preventDefault();
            var reason = $('#edConfirmReason', dialog);
            if (pending) {
                if (reason.required || (reason.dataset.field !== 'reason' && reason.value.trim() !== '')) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = reason.dataset.field || 'reason';
                    input.value = reason.value;
                    pending.appendChild(input);
                }
                pending.dataset.confirmed = '1';
                var form = pending;
                pending = null;
                dialog.close();
                startVeil(form);
                form.submit();
            }
        });
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.dataset.confirm && form.dataset.confirmed !== '1') {
            event.preventDefault();
            askConfirm(form, form);
        }
    }, true);

    /* ---------- Buttons stay honest while a form is sending ---------- */
    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented) { return; }
        var form = event.target;
        if (form.method && form.method.toLowerCase() === 'post') {
            $$('button[type="submit"], .ed-clock__btn', form).forEach(function (button) {
                button.disabled = true;
            });
            startVeil(form);
        }
    });
    window.addEventListener('pageshow', function () {
        $$('button[disabled][type="submit"], .ed-clock__btn[disabled]').forEach(function (button) { button.disabled = false; });
        $$('.ed-veil').forEach(function (veil) { veil.remove(); });
    });

    /* Clock-in and clock-out grow out of the button, then the next page takes over. */
    function startVeil(form) {
        if (!form || form.dataset.momentForm === undefined) { return; }
        var origin = $('button', form);
        var rect = origin ? origin.getBoundingClientRect() : null;
        var veil = document.createElement('div');
        veil.className = 'ed-veil';
        if (rect) {
            veil.style.setProperty('--x', Math.round(rect.left + rect.width / 2) + 'px');
            veil.style.setProperty('--y', Math.round(rect.top + rect.height / 2) + 'px');
        }
        document.body.appendChild(veil);
    }

    /* ---------- Tabs: instant, and the URL remembers the tab ---------- */
    $$('[data-tabs]').forEach(function (tabs) {
        var links = $$('a[data-tab]', tabs);
        links.forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                links.forEach(function (other) { other.classList.toggle('is-active', other === link); });
                $$('.ed-tabpane').forEach(function (pane) { pane.classList.toggle('is-active', pane.id === 'tab-' + link.dataset.tab); });
                var url = new URL(window.location.href);
                url.searchParams.set('tab', link.dataset.tab);
                history.replaceState(null, '', url.toString());
            });
        });
    });

    /* ---------- Animated counters ---------- */
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    $$('[data-count]').forEach(function (el) {
        var target = parseFloat(el.dataset.count);
        if (isNaN(target) || reduced || target === 0) { return; }
        var decimals = parseInt(el.dataset.decimals || '0', 10);
        var prefix = el.dataset.prefix || '';
        var suffix = el.dataset.suffix || '';
        var started = null;
        var duration = 620;
        function step(now) {
            if (started === null) { started = now; }
            var t = Math.min(1, (now - started) / duration);
            var eased = 1 - Math.pow(1 - t, 3);
            var value = target * eased;
            el.textContent = prefix + value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
            if (t < 1) { requestAnimationFrame(step); }
        }
        requestAnimationFrame(step);
    });

    /* ---------- Clock: follows the server's time, not the device's ---------- */
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function formatDuration(total) {
        total = Math.max(0, Math.floor(total));
        return pad(Math.floor(total / 3600)) + ':' + pad(Math.floor(total % 3600 / 60)) + ':' + pad(total % 60);
    }
    var clockRoot = $('[data-clock]');
    if (clockRoot) {
        var serverEpoch = parseInt(clockRoot.dataset.serverEpoch, 10);
        var offset = serverEpoch * 1000 - Date.now();
        var zone = clockRoot.dataset.zone;
        var openSince = clockRoot.dataset.openSince ? parseInt(clockRoot.dataset.openSince, 10) : null;
        var finished = parseInt(clockRoot.dataset.finished || '0', 10);
        var timeEl = $('[data-clock-time]', clockRoot);
        var sessionEl = $('[data-clock-session]', clockRoot);
        var totalEl = $('[data-clock-total]', clockRoot);
        var formatter = new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        var drawArc = dayArc(clockRoot, offset);
        var tick = function () {
            var nowMs = Date.now() + offset;
            if (timeEl) { timeEl.textContent = formatter.format(new Date(nowMs)); }
            var running = openSince ? (nowMs / 1000 - openSince) : 0;
            if (sessionEl) { sessionEl.textContent = formatDuration(running); }
            if (totalEl) { totalEl.textContent = formatDuration(finished + running); }
        };
        tick();
        setInterval(tick, 1000);
        if (drawArc) { setInterval(drawArc, 30000); }
    }

    /* ---------- The day arc: the sun follows the server's clock across the workday ---------- */
    function dayArc(host, serverOffset) {
        var arc = $('[data-arc]', host);
        if (!arc) { return null; }
        var start = parseInt(host.dataset.shiftStart, 10);
        var end = parseInt(host.dataset.shiftEnd, 10);
        if (!start || !end || end <= start) { return null; }

        var isRtl = root.getAttribute('dir') !== 'ltr';
        var sun = $('[data-sun]', arc);
        var worked = $('[data-arc-worked]', arc);
        var segments = [];
        try { segments = JSON.parse(host.dataset.segments || '[]'); } catch (e) { segments = []; }

        function toT(epoch) { return Math.max(0, Math.min(1, (epoch - start) / (end - start))); }
        // The same curve as the path in the markup: M60 130 Q500 -130 940 130.
        function x(t) { var along = 60 + 880 * t; return isRtl ? 1000 - along : along; }
        function y(t) { var u = 1 - t; return u * u * 130 + 2 * u * t * -130 + t * t * 130; }

        function placeSun(t) {
            sun.style.left = (x(t) / 10) + '%';
            sun.style.top = (y(t) / 170 * 100) + '%';
        }
        function drawWorked(nowEpoch) {
            var markup = '';
            segments.forEach(function (segment) {
                var from = toT(segment[0]);
                var to = toT(segment[1] === null ? nowEpoch : segment[1]);
                if (to <= from) { return; }
                var points = [];
                for (var t = from; t < to; t += 0.012) { points.push(x(t).toFixed(1) + ',' + y(t).toFixed(1)); }
                points.push(x(to).toFixed(1) + ',' + y(to).toFixed(1));
                markup += '<polyline class="ed-day__worked" points="' + points.join(' ') + '"></polyline>';
            });
            worked.innerHTML = markup;
        }
        function draw() {
            var nowEpoch = (Date.now() + serverOffset) / 1000;
            placeSun(toT(nowEpoch));
            drawWorked(nowEpoch);
        }

        // The sun climbs to where it belongs on the first visit of the day.
        if (root.classList.contains('ed-first-today') && !reduced) {
            var target = toT((Date.now() + serverOffset) / 1000);
            var began = null;
            var climb = function (time) {
                if (began === null) { began = time; }
                var p = Math.min(1, (time - began) / 1500);
                placeSun(target * (1 - Math.pow(1 - p, 3)));
                if (p < 1) { requestAnimationFrame(climb); }
            };
            placeSun(0);
            drawWorked((Date.now() + serverOffset) / 1000);
            requestAnimationFrame(climb);
        } else {
            draw();
        }

        return draw;
    }

    /* ---------- Small celebrations ---------- */
    function burst(originX, originY, amount) {
        if (reduced) { return; }
        var canvas = document.createElement('canvas');
        canvas.className = 'ed-burst';
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        document.body.appendChild(canvas);
        var context = canvas.getContext('2d');
        var colours = ['#BFFA65', '#82F67D', '#00D3B5', '#00C2C6', '#72B00B', '#002D59'];
        var bits = [];
        for (var i = 0; i < (amount || 46); i++) {
            var angle = Math.random() * Math.PI * 2;
            var speed = 3 + Math.random() * 8;
            bits.push({
                x: originX, y: originY,
                vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed - 5,
                size: 4 + Math.random() * 5, spin: Math.random() * 6,
                colour: colours[i % colours.length], round: i % 3 === 0
            });
        }
        var started = null;
        function frame(time) {
            if (started === null) { started = time; }
            var age = time - started;
            context.clearRect(0, 0, canvas.width, canvas.height);
            context.globalAlpha = Math.max(0, 1 - age / 1300);
            bits.forEach(function (bit) {
                bit.vy += 0.24;
                bit.vx *= 0.985;
                bit.x += bit.vx;
                bit.y += bit.vy;
                bit.spin += 0.2;
                context.fillStyle = bit.colour;
                context.save();
                context.translate(bit.x, bit.y);
                context.rotate(bit.spin);
                if (bit.round) {
                    context.beginPath();
                    context.arc(0, 0, bit.size / 2, 0, Math.PI * 2);
                    context.fill();
                } else {
                    context.fillRect(-bit.size / 2, -bit.size / 3, bit.size, bit.size / 1.5);
                }
                context.restore();
            });
            if (age < 1300) { requestAnimationFrame(frame); } else { canvas.remove(); }
        }
        requestAnimationFrame(frame);
    }
    window.edBurst = burst;

    /* ---------- The moment after clock-in and clock-out ---------- */
    var moment = $('#edMoment');
    if (moment) {
        var seconds = parseInt(moment.dataset.seconds || '10', 10);
        moment.style.setProperty('--seconds', seconds + 's');
        document.documentElement.style.overflow = 'hidden';
        moment.setAttribute('tabindex', '-1');
        moment.focus({ preventScroll: true });

        // The headline's box depends on its length, so the drawing is fitted to the words.
        var write = $('[data-write]', moment);
        var fit = function () {
            var words = $('text', write);
            try {
                var box = words.getBBox();
                write.setAttribute('viewBox', [box.x - 12, box.y - 12, box.width + 24, box.height + 24].join(' '));
            } catch (e) { /* keeps its default box */ }
        };
        if (write) {
            fit();
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }
        }

        var closed = false;
        var closeMoment = function () {
            if (closed) { return; }
            closed = true;
            moment.classList.add('is-leaving');
            document.documentElement.style.overflow = '';
            document.removeEventListener('keydown', onMomentKey);
            setTimeout(function () { moment.remove(); }, 520);
        };
        var onMomentKey = function (event) {
            if (event.key === 'Escape') { closeMoment(); }
        };
        $('[data-moment-close]', moment).addEventListener('click', closeMoment);
        document.addEventListener('keydown', onMomentKey);
        setTimeout(closeMoment, seconds * 1000);
    }

    /* ---------- One-click task completion on the employee's page ---------- */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-task-done]');
        if (!button || button.classList.contains('is-done')) { return; }
        var row = button.closest('[data-task-row]');
        button.classList.add('is-done');
        post(button.dataset.url, { stage_id: button.dataset.stage }).then(function () {
            var rect = button.getBoundingClientRect();
            burst(rect.left + rect.width / 2, rect.top + rect.height / 2);
            toast(document.body.dataset.cheer);
            bumpFigure(0, -1);
            bumpFigure(2, 1);
            row.style.height = row.offsetHeight + 'px';
            row.classList.add('is-leaving');
            setTimeout(function () {
                var list = row.parentNode;
                row.remove();
                var clear = document.getElementById('edTasksClear');
                if (list && !list.children.length && clear) {
                    list.innerHTML = clear.innerHTML;
                    var box = list.getBoundingClientRect();
                    burst(box.left + box.width / 2, box.top + 40, 80);
                }
            }, 720);
        }).catch(function () {
            button.classList.remove('is-done');
            toast(document.body.dataset.moveFailed, 'error');
        });
    });
    function bumpFigure(index, by) {
        var strip = $('[data-my-figures]');
        var value = strip && strip.children[index] && $('.ed-figure__value', strip.children[index]);
        if (value) {
            var next = Math.max(0, parseInt(value.textContent.replace(/\D/g, '') || '0', 10) + by);
            value.textContent = next.toLocaleString('en-US');
        }
    }

    /* ---------- A word of thanks: a ready-made line fills the box ---------- */
    document.addEventListener('click', function (event) {
        var preset = event.target.closest('[data-kudos-preset]');
        if (!preset) { return; }
        var field = $('textarea[name="message"]', preset.closest('[data-kudos]'));
        if (field) { field.value = preset.textContent; field.focus(); }
    });

    /* ---------- Birthdays and work anniversaries: confetti once a day, not on every visit ---------- */
    var celebrating = $('[data-celebrate]');
    if (celebrating) {
        var celebrationKey = 'edCelebrated:' + celebrating.dataset.celebrate;
        var celebrationDay = new Date().toDateString();
        if (storageGet(celebrationKey) !== celebrationDay) {
            storageSet(celebrationKey, celebrationDay);
            setTimeout(function () {
                var heroBox = celebrating.getBoundingClientRect();
                burst(heroBox.left + heroBox.width / 2, heroBox.top + 60, 120);
            }, moment ? parseInt(moment.dataset.seconds || '10', 10) * 1000 + 300 : 500);
        }
    }

    /* A server-side action finished something worth a small cheer. */
    if (document.body.dataset.cheerNow) {
        setTimeout(function () {
            burst(window.innerWidth / 2, Math.min(220, window.innerHeight / 3), 70);
        }, 250);
    }
    var chip = $('[data-clockchip]');
    if (chip) {
        var chipOffset = parseInt(chip.dataset.serverEpoch, 10) * 1000 - Date.now();
        var chipSince = parseInt(chip.dataset.openSince, 10);
        var chipEl = $('b', chip);
        var chipTick = function () { chipEl.textContent = formatDuration((Date.now() + chipOffset) / 1000 - chipSince); };
        chipTick();
        setInterval(chipTick, 1000);
    }

    /* ---------- Kanban: move a card, confirm with the server, undo if refused ---------- */
    function post(url, payload) {
        var body = new URLSearchParams(payload);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) { throw response; }
            return response.json();
        });
    }
    var dragged = null;
    var cameFrom = null;
    var cameBefore = null;
    document.addEventListener('dragstart', function (event) {
        var card = event.target.closest && event.target.closest('.ed-card[draggable="true"]');
        if (!card) { return; }
        dragged = card;
        cameFrom = card.parentNode;
        cameBefore = card.nextSibling;
        card.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.id || '');
    });
    document.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('is-dragging'); }
        $$('.ed-col.is-over').forEach(function (col) { col.classList.remove('is-over'); });
    });
    document.addEventListener('dragover', function (event) {
        var column = event.target.closest && event.target.closest('[data-col]');
        if (!column || !dragged) { return; }
        event.preventDefault();
        $$('.ed-col.is-over').forEach(function (col) { if (col !== column) { col.classList.remove('is-over'); } });
        column.classList.add('is-over');
    });
    document.addEventListener('drop', function (event) {
        var column = event.target.closest && event.target.closest('[data-col]');
        if (!column || !dragged) { return; }
        event.preventDefault();
        column.classList.remove('is-over');
        var card = dragged;
        var list = $('[data-col-list]', column);
        if (card.parentNode === list) { return; }
        var source = cameFrom;
        var sourceNext = cameBefore;
        var sourceCol = source.closest('[data-col]');
        list.insertBefore(card, list.firstChild);
        bumpCount(sourceCol, -1);
        bumpCount(column, 1);
        var payload = {};
        payload[column.dataset.colField] = column.dataset.col;
        post(card.dataset.moveUrl, payload).then(function (data) {
            card.classList.add('is-landed');
            setTimeout(function () { card.classList.remove('is-landed'); }, 450);
            if (data.done) {
                var cardBox = card.getBoundingClientRect();
                burst(cardBox.left + cardBox.width / 2, cardBox.top + cardBox.height / 2);
                toast(document.body.dataset.cheer);
            }
            var badge = $('[data-card-badge]', card);
            if (badge && data.label) {
                badge.textContent = data.label;
                badge.className = 'ed-badge tone-' + data.tone;
            }
        }).catch(function () {
            source.insertBefore(card, sourceNext);
            bumpCount(sourceCol, 1);
            bumpCount(column, -1);
            toast(document.body.dataset.moveFailed, 'error');
        });
    });
    function bumpCount(column, by) {
        var count = column && $('[data-col-count]', column);
        if (count) { count.textContent = Math.max(0, parseInt(count.textContent, 10) + by); }
    }

    /* ---------- Auto-submit filters ---------- */
    $$('[data-autosubmit]').forEach(function (form) {
        $$('select, input[type="date"]', form).forEach(function (control) {
            control.addEventListener('change', function () { form.submit(); });
        });
    });

    /* ---------- Shared chart defaults ---------- */
    window.edChart = function (selector, options) {
        var host = document.querySelector(selector);
        if (!host || typeof ApexCharts === 'undefined') { return null; }
        var base = {
            chart: {
                fontFamily: '"Alan Sans", "Segoe UI", sans-serif',
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: { enabled: !reduced, speed: 420 },
                parentHeightOffset: 0
            },
            dataLabels: { enabled: false },
            grid: { borderColor: '#E2E8EE', strokeDashArray: 3, padding: { left: 8, right: 8 } },
            legend: { position: 'top', horizontalAlign: 'start', fontSize: '12.5px', markers: { size: 6 }, itemMargin: { horizontal: 10 } },
            tooltip: { theme: 'light' },
            xaxis: { axisBorder: { color: '#E2E8EE' }, axisTicks: { color: '#E2E8EE' }, labels: { style: { colors: '#6F8192', fontSize: '11.5px' } } },
            yaxis: { labels: { style: { colors: '#6F8192', fontSize: '11.5px' } } }
        };
        function merge(a, b) {
            Object.keys(b).forEach(function (key) {
                if (b[key] && typeof b[key] === 'object' && !Array.isArray(b[key]) && a[key]) { merge(a[key], b[key]); } else { a[key] = b[key]; }
            });
            return a;
        }
        var chart = new ApexCharts(host, merge(base, options));
        host.classList.remove('is-loading');
        chart.render();
        return chart;
    };

    /* ---------- Searchable lists ----------
       A long <select> gets a text box that filters as you type. The original select
       stays in the form, so submitting, old values and validation work as before.
       Arabic letter forms are folded, so «احمد» finds «أحمد» and «مؤسسة» finds «موسسه». */
    function fold(text) {
        return String(text || '').toLowerCase()
            .replace(/[ً-ْـ]/g, '')
            .replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/ؤ/g, 'و').replace(/ئ/g, 'ي')
            .replace(/\s+/g, ' ').trim();
    }
    $$('select[data-searchable]').forEach(function (select) {
        if (select.disabled || select.multiple) { return; }

        var wrap = document.createElement('div');
        wrap.className = 'ed-combo';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ed-input ed-combo__input' + (select.classList.contains('is-invalid') ? ' is-invalid' : '');
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.placeholder = select.dataset.searchPlaceholder || '';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-autocomplete', 'list');
        // The field's label points at this id, so it now focuses the search box.
        if (select.id) {
            input.id = select.id;
            select.id = select.id + '__native';
        }
        select.classList.add('ed-combo__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var list = document.createElement('ul');
        list.className = 'ed-combo__list';
        list.hidden = true;
        list.id = (input.id || select.name) + '__list';
        list.setAttribute('role', 'listbox');
        input.setAttribute('aria-controls', list.id);
        var caret = document.createElement('i');
        caret.className = 'ti ti-chevron-down ed-combo__caret';
        caret.setAttribute('aria-hidden', 'true');
        wrap.appendChild(input);
        wrap.appendChild(caret);
        wrap.appendChild(list);

        var options = Array.prototype.filter.call(select.options, function (o) { return o.value !== '' || !select.required; })
            .map(function (o) { return { value: o.value, text: o.text, key: fold(o.text) }; });
        var shown = [];
        var active = -1;

        function currentText() {
            var o = select.options[select.selectedIndex];
            return o && o.value !== '' ? o.text : '';
        }
        function mark(text, query) {
            var safe = escapeHtml(text);
            var q = query.trim();
            if (!q) { return safe; }
            var at = text.toLowerCase().indexOf(q.toLowerCase());
            if (at < 0) { return safe; }
            return escapeHtml(text.slice(0, at)) + '<mark>' + escapeHtml(text.slice(at, at + q.length)) + '</mark>' + escapeHtml(text.slice(at + q.length));
        }
        function paint() {
            $$('li', list).forEach(function (li, i) {
                li.classList.toggle('is-active', i === active);
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                if (i === active) { li.scrollIntoView({ block: 'nearest' }); }
            });
        }
        function render(query) {
            var q = fold(query);
            shown = options.filter(function (o) { return !q || o.key.indexOf(q) !== -1; });
            if (q) {
                // Names that start with what was typed come first.
                shown.sort(function (a, b) { return (a.key.indexOf(q) === 0 ? 0 : 1) - (b.key.indexOf(q) === 0 ? 0 : 1); });
            }
            shown = shown.slice(0, 100);
            if (!shown.length) {
                active = -1;
                list.innerHTML = '<li class="is-empty">' + escapeHtml(select.dataset.searchEmpty || '') + '</li>';
                return;
            }
            active = 0;
            shown.forEach(function (o, i) { if (!q && o.value === select.value) { active = i; } });
            list.innerHTML = shown.map(function (o, i) {
                return '<li role="option" data-i="' + i + '"' + (o.value === select.value ? ' class="is-selected"' : '') + '>' + mark(o.text, query) + '</li>';
            }).join('');
            paint();
        }
        function open() {
            render('');
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.value = currentText();
        }
        function choose(i) {
            var o = shown[i];
            if (!o) { return; }
            select.value = o.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            input.classList.remove('is-invalid');
            close();
        }

        input.value = currentText();
        input.addEventListener('focus', function () { open(); input.select(); });
        input.addEventListener('click', function () { if (list.hidden) { open(); } });
        input.addEventListener('input', function () {
            render(input.value);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(); return; }
                if (!shown.length) { return; }
                active = (active + (event.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
                paint();
            } else if (event.key === 'Enter') {
                if (!list.hidden && active >= 0) {
                    event.preventDefault();
                    choose(active);
                }
            } else if (event.key === 'Escape') {
                if (!list.hidden) { event.stopPropagation(); close(); }
            } else if (event.key === 'Tab') {
                close();
            }
        });
        list.addEventListener('mousedown', function (event) {
            event.preventDefault();
            var li = event.target.closest('li[data-i]');
            if (li) { choose(parseInt(li.dataset.i, 10)); }
        });
        input.addEventListener('blur', function () { setTimeout(close, 120); });
        // A rejected submit shows its message on the visible box.
        select.addEventListener('invalid', function () { input.classList.add('is-invalid'); input.focus(); });
    });

    /* ---------- Flash messages from the server ---------- */
    (window.edFlash || []).forEach(function (flash) { toast(flash.text, flash.type); });
})();

/* ---------- Task timer: a live counter from the start time ---------- */
(function () {
    var clocks = document.querySelectorAll('[data-timer-since]');
    if (!clocks.length) { return; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tick() {
        var now = Math.floor(Date.now() / 1000);
        clocks.forEach(function (el) {
            var s = Math.max(0, now - parseInt(el.dataset.timerSince, 10));
            el.textContent = pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
        });
    }
    tick();
    setInterval(tick, 1000);
})();

/* ---------- Day plan: at most three tasks ---------- */
(function () {
    var form = document.querySelector('[data-plan-form]');
    if (!form) { return; }
    var picks = form.querySelectorAll('[data-plan-pick]');
    function sync() {
        var count = form.querySelectorAll('[data-plan-pick]:checked').length;
        picks.forEach(function (box) {
            box.disabled = !box.checked && count >= 3;
            box.closest('.ed-chip').style.opacity = box.disabled ? '.45' : '';
        });
    }
    picks.forEach(function (box) { box.addEventListener('change', sync); });
    sync();
})();
