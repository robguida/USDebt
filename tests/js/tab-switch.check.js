// Checks initSwitch (tabs + the "Remember my tab" checkbox) from src/Public/js/usdebt.js in Node, with a fake page.
// Counts every cookie write. Run by hand: node tests/js/tab-switch.check.js  (also run by PageUpdatesTest when Node is installed)
'use strict';
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', '..', 'src', 'Public', 'js', 'usdebt.js');
const src = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
const mem = src.match(/\/\* tab-memory:start[\s\S]*?\/\* tab-memory:end \*\//);
const sw = src.match(/function initSwitch\(\) \{[\s\S]*?\n    \}\n(?=\n    \/\* -+ "Select all")/);
if (!mem || !sw) {
    console.error('tab-memory block or initSwitch not found in ' + file);
    process.exit(2);
}
const build = new Function('document', 'window', 'chart', mem[0] + '\n' + sw[0] + '\nreturn initSwitch;');

let failures = 0;
function check(name, ok) {
    if (!ok) { failures++; console.error('FAIL: ' + name); }
}

// A fake page: three tabs with panels, the memory wrapper, the box and the status line.
function page(initialCookie, preTicked) {
    const store = {};
    const writes = [];
    if (initialCookie !== undefined) { store.usdebt_tab = initialCookie; }
    const byId = {};
    function el(id) {
        const attrs = {};
        const handlers = {};
        const e = {
            id: id, hidden: false, tabIndex: 0, textContent: '', checked: false, attrs: attrs,
            setAttribute: function (k, v) { attrs[k] = String(v); },
            getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
            addEventListener: function (t, f) { (handlers[t] = handlers[t] || []).push(f); },
            fire: function (t, ev) { (handlers[t] || []).forEach(function (f) { f(ev || {}); }); },
            focus: function () {}
        };
        byId[id] = e;
        return e;
    }
    const keys = ['chart', 'stats', 'table'];
    const tabs = keys.map(function (k, i) {
        const t = el('tab_' + k);
        t.setAttribute('role', 'tab');
        t.setAttribute('aria-controls', 'panel_' + k);
        t.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
        el('panel_' + k).hidden = i !== 0;
        return t;
    });
    const list = { querySelectorAll: function () { return tabs; } };
    const wrap = el('tab_memory');
    wrap.hidden = true;
    wrap.setAttribute('data-path', '/ourusdebt/');
    wrap.setAttribute('data-saved', 'SAVED');
    wrap.setAttribute('data-removed', 'REMOVED');
    wrap.setAttribute('data-failed', 'FAILED');
    const box = el('tab_memory_box');
    box.checked = !!preTicked;
    const status = el('tab_memory_status');
    const doc = {
        querySelector: function () { return list; },
        getElementById: function (id) { return byId[id] || null; },
        get cookie() { return Object.keys(store).map(function (k) { return k + '=' + store[k]; }).join('; '); },
        set cookie(line) {
            writes.push(line);
            const parts = line.split(';').map(function (s) { return s.trim(); });
            const kv = parts[0].split('=');
            if (parts.indexOf('Max-Age=0') !== -1) { delete store[kv[0]]; } else { store[kv[0]] = kv.slice(1).join('='); }
        }
    };
    const win = { location: { protocol: 'https:' } };
    build(doc, win, null)();
    function selected() {
        return tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; }).map(function (t) { return t.id; });
    }
    function setBox(on) { box.checked = on; box.fire('change'); }
    return { tabs: tabs, box: box, wrap: wrap, status: status, writes: writes, store: store, selected: selected, setBox: setBox, byId: byId };
}

// 1. No cookie: nothing is written on load or on tab clicks while unticked.
let p = page();
check('wrapper shown', p.wrap.hidden === false);
check('box starts unticked', p.box.checked === false);
check('no write on load', p.writes.length === 0);
p.tabs[1].fire('click');
p.tabs[2].fire('click');
check('tab click still switches', p.selected().join() === 'tab_table');
check('panel shown', p.byId.panel_table.hidden === false && p.byId.panel_chart.hidden === true);
check('no write on tab clicks while unticked', p.writes.length === 0);

// 2. Ticking writes once, with the tab shown now.
p.setBox(true);
check('one write on tick', p.writes.length === 1);
check('tick saves the shown tab', /^usdebt_tab=table; Path=\/ourusdebt\/;/.test(p.writes[0]));
check('saved line spoken', p.status.textContent === 'SAVED');

// 3. While ticked, one write per tab change.
p.tabs[0].fire('click');
check('one more write on tab change', p.writes.length === 2 && /^usdebt_tab=chart;/.test(p.writes[1]));
p.tabs[1].fire('click');
check('and another', p.writes.length === 3 && /^usdebt_tab=stats;/.test(p.writes[2]));

// 4. Unticking deletes (Max-Age=0) and later tab changes write nothing.
p.setBox(false);
check('untick writes one delete', p.writes.length === 4 && /Max-Age=0/.test(p.writes[3]));
check('cookie gone', p.store.usdebt_tab === undefined);
check('removed line spoken', p.status.textContent === 'REMOVED');
p.tabs[2].fire('click');
check('no write after untick', p.writes.length === 4);

// 5. A saved cookie restores its tab and ticks the box, without writing.
p = page('stats');
check('saved tab restored', p.selected().join() === 'tab_stats');
check('saved panel shown', p.byId.panel_stats.hidden === false && p.byId.panel_chart.hidden === true);
check('box ticked', p.box.checked === true);
check('restoring only renews the same cookie (one write, same tab)', p.writes.length === 1 && /^usdebt_tab=stats;/.test(p.writes[0]));
p.tabs[2].fire('click');
check('ticked box follows the next tab', p.writes.length === 2 && /^usdebt_tab=table;/.test(p.writes[1]));

// 6. An invalid cookie is ignored: first tab, box unticked, no writes.
['evil', 'CHART', ''].forEach(function (v) {
    p = page(v);
    check('invalid cookie "' + v + '": default tab', p.selected().join() === 'tab_chart');
    check('invalid cookie "' + v + '": box unticked', p.box.checked === false);
    check('invalid cookie "' + v + '": no writes', p.writes.length === 0);
});

// 7. A browser that restored form state (box already ticked) with no cookie: the script unticks it, nothing is written.
p = page(undefined, true);
check('restored-form box is unticked', p.box.checked === false);
check('restored-form box writes nothing', p.writes.length === 0);
p.tabs[1].fire('click');
check('and a tab click then writes nothing', p.writes.length === 0);

if (failures) {
    console.error(failures + ' check(s) failed');
    process.exit(1);
}
console.log('tab-switch: all checks passed');
