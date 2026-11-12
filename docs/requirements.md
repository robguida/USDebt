# UsDebt — requirements (living document)

Last updated: 2026-11-12. Owner of decisions: Rob Guida.

Status words: **approved 2026-11-12** = Rob said yes. **built** = in the code on branch USD-7-your-share (which also carries USD-1 to USD-6); not merged into `development` yet. **open** = still waiting on an answer. **next** = approved, not started.

## Purpose

Put UsDebt on bengUSA.pro as a public demo. End-users can play with it while the full software is still being prepared.

"Projectory" in Rob's request is read as "trajectory" (a trend line).

## Plan (approved 2026-11-12)

1. **USD-0** — repo setup. Done.
2. **USD-1** — safe backend: daily cache, timeouts, error handling, remove the dead debug page and unused libraries, PHP upgrade, tests. Done.
3. **USD-2** — look and feel, one chart library (Chart.js 4), neutral term colors. Done.
4. **USD-3** — trend line. Built (straight line and projection). Waiting on Rob's review stop.
    - **a.** **USD-5** — growth curve beside the straight line, each with its own projection. Built.
5. **Features, in Rob's order:** share link + picture card (**USD-6**, built, waiting on Rob's review stop). **USD-7** Your Share (built, waiting on Rob's review stop). Next: Since you were born.
6. **USD-4** — deploy to `usdebt.bengusa.pro`. Not started. Going live waits for Rob's direct go.

Rob's review stops, in build order (the build runs all day and stops only here):

1. When the red trend line is implemented. Reached.
2. When "Share link + picture card" is done. Reached.
3. When "Your Share" is done. Reached.
4. When "Since you were born" is done.

## Requirements

1. **R1 — Look and feel.**
    - **a.** Make the site look good. Today it looks bad (Rob). Status: built.
    - **b.** Must work on a phone as well as a desktop. Status: built. Checked at 360, 768 and 1280 pixels wide.
    - **c.** One chart library: Chart.js 4.5.1. Term colors are neutral. Red is kept for the trend. Status: built (plan USD-2).
    - **d.** User-picked chart colors. Moved to version 2. Rob: "Q3, Wait". Status: later, not built.
        - **i.** Estimate: 4 to 6 hours if saved in browser storage. 7 to 9 hours if saved as a cookie.
2. **R2 — Trend line and projection on each chart.**
    - **a.** A red straight line through the data shown. Status: built.
    - **b.** Applies to every graph. Status: built.
        - **i.** Date-range chart: one red straight line, plus the dashed projection.
        - **ii.** Compare chart: each president's own dashed trend line, in that president's color. No projection.
    - **c.** The line is STRAIGHT in version 1. Rob: required. Status: built.
        - **i.** It is a least-squares line over calendar days, so weekends and holidays keep their true width.
        - **ii.** No trend when the range has fewer than 2 days.
    - **d.** A dashed red line labeled "Projection" continues the trend into the future. Status: built.
        - **i.** Length: 25% of the shown range, at least 3 days, at most 365 days.
    - **e.** A growth curve (steady percent growth) is built in version 1, next to the straight line. Rob: "Q2, Sure". Status: built.
        - **i.** Method: least squares on ln(value) against calendar days. Non-positive days are skipped. The page shows annual growth %. (R² is worked out in the code and sent to the chart data, but the page does not display it.)
        - **ii.** Its own projection follows the same rules as the straight line (25% of range, at least 3 days, at most 365 days).
        - **iii.** Look: curve in a different red shade, dotted, its projection dash-dot. Straight line solid red, its projection dashed red. One switch hides all four. The shade is darker red in light mode and a paler pink in dark mode, so the dotted pattern is what really tells the curve apart from the straight line.
        - **iv.** Compare keeps straight per-president trends only. No curve in version 1.
        - **v.** Example, range 2000 to today, live daily data on 2026-11-12: today's debt is $40.56T. The straight projection reaches $36.13T. The curve projection reaches $46.03T by Nov 9, 2027 (about 8.1% a year). The curve follows the data better. (The monthly test data gives slightly different numbers: 36.21, 46.00 and 8.05%.)
    - **f.** The Stats tab shows "Trend (straight line): about +$X billion per day" and "Growth curve: about +X% per year". Status: built.
3. **R3 — Trend line on/off button.**
    - **a.** A "Trend line" button turns the trend line and projection off and on. Status: built.
    - **b.** Default is on. The choice is remembered in the browser and in the URL (`trend=0`), so a shared link opens the same view.
4. **R4 — Deploy.**
    - **a.** Address: `usdebt.bengusa.pro`. Status: approved 2026-11-12.
    - **b.** Host: its own small AWS Lightsail server (about $5 a month). nginx and PHP.
    - **c.** Deploy ships `./src` only (allow-list). Rollback in one step.
    - **d.** Going live waits for Rob's direct go. Status: not started.
5. **R5 — More features, built in this order.** Status: approved 2026-11-12.
    - **a.** Share link + picture card. Status: BUILT (USD-6). Review stop reached.
        - **i.** Share links are plain GET links for every view: `/?start_date=…&end_date=…[&trend=0]` and `/?compare=id,…`. The Compare form POST still works. No cap on the Compare list.
        - **ii.** `card.php` draws a 1200×630 PNG picture card (DejaVu Sans font). Cards are cached in the `cards` folder inside the cache folder: at most 500 files, oldest removed first.
        - **iii.** Each page has og and twitter preview tags. Links in them are absolute and built from `USDEBT_BASE_URL`, never from the request's Host header.
        - **iv.** Share menu: copy link, download picture, plain links to X, Facebook, LinkedIn, Reddit and email, and the phone's own share sheet where available. The menu's picture links start at the site root (`/card.php?…`), so they work from any page address.
        - **v.** No third-party scripts. Share wording lives in `ShareText.php`: the card, the preview tags, the share and email text, the menu's labels, the "Link copied" lines and the card's two error lines. Two card errors are not share wording and use the site's own messages: bad input (for example too few Compare picks) and an unexpected failure.
        - **vi.** The server needs PHP gd with FreeType. Without it the card returns a short plain-text error, and the rest of the site works.
        - **vii.** Compare totals on the card and on the Compare page are the same numbers: each term from its first data day to its last data day. Two terms of one president add up both terms' changes. Example, live data on 2026-11-12: Joseph Biden +$8.45 trillion (an earlier build showed +$8.42 trillion, because it stopped at the first day of the term's last month).
    - **b.** Your Share. Status: BUILT (USD-7). Review stop reached. Next: "Since you were born".
        - **i.** A "Show numbers as: Total / Per person / Per household" switch at the top of the page. The choice is `per=` in the link (left out for Total) and carries through every page link, the share links, the picture card and Compare.
        - **ii.** Population and households come from Census figures, 1993 to 2025, kept in `src/lib/Data/us_population_households.json` with its sources and notes. Population is pinned to July 1 and households to March 15 of each year. Between two years the number moves in a straight line, day by day. For dates after the latest figure (2025), the latest figure is held. No guessing ahead.
        - **iii.** The change is the end value per person (or household) minus the start value per person. Per day is that change divided by the calendar days. The trend line and growth curve are fitted on the per-person series. Compare works in each mode. Charts and cards show dollars.
        - **iv.** The Census population series has a step between 2019 and 2020 (different Census estimate releases). It is kept as published, not smoothed. The page carries a Census footnote.
        - **v.** Example, data of Nov 9, 2026: $118,670.55 per person, $300,911.02 per household.
    - **c.** Since you were born. Review stop when done.
        - **i.** Treasury data does not go back to every birth year. If the birth year is too early, show a funny, friendly alert. Example: "You are too old! Sorry!"
        - **ii.** The joke is about the data being young, not the person. Neutral, never mean.
