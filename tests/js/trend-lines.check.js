// Checks how src/Public/js/usdebt.js draws the date-range trend lines, in Node
// (no browser needed). The whole page script runs against a small stand-in
// page and a stand-in Chart that records what it is given.
// Run by ViewRenderTest when Node is installed; also runnable by hand:
//   node tests/js/trend-lines.check.js
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const file = path.join(__dirname, '..', '..', 'src', 'Public', 'js', 'usdebt.js');
const source = fs.readFileSync(file, 'utf8');

const failures = [];
function check(ok, what) {
    if (!ok) {
        failures.push(what);
    }
}

/* chart data shaped like ChartData::daily(): series order debt, trend, projection, curve, curve_projection */
const chartData = {
    type: 'daily',
    series: [
        { key: 'debt', label: 'Total debt (trillions USD)', points: [['2026-01-01', 38.0], ['2026-01-02', 38.1], ['2026-01-05', 38.3], ['2026-01-06', 38.35]] },
        { key: 'trend', label: 'Trend (straight line)', slope_per_day: 0.07, r2: 0.99, points: [['2026-01-01', 38.0], ['2026-01-02', 38.07], ['2026-01-05', 38.28], ['2026-01-06', 38.35]] },
        { key: 'projection', label: 'Projection if the trend continues', projection: true, points: [['2026-01-07', 38.42], ['2026-01-08', 38.49], ['2026-01-09', 38.56]] },
        { key: 'curve', label: 'Trend (growth curve)', growth_pct_per_year: 8.0, r2: 0.99, points: [['2026-01-01', 38.01], ['2026-01-02', 38.08], ['2026-01-05', 38.27], ['2026-01-06', 38.4]] },
        { key: 'curve_projection', label: 'Growth projection', projection: true, points: [['2026-01-07', 38.5], ['2026-01-08', 38.6], ['2026-01-09', 38.7]] }
    ]
};

