# Notifications — Product Intent

> What the household sees in their Nextcloud bell, and why it is quiet
> by default. Decision record: [ADR-016](../adr/ADR-016-opt-in-digest-notifications.md).
> Pipeline details: [engineering/notifications.md](../engineering/notifications.md).

## One Summary a Day, Never Pressure

ChoreStrike is a household app: the same bell that carries a chore
reminder also carries the family's private life. The product decision
(D9) is radical restraint:

- **Silent by default.** Nothing is sent until a member explicitly opts
  in. Enabling a digest category suggests quiet hours (20:00–08:00) so
  the default is considerate.
- **At most one notification per person per day.** The task summary and
  the household updates ride together in a single digest at the member's
  chosen time. There is no second bell, however much happens.
- **Counts, not content.** The bell says "3 planned tasks · 2 household
  updates" — details live in My Day. Private tasks can never leak
  through a notification.

## What Rides Along (Organizational Updates)

Opting into household updates keeps members informed about structural
changes that concern them: someone took over their overdue task, an
occurrence agreement was amended (handover, date, work share), or a
pause was acknowledged/rejected/shortened. Members who made the change
themselves do not get told about it.

## Quiet Hours

A personal quiet range (which may cross midnight) suppresses the
delivery attempt — it never moves the summary to a later day. A summary
that misses its window (60 minutes past the chosen time, inside the same
local day) is simply skipped; tomorrow gets a fresh one. No
catch-up bursts, no midnight nudges.

## What Stays Inside the App

Awards (medals), title unlocks, weekly-goal celebrations, streak and
house-health signals — all remain in-app surfaces. The former bell for
each of them is retired; pressure does not belong in the system
notification tray, and children's devices must never be reached over
their head.

## The Settings Surface

Every member configures their OWN notifications in Settings →
Notifications — including children. There is no owner view or write of
another member's preferences: notification appetite is personal, and
the server is the single authority for it. Owners keep the HOUSE-level
switches (ranking, catalog) in their own sections.
