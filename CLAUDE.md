# UsDebt — project guide

Source of truth for anyone (human or tool) working on this project.
Owner: Rob Guida (RandBMedia, his own company; not a client project).
Last updated: 2026-11-14 (matches branch USD-10-page-updates).

## 1. What it is

1. A small PHP web app that shows the US national debt as tables, charts and stats.
2. Data comes from the U.S. Treasury (fiscaldata "Debt to the Penny"), downloaded once a day and saved.
3. Presidential terms can be compared side by side (debt change during each term).
4. Charts show a straight trend line and a growth curve, each with its own projection, with one on/off button.
5. Goal now: a public demo at bengusa.pro/ourusdebt/. See `docs/requirements.md`.

## 2. Stack

1. PHP 8.2 or newer (`composer.json` says `>=8.2`). Local development runs on PHP 8.2. Production targets PHP 8.5 in its own PHP pool on the shared bengUSA.pro Lightsail server (see §10).
2. Required PHP extensions: `ext-curl`, `ext-json`.
    - **a.** Also needed for the share picture card: `ext-gd` built with FreeType (and PNG). It is not in `composer.json`, because the rest of the site works without it: `card.php` then answers 503 with a short plain-text error. The production server must have it. Locally, load it per run (`php -d extension=gd ...`); the card tests skip without it.
3. Composer 2: `composer.phar` at the repo root, with `composer.phar.sha256` beside it. Dev dependency: PHPUnit 11.
4. Charts: Chart.js 4.5.1 only (`src/Public/lib/chart.umd.min.js`). Its checksum is pinned in a test, and `.gitattributes` keeps the file byte-exact.
5. Page script: vanilla JavaScript, `src/Public/js/usdebt.js`. jQuery, jQuery UI, Flot and the old Chart.js 2.6 are gone.
6. No framework. Plain PHP classes plus include-style view files.

## 3. Layout

Rule: `./src` is the root of the deployed code. Only `./src` ships.

Every PHP file under `src/lib` and `src/Public` must be named here. A test (`tests/Unit/DocsTest.php`) fails when one is missing.

1. `src/Public/` — the web root (document root).
    - **a.** `index.php` — entry point. Loads `lib/bootstrap.php` with `__DIR__` (not a relative path), then hands the request to `FrontController`.
    - **b.** `card.php` — the share picture card (a 1200×630 PNG). Loads `bootstrap.php` the same way, then hands the request to `CardController`.
        - **i.** `download.php` — the Table tab's CSV file. Loads `bootstrap.php` with `__DIR__`, then hands the request to `DownloadController`.
    - **c.** `css/usdebt.css` — site styles and colour variables.
    - **d.** `js/usdebt.js` — the page script (charts, trend button, tabs and the saved-tab cookie, Share menu).
    - **e.** `lib/` — `chart.umd.min.js` and its license file.
    - **f.** `images/` — president photos and `bengusa-logo.svg` (the header logo, nothing active inside it). `favicon.svg` — site icon.
