# UsDebt — project guide

Source of truth for anyone (human or tool) working on this project.
Owner: Rob Guida (RandBMedia, his own company; not a client project).

## 1. What it is

1. A small PHP web app that shows the US national debt as tables, charts and stats.
2. Data comes live from the U.S. Treasury (TreasuryDirect web service).
3. Presidents can be compared side by side (debt during each term).
4. Goal now: a public demo on bengUSA.pro. See `docs/requirements.md`.

## 2. Stack

1. PHP. `composer.json` says `>=5.4`, but `bootstrap.php` passes an options array to `session_start()`, which needs PHP 7.0 or newer. Treat **PHP 7.x or newer as the real minimum**. Exact version to run: unverified.
2. Charts: Chart.js (`Chart.bundle.min.js`) and Flot (`flot-master`), vendored.
3. jQuery and jQuery UI, vendored.
4. No framework. Plain PHP classes plus include-style view files.
5. Dev dependency: `phpunit/phpunit` (`>7`).

## 3. Layout

Rule: `./src` is the root of the deployed code. Only `./src` ships.

1. `src/Public/` — the web root (document root).
    - **a.** `index.php` — entry point. Reads `submit`, `start_date`, `end_date`, `compare_pres`. Picks default view or compare view. Prints a version and timing footer.
    - **b.** `index.topdown.php` — old dead debug page. Scheduled for deletion in USD-1. Do not build on it.
    - **c.** `css/usdebt.css` — site styles.
    - **d.** `images/` — president photos and site images.
    - **e.** `lib/` — vendored libraries: `Chart.bundle.min.js`, `jquery.js`, `jquery-ui/`, `flot-master/`.
2. `src/lib/` — app code, outside the web root.
    - **a.** `bootstrap.php` — session setup, timezone (America/New_York), class autoloader.
    - **b.** `Controller/USDebtController.php` — builds the default view, compare view and error view. Loads view files.
    - **c.** `Model/RequestModel.php` — holds the request values (dates, presidents to compare).
    - **d.** `Service/TreasuryDirect.php` — calls the Treasury web service and decodes the JSON.
    - **e.** `Service/PresidentService.php` — fixed list of presidents (term dates, photo, chart colour).
    - **f.** `Service/ValidateDateTime.php` — date checks.
    - **g.** `View/master.php` — page frame.
    - **h.** `View/search_form.php` — date and president picker.
    - **i.** `View/tabs.php` — tab container.
    - **j.** `View/tab_data.php`, `tab_stats.php` — data table and stats tabs.
    - **k.** `View/tab_graph_Chart.php` — Chart.js graph tab (default view).
    - **l.** `View/tab_graph_Flot.php` — Flot graphs (president compare view).
3. Top level, not shipped: `composer.json`, `composer.phar`, `docs/`, this file.
4. Stray untracked files at the top (`index.php`, `scratch.php`) are not part of this app. Do not ship them or commit them without Rob's say-so.
5. Anything new that runs the product goes in `./src`. Tests, scripts and docs go outside it.

## 4. Data source

1. `TreasuryDirect::httpRequest($start, $end)` calls `https://www.treasurydirect.gov/NP_WS/debt/search` with `format=json`.
2. It uses `file_get_contents`, so `allow_url_fopen` must be on.
3. Caching code exists but is effectively dead (it checks `acp_exists` but then calls `apc_*`). Plan USD-1 replaces it: save the Treasury data once per day. Method to confirm: one copy per unique request, or one full-dataset copy.
4. Presidents and term dates are hard-coded in `PresidentService`.

## 5. Run locally

1. From the repo root: `php -S localhost:8000 -t src/Public`
2. Open `http://localhost:8000/`.
3. Unverified: not run by the writer. `bootstrap.php` is loaded by relative path (`../lib/bootstrap.php`), so keep `src/Public` as the document root.

## 6. Tests

1. `phpunit` is listed in `require-dev`, but **no tests exist yet**.
2. New or changed behaviour needs tests (outside `./src`, e.g. `tests/`).

## 7. Deploy

1. Address: `usdebt.bengusa.pro` (approved 2026-11-12).
2. Host: its own small AWS Lightsail server (about $5 a month), nginx + PHP. Never standard AWS.
3. Allow-list deploy: only `./src` ships. Rollback in one step.
4. Every production deploy waits for Rob's direct go. Going live is plan step USD-4.
5. Plan order: USD-0 repo setup, USD-1 safe backend, USD-2 look and feel (Chart.js 4), USD-3 trend line, features (share link + picture card, Your Share, Since you were born), USD-4 deploy. Rob's review stops are listed in `docs/requirements.md`.

## 8. Git rules

1. Branch names start with the ticket number, e.g. `XXX-123-short-description`. One ticket, one branch.
2. Branch off the latest `development`; open a PR into `development`.
3. Branch setup is still pending: the repo has only `master` today.
4. After a PR is merged, delete its branch (local and remote).
5. Commit messages: say what changed and why, every change covered. Never mention the AI team or any agent or persona name. Use roles ("tests", "security review").

## 9. Conventions

1. Loops continue on a per-item error. One bad item must not stop the rest. Collect and report failures at the end.
2. Plain English in docs. Short sentences.
3. Scratch files only under `C:\Users\robgu\Downloads\scratch\`. Never in the repo or elsewhere on C:.
4. Do not commit secrets. None are needed today (the Treasury API is public).
5. Known cleanups (not yet ticketed): `error_log` calls with `print_r` in `compareView`; raw exception dump in `index.php`; `session_regenerate_id` on every request; user input goes straight into the Treasury URL (check dates first).
