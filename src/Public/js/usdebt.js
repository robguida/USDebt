/*
 * Our U.S. Debt - page script.
 *
 * Loaded as a separate file (no inline scripts), so the site can run under a
 * strict Content Security Policy. Chart data comes from the
 * <script type="application/json" id="chart-data"> block on the page.
 * Colors come from the CSS variables in css/usdebt.css, so dark mode and
 * any later color changes need no edits here.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    var root = document.documentElement;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var chart = null;

    /* ---------- helpers ---------- */

    function cssVar(name, fallback) {
        var value = getComputedStyle(root).getPropertyValue(name).trim();
        return value || fallback;
    }

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    var money = new Intl.NumberFormat('en-US', {
        style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    /* 38.123 -> "$38.12T"; signed adds "+" for a rise */
    function trillions(value, signed) {
        var text = money.format(value) + 'T';
        return signed && value > 0 ? '+' + text : text;
    }

    function isoToMs(iso) {
        var p = String(iso).split('-');
        return Date.UTC(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
    }

    var fullDate = new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' });
    var monthYear = new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'short', timeZone: 'UTC' });
    var monthDay = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });

    function baseOptions() {
        var text = cssVar('--muted', '#526077');
        var grid = cssVar('--grid', 'rgba(0,0,0,0.08)');
        return {
            text: text,
            grid: grid,
            zero: cssVar('--zero-line', 'rgba(0,0,0,0.45)'),
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduceMotion ? false : { duration: 400 },
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: cssVar('--text', '#0f172a'), usePointStyle: true, boxHeight: 8, padding: 14 }
                    },
                    tooltip: {
                        backgroundColor: cssVar('--surface', '#fff'),
                        titleColor: cssVar('--text', '#0f172a'),
                        bodyColor: cssVar('--text', '#0f172a'),
                        borderColor: cssVar('--border', '#dde3ec'),
                        borderWidth: 1,
                        padding: 10,
                        usePointStyle: true
                    }
                }
            }
        };
    }

    /* Line style per series key. "trend" is reserved for the trend line (red). */
    function seriesStyle(key, index) {
        if (key === 'debt') {
            return { color: cssVar('--accent', '#1e3a8a'), fill: cssVar('--accent-soft', 'rgba(30,58,138,0.1)') };
        }
        if (key === 'trend') {
            return { color: cssVar('--trend', '#c62828'), fill: 'transparent' };
        }
        var color = cssVar('--series-' + ((index % 6) + 1), '#475569');
        return { color: color, fill: 'transparent' };
    }

    /* ---------- date-range chart ---------- */

    function dailyConfig(data) {
        var base = baseOptions();
        var datasets = [];
        var minX = Infinity;
        var maxX = -Infinity;
        data.series.forEach(function (s, i) {
            try {
                var style = seriesStyle(s.key, i);
                var points = s.points.map(function (p) {
                    return { x: isoToMs(p[0]), y: p[1] };
                });
                points.forEach(function (pt) {
                    if (pt.x < minX) { minX = pt.x; }
                    if (pt.x > maxX) { maxX = pt.x; }
                });
                datasets.push({
                    label: s.label,
                    data: points,
                    borderColor: style.color,
                    backgroundColor: style.fill,
                    fill: s.key === 'debt' ? 'start' : false,
                    borderWidth: 2,
                    pointRadius: points.length === 1 ? 4 : 0,
                    pointHoverRadius: 4,
                    tension: 0
                });
            } catch (e) {
                /* one bad series must not stop the others */
                if (window.console) { console.warn('UsDebt: skipped a chart series', e); }
            }
        });

        var spanYears = (maxX - minX) / (365.25 * 864e5);
        var tickDate = spanYears > 2 ? monthYear : monthDay;
        var opts = base.options;
        opts.parsing = false;
        opts.plugins.decimation = { enabled: true, algorithm: 'lttb', samples: 600 };
        opts.plugins.legend.display = datasets.length > 1;
        opts.plugins.tooltip.callbacks = {
            title: function (items) { return items.length ? fullDate.format(items[0].parsed.x) : ''; },
            label: function (item) { return ' ' + item.dataset.label.replace(/ \(trillions USD\)$/, '') + ': ' + trillions(item.parsed.y); }
        };
        opts.scales = {
            x: {
                type: 'linear',
                min: minX,
                max: maxX,
                grid: { color: base.grid },
                ticks: {
                    color: base.text,
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: window.innerWidth < 640 ? 4 : 8,
                    callback: function (value) { return tickDate.format(value); }
                }
            },
            y: {
                grid: { color: base.grid },
                ticks: { color: base.text, callback: function (value) { return trillions(value); } }
            }
        };
        return { type: 'line', data: { datasets: datasets }, options: opts };
    }

    /* ---------- compare chart ---------- */

    function compareConfig(data) {
        var base = baseOptions();
        var datasets = [];
        data.series.forEach(function (s, i) {
            try {
                var style = seriesStyle(s.key, i);
                var starts = Array.isArray(s.term_starts) ? s.term_starts : [];
                datasets.push({
                    label: s.label,
                    data: s.points.map(function (p) { return { x: p[0], y: p[1] }; }),
                    termStarts: starts,
                    borderColor: style.color,
                    backgroundColor: style.color,
                    borderWidth: 2,
                    tension: 0,
                    /* a diamond marks where a second, non-consecutive term begins */
                    pointStyle: function (ctx) { return ctx.raw && starts.indexOf(ctx.raw.x) !== -1 ? 'rectRot' : 'circle'; },
                    pointRadius: function (ctx) { return ctx.raw && starts.indexOf(ctx.raw.x) !== -1 ? 7 : 0; },
                    pointHoverRadius: 5
                });
            } catch (e) {
                if (window.console) { console.warn('UsDebt: skipped a chart series', e); }
            }
        });

        var opts = base.options;
        opts.plugins.legend.labels.generateLabels = function (c) {
            var labels = Chart.defaults.plugins.legend.labels.generateLabels(c);
            labels.forEach(function (l) { l.pointStyle = 'circle'; });
            return labels;
        };
        opts.plugins.tooltip.callbacks = {
            title: function (items) { return items.length ? 'Month ' + items[0].parsed.x + ' in office' : ''; },
            label: function (item) {
                var note = item.dataset.termStarts.indexOf(item.parsed.x) !== -1 ? ' (second term begins)' : '';
                return ' ' + item.dataset.label + ': ' + trillions(item.parsed.y, true) + note;
            }
        };
        opts.scales = {
            x: {
                type: 'linear',
                min: 1,
                title: { display: true, text: 'Months in office', color: base.text },
                grid: { color: base.grid },
                ticks: { color: base.text, stepSize: 12, precision: 0 }
            },
            y: {
                title: { display: true, text: 'Change since taking office', color: base.text },
                grid: {
                    color: function (ctx) { return ctx.tick && ctx.tick.value === 0 ? base.zero : base.grid; }
                },
                ticks: { color: base.text, callback: function (value) { return trillions(value, true); } }
            }
        };
        return { type: 'line', data: { datasets: datasets }, options: opts };
    }

    /* ---------- draw ---------- */

    function drawChart() {
        var canvas = document.getElementById('debt_chart');
        var data = readJson('chart-data');
        if (!canvas || !data || !Array.isArray(data.series) || typeof window.Chart === 'undefined') {
            return;
        }
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        if (chart) {
            chart.destroy();
        }
        var config = data.type === 'compare' ? compareConfig(data) : dailyConfig(data);
        chart = new Chart(canvas, config);
    }

    /* ---------- Chart / Stats / Table switch ---------- */

    function initSwitch() {
        var list = document.querySelector('.segmented[role="tablist"]');
        if (!list) {
            return;
        }
        var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));

        function select(tab, focus) {
            tabs.forEach(function (t) {
                var on = t === tab;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                var panel = document.getElementById(t.getAttribute('aria-controls'));
                if (panel) {
                    panel.hidden = !on;
                }
            });
            if (focus) {
                tab.focus();
            }
            if (chart && tab.getAttribute('aria-controls') === 'graph_tab') {
                chart.resize();
            }
        }

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { select(tab, false); });
            tab.addEventListener('keydown', function (e) {
                var next = null;
                if (e.key === 'ArrowRight') { next = tabs[(i + 1) % tabs.length]; }
                if (e.key === 'ArrowLeft') { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
                if (e.key === 'Home') { next = tabs[0]; }
                if (e.key === 'End') { next = tabs[tabs.length - 1]; }
                if (next) {
                    e.preventDefault();
                    select(next, true);
                }
            });
        });

        var current = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0];
        select(current, false);
    }

    /* ---------- "Select all" for the term strip ---------- */

    function initSelectAll() {
        var button = document.getElementById('select_all');
        var form = document.getElementById('compare');
        if (!button || !form) {
            return;
        }
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"]'));
        function allChecked() {
            return boxes.length > 0 && boxes.every(function (b) { return b.checked; });
        }
        function refresh() {
            button.textContent = allChecked() ? 'Clear all' : 'Select all';
        }
        button.hidden = false;
        button.addEventListener('click', function () {
            var target = !allChecked();
            boxes.forEach(function (b) { b.checked = target; });
            refresh();
        });
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        refresh();
    }

    function init() {
        initSwitch();
        initSelectAll();
        drawChart();
        if (window.matchMedia) {
            var dark = window.matchMedia('(prefers-color-scheme: dark)');
            var redraw = function () { drawChart(); };
            if (dark.addEventListener) {
                dark.addEventListener('change', redraw);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
