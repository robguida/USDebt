# UsDebt — project guide

Source of truth for anyone (human or tool) working on this project.
Owner: Rob Guida (RandBMedia, his own company; not a client project).
Last updated: 2026-11-12 (matches branch USD-7-your-share).

## 1. What it is

1. A small PHP web app that shows the US national debt as tables, charts and stats.
2. Data comes from the U.S. Treasury (fiscaldata "Debt to the Penny"), downloaded once a day and saved.
3. Presidential terms can be compared side by side (debt change during each term).
4. Charts show a straight trend line and a growth curve, each with its own projection, with one on/off button.
5. Goal now: a public demo on bengUSA.pro. See `docs/requirements.md`.

## 2. Stack

1. PHP 8.2 or newer (`composer.json` says `>=8.2`). Local development runs on PHP 8.2. Production targets PHP 8.5 on its own Lightsail server.
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
    - **c.** `css/usdebt.css` — site styles and colour variables.
    - **d.** `js/usdebt.js` — the page script (charts, trend button, tabs, Share menu).
    - **e.** `lib/` — `chart.umd.min.js` and its license file.
    - **f.** `images/` — president photos. `favicon.svg` — site icon.
2. `src/lib/` — app code, outside the web root.
    - **a.** `bootstrap.php` — error settings, timezone (America/New_York), class autoloader. No session.
        - **i.** `SiteConfig.php` — the site's name (`SiteConfig::NAME`), in this one place. The header, page title, link preview tags, share words and picture card all read it. Not final yet ("Our U.S. Debt" or "U.S. Debt Tracker"); change it only here.
    - **b.** `Controller/FrontController.php` — turns one request into one page. Visitors only see friendly messages.
    - **c.** `Controller/USDebtController.php` — builds the date-range view, compare view and error view, and the share tags and Share menu for each. Loads view files.
    - **d.** `Controller/CardController.php` — the picture card: same input checks as the page, the cached PNG or a fresh one, plain-text errors (400, 503, 500).
    - **e.** `Model/` — `RequestModel.php` (dates, picked terms, trend on/off, per mode), `DebtEntry.php`, `DebtDataset.php`, `ShareCard.php` (everything needed to share one view: words, links, cache key, what the card draws), `Per.php` (Your Share: total, per person or per household, from `?per=`).
    - **f.** `Service/FiscalDataClient.php` — downloads the full Treasury history, page by page.
    - **g.** `Service/DebtSource.php` (the interface) and `Service/DebtRepository.php` — the daily cache.
    - **h.** `Service/LogOnce.php` — logs a lasting problem once per 15 minutes across PHP processes (a marker file in `src/var/log-markers`). Used by the Treasury cache and the card cache.
    - **i.** `Service/TrendService.php` — the straight trend line and its projection, and the growth curve (least squares on ln(value)) and its projection.
    - **j.** `Service/CompareService.php` — checks the picked terms and builds the Compare series, with each line's exact total change.
    - **k.** `Service/StatsCalculator.php`, `Service/ChartData.php`, `Service/Html.php` — stats, chart data shaping, safe HTML output.
    - **l.** `Service/PresidentService.php` — fixed list of presidents (term dates, photo, chart colour).
    - **m.** `Service/ValidateDateTime.php` — date checks.
        - **i.** `Service/PopulationService.php` — Your Share: U.S. population and households for any day (straight line between yearly Census figures), and the debt divided by them.
    - **n.** Sharing:
        - **i.** `Service/ShareText.php` — every share word (see §5.6.c).
        - **ii.** `Service/ShareLinks.php` — the share links: absolute page and card links from `USDEBT_BASE_URL`, the root-relative card link for the menu, and the plain links to each social site.
        - **iii.** `Service/ShareCardBuilder.php` — builds the `ShareCard` for a date range or a Compare view.
        - **iv.** `Service/CardRenderer.php` — draws the card with PHP gd.
        - **v.** `Service/CardCache.php` — the card files on disk (at most 500, oldest removed first).
    - **o.** `Http/` — `HttpFetcher.php` (the interface), `NativeHttpFetcher.php`, `Response.php`.
    - **p.** `Exception/` — `UserFacingException.php` (a message safe to show the visitor: 400 by default), `DataUnavailableException.php` (no download and no saved copy: 503), `HttpException.php` (a Treasury request failed), `IncompleteDownloadException.php` (a page or rows are missing, so nothing is saved), `CardUnavailableException.php` (this server cannot draw cards: 503).
    - **q.** `Data/debt_seed_1993.json` — debt for 1993-01-04 to 1993-03-31, before the API's first day.
        - **i.** `Data/us_population_households.json` — Census population (July 1) and households (March), 1993 to 2025, one figure per year, with its sources and notes inside. Public domain.
    - **r.** `Assets/fonts/` — `DejaVuSans.ttf`, `DejaVuSans-Bold.ttf` and their license, for the card. Self-hosted; their checksums are pinned in a test.
    - **s.** `View/` — `master.php` (page frame), `headline.php` (top number cards), `search_form.php` (date range, quick ranges, term strip), `tabs.php`, `chart_daily.php`, `chart_compare.php`, `tab_stats.php`, `tab_data.php`, `share_menu.php` (Share button and menu).
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
    - **b.** Copy link, Download picture (`/card.php`, same site, root-relative so any page address works), a card preview, and plain links to X, Facebook, LinkedIn, Reddit and email. No scripts from social sites. Phones with a system share sheet get "Share with an app" first.
    - **c.** Share words, and where each lives:
        - **i.** `Service/ShareText.php` holds every share word: the card's words, the link preview tags, the share text and email, alt text, the Share menu's labels, the "Link copied" and "Couldn't copy" lines, and the card's two error lines (no data, cannot draw). `View/share_menu.php` has no words of its own, and `js/usdebt.js` reads the copy lines from the menu's `data-copied` and `data-copy-failed` attributes. A test checks all three.
        - **ii.** Not in `ShareText`: a card request that fails its input checks gets the page's own message (for example "Please pick at least 2 presidents to compare."), and an unexpected failure gets the site-wide `FrontController::GENERIC_ERROR`.
    - **d.** The share links are built in `Service/ShareLinks.php` and again in `js/usdebt.js` when the trend switch changes (checked by `tests/js/share-url.check.js`).
    - **e.** Compare totals (the page's summary lines and the card's rows) run from each term's first data day to its last data day. Two terms of one president are the sum of the two terms' changes, the same number the joined chart line is built from. The chart itself has one point per month, so its last point can be up to a month short of the stated total.

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

