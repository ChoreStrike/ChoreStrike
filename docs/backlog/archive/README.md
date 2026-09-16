# Backlog Archive

Completed backlog history, **tracked in Git** (unlike the repo-root
[`../../../archive/`](../../../archive/) which is gitignored local reference).

## Convention

- **One file per month:** `YYYY-MM.md` groups everything that was **completed /
  closed / shipped / rejected** in that month — shipped plans, closed bugs,
  resolved tech-debt items, and resolved review follow-ups.
- **Review records:** dated `CODE_REVIEW_YYYY-MM-DD.md` files live here too
  (kept verbatim — they are the primary evidence for the findings).
- **Open items never live here** — open items live in
  [`../backlog.md`](../backlog.md). An item is moved here only once it is
  **Done** (or explicitly rejected/wontfix).
- **Granularity:** one-line entries with a link to the plan / review record.
  Full per-item scope lives in [`../../plans/done/`](../../plans/done/) while
  the plan remains tracked, and in the review/monthly records after an explicit
  local archival. The monthly file is a navigable index, not a rewrite of the
  plan or review record.

## Files

```
archive/
├── README.md                       ← this file
├── 2026-03.md                      ← reward-system redesign, Phase 5/6/7, role filtering, …
├── 2026-07.md                      ← CS-PLAN-001..022, authorization cluster, release gate, …
├── 2026-08.md                      ← CS-PLAN-023..055, service/security/concurrency clusters, …
├── 2026-09.md                      ← CS-PLAN-056..068, core-workflow program closure, …
├── USABILITY_REVIEW_2026-08-20.md  ← usability review record
└── CODE_REVIEW_2026-07-24.md … CODE_REVIEW_2026-09-14.md
                                    ← dated review records, one file per review (kept verbatim)
```
