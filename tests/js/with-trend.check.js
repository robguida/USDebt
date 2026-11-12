// Checks withTrend() from src/Public/js/usdebt.js in Node (no browser needed).
// Run by ViewRenderTest when Node is installed; also runnable by hand:
//   node tests/js/with-trend.check.js
// Every rewritten link must stay on the page's own origin, even on a page
// whose path starts with "//" (which a browser reads as another site).
'use strict';
const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', '..', 'src', 'Public', 'js', 'usdebt.js');
const src = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
const found = src.match(/function withTrend\(href, on\) \{[\s\S]*?\n {4}\}\n/);
if (!found) {
    console.error('withTrend() not found in ' + file);
    process.exit(2);
}

function withTrendOn(pageHref) {
    const u = new URL(pageHref);
    const window = { location: { href: u.href, origin: u.origin, pathname: u.pathname, search: u.search, hash: u.hash } };
    return new Function('window', 'URL', found[0] + '; return withTrend;')(window, URL);
}

const pages = [
    'http://localhost:8000/',
    'http://localhost:8000/?trend=0',
    'http://localhost:8000//evil.example/',
    'http://localhost:8000//evil.example/x?start_date=2020-01-01',
    'http://localhost:8000///evil.example/',
];
const hrefs = ['./', './?trend=0', './?start_date=2024-01-01&end_date=2024-02-01#results', '//evil.example/', 'https://evil.example/'];

const failures = [];
for (const page of pages) {
    const origin = new URL(page).origin;
    const withTrend = withTrendOn(page);
    for (const href of hrefs.concat([page])) {
        for (const on of [true, false]) {
            const out = withTrend(href, on);
            if (out === null) {
                if (new URL(href, page).origin === origin) {
                    failures.push(`same-origin link left alone: page=${page} href=${href}`);
                }
                continue;
            }
            if (/^[/\\]{2}/.test(out) || new URL(out, page).origin !== origin) {
                failures.push(`leaves the site: page=${page} href=${href} on=${on} -> ${out}`);
            }
            if (on === false && new URL(out, page).searchParams.get('trend') !== '0') {
                failures.push(`trend=0 missing: page=${page} href=${href} -> ${out}`);
            }
            if (on === true && new URL(out, page).searchParams.has('trend')) {
                failures.push(`trend not removed: page=${page} href=${href} -> ${out}`);
            }
        }
    }
}

// The plain case keeps its exact shape.
const plain = withTrendOn('http://localhost:8000/')('./?start_date=2024-01-01#results', false);
if (plain !== '/?start_date=2024-01-01&trend=0#results') {
    failures.push('plain link changed shape: ' + plain);
}

if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
}
console.log('withTrend: all links stay same-origin');
