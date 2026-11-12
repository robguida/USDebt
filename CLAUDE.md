# UsDebt — project guide

Source of truth for anyone (human or tool) working on this project.
Owner: Rob Guida (RandBMedia, his own company; not a client project).

## 1. What it is

1. A small PHP web app that shows the US national debt as tables, charts and stats.
2. Data comes from the U.S. Treasury (fiscaldata "Debt to the Penny"), downloaded once a day and saved.
3. Presidents can be compared side by side (debt during each term).
4. Goal now: a public demo on bengUSA.pro. See `docs/requirements.md`.

## 2. Stack

1. PHP 8.2 or newer (`composer.json` says `>=8.2`). Production targets PHP 8.5 on Amazon Linux 2023.
2. Composer 2 (`composer.phar` at the repo root). Dev dependency: PHPUnit 11.
3. Charts: Chart.js (`Chart.bundle.min.js`) and Flot (`flot-master`), vendored.
4. jQuery and jQuery UI, vendored.
5. No framework. Plain PHP classes plus include-style view files.

## 3. Layout

Rule: `./src` is the root of the deployed code. Only `./src` ships.

1. `src/Public/` — the web root (document root).
    - **a.** `index.php` — entry point. Hands the request to `FrontController`.
    - **b.** `css/usdebt.css` — site styles.
    - **c.** `images/` — president photos and site images.
    - **d.** `lib/` — vendored libraries: `Chart.bundle.min.js`, `jquery.js`, `jquery-ui/`, `flot-master/`.
2. `src/lib/` — app code, outside the web root.
    - **a.** `bootstrap.php` — error settings, timezone (America/New_York), class autoloader. No session.
    - **b.** `Controller/FrontController.php` — turns one request into one page. Visitors only see friendly messages.
    - **c.** `Controller/USDebtController.php` — builds the default view, compare view and error view. Loads view files.
    - **d.** `Model/RequestModel.php` — the request values (dates, presidents to compare). Also `DebtEntry.php`, `DebtDataset.php`.
    - **e.** `Service/FiscalDataClient.php` — downloads the full Treasury history in one call.
    - **f.** `Service/DebtRepository.php` — the daily cache. Serves every page from the saved copy.
    - **g.** `Service/CompareService.php` — checks the picked terms and builds the Compare series.
    - **h.** `Service/StatsCalculator.php`, `ChartData.php`, `Html.php` — stats, chart data shaping, safe HTML output.
    - **i.** `Service/PresidentService.php` — fixed list of presidents (term dates, photo, chart colour).
    - **j.** `Service/ValidateDateTime.php` — date checks.
    - **k.** `Http/`, `Exception/` — the HTTP fetcher and the error types.
    - **l.** `Data/debt_seed_1993.json` — debt for 1993-01-04 to 1993-03-31, before the API's first day.
    - **m.** `View/` — `master.php` (page frame), `search_form.php`, `tabs.php`, `tab_data.php`, `tab_stats.php`, `tab_graph_Chart.php` (default view), `tab_graph_Flot.php` (compare view).
3. `src/var/cache/` — the saved Treasury copy. Gitignored. Never web-served.
4. Top level, not shipped: `composer.json`, `composer.phar`, `tests/`, `docs/`, this file.
5. Stray untracked files at the top (`index.php`, `scratch.php`) are not part of this app. Do not ship them or commit them without Rob's say-so.
6. Anything new that runs the product goes in `./src`. Tests, scripts and docs go outside it.

## 4. Data source

1. Source: fiscaldata "Debt to the Penny" (`api.fiscaldata.treasury.gov`).
2. One full-history download per day (by the New York date). Saved as `src/var/cache/treasury-debt.json`. Set `USDEBT_CACHE_DIR` to use another folder. The server's PHP must be able to write there.
3. Download timeout: 10 seconds. If it fails, the last saved copy is shown with a notice, and the app retries after 15 minutes. With no saved copy, the visitor sees a friendly error.
4. Every page and every Compare president is cut from the one saved copy. Compare costs zero extra Treasury calls.
5. Watch item: the API returns at most 10,000 rows per page. Newest rows come first, so only the oldest would be cut off. Check this as the history grows.
6. Presidents and term dates are hard-coded in `PresidentService`. Terms end on January 19, so no day is in two terms.

## 5. Run locally

1. From the repo root: `php -S localhost:8000 -t src/Public`
2. Open `http://localhost:8000/`.
3. Set `USDEBT_DEV=1` to show errors on the page while developing. Without it, errors go to the log only.
4. Unverified (not checked in this review): that the run command works on PHP 8.2. `bootstrap.php` is loaded by relative path, so keep `src/Public` as the document root.

## 6. Tests

1. Install: `php composer.phar install`. Run: `php vendor/bin/phpunit`.
2. Tests live in `tests/` (outside `./src`).
3. New or changed behaviour needs tests.

## 7. Errors

1. Errors show on the page only with `USDEBT_DEV=1`. Otherwise they are logged only.
2. No session. No version or timing footer.
3. Compare is not capped. Unknown or duplicate picks are ignored. Two terms of one president (for example Donald Trump) become one continuous line. Compare needs at least 2 valid picks; with fewer, the visitor sees "Please pick at least 2 presidents to compare." (`RequestModel::setComparePres`).

## 8. Deploy

1. Address: `usdebt.bengusa.pro` (approved 2026-11-12).
2. Host: its own small AWS Lightsail server (about $5 a month), nginx + PHP. Never standard AWS.
3. Allow-list deploy: only `./src` ships. Rollback in one step.
4. Every production deploy waits for Rob's direct go. Going live is plan step USD-4.
5. Plan order: USD-0 repo setup, USD-1 safe backend, USD-2 look and feel (Chart.js 4), USD-3 trend line, features (share link + picture card, Your Share, Since you were born), USD-4 deploy. Rob's review stops are listed in `docs/requirements.md`.

## 9. Git rules

1. Branch names start with the ticket number, e.g. `USD-1-short-description`. One ticket, one branch.
2. Branch off the latest `development`; open a PR into `development`. `development` exists and is the GitHub default branch.
3. After a PR is merged, delete its branch (local and remote).
4. Commit messages: say what changed and why, every change covered. Never name AI tools, agents or reviewers in commits or docs. Use roles ("tests", "security review").

## 10. Conventions

1. Loops continue on a per-item error. One bad item must not stop the rest. Collect and report failures at the end.
2. Plain English in docs. Short sentences.
3. Scratch files go in a scratch folder outside the repo. Never in the repo.
4. Do not commit secrets. None are needed today (the Treasury API is public).
5. The old "known cleanups" list (print_r logging, raw exception dump, session regenerate, unchecked input in the URL) was fixed in USD-1. Still open: server hardening, planned for USD-4.
