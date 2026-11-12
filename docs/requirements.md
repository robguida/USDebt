# UsDebt — requirements (living document)

Last updated: 2026-11-12. Owner of decisions: Rob Guida.

Status words: **approved 2026-11-12** = Rob said yes. **open** = still waiting on an answer or an estimate.

## Purpose

Put UsDebt on bengUSA.pro as a public demo. End-users can play with it while the full software is still being prepared.

"Projectory" in Rob's request is read as "trajectory" (a trend line).

## Plan (approved 2026-11-12)

1. **USD-0** — repo setup.
2. **USD-1** — safe backend: daily cache, timeouts, error handling, remove the dead debug page and unused libraries, PHP upgrade, tests.
3. **USD-2** — look and feel, one chart library (Chart.js 4), neutral term colors.
4. **USD-3** — trend line.
5. **Features, in Rob's order:** share link + picture card, Your Share, Since you were born.
6. **USD-4** — deploy to `usdebt.bengusa.pro`. Going live waits for Rob's direct go.

Rob's review stops (the build runs all day and stops only here):

1. When the red trend line is implemented.
2. When "Your Share" is done.
3. When "Since you were born" is done.
4. When "Share link + picture card" is done.

## Requirements

1. **R1 — Look and feel.**
    - **a.** Make the site look good. Today it looks bad (Rob).
    - **b.** Must work on a phone as well as a desktop (assumed, to confirm).
    - **c.** One chart library: Chart.js 4. Term colors are neutral. Status: approved 2026-11-12 (plan USD-2).
    - **d.** User-picked chart colors, saved in a cookie. Rob asked only how hard it is. Status: open. Estimate pending. Not approved to build.
2. **R2 — Trend line and projection on each chart.**
    - **a.** A red straight line through the data shown, up the center of the graph. Status: approved 2026-11-12.
    - **b.** Applies to every graph (date range chart and president compare charts).
    - **c.** The line is STRAIGHT in version 1. Rob: required. Status: approved 2026-11-12.
    - **d.** A dashed line labeled "Projection" continues the trend into the future. Status: approved 2026-11-12.
    - **e.** A curved line instead of straight. Team gives an hours estimate. Rob decides: version 1 or version 2. Status: open.
3. **R3 — Trend line on/off button.**
    - **a.** A button turns the trend line and projection off and on. Status: approved 2026-11-12.
4. **R4 — Deploy.**
    - **a.** Address: `usdebt.bengusa.pro`. Status: approved 2026-11-12.
    - **b.** Host: its own small AWS Lightsail server (about $5 a month). nginx and PHP.
    - **c.** Deploy ships `./src` only (allow-list). Rollback in one step.
    - **d.** Going live waits for Rob's direct go.
5. **R5 — More features, built in this order.** Status: approved 2026-11-12.
    - **a.** Share link + picture card. Review stop when done.
    - **b.** Your Share. Review stop when done.
    - **c.** Since you were born. Review stop when done.
        - **i.** Treasury data does not go back to every birth year. If the birth year is too early, show a funny, friendly alert. Example: "You are too old! Sorry!"
        - **ii.** The joke is about the data being young, not the person. Neutral, never mean.
6. **R6 — Backend (plan USD-1).**
    - **a.** Cache the Treasury data once per day. Rob: once per day for each unique request, unless there is a more efficient way. Status: approved 2026-11-12. Method open. The team confirms which is more efficient: one daily copy per unique request, or one daily copy of the full dataset.
    - **b.** Timeouts and error handling on the Treasury call. Status: approved 2026-11-12.
    - **c.** Delete the old dead debug page (`src/Public/index.topdown.php`) and unused libraries. Status: approved 2026-11-12.
    - **d.** PHP: latest stable version the server supports. Status: approved 2026-11-12. Docker only if needed to avoid conflicts. Rob wants to be told first. Status: open.
    - **e.** Tests for new and changed behavior. Status: approved 2026-11-12.
7. **R7 — Compare list.**
    - **a.** The Compare list is NOT capped. Rob: it is one of the most important pages, because all presidents can be stacked against each other. Status: approved 2026-11-12.
    - **b.** When a president has a gap between terms and both terms are selected, show the data as one continuous run. Version 1 if it takes under a day. If longer, version 2. Status: open. Estimate pending.

## Open questions

1. **R2e:** curved line: hours estimate, then Rob picks version 1 or version 2.
2. **R1d:** user-picked chart colors in a cookie: estimate, then Rob decides.
3. **R6a:** cache method: per unique request, or one full-dataset copy per day.
4. **R6d:** does the server need Docker for the latest PHP?
5. **R7b:** gap-between-terms run: under a day (version 1) or version 2?
6. **R1:** any look Rob likes (a site to copy, colors, logo)?
7. **R4:** public with no login, or behind a password for now?

Answered 2026-11-12: Compare list stays uncapped. Projection into the future: yes. Address: `usdebt.bengusa.pro`. Treasury calls are cached daily.
