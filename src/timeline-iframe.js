/**
 * TeamHub — standalone visual timeline (iframe page).
 *
 * Loaded via \OCP\Util::addScript('teamhub', 'timeline') from templates/timeline.php
 * — NOT inlined in the PHP template. Nextcloud's default Content-Security-Policy is
 * `script-src 'nonce-xxx'` with no 'unsafe-inline'; any inline <script> tag is silently
 * blocked with no console-visible explanation beyond a CSP violation log entry. External
 * scripts registered via Util::addScript() are printed with the per-request CSP nonce by
 * NC's own template engine, so they execute correctly. Do not move this back inline.
 *
 * This script owns ONLY the canvas rendering — proportional date axis, day markers,
 * the "today" line, and event chips. All interactive controls (period navigation,
 * view-mode selector, source filter toggles) live in the parent page's AppEmbed bar
 * (see TeamView.vue timelineEmbedActions/Selects/Toggles) and are NOT duplicated here.
 * Every control interaction in the parent triggers a full iframe reload with updated
 * URL query params (view, from, sources) — this script reads those params once on load
 * and renders accordingly. There is no postMessage channel and none is needed.
 */
(function () {
    'use strict';

    var root = document.getElementById('root');
    if (!root) {
        return; // error state already rendered by the PHP template
    }

    var TEAM_ID  = root.dataset.teamId || '';
    var API_BASE = root.dataset.apiBase || '';

    var params    = new URLSearchParams(window.location.search);
    var viewMode  = params.get('view') || '1W';
    var fromParam = parseInt(params.get('from'), 10);
    var sourcesParam = (params.get('sources') || 'calendar,decisions,deck').split(',').filter(Boolean);
    var showSrc = {
        calendar:  sourcesParam.indexOf('calendar')  !== -1,
        decisions: sourcesParam.indexOf('decisions') !== -1,
        deck:      sourcesParam.indexOf('deck')       !== -1,
    };

    function startOfWeek(d) {
        var r = new Date(d); r.setHours(0, 0, 0, 0);
        r.setDate(r.getDate() - ((r.getDay() + 6) % 7)); // Monday
        return r;
    }
    function addPeriod(d, mode) {
        var r = new Date(d);
        if (mode === '1W') r.setDate(r.getDate() + 7);
        else if (mode === '1M') r.setMonth(r.getMonth() + 1);
        else if (mode === '3M') r.setMonth(r.getMonth() + 3);
        else r.setMonth(r.getMonth() + 6);
        return r;
    }

    var windowStart = isNaN(fromParam) ? startOfWeek(new Date()) : new Date(fromParam * 1000);

    // ── Layout constants (pixels per day per view mode) ───────────────
    var PX_PER_DAY = { '1W': 90, '1M': 44, '3M': 18, '6M': 10 };
    var CHIP_H = 24; // minimum vertical gap between chips, must match canvas math

    var allEvents = [];

    function fmtDate(d, opts) { return new Intl.DateTimeFormat(undefined, opts).format(d); }

    async function fetchAndRender() {
        var from = Math.floor(windowStart.getTime() / 1000);
        var to   = Math.floor(addPeriod(windowStart, viewMode).getTime() / 1000);

        var overlay = document.getElementById('overlay');
        overlay.textContent = 'Loading…';
        overlay.style.display = '';

        try {
            var url = API_BASE + '/apps/teamhub/api/v1/teams/' + TEAM_ID + '/timeline?from=' + from + '&to=' + to;
            var res = await fetch(url, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var data = await res.json();
            allEvents = Array.isArray(data && data.events) ? data.events : [];
        } catch (err) {
            console.error('[TeamHub][Timeline]', err);
            overlay.textContent = 'Could not load timeline: ' + err.message;
            overlay.style.display = '';
            return;
        }
        overlay.style.display = 'none';
        renderCanvas(from, to);
    }

    function renderCanvas(from, to) {
        var canvas  = document.getElementById('canvas');
        var totalS  = to - from;
        var totalPx = Math.max(400, ((to - from) / 86400) * PX_PER_DAY[viewMode]);

        canvas.innerHTML = '<div class="axis"></div>';
        canvas.style.height = (totalPx + 60) + 'px';

        var pxPerS = totalPx / totalS;
        function yOf(ts) { return Math.max(0, (ts - from) * pxPerS); }

        // ── Date markers ──────────────────────────────────────────────
        var DAY = 86400;
        var markerDays = (viewMode === '1W' || viewMode === '1M') ? 1 : 7;

        var d = new Date(windowStart);
        while (d.getTime() / 1000 < to + DAY) {
            var ts = d.getTime() / 1000;
            if (ts < from) { d.setDate(d.getDate() + markerDays); continue; }
            var y = yOf(ts);
            var dow = d.getDay();
            var isWeekStart = dow === 1;
            var isMonthStart = d.getDate() === 1;
            var isMajor = isMonthStart || (markerDays >= 7 && isWeekStart);

            var el = document.createElement('div');
            el.className = 'dmark' + (isMajor ? ' dmark--major' : ' dmark--minor');
            el.style.top = y + 'px';

            var labelText;
            if (viewMode === '1W') {
                labelText = fmtDate(d, { weekday: 'short', day: 'numeric' });
            } else if (viewMode === '1M') {
                labelText = isMajor
                    ? fmtDate(d, { month: 'short', day: 'numeric' })
                    : fmtDate(d, { day: 'numeric' });
            } else {
                labelText = fmtDate(d, { month: 'short', day: 'numeric' });
            }

            el.innerHTML = '<span class="dmark__label">' + esc(labelText) + '</span><div class="dmark__rule"></div>';
            canvas.appendChild(el);

            d.setDate(d.getDate() + markerDays);
        }

        // ── Today marker ──────────────────────────────────────────────
        var nowTs = Date.now() / 1000;
        if (nowTs >= from && nowTs <= to) {
            var y2 = yOf(nowTs);
            var todayEl = document.createElement('div');
            todayEl.className = 'today-line';
            todayEl.style.top = y2 + 'px';
            todayEl.innerHTML = '<span class="today-line__label">Today</span><div class="today-line__rule"></div>';
            canvas.appendChild(todayEl);
        }

        // ── Events ────────────────────────────────────────────────────
        var filtered = allEvents.filter(function (ev) { return showSrc[ev.source]; });

        var placed = filtered.map(function (ev) {
            var ts = Math.max(from, Math.min(to, new Date(ev.date).getTime() / 1000));
            return { ev: ev, y: yOf(ts) };
        });

        placed.sort(function (a, b) { return a.y - b.y; });
        for (var i = 1; i < placed.length; i++) {
            if (placed[i].y < placed[i - 1].y + CHIP_H) {
                placed[i].y = placed[i - 1].y + CHIP_H;
            }
        }

        placed.forEach(function (p) {
            var chip = buildChip(p.ev);
            chip.style.top = p.y + 'px';
            canvas.appendChild(chip);
        });
    }

    function buildChip(ev) {
        var cls = 'echip';
        if (ev.source === 'calendar') {
            cls += ' echip--calendar';
        } else if (ev.source === 'decisions') {
            var map = { proposed: 'd-proposed', decided: 'd-decided', withdrawn: 'd-withdrawn' };
            cls += ' echip--' + (map[ev.type] || 'd-proposed');
        } else if (ev.source === 'deck') {
            cls += ' echip--' + (ev.type === 'due' ? 'deck-due' : 'deck-created');
            if (ev.meta && ev.meta.overdue) cls += ' echip--overdue';
        }

        var badgeText = {
            event: 'Event', proposed: 'Proposed', decided: 'Decided',
            withdrawn: 'Withdrawn', created: 'Created', due: 'Due',
        }[ev.type] || ev.type;

        var timeLabel = '';
        if (!ev.allDay) {
            timeLabel = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(ev.date));
        }

        var overdueHtml = (ev.source === 'deck' && ev.type === 'due' && ev.meta && ev.meta.overdue)
            ? '<span class="echip__overdue">⚠</span>'
            : '';

        var contextText = (ev.meta && (ev.meta.calendarName || ev.meta.boardName)) || '';
        var contextHtml = contextText
            ? '<span class="echip__time" title="' + esc(contextText) + '">' + esc(trunc(contextText, 14)) + '</span>'
            : '';

        var html =
            '<span class="echip__dot"></span>' +
            '<span class="echip__title" title="' + esc(ev.title) + '">' + esc(ev.title) + '</span>' +
            (timeLabel ? '<span class="echip__time">' + esc(timeLabel) + '</span>' : '') +
            contextHtml +
            overdueHtml +
            '<span class="echip__badge">' + esc(badgeText) + '</span>';

        if (ev.url) {
            var a = document.createElement('a');
            a.className = cls + ' echip--url';
            a.href = ev.url;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.title = ev.title;
            a.innerHTML = html;
            return a;
        }

        var div = document.createElement('div');
        div.className = cls;
        div.title = ev.title;
        div.innerHTML = html;
        return div;
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function trunc(s, n) { return s.length > n ? s.slice(0, n) + '…' : s; }

    fetchAndRender();
})();