1. From the repo root: `php -S localhost:8000 -t src/Public`. This starts on PHP 8.2 (checked 2026-11-12).
    - **a.** To also draw the share picture card, start it with gd loaded: `php -d extension=gd -S localhost:8000 -t src/Public`. Without gd, `card.php` shows a short plain-text error and everything else works.
2. Open `http://localhost:8000/`.
3. Set `USDEBT_DEV=1` to show errors on the page while developing. Without it, errors go to the log only.
4. `index.php` loads `bootstrap.php` with `__DIR__`, so it does not depend on the working folder. Keep `src/Public` as the document root.

## 8. Tests

1. Install: `php composer.phar install`. Run: `php vendor/bin/phpunit`.
2. Tests live in `tests/` (outside `./src`): trend, cache, compare, client, front controller, views, stats and dates, presidents, share links and picture card, and the docs check (the §3 file map). Run with gd loaded (`php -d extension=gd vendor/bin/phpunit`) so the card tests run instead of skipping.
    - **a.** `tests/js/*.check.js` (for example `with-trend.check.js`, `trend-lines.check.js`) check the page script in Node. The view tests run it when Node is installed, and skip that part when it is not.
3. New or changed behaviour needs tests.

## 9. Errors

1. Errors show on the page only with `USDEBT_DEV=1`. Otherwise they are logged only.
2. No session. No version or timing footer.
3. Compare is not capped. Unknown or duplicate picks are ignored. Two terms of one president (for example Donald Trump) become one continuous line. Compare needs at least 2 valid picks; with fewer, the visitor sees "Please pick at least 2 presidents to compare." (`RequestModel::setComparePres`).

## 10. Deploy

1. Address: `usdebt.bengusa.pro` (approved 2026-11-12).
2. Host: its own small AWS Lightsail server (about $5 a month), nginx + PHP. Never standard AWS.
3. Allow-list deploy: only `./src` ships. Rollback in one step.
4. Every production deploy waits for Rob's direct go. Going live is plan step USD-4. Not started.
5. Plan order: USD-0 repo setup, USD-1 safe backend, USD-2 look and feel (Chart.js 4), USD-3 trend line, features (share link + picture card, Your Share, Since you were born), USD-4 deploy. Rob's review stops are listed in `docs/requirements.md`.

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
