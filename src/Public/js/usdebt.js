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

    /*
     * Trend line switch. On by default. The URL (?trend=0) wins, so a shared
     * link opens the same view; otherwise the visitor's last choice, saved in
     * this browser. Saving can fail (private window, blocked storage): the
     * switch still works, it is just not remembered.
     */
    var TREND_STORAGE_KEY = 'usdebt.trend';
    var trendOn = true;
    var chartType = '';
    /* date-range chart only: the x-axis with and without the projection days */
    var dailyAxes = null;
    var axisState = null;

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

    /* A trend line is straight, so its first and last points draw all of it. */
    function ends(points) {
        return points.length > 2 ? [points[0], points[points.length - 1]] : points;
    }

    var DAY_MS = 864e5;
    /* up to this many days on the x-axis, ticks fall on whole days (no date shown twice) */
    var SHORT_SPAN_DAYS = 62;
    var TREND_DASH = [6, 4];
    var LEGEND_DASH = [3, 2];

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
                        labels: { color: cssVar('--text', '#0f172a'), usePointStyle: true, boxHeight: 8, padding: 14 },
                        /* a trend entry in the legend works the same as the Trend line button */
                        onClick: function (e, item, legend) {
                            var ds = legend.chart.data.datasets[item.datasetIndex];
                            if (item.trendNote || (ds && ds.trendLine)) {
                                setTrend(!trendOn, true);
                                return;
                            }
                            Chart.defaults.plugins.legend.onClick.call(this, e, item, legend);
                        }
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

    /* Line style per series key. Red (--trend) is reserved for the trend line and its projection. */
    function seriesStyle(key, index) {
        if (key === 'debt') {
            return { color: cssVar('--accent', '#1e3a8a'), fill: cssVar('--accent-soft', 'rgba(30,58,138,0.1)') };
        }
        if (key === 'trend' || key === 'projection') {
            return { color: cssVar('--trend', '#c62828'), fill: 'transparent' };
        }
        var color = cssVar('--series-' + ((index % 6) + 1), '#475569');
        return { color: color, fill: 'transparent' };
    }

    /* ---------- date-range chart ---------- */

    /*
     * Fit the x-axis to [minX, maxX]. A one-day range has min == max, which
     * Chart.js cannot draw, so pad it by a day on each side. With no points
     * at all (min/max still +-Infinity) leave the axis to Chart.js.
     */
    function dailyAxis(minX, maxX) {
        if (!(isFinite(minX) && isFinite(maxX))) {
            return { tickDate: monthDay };
        }
        var x;
        if (maxX > minX) {
            x = { min: minX, max: maxX };
        } else {
            x = { min: minX - DAY_MS, max: maxX + DAY_MS };
        }
        x.maxTicks = window.innerWidth < 640 ? 4 : 8;
        var spanDays = Math.round((x.max - x.min) / DAY_MS);
        if (spanDays <= SHORT_SPAN_DAYS) {
            /* whole-day steps: a 3-day range shows 3 dates, not "Nov 9" three times */
            x.stepSize = DAY_MS * Math.max(1, Math.ceil(spanDays / (x.maxTicks - 1)));
        }
        var spanYears = spanDays / 365.25;
        var crossesYear = new Date(x.min).getUTCFullYear() !== new Date(x.max).getUTCFullYear();
        /* long ranges: "Nov 2026"; a range across New Year (1Y, or Dec-Jan): "Nov 9, 2026"; else "Nov 9" */
        x.tickDate = spanYears > 2 ? monthYear : (crossesYear ? fullDate : monthDay);
        return x;
    }

    function dailyConfig(data) {
        var base = baseOptions();
        var datasets = [];
        var dataMin = Infinity;
        var dataMax = -Infinity;
        var allMin = Infinity;
        var allMax = -Infinity;
        var lastTrendPoint = null;
        data.series.forEach(function (s, i) {
            try {
                var isTrend = s.key === 'trend';
                var isProjection = s.key === 'projection' || s.projection === true;
                var style = seriesStyle(isProjection ? 'projection' : s.key, i);
                var points = s.points.map(function (p) {
                    return { x: isoToMs(p[0]), y: p[1] };
                });
                points.forEach(function (pt) {
                    if (pt.x < allMin) { allMin = pt.x; }
                    if (pt.x > allMax) { allMax = pt.x; }
                    if (!isTrend && !isProjection) {
                        if (pt.x < dataMin) { dataMin = pt.x; }
                        if (pt.x > dataMax) { dataMax = pt.x; }
                    }
                });
                if (isTrend || isProjection) {
                    if (isTrend && points.length) {
                        lastTrendPoint = points[points.length - 1];
                    }
                    /* the projection starts the day after the last data day: join it to the trend line */
                    if (isProjection && lastTrendPoint) {
                        points.unshift(lastTrendPoint);
                    }
                    datasets.push({
                        label: s.label,
                        data: ends(points),
                        trendLine: true,
                        hidden: !trendOn,
                        borderColor: style.color,
                        backgroundColor: style.color,
                        borderDash: isProjection ? TREND_DASH : [],
                        fill: false,
                        borderWidth: 2,
                        pointStyle: 'line',
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        tension: 0
                    });
                    return;
                }
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

        dailyAxes = { on: dailyAxis(allMin, allMax), off: dailyAxis(dataMin, dataMax) };
        axisState = trendOn ? dailyAxes.on : dailyAxes.off;
        var x = axisState;
        var opts = base.options;
        opts.parsing = false;
        opts.plugins.decimation = { enabled: true, algorithm: 'lttb', samples: 600 };
        opts.plugins.legend.display = visibleCount(datasets) > 1;
        opts.plugins.legend.labels.filter = function (item, chartData) {
            var ds = chartData.datasets[item.datasetIndex];
            return !(ds && ds.trendLine && !trendOn);
        };
        opts.plugins.legend.labels.generateLabels = function (c) {
            var labels = Chart.defaults.plugins.legend.labels.generateLabels(c);
            labels.forEach(function (l) {
                /*
                 * The projection's swatch is dashed too, so it is not told apart
                 * by color alone. Chart.js takes swatch styles from the points,
                 * which have no dash, so set it from the line here.
                 */
                var ds = c.data.datasets[l.datasetIndex];
                if (ds && ds.borderDash && ds.borderDash.length) { l.lineDash = LEGEND_DASH; }
            });
            return labels;
        };
        opts.plugins.tooltip.callbacks = {
            title: function (items) { return items.length ? fullDate.format(items[0].parsed.x) : ''; },
            label: function (item) { return ' ' + item.dataset.label.replace(/ \(trillions USD\)$/, '') + ': ' + trillions(item.parsed.y); }
        };
        opts.scales = {
            x: {
                type: 'linear',
                min: x.min,
                max: x.max,
                grid: { color: base.grid },
                /*
                 * Short ranges: one tick per step of whole days, built here.
                 * Chart.js would split the range evenly instead, which can put
                 * two ticks on the same day ("Nov 9, Nov 9").
                 */
                afterBuildTicks: function (scale) {
                    if (!axisState.stepSize) {
                        return;
                    }
                    var ticks = [];
                    for (var v = axisState.min; v <= axisState.max; v += axisState.stepSize) {
                        ticks.push({ value: v });
                    }
                    scale.ticks = ticks;
                },
                ticks: {
                    color: base.text,
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: x.maxTicks,
                    callback: function (value) { return axisState.tickDate.format(value); }
                }
            },
            y: {
                grid: { color: base.grid },
                ticks: { color: base.text, callback: function (value) { return trillions(value); } }
            }
        };
        return { type: 'line', data: { datasets: datasets }, options: opts };
    }

    function visibleCount(datasets) {
        return datasets.filter(function (ds) { return !ds.trendLine || trendOn; }).length;
    }

    /* ---------- compare chart ---------- */

    function compareConfig(data) {
        var base = baseOptions();
        var datasets = [];
        data.series.forEach(function (s, i) {
            try {
                var style = seriesStyle(s.key, i);
                var starts = Array.isArray(s.term_starts) ? s.term_starts : [];
                var points = s.points.map(function (p) { return { x: p[0], y: p[1] }; });
                /* a line with one point has nothing to connect, so show the point */
                var plainRadius = points.length === 1 ? 4 : 0;
                datasets.push({
                    label: s.label,
                    data: points,
                    termStarts: starts,
                    borderColor: style.color,
                    backgroundColor: style.color,
                    borderWidth: 2,
                    tension: 0,
                    /* a diamond marks where a second, non-consecutive term begins */
                    pointStyle: function (ctx) { return ctx.raw && starts.indexOf(ctx.raw.x) !== -1 ? 'rectRot' : 'circle'; },
                    pointRadius: function (ctx) { return ctx.raw && starts.indexOf(ctx.raw.x) !== -1 ? 7 : plainRadius; },
                    pointHoverRadius: 5
                });
                /* this term's own trend: dashed, in the term's color (red is only for the date-range trend) */
                if (s.trend && Array.isArray(s.trend.points) && s.trend.points.length > 1) {
                    datasets.push({
                        label: 'Trend: ' + s.label,
                        data: ends(s.trend.points.map(function (p) { return { x: p[0], y: p[1] }; })),
                        termStarts: [],
                        trendLine: true,
                        hidden: !trendOn,
                        borderColor: style.color,
                        backgroundColor: style.color,
                        borderDash: TREND_DASH,
                        borderWidth: 2,
                        tension: 0,
                        pointStyle: 'line',
                        pointRadius: 0,
                        pointHoverRadius: 4
                    });
                }
            } catch (e) {
                if (window.console) { console.warn('UsDebt: skipped a chart series', e); }
            }
        });

        var hasTrend = datasets.some(function (ds) { return ds.trendLine; });
        var opts = base.options;
        opts.plugins.legend.labels.generateLabels = function (c) {
            var labels = Chart.defaults.plugins.legend.labels.generateLabels(c).filter(function (l) {
                var ds = c.data.datasets[l.datasetIndex];
                return !(ds && ds.trendLine);
            });
            labels.forEach(function (l) { l.pointStyle = 'circle'; });
            /* one legend entry explains every dashed line */
            if (hasTrend && trendOn) {
                labels.push({
                    text: 'Dashed line: trend (straight line)',
                    trendNote: true,
                    fillStyle: 'transparent',
                    strokeStyle: base.text,
                    fontColor: cssVar('--text', '#0f172a'),
                    lineWidth: 2,
                    lineDash: LEGEND_DASH,
                    pointStyle: 'line',
                    hidden: false
                });
            }
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
        dailyAxes = null;
        var config = data.type === 'compare' ? compareConfig(data) : dailyConfig(data);
        chart = new Chart(canvas, config);
    }

    /* Show or hide the trend lines on the chart that is already drawn (no reload). */
    function applyTrendToChart() {
        if (!chart) {
            return;
        }
        chart.data.datasets.forEach(function (ds, i) {
            if (ds.trendLine) {
                chart.setDatasetVisibility(i, trendOn);
            }
        });
        if (dailyAxes) {
            /* without the projection, the x-axis ends on the last reported day again */
            axisState = trendOn ? dailyAxes.on : dailyAxes.off;
            var x = chart.options.scales.x;
            x.min = axisState.min;
            x.max = axisState.max;
            x.ticks.maxTicksLimit = axisState.maxTicks;
            chart.options.plugins.legend.display = visibleCount(chart.data.datasets) > 1;
        }
        chart.update();
    }

    /* ---------- trend line switch ---------- */

    /* href or action with trend=0 added (off) or removed (on), as a same-site path */
    function withTrend(href, on) {
        var url = new URL(href, window.location.href);
        if (on) {
            url.searchParams.delete('trend');
        } else {
            url.searchParams.set('trend', '0');
        }
        return url.pathname + url.search + url.hash;
    }

    function initialTrend() {
        var param = null;
        try {
            param = new URLSearchParams(window.location.search).get('trend');
        } catch (e) {
            param = null;
        }
        if (param !== null) {
            return param !== '0';
        }
        try {
            return window.localStorage.getItem(TREND_STORAGE_KEY) !== '0';
        } catch (e) {
            return true;
        }
    }

    function setTrend(on, remember) {
        trendOn = on;

        var button = document.getElementById('trend_toggle');
        if (button) {
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            var state = button.querySelector('.toggle-state');
            if (state) {
                state.textContent = ': ' + (on ? 'On' : 'Off');
            }
        }
        Array.prototype.forEach.call(document.querySelectorAll('.trend-off-note'), function (note) {
            note.hidden = on;
        });

        /* links and forms keep the choice, so the next page opens the same way */
        var field = document.getElementById('trend_field');
        if (field) {
            field.disabled = on;
        }
        Array.prototype.forEach.call(document.querySelectorAll('[data-trend-link]'), function (el) {
            try {
                var attr = el.tagName === 'FORM' ? 'action' : 'href';
                el.setAttribute(attr, withTrend(el.getAttribute(attr) || './', on));
            } catch (e) {
                /* one odd link must not stop the rest */
                if (window.console) { console.warn('UsDebt: could not update a link', e); }
            }
        });

        /*
         * The address bar too, so a copied link opens the same view. Not on
         * Compare: that page comes from a form post, and changing its address
         * would make a reload show a different page.
         */
        if (chartType !== 'compare' && window.history && window.history.replaceState) {
            try {
                var here = window.location.pathname + window.location.search + window.location.hash;
                var next = withTrend(window.location.href, on);
                if (next !== here) {
                    window.history.replaceState(window.history.state, '', next);
                }
            } catch (e) {
                if (window.console) { console.warn('UsDebt: could not update the address', e); }
            }
        }

        if (remember) {
            try {
                window.localStorage.setItem(TREND_STORAGE_KEY, on ? '1' : '0');
            } catch (e) {
                /* storage blocked: the switch still works for this page */
            }
        }

        applyTrendToChart();
    }

    function initTrendToggle() {
        var data = readJson('chart-data');
        chartType = data && typeof data.type === 'string' ? data.type : '';
        /* sets links, notes and the address before the chart is drawn */
        setTrend(initialTrend(), false);
    }

    function showTrendButton() {
        var button = document.getElementById('trend_toggle');
        if (!button || !chart) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', function () {
            setTrend(!trendOn, true);
        });
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
        initTrendToggle();
        drawChart();
        showTrendButton();
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
