// Checks "Your Share" in src/Public/js/usdebt.js in Node (no browser needed):
// 1. formatAmount(): dollars for "usd" (per person, per household), trillions
//    for "trillion_usd" (the total), full and compact (axis), signed or not.
// 2. chartUnit(): reads the unit from the chart data; anything else is trillions.
// 3. The "Show numbers as" switch links (written by the server) keep per, the
//    dates or Compare picks when the trend switch rewrites them (withTrend()).
// Run by YourShareTest when Node is installed; also runnable by hand:
//   node tests/js/per-format.check.js
'use strict';
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', '..', 'src', 'Public', 'js', 'usdebt.js');
const src = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');

function grab(name, args) {
    const re = new RegExp('function ' + name + '\\(' + args + '\\) \\{[\\s\\S]*?\\n {4}\\}\\n');
    const found = src.match(re);
    if (!found) {
        console.error(name + '() not found in ' + file);
        process.exit(2);
    }
    return found[0];
}

const { formatAmount, chartUnit } = new Function(
    grab('formatAmount', 'value, unit, compact, signed') + grab('chartUnit', 'data')
        + '; return { formatAmount: formatAmount, chartUnit: chartUnit };'
)();

const failures = [];
function same(actual, expected, what) {
    if (actual !== expected) {
        failures.push(`${what}\n  expected: ${expected}\n  actual:   ${actual}`);
    }
}

// 1. Dollars per person / household: cents in tooltips and cards, short on the axis.
same(formatAmount(118670.554, 'usd', false), '$118,670.55', 'usd, full');
same(formatAmount(118670.554, 'usd', false, true), '+$118,670.55', 'usd, full, signed rise');
same(formatAmount(-2504.5, 'usd', false, true), '-$2,504.50', 'usd, full, fall');
same(formatAmount(15.234, 'usd', false, true), '+$15.23', 'usd, small');
same(formatAmount(120000, 'usd', true), '$120K', 'usd, axis');
same(formatAmount(2500, 'usd', true), '$2.5K', 'usd, axis, half thousand');
same(formatAmount(1200000, 'usd', true), '$1.2M', 'usd, axis, million');
same(formatAmount(500, 'usd', true), '$500', 'usd, axis, under a thousand');
same(formatAmount(30000, 'usd', true, true), '+$30K', 'usd, axis, signed (Compare)');
same(formatAmount(-10000, 'usd', true, true), '-$10K', 'usd, axis, negative (Compare)');
same(formatAmount(0, 'usd', true, true), '$0', 'usd, axis, zero has no sign');
same(formatAmount(-0, 'usd', false, true), '$0.00', 'usd, minus zero has no sign');

// The total: trillions, as before "Your Share" (full and axis look the same).
same(formatAmount(38.123, 'trillion_usd', false), '$38.12T', 'trillions, full');
same(formatAmount(38.123, 'trillion_usd', true), '$38.12T', 'trillions, axis');
same(formatAmount(1.42, 'trillion_usd', false, true), '+$1.42T', 'trillions, signed');
same(formatAmount(-0.5, 'trillion_usd', true, true), '-$0.50T', 'trillions, fall');
same(formatAmount(38.123, undefined, false), '$38.12T', 'no unit is trillions');

// A per person value is never shown as trillions, and the total never as plain dollars.
if (/T$/.test(formatAmount(118670.55, 'usd', true))) {
    failures.push('usd value shown as trillions');
}
if (!/T$/.test(formatAmount(38.1, 'trillion_usd', true))) {
    failures.push('trillion value shown without T');
}

// 2. The unit comes from the chart data.
same(chartUnit({ unit: 'usd' }), 'usd', 'unit usd');
same(chartUnit({ unit: 'trillion_usd' }), 'trillion_usd', 'unit trillion_usd');
same(chartUnit({}), 'trillion_usd', 'no unit');
same(chartUnit({ unit: '<b>' }), 'trillion_usd', 'odd unit');
same(chartUnit(null), 'trillion_usd', 'no data');

// 3. The switch links keep per and the view when the trend switch changes.
const withTrendSrc = grab('withTrend', 'href, on');
function withTrendOn(pageHref) {
    const u = new URL(pageHref);
    const window = { location: { href: u.href, origin: u.origin, pathname: u.pathname, search: u.search, hash: u.hash } };
    return new Function('window', 'URL', withTrendSrc + '; return withTrend;')(window, URL);
}
const page = 'http://localhost:8000/?start_date=2000-01-04&end_date=2026-11-09&per=person';
const wt = withTrendOn(page);
const range = 'start_date=2000-01-04&end_date=2026-11-09';
same(wt('./?' + range, false), '/?' + range + '&trend=0', 'Total option, trend off');
same(wt('./?' + range + '&per=person', false), '/?' + range + '&per=person&trend=0', 'Per person option, trend off');
same(wt('./?' + range + '&per=household&trend=0', true), '/?' + range + '&per=household', 'Per household option, trend back on');
same(wt('./?compare=1,2&per=person', false), '/?compare=1,2&per=person&trend=0', 'Compare option, trend off');
same(wt('./', false), '/?trend=0', 'home Total option, trend off');
for (const [href, per] of [['./?' + range + '&per=person', 'person'], ['./?' + range + '&per=household&trend=0', 'household']]) {
    for (const on of [true, false]) {
        const out = new URL(wt(href, on), page);
        same(out.searchParams.get('per'), per, `per kept: ${href} on=${on}`);
        same(out.searchParams.get('start_date'), '2000-01-04', `start kept: ${href} on=${on}`);
        same(out.searchParams.get('end_date'), '2026-11-09', `end kept: ${href} on=${on}`);
    }
}

if (failures.length) {
    console.error(failures.length + ' failure(s):\n' + failures.join('\n'));
    process.exit(1);
}
console.log('per-format: all checks passed');
