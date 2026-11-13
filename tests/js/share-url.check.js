// Checks the Share menu link builders from src/Public/js/usdebt.js in Node
// (no browser needed): shareUrl(), shareHrefs() and encode().
// Run by ShareTest when Node is installed; also runnable by hand:
//   node tests/js/share-url.check.js [expected.json]
// expected.json (written by ShareTest) holds pages the server rendered: the
// og:url, the share words and the links the server wrote. The script must
// build the very same links from them.
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

const code = [grab('encode', 'value'), grab('shareUrl', 'canonical, on'), grab('shareHrefs', 'url, text, subject, note')].join('\n');
const { shareUrl, shareHrefs } = new Function('URL', code + '; return { shareUrl: shareUrl, shareHrefs: shareHrefs };')(URL);

const failures = [];
function same(actual, expected, what) {
    if (actual !== expected) {
        failures.push(`${what}\n  expected: ${expected}\n  actual:   ${actual}`);
    }
}

const ORIGIN = 'https://bengusa.pro';
const BASE = ORIGIN + '/ourusdebt'; // the site lives in a sub-folder

// 1. The share link is the canonical link, on the canonical's own origin.
same(shareUrl(BASE + '/?start_date=2009-01-20&end_date=2017-01-19', true), BASE + '/?start_date=2009-01-20&end_date=2017-01-19', 'range, trend on');
same(shareUrl(BASE + '/?start_date=2009-01-20&end_date=2017-01-19', false), BASE + '/?start_date=2009-01-20&end_date=2017-01-19&trend=0', 'range, trend off');
same(shareUrl(BASE + '/?start_date=2009-01-20&end_date=2017-01-19&trend=0', true), BASE + '/?start_date=2009-01-20&end_date=2017-01-19', 'trend=0 removed');
same(shareUrl(BASE + '/?trend=0&compare=1,2', false), BASE + '/?compare=1,2&trend=0', 'one trend=0, commas kept');
same(shareUrl(BASE + '/', true), BASE + '/', 'home page');
same(shareUrl(BASE + '/', false), BASE + '/?trend=0', 'home page, trend off');
same(shareUrl(BASE + '/?compare=727506000,979966800', true), BASE + '/?compare=727506000,979966800', 'compare commas not encoded');
same(shareUrl('http://localhost:8000/sub/?a=1#x', true), 'http://localhost:8000/sub/?a=1', 'sub-path kept, fragment dropped');
// Whatever this page's own address is, the link stays on the canonical's origin.
for (const odd of [BASE + '//evil.example/', BASE + '/\\evil.example/', ORIGIN + '//evil.example/']) {
    const out = shareUrl(odd, false);
    if (out === null || new URL(out).origin !== ORIGIN) {
        failures.push('left the canonical origin: ' + odd + ' -> ' + out);
    }
}
// Anything but a plain http(s) link is refused (the menu keeps the server's links).
for (const bad of ['javascript:alert(1)', 'data:text/html,x', '/relative', '', 'https://user:pw@bengusa.pro/ourusdebt/', 'not a url', null]) {
    same(shareUrl(bad, true), null, 'refused: ' + bad);
}

// 2. Share links: everything percent-encoded, spaces as %20 (mail apps need it).
const url = BASE + '/?start_date=2009-01-20&end_date=2017-01-19&trend=0';
const text = "U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017. It's (100%) * #1 & more!";
const h = shareHrefs(url, text, 'U.S. debt, Jan 20, 2009 to Jan 19, 2017', 'Data: U.S. Treasury, Debt to the Penny.');
const encUrl = 'https%3A%2F%2Fbengusa.pro%2Fourusdebt%2F%3Fstart_date%3D2009-01-20%26end_date%3D2017-01-19%26trend%3D0';
const encText = 'U.S.%20debt%20grew%20%249.32%20trillion%20from%20Jan%2020%2C%202009%20to%20Jan%2019%2C%202017.%20It%27s%20%28100%25%29%20%2A%20%231%20%26%20more%21';
same(h.x, 'https://x.com/intent/tweet?text=' + encText + '&url=' + encUrl, 'X link');
same(h.facebook, 'https://www.facebook.com/sharer/sharer.php?u=' + encUrl, 'Facebook link');
same(h.linkedin, 'https://www.linkedin.com/sharing/share-offsite/?url=' + encUrl, 'LinkedIn link');
same(h.reddit, 'https://www.reddit.com/submit?url=' + encUrl + '&title=' + encText, 'Reddit link');
same(h.email, 'mailto:?subject=U.S.%20debt%2C%20Jan%2020%2C%202009%20to%20Jan%2019%2C%202017&body=' + encText + '%20' + encUrl
    + '%0A%0AData%3A%20U.S.%20Treasury%2C%20Debt%20to%20the%20Penny.', 'email link');
for (const [key, href] of Object.entries(h)) {
    const u = new URL(href);
    if (key !== 'email' && u.protocol !== 'https:') {
        failures.push(key + ' is not https: ' + href);
    }
    // The shared link comes back out exactly once the site decodes it.
    const back = key === 'facebook' ? u.searchParams.get('u') : key === 'email' ? null : u.searchParams.get('url');
    if (back !== null && back !== url) {
        failures.push(key + ' does not decode to the page link: ' + back);
    }
    if (/\s|\+/.test(href)) {
        failures.push(key + ' has a raw space or plus: ' + href);
    }
}

// 3. The script builds the same links the server wrote (pages from ShareTest).
const expectedFile = process.argv[2];
if (expectedFile) {
    const pages = JSON.parse(fs.readFileSync(expectedFile, 'utf8'));
    for (const page of pages) {
        const built = shareUrl(page.ogUrl, page.trendOn);
        same(built, page.ogUrl, page.name + ': share link = og:url');
        const links = shareHrefs(built, page.text, page.subject, page.note);
        for (const key of Object.keys(page.links)) {
            same(links[key], page.links[key], page.name + ': ' + key + ' link matches the server');
        }
    }
}

if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
}
console.log('share links: canonical, same-origin, encoded' + (expectedFile ? ', match the server' : ''));
