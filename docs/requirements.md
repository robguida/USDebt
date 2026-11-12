# UsDebt — requirements (living document)

Last updated: 2026-11-12. Owner of decisions: Rob Guida.

Status words: **approved 2026-11-12** = Rob said yes. **built** = in the code on branch USD-3-trend-line. **open** = still waiting on an answer. **next** = approved, not started.

## Purpose

Put UsDebt on bengUSA.pro as a public demo. End-users can play with it while the full software is still being prepared.

"Projectory" in Rob's request is read as "trajectory" (a trend line).

## Plan (approved 2026-11-12)

1. **USD-0** — repo setup. Done.
2. **USD-1** — safe backend: daily cache, timeouts, error handling, remove the dead debug page and unused libraries, PHP upgrade, tests. Done.
3. **USD-2** — look and feel, one chart library (Chart.js 4), neutral term colors. Done.
4. **USD-3** — trend line. Built (straight line and projection). Waiting on Rob's review stop.
5. **Features, in Rob's order (next):** share link + picture card, Your Share, Since you were born.
6. **USD-4** — deploy to `usdebt.bengusa.pro`. Not started. Going live waits for Rob's direct go.

Rob's review stops, in build order (the build runs all day and stops only here):

1. When the red trend line is implemented. Reached.
2. When "Share link + picture card" is done.
3. When "Your Share" is done.
4. When "Since you were born" is done.

## Requirements

1. **R1 — Look and feel.**
    - **a.** Make the site look good. Today it looks bad (Rob). Status: built.
    - **b.** Must work on a phone as well as a desktop. Status: built. Checked at 360, 768 and 1280 pixels wide.
    - **c.** One chart library: Chart.js 4.5.1. Term colors are neutral. Red is kept for the trend. Status: built (plan USD-2).
    - **d.** User-picked chart colors. Rob asked only how hard it is. Status: open. Not built, not approved to build.
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
    - **e.** Version 1 uses the straight line (Rob's choice). A curved (percent-growth) line is common for long ranges; adding it as a second line is estimated at 6–8 hours. Whether it goes in version 1 or version 2 is Rob's call (open).
        - **i.** Curves are common for long ranges. Estimate: 6 to 8 hours.
        - **ii.** Why it matters: on a 2000-to-today range, the straight projection lands below today's debt. A curve would follow the data better.
    - **f.** The Stats tab shows "Trend (straight line): about +$X billion per day". Status: built.
3. **R3 — Trend line on/off button.**
    - **a.** A "Trend line" button turns the trend line and projection off and on. Status: built.
    - **b.** Default is on. The choice is remembered in the browser and in the URL (`trend=0`), so a shared link opens the same view.
4. **R4 — Deploy.**
    - **a.** Address: `usdebt.bengusa.pro`. Status: approved 2026-11-12.
    - **b.** Host: its own small AWS Lightsail server (about $5 a month). nginx and PHP.
    - **c.** Deploy ships `./src` only (allow-list). Rollback in one step.
    - **d.** Going live waits for Rob's direct go. Status: not started.
5. **R5 — More features, built in this order.** Status: approved 2026-11-12. Next.
    - **a.** Share link + picture card. Review stop when done.
    - **b.** Your Share. Review stop when done.
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

1. **R2e:** Version 1 uses the straight line (Rob's choice). A curved (percent-growth) line is common for long ranges; adding it as a second line is estimated at 6–8 hours. Whether it goes in version 1 or version 2 is Rob's call (open).
2. **R1d:** user-picked chart colors (4 to 6 hours in browser storage, 7 to 9 hours as a cookie). Rob decides whether to build.
3. **R1:** any look Rob likes (a site to copy, colors, logo)?
4. **R4:** public with no login, or behind a password for now?

Answered 2026-11-12: Compare list stays uncapped. Projection into the future: yes. Address: `usdebt.bengusa.pro`. Treasury calls are cached daily (one full-dataset copy). PHP 8.5, no Docker. Gap-between-terms run is in version 1. Phone support: built and checked.
