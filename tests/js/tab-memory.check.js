// Checks the saved-tab cookie logic (tabMemory) from src/Public/js/usdebt.js in Node.
// Run by hand: node tests/js/tab-memory.check.js  (also run by PageUpdatesTest when Node is installed)
'use strict';
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', '..', 'src', 'Public', 'js', 'usdebt.js');
const src = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
const found = src.match(/\/\* tab-memory:start[\s\S]*?\/\* tab-memory:end \*\//);
if (!found) {
    console.error('tab-memory block not found in ' + file);
    process.exit(2);
}
const make = new Function(found[0] + '; return tabMemory;')();

// A tiny cookie jar: remembers name=value, honours Max-Age=0 as delete, and records every write.
function jar(opts) {
    const o = opts || {};
    const store = {};
    const writes = [];
    const doc = {
        get cookie() {
            if (o.throwOnRead) { throw new Error('blocked'); }
            return Object.keys(store).map(function (k) { return k + '=' + store[k]; }).join('; ');
        },
        set cookie(line) {
            if (o.throwOnWrite) { throw new Error('blocked'); }
            writes.push(line);
            if (o.ignoreWrites) { return; }
            const parts = line.split(';').map(function (s) { return s.trim(); });
            const kv = parts[0].split('=');
            if (parts.indexOf('Max-Age=0') !== -1) { delete store[kv[0]]; } else { store[kv[0]] = kv.slice(1).join('='); }
        }
    };
    return { doc: doc, writes: writes, store: store };
}

let failures = 0;
function check(name, ok) {
    if (!ok) { failures++; console.error('FAIL: ' + name); }
}

// nothing is written until something saves
let j = jar();
let m = make(j.doc, { protocol: 'https:' }, '/ourusdebt/');
check('a fresh jar reads as no choice', m.read() === null);
check('nothing written by reading', j.writes.length === 0);

// valid values save and read back; Path, Max-Age, SameSite
['chart', 'stats', 'table'].forEach(function (v) {
    j = jar();
    m = make(j.doc, { protocol: 'https:' }, '/ourusdebt/');
    check('save ' + v + ' reports success', m.save(v) === true);
    check('read back ' + v, m.read() === v);
    check('cookie text for ' + v, j.writes[0] === 'usdebt_tab=' + v + '; Path=/ourusdebt/; Max-Age=31536000; SameSite=Lax; Secure');
});

// invalid values are never written
j = jar();
m = make(j.doc, { protocol: 'https:' }, '/');
['', 'graph', 'CHART', 'chart;x=1', 'stats\nSet-Cookie', null, undefined].forEach(function (v) {
    check('invalid save refused: ' + String(v), m.save(v) === false);
});
check('no write for invalid values', j.writes.length === 0);

// an invalid stored value is ignored (no choice)
j = jar();
j.store.usdebt_tab = 'evil';
m = make(j.doc, { protocol: 'https:' }, '/');
check('invalid stored value reads as null', m.read() === null);
j.store.other = 'x';
j.store.usdebt_tab = 'stats';
check('picks our cookie among others', m.read() === 'stats');

// Secure only on https
j = jar();
make(j.doc, { protocol: 'http:' }, '/').save('chart');
check('no Secure on http', j.writes[0].indexOf('Secure') === -1 && j.writes[0].indexOf('SameSite=Lax') !== -1);
j = jar();
make(j.doc, { protocol: 'https:' }, '/').save('chart');
check('Secure on https', /; Secure$/.test(j.writes[0]));

// Path: the given one, "/" when missing or odd
[['/ourusdebt/', '/ourusdebt/'], ['/', '/'], ['', '/'], [undefined, '/'], ['ourusdebt', '/'], ['/a;b', '/'], ['/a b/', '/']].forEach(function (c) {
    j = jar();
    make(j.doc, { protocol: 'https:' }, c[0]).save('chart');
    check('path ' + String(c[0]), j.writes[0].indexOf('; Path=' + c[1] + ';') !== -1);
});

// untick deletes: Max-Age=0, same Path
j = jar();
m = make(j.doc, { protocol: 'https:' }, '/ourusdebt/');
m.save('table');
m.clear();
check('clear writes Max-Age=0 with the same Path', j.writes[1] === 'usdebt_tab=; Path=/ourusdebt/; Max-Age=0; SameSite=Lax; Secure');
check('cleared reads as null', m.read() === null);

// blocked cookies: nothing throws, save reports failure
j = jar({ throwOnWrite: true, throwOnRead: true });
m = make(j.doc, { protocol: 'https:' }, '/');
check('blocked save returns false', m.save('chart') === false);
check('blocked read returns null', m.read() === null);
m.clear();
j = jar({ ignoreWrites: true });
m = make(j.doc, { protocol: 'https:' }, '/');
check('a browser that drops cookies reports failure', m.save('chart') === false);

if (failures) {
    console.error(failures + ' check(s) failed');
    process.exit(1);
}
console.log('tab-memory: all checks passed');