6. **R6 — Backend (plan USD-1).** Status: built.
    - **a.** Cache the Treasury data once per day. Answered: one daily copy of the full dataset. Why: one download serves every request, and Compare costs zero extra Treasury calls.
        - **i.** Download is paginated and never saves partial data. 10 second timeout per page, 20 MB cap.
        - **ii.** If the download fails: the saved copy is shown with a notice, and there is a 15-minute wait before the next try.
        - **iii.** If the cache folder cannot be written, nothing is downloaded.
    - **b.** Timeouts and error handling on the Treasury call. Error pages use HTTP 400, 503 and 500.
    - **c.** The old dead debug page and unused libraries (jQuery, jQuery UI, Flot, Chart.js 2.6) are deleted.
    - **d.** PHP: latest stable version the server supports. Answered: production targets PHP 8.5 on a dedicated server, no Docker. Local development runs on 8.2.
    - **e.** Tests for new and changed behavior (PHPUnit 11).
7. **R7 — Compare list.** Status: built.
    - **a.** The Compare list is NOT capped. Rob: it is one of the most important pages, because all presidents can be stacked against each other.
    - **b.** When a president has a gap between terms and both terms are selected, show the data as one continuous run.
    - **c.** The Compare area is labeled "View by presidential term", with a caption that Congress sets spending and taxes.

## Open questions

1. **R1:** any look Rob likes (a site to copy, colors, logo)?
2. **R4:** public with no login, or behind a password for now?

Answered 2026-11-12: **Site name is "Our U.S. Debt"** (Rob). It is written in one place only, `SiteConfig::NAME`, and used for the page header, the page title, the share preview, the picture cards and the share text. The old "U.S. Debt Tracker" wording is dropped.
Answered 2026-11-12: Curved trend line is in version 1 (Rob: "Q2, Sure"). User-picked chart colors wait for version 2 (Rob: "Q3, Wait").
Decision 2026-11-12: no AI-tool attribution in commits or PR descriptions (no co-author line, no "generated with" line). Rob: "No drop it ... I pay the bill, and I do not pay [the tool maker] to advertise for them."
Answered 2026-11-12: Compare list stays uncapped. Projection into the future: yes. Address: `usdebt.bengusa.pro`. Treasury calls are cached daily (one full-dataset copy). PHP 8.5, no Docker. Gap-between-terms run is in version 1. Phone support: built and checked.
