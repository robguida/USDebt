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
    - **a.** **USD-5** — growth curve beside the straight line, each with its own projection. Built.
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
        - **i.** Method: least squares on ln(value) against calendar days. Non-positive days are skipped. It shows annual growth % and R² (on the dollar scale).
        - **ii.** Its own projection follows the same rules as the straight line (25% of range, at least 3 days, at most 365 days).
        - **iii.** Look: curve dark-red dotted, its projection dark-red dash-dot. Straight line solid red, its projection dashed red. One switch hides all four.
        - **iv.** Compare keeps straight per-president trends only. No curve in version 1.
        - **v.** Example, range 2000 to today: today's debt is $40.56T. The straight projection reaches $36.13T. The curve projection reaches $46.03T by Nov 9, 2027 (about 8.1% a year). The curve follows the data better.
    - **f.** The Stats tab shows "Trend (straight line): about +$X billion per day" and "Growth curve: about +X% per year". Status: built.
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

1. **R1:** any look Rob likes (a site to copy, colors, logo)?
2. **R4:** public with no login, or behind a password for now?

Answered 2026-11-12: Curved trend line is in version 1 (Rob: "Q2, Sure"). User-picked chart colors wait for version 2 (Rob: "Q3, Wait").
Decision 2026-11-12: no AI-tool attribution in commits or PR descriptions (no co-author line, no "generated with" line). Rob: "No drop it ... I pay the bill, and I do not pay [the tool maker] to advertise for them."
Answered 2026-11-12: Compare list stays uncapped. Projection into the future: yes. Address: `usdebt.bengusa.pro`. Treasury calls are cached daily (one full-dataset copy). PHP 8.5, no Docker. Gap-between-terms run is in version 1. Phone support: built and checked.