2. `src/lib/` — app code, outside the web root.
    - **a.** `bootstrap.php` — error settings, timezone (America/New_York), class autoloader. No session.
        - **i.** `SiteConfig.php` — the site's name (`SiteConfig::NAME` = "Our U.S. Debt", Rob's decision 2026-11-12), in this one place. The header, page title, link preview tags, share words and picture card all read it. Change it only here; a test checks no other file spells it out.
        - **ii.** Also in `SiteConfig`: `COPYRIGHT_HOLDER` ("bengUSA.pro", no "LLC"), `COPYRIGHT_FIRST_YEAR` (2026), `HOME_URL` (`https://bengusa.pro/`, the header logo's link), `copyrightLine()` (the footer line; the end year is the current year in America/New_York) and `FILE_SLUG` (`our-us-debt`, the start of the CSV file name).
    - **b.** `Controller/FrontController.php` — turns one request into one page. Visitors only see friendly messages.
    - **c.** `Controller/USDebtController.php` — builds the date-range view, compare view and error view, and the share tags and Share menu for each. Loads view files.
    - **d.** `Controller/CardController.php` — the picture card: same input checks as the page, the cached PNG or a fresh one, plain-text errors (400, 503, 500).
        - **i.** `Controller/DownloadController.php` — the Table's CSV: reads only `start_date`, `end_date` and `per`, same input checks as the page, cut from the one daily Treasury copy. Plain-text errors like the card: 400 (bad input, a Compare request, a range with no data), 503 (no Treasury data at all), 500 (anything unexpected).
    - **e.** `Model/` — `RequestModel.php` (dates, picked terms, trend on/off, per mode), `DebtEntry.php`, `DebtDataset.php`, `ShareCard.php` (everything needed to share one view: words, links, cache key, what the card draws), `Per.php` (Your Share: total, per person or per household, from `?per=`).
    - **f.** `Service/FiscalDataClient.php` — downloads the full Treasury history, page by page.
    - **g.** `Service/DebtSource.php` (the interface) and `Service/DebtRepository.php` — the daily cache.
    - **h.** `Service/LogOnce.php` — logs a lasting problem once per 15 minutes across PHP processes (a marker file in `src/var/log-markers`). Used by the Treasury cache and the card cache.
    - **i.** `Service/TrendService.php` — the straight trend line and its projection, and the growth curve (least squares on ln(value)) and its projection.
    - **j.** `Service/CompareService.php` — checks the picked terms and builds the Compare series, with each line's exact total change.
        - **i.** `Service/TableCsv.php` — builds the CSV: the same rows as the Table (newest first), amounts as plain 2-decimal numbers, a guard against spreadsheet formulas, and the file name `our-us-debt_<start>_to_<end>[_per-person|_per-household].csv`.
    - **k.** `Service/StatsCalculator.php`, `Service/ChartData.php`, `Service/Html.php` — stats, chart data shaping, safe HTML output.
    - **l.** `Service/PresidentService.php` — fixed list of presidents (term dates, photo, chart colour).
    - **m.** `Service/ValidateDateTime.php` — date checks.
        - **i.** `Service/PopulationService.php` — Your Share: U.S. population and households for any day (straight line between yearly Census figures), and the debt divided by them. The latest figure (2025) is held for later dates, and the first (1993) for earlier ones; the footnote and the Stats note say so. A bad year entry is skipped and logged once (the line spans the gap). With fewer than 2 good years, `available()` is false and the page shows the total, with a logged warning.
        - **ii.** The `per` parameter (`?per=person` or `?per=household`; anything else, or missing, is Total and is never written in a link) is read by `RequestModel` into `Model/Per.php`. It rides in every page link, share link, card link and Compare. Trend and curve are fitted on the per-person series; chart values are dollars (Total stays in trillions). Every trend, change and Compare line keeps its "per person" or "per household" words (`ShareText::trendAmount`, `compareLine`, `perWords`), and so does the email's source line. The growth rate is rounded once, when shown, so the chart summary and the Stats tab agree.
    - **n.** Sharing:
        - **i.** `Service/ShareText.php` — every share word (see §5.6.c).
        - **ii.** `Service/ShareLinks.php` — the share links: absolute page and card links from `USDEBT_BASE_URL`, the card link for the menu (always built from `USDEBT_BASE_URL`'s path, e.g. `/ourusdebt/card.php`), and the plain links to each social site.
        - **iii.** `Service/ShareCardBuilder.php` — builds the `ShareCard` for a date range or a Compare view.
        - **iv.** `Service/CardRenderer.php` — draws the card with PHP gd.
        - **v.** `Service/CardCache.php` — the card files on disk (at most 500, oldest removed first).
    - **o.** `Http/` — `HttpFetcher.php` (the interface), `NativeHttpFetcher.php`, `Response.php`.
    - **p.** `Exception/` — `UserFacingException.php` (a message safe to show the visitor: 400 by default), `DataUnavailableException.php` (no download and no saved copy: 503), `HttpException.php` (a Treasury request failed), `IncompleteDownloadException.php` (a page or rows are missing, so nothing is saved), `CardUnavailableException.php` (this server cannot draw cards: 503).
    - **q.** `Data/debt_seed_1993.json` — debt for 1993-01-04 to 1993-03-31, before the API's first day.
        - **i.** `Data/us_population_households.json` — Census population (July 1 anchor) and households (March 15 anchor), 1993 to 2025, one figure per year, with its sources and notes inside. Public domain. The 2019 to 2020 population step is kept as published.
    - **r.** `Assets/fonts/` — `DejaVuSans.ttf`, `DejaVuSans-Bold.ttf` and their license, for the card. Self-hosted; their checksums are pinned in a test.
    - **s.** `View/` — `master.php` (page frame), `headline.php` (top number cards), `search_form.php` (date range, quick ranges, term strip), `tabs.php`, `chart_daily.php`, `chart_compare.php`, `tab_stats.php`, `tab_data.php`, `share_menu.php` (Share button and menu), `per_switch.php` (the "Show numbers as: Total / Per person / Per household" switch at the top of the page: plain links, so it works without JavaScript).
3. `src/var/cache/` — the saved Treasury copy. `src/var/cache/cards/` — the saved picture cards. `src/var/log-markers/` — private (0700) "already logged" markers, next to the cache folder. All gitignored. Never web-served.
4. Top level, not shipped: `composer.json`, `composer.lock`, `composer.phar`, `composer.phar.sha256`, `phpunit.xml`, `tests/`, `docs/`, this file.
5. Stray untracked files at the top (`index.php`, `scratch.php`) are not part of this app. Do not ship them or commit them without Rob's say-so.
6. Anything new that runs the product goes in `./src`. Tests, scripts and docs go outside it.

## 4. Data source and cache

1. Source: fiscaldata "Debt to the Penny" (`api.fiscaldata.treasury.gov`).
2. One full-history download per day, by the New York date. Saved as `src/var/cache/treasury-debt.json`. Set `USDEBT_CACHE_DIR` to use another folder.
3. The download is paginated (10,000 rows per page, at most 20 pages). Each page has a 10 second timeout, and one page may be at most 20 MB. If any page fails or rows are missing, nothing is saved. Partial data is never saved.
4. If the download fails, the last saved copy is shown with a notice, and the app does not try again for 15 minutes. With no saved copy, the visitor sees a friendly error.
5. If the cache folder is not writable, or the lock file cannot be opened, the app does not download at all. It serves the saved copy (marked stale) or the friendly error. This stops one Treasury download per page view.
6. If the download works but cannot be saved, this page still shows the new data and the same 15-minute wait starts.
7. Lasting problems are logged once per 15 minutes, not once per page view. The shared system temp folder is never used for this.
    - **a.** The 15-minute wait is the failure file's modified time, not its contents, so it still works on a full disk.
8. File safety: the file is written to a temp name and renamed. Folder mode 0750, files 0640. A lock lets only one request download.
9. Every page and every Compare term is cut from the one saved copy. Compare costs zero extra Treasury calls.
10. Presidents and term dates are hard-coded in `PresidentService`. Terms end on January 19, so no day is in two terms.
11. HTTP status codes: 200 page, 400 input we cannot use, 503 no Treasury data can be loaded, 500 anything unexpected.

## 5. Front end

1. Chart data goes to the page in one block: `<script type="application/json" id="chart-data">`. The page script reads it.
2. CSP-ready: no inline scripts, no inline styles, no inline event handlers. The page script is a separate file.
3. Colours come from CSS variables in `css/usdebt.css`. Term lines use neutral colours. Red (`--trend`, and the separate `--trend-curve` shade) is reserved for the date-range trend lines and their projections.
4. Compare is labelled "View by presidential term", with the caption that Congress sets spending and taxes and terms are shown for reference only.
5. Quick ranges (1M, 1Y, 5Y, This term) are plain links. They are month-end safe: Mar 31 minus one month gives Feb 28 or 29, never Mar 3.
6. Share button (chart card header, next to "Trend line"): a `<details>` menu, so it works with JavaScript off.
    - **a.** Shares the view's own GET link (the same as og:url), with the trend choice. After a Compare form post it is the `?compare=` link.
    - **b.** Copy link, Download picture (`card.php`, same site; the link is built from the path in `USDEBT_BASE_URL`, so it works under `/ourusdebt/`), a card preview, and plain links to X, Facebook, LinkedIn, Reddit and email. No scripts from social sites. Phones with a system share sheet get "Share with an app" first.
    - **c.** Share words, and where each lives:
        - **i.** `Service/ShareText.php` holds every share word, plus the saved-tab words (`TAB_MEMORY_*`) and the logo link label (`LOGO_LINK_LABEL`): the card's words, the link preview tags, the share text and email, alt text, the Share menu's labels, the "Link copied" and "Couldn't copy" lines, and the card's two error lines (no data, cannot draw). `View/share_menu.php` has no words of its own, and `js/usdebt.js` reads the copy lines from the menu's `data-copied` and `data-copy-failed` attributes. A test checks all three.
        - **ii.** Not in `ShareText`: a card request that fails its input checks gets the page's own message (for example "Please pick at least 2 presidents to compare."), and an unexpected failure gets the site-wide `FrontController::GENERIC_ERROR`. The CSV download's words are also outside it: the button label ("Download table (CSV)") and helper text live in `View/tab_data.php`, the column titles in `Service/TableCsv.php`, and the Compare error (`NO_COMPARE`) in `Controller/DownloadController.php`. Its no-data error reuses `ShareText::CARD_NO_DATA`.
    - **d.** The share links are built in `Service/ShareLinks.php` and again in `js/usdebt.js` when the trend switch changes (checked by `tests/js/share-url.check.js`).
        - **i.** The page's own links are re-written the same way (`withTrend`): the query is edited as text, so `compare=1,2` keeps its commas.
    - **e.** Compare totals (the page's summary lines and the card's rows) run from each term's first data day to its last data day. Two terms of one president are the sum of the two terms' changes, the same number the joined chart line is built from. The chart itself has one point per month, so its last point can be up to a month short of the stated total.
7. Header logo: `images/bengusa-logo.svg` sits left of the site name and links to `SiteConfig::HOME_URL` (`https://bengusa.pro/`) in a new tab. The picture has empty alt text; the link has a screen-reader label ("bengUSA.pro home (opens in a new tab)").
8. Links to another site open in a new tab: `target="_blank"` and `rel="noopener noreferrer"`, plus a screen-reader-only "(opens in a new tab)" (`ShareText::MENU_NEW_TAB`). Today: the logo, "Debt to the Penny" and "GitHub". Any new off-site link follows the same rule. Not changed: `mailto:` links, same-site links, and the card and CSV downloads.
9. Saved tab ("Remember my tab", next to the Chart / Stats / Table switch; it wraps under the switch on phones): an opt-in checkbox with a short cookie notice. Rob, 2026-11-14: so that clicking a president stays on the tab the visitor is on.
    - **a.** One first-party cookie, `usdebt_tab`, holding only `chart`, `stats` or `table`. Written, read and cleared only by the page script (the `tabMemory` block in `js/usdebt.js`). The server never reads it: the CDN in front of the site does not forward cookies, and a URL parameter would need a CDN change.
    - **b.** Path comes from the path in `USDEBT_BASE_URL` (`ShareLinks::cookiePath()`, passed to the script in `data-path`). Lasts 1 year. `SameSite=Lax`. `Secure` on https. The cookie lasts 1 year from the last visit that loads a saved tab or changes it, because it is saved again then (not 1 year from the first tick).
    - **c.** Ticking saves the tab now shown and says "Saved. We'll keep this tab." Every later tab change saves again while the box is ticked. Unticking deletes the cookie and says "Cookie removed." A saved tab opens first when the page loads, with the box ticked, and loading it renews the 1 year. If the browser blocks cookies, the box unticks and says "Couldn't save. Cookies seem to be blocked in this browser."
    - **d.** The checkbox is hidden until the page script runs, because without JavaScript all three panels show and there is no tab to save. It is not on Compare, which has no tabs.
    - **e.** Its words live in `ShareText` (`TAB_MEMORY_*`) and reach the script through `data-` attributes (a test checks the script has none of its own).
10. Table download: the Table tab has a "Download table (CSV)" button, a plain link to `download.php`, so it works without JavaScript. It carries only `start_date`, `end_date` and `per`. Same rows as the Table (the chosen range, Total or per person or per household, newest first). Under the button, the helper line: "Opens in Excel, Numbers or Google Sheets." The file is `our-us-debt_<start>_to_<end>[_per-person|_per-household].csv`. Text cells starting with `=`, `+`, `-`, `@`, tab or return get a leading apostrophe so a spreadsheet cannot run them as a formula. Compare has no Table, so there is no button there.
11. Footer copyright, under the GitHub line: "© 2026 bengUSA.pro. Covers this site and its code, not the public-domain Treasury and Census data." The first year is fixed; the end year is the current year (America/New_York), so it reads "2026–2027" after New Year 2027. Words come from `SiteConfig::copyrightLine()`.

## 6. Trend line and projection

1. The trend is a straight least-squares line (`TrendService`). X is the calendar day, so weekends and holidays keep their true width.
2. Date-range chart: a solid red trend line, plus a dashed red "Projection". The projection is 25% of the shown range, at least 3 days and at most 365 days. Fewer than 2 days of data gives no trend.
3. Compare: each term gets its own dashed trend line in that term's colour. Straight trends only: no curve and no projection in version 1.
4. On/off button "Trend line": default on. The choice is remembered in the browser (localStorage) and in the URL (`trend=0`). The URL wins. Links and forms on the page keep the choice. One switch hides all four date-range lines (straight, its projection, curve, its projection).
5. The Stats tab has a row "Trend (straight line)": about +$X billion per day. It also has "Growth curve: about +X% per year".
6. The growth curve IS built (Rob approved it for version 1). It is drawn beside the straight line on the date-range chart.
    - **a.** Method: least squares on ln(value) against calendar days. Days with a non-positive value are skipped.
    - **b.** Annual growth % comes from the fitted slope and is shown on the Stats tab. R² is measured on the dollar scale and sent in the chart data, but the page does not show it.
    - **c.** Its own projection follows the same rules as the straight line: 25% of the range, at least 3 days, at most 365 days.
    - **d.** Look: straight line solid red, its projection dashed red. Curve dotted, its projection dash-dot, in the `--trend-curve` shade (darker red in light mode, paler pink in dark mode). The dotted pattern is the real way to tell it from the straight line.
    - **e.** Test data: `tests/fixtures/debt_monthly_2000_2026.json`. Node checks: `tests/js/*.check.js`.

## 7. Run locally

1. From the repo root: `USDEBT_BASE_URL=http://localhost:8000 php -S localhost:8000 -t src/Public`. This starts on PHP 8.2 (checked 2026-11-12).
    - **a.** Always set `USDEBT_BASE_URL` locally. Without it, every link, including the card link, points under `https://bengusa.pro/ourusdebt` and will not work on localhost.
    - **b.** To also draw the share picture card, start it with gd loaded: `USDEBT_BASE_URL=http://localhost:8000 php -d extension=gd -S localhost:8000 -t src/Public`. Without gd, `card.php` shows a short plain-text error and everything else works.
2. Open `http://localhost:8000/`.
3. Set `USDEBT_DEV=1` to show errors on the page while developing. Without it, errors go to the log only.
4. `index.php` loads `bootstrap.php` with `__DIR__`, so it does not depend on the working folder. Keep `src/Public` as the document root.

## 8. Tests

1. Install: `php composer.phar install`. Run: `php vendor/bin/phpunit`.
2. Tests live in `tests/` (outside `./src`): trend, cache, compare, client, front controller, views, stats and dates, presidents, share links and picture card, and the docs check (the §3 file map). Run with gd loaded (`php -d extension=gd vendor/bin/phpunit`) so the card tests run instead of skipping.
    - **a.** `tests/js/*.check.js` (for example `with-trend.check.js`, `trend-lines.check.js`, `per-format.check.js` for the dollar formatting, `tab-memory.check.js` for the saved-tab cookie, `tab-switch.check.js` for the checkbox wiring, which also counts the cookie writes) check the page script in Node. The view tests run it when Node is installed, and skip that part when it is not.
    - **b.** USD-10 tests: `tests/Unit/DownloadTest.php` (the CSV: rows match the Table, per modes, formula guard, quoting, file names, the 400 / 503 / 500 answers, the button) and `tests/Unit/PageUpdatesTest.php` (logo link, footer copyright, every off-site link in a new tab, the saved-tab checkbox and cookie path, and a Node run of the cookie code).
3. New or changed behaviour needs tests.

## 9. Errors

1. Errors show on the page only with `USDEBT_DEV=1`. Otherwise they are logged only.
2. No session. No version or timing footer.
3. Compare is not capped. Unknown or duplicate picks are ignored. Two terms of one president (for example Donald Trump) become one continuous line. Compare needs at least 2 valid picks; with fewer, the visitor sees "Please pick at least 2 presidents to compare." (`RequestModel::setComparePres`).

## 10. Deploy

1. Live state, 2026-11-14: `https://bengusa.pro/ourusdebt/` has been live since 2026-11-13 01:26 UTC, served from master `de55c9b` (USD-7 Your Share plus USD-8). The old internal name `usdebt.bengusa.pro` is no longer the public address. History: USD-4 was installed 2026-11-12 under that internal name, with no public route yet.
    - **a.** Rob's go for the 2026-11-12 deploy: *"It is up, and it goes to the bengusa.pro server on lightsail."*
    - **b.** Rob's go for the move to `/ourusdebt/`, 2026-11-13: *"Just deploy the code. THis is taking way too long."*
2. Public address: `https://bengusa.pro/ourusdebt/` (Rob, 2026-11-13). The bare `/ourusdebt` redirects to `/ourusdebt/`.
    - **a.** The base-path rule is ONE setting for every output: `USDEBT_BASE_URL`. The page links, share links, preview tags, the card link, the CSV download link and the saved-tab cookie path all follow it. When it is unset or invalid, the default `https://bengusa.pro/ourusdebt` (in `ShareLinks`) applies to all of them. Production sets it.
    - **b.** ⚠ SUPERSEDED 2026-11-13 (Rob): `usdebt.bengusa.pro` (approved 2026-11-12) as the public address.
3. Host: the existing bengUSA.pro AWS Lightsail server, shared with the bengusa.pro site and pay.bengusa.pro. Not its own box, and no separate distribution. Never standard AWS.
    - **a.** Why: to keep costs down. Rob, 2026-11-13: *"In order to keep the costs down, rather than usdebt.com, let's add a page, bengusa.pro/ourusdebt, and have a page for it there."*
    - **b.** UsDebt has its own nginx + PHP 8.5 pool on that server, capped at 15% of one CPU and 256 MB (this matches the live USD-4 setup).
    - **c.** Live since 2026-11-13: the bengusa.pro front door routes `/ourusdebt/` to that pool. The bengusa.pro Home link that opens in a new tab (`target="_blank"`, `rel="noopener"`) is a change in the other site's repo.
    - **d.** ⚠ SUPERSEDED 2026-11-13 (Rob): "its own small AWS Lightsail server (about $5 a month)".
4. Allow-list deploy: only `./src` ships. Rollback in one step.
    - **a.** ⚠ Release step for USD-10, NOT done yet: the host nginx today runs only `index.php` and `card.php` (every other `.php` returns 404). Without the steps below the Table's download button gets a 404. These kit files live outside the repo (named by file name only). Production waits for Rob's direct go (rule 5). Checklist:
        - **i.** Host `20-usdebt.conf`, before `location ~ \.php(/|$)`: add a `location = /download.php` block with `limit_except GET { deny all; }`, `limit_req zone=usdebt_dl_ip burst=5 nodelay;`, `limit_req zone=usdebt_page_all burst=10;`, `include /etc/nginx/usdebt/php.conf;`, `fastcgi_param SCRIPT_FILENAME $realpath_root/download.php;` and `fastcgi_param SCRIPT_NAME /download.php;`.
        - **ii.** Host `10-usdebt-zones.conf`: add `limit_req_zone $usdebt_client zone=usdebt_dl_ip:5m rate=1r/s;` and two `$usdebt_cc` map lines: `"~^\|200\|/download\.php$" "public, max-age=300";` and `"~^\|[0-9]+\|/download\.php$" "no-store";`.
        - **iii.** Deploy lists: add `src/Public/download.php` to the file-presence checks in `deploy-shared.sh` and `usdebt-release.sh`, plus a smoke check for a 200 with `text/csv`.
        - **iv.** The CDN keeps forwarding no cookies. Otherwise the cache splits per visitor.
5. Every production deploy waits for Rob's direct go. The 2026-11-12 go (1.a) covered the deploy at the internal name. The 2026-11-13 go (1.b) covered the move to `/ourusdebt/`. Every later release, USD-10 included, needs its own direct go.
6. Plan order: USD-0 repo setup, USD-1 safe backend, USD-2 look and feel (Chart.js 4), USD-3 trend line, features (share link + picture card, Your Share, Since you were born), USD-4 deploy (installed 2026-11-12 at the internal name), USD-8 move under `/ourusdebt/` (live 2026-11-13). Rob's review stops are listed in `docs/requirements.md`.

## 11. Git rules

1. Branch names start with the ticket number, e.g. `USD-1-short-description`. One ticket, one branch.
2. Branch off the latest `development`; open a PR into `development`. `development` exists and is the GitHub default branch.
3. After a PR is merged, delete its branch (local and remote).
4. Commit messages: say what changed and why, every change covered. Never name AI tools, agents or reviewers in commits or docs. Use roles ("tests", "security review").
5. No AI-tool attribution in commits or PRs: no Co-Authored-By line for an AI tool, no "generated with" line. Rob's decision 2026-11-12.

## 12. Conventions

1. Loops continue on a per-item error. One bad item must not stop the rest. Collect and report failures at the end.
2. Plain English in docs. Short sentences.
3. Scratch files go in a scratch folder outside the repo. Never in the repo.
4. Do not commit secrets. None are needed today (the Treasury API is public).
5. The old "known cleanups" list (print_r logging, raw exception dump, session regenerate, unchecked input in the URL) was fixed in USD-1. Still open: server hardening, planned for USD-4.