function run(trendParam) {
    const listeners = {};
    const visibility = [];
    let made = null;
    const button = {
        hidden: true,
        attrs: {},
        setAttribute(name, value) { this.attrs[name] = value; },
        querySelector() { return { textContent: '' }; },
        addEventListener(type, fn) { listeners[type] = fn; }
    };
    const elements = {
        chart_data_block: { textContent: JSON.stringify(chartData) },
        debt_chart: {},
        trend_toggle: button
    };
    const document = {
        readyState: 'complete',
        documentElement: { classList: { add() {} } },
        body: {},
        getElementById(id) { return id === 'chart-data' ? elements.chart_data_block : (elements[id] || null); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        addEventListener() {}
    };
    function Chart(canvas, config) {
        made = this;
        this.config = config;
        this.data = config.data;
        this.options = config.options;
    }
    Chart.prototype.setDatasetVisibility = function (i, on) { visibility.push([i, on]); this.data.datasets[i].hidden = !on; };
    Chart.prototype.update = function () {};
    Chart.prototype.destroy = function () {};
    Chart.prototype.resize = function () {};
    Chart.defaults = {
        font: {},
        plugins: { legend: { onClick() {}, labels: { generateLabels(c) { return c.data.datasets.map((ds, i) => ({ datasetIndex: i, text: ds.label })); } } } }
    };
    const location = new URL('http://localhost:8000/' + (trendParam === null ? '' : '?trend=' + trendParam));
    const window = {
        location: { href: location.href, origin: location.origin, pathname: location.pathname, search: location.search, hash: '' },
        localStorage: { getItem() { return null; }, setItem() {} },
        history: { state: null, replaceState() {} },
        matchMedia() { return { matches: false, addEventListener() {} }; },
        innerWidth: 1280,
        console
    };
    window.Chart = Chart;
    const context = {
        window, document, Chart, URL, URLSearchParams, Intl, Math, JSON, Date, Object, Array, String, Number, isFinite, console,
        getComputedStyle() { return { getPropertyValue() { return ''; }, fontFamily: 'sans-serif' }; }
    };
    vm.runInNewContext(source, context, { filename: file });
    return { chart: made, listeners, visibility, button };
}

/* ---- trend on (default) ---- */
const on = run(null);
check(on.chart !== null, 'a chart is drawn');
const ds = on.chart.data.datasets;
const byLabel = (label) => ds.find((d) => d.label === label);
const debt = byLabel('Total debt (trillions USD)');
const trend = byLabel('Trend (straight line)');
const projection = byLabel('Projection if the trend continues');
const curve = byLabel('Trend (growth curve)');
const curveProjection = byLabel('Growth projection');

check(ds.length === 5, 'five datasets, got ' + ds.length);
check(debt && !debt.trendLine, 'the debt line is a data line, not a trend line');
check(curve && curve.trendLine === true, 'the growth curve is a trend line (the switch hides it)');
check(curveProjection && curveProjection.trendLine === true, 'the growth projection is a trend line');
check(trend.data.length === 2, 'the straight trend is drawn from its two ends');
check(curve.data.length === 4, 'the growth curve keeps every point, got ' + curve.data.length);

/* each projection joins the last point of its own line */
const lastOf = (d) => d.data[d.data.length - 1];
check(projection.data[0].y === lastOf(trend).y && projection.data[0].x === lastOf(trend).x, 'the straight projection starts at the straight line\'s last point');
check(curveProjection.data[0].y === lastOf(curve).y && curveProjection.data[0].x === lastOf(curve).x,
    'the growth projection starts at the growth curve\'s last point (' + JSON.stringify(curveProjection.data[0]) + ' vs ' + JSON.stringify(lastOf(curve)) + ')');
check(curveProjection.data.length === 4, 'the growth projection keeps its points plus the join');

/* told apart without color: four different patterns */
const patterns = [trend, projection, curve, curveProjection].map((d) => JSON.stringify(d.borderDash));
check(new Set(patterns).size === 4, 'four different line patterns: ' + patterns.join(' '));
check(JSON.stringify(trend.borderDash) === '[]', 'the straight trend is solid');
check(projection.borderDash.length === 2, 'the straight projection is dashed');
check(curve.borderCapStyle === 'round' && curveProjection.borderCapStyle === 'round', 'the growth curve lines use round dots');
check(trend.borderColor === projection.borderColor && curve.borderColor === curveProjection.borderColor, 'a line and its projection share a color');
check(trend.borderColor !== curve.borderColor, 'the growth curve has its own (darker) red');
check(ds.every((d) => !d.hidden), 'trend on: every line shows');

/* legend: one entry per line, each swatch carrying its line's pattern */
const legend = on.chart.options.plugins.legend;
check(legend.display === true, 'the legend shows');
const items = legend.labels.generateLabels(on.chart);
check(items.length === 5, 'five legend entries');
const swatches = items.filter((l) => ds[l.datasetIndex].trendLine).map((l) => JSON.stringify(l.lineDash));
check(new Set(swatches).size === 4, 'four different legend swatches: ' + swatches.join(' '));
check(items.every((l) => l.pointStyle === 'line'), 'every swatch is a piece of line');

/* the switch hides and shows all four trend lines together */
check(typeof on.listeners.click === 'function', 'the switch is wired');
on.listeners.click();
const hiddenNow = ds.filter((d) => d.trendLine).every((d) => d.hidden === true);
check(hiddenNow, 'switch off: all four trend lines hidden');
check(debt.hidden !== true, 'switch off: the debt line still shows');
check(on.button.attrs['aria-pressed'] === 'false', 'switch off: aria-pressed is false');
check(items.filter((l) => legend.labels.filter(l, on.chart.data)).length === 1, 'switch off: only the debt line is in the legend');
on.listeners.click();
check(ds.every((d) => !d.hidden), 'switch on again: every line shows');

/* ---- trend off from the URL ---- */
const off = run('0');
check(off.chart.data.datasets.filter((d) => d.trendLine).length === 4, 'trend=0: four trend lines still sent');
check(off.chart.data.datasets.filter((d) => d.trendLine).every((d) => d.hidden === true), 'trend=0: all four start hidden');
check(off.chart.options.plugins.legend.display === false, 'trend=0: one line left, so no legend');

if (failures.length) {
    console.error(failures.length + ' trend-line check(s) failed:\n- ' + failures.join('\n- '));
    process.exit(1);
}
console.log('trend-lines: all checks passed');
