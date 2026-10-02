<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

# Calm Core UX

> The product semantics of the calm core surfaces (CS-PLAN-070 WP6):
> all-role My Day landing, neutral rhythm presentation, opt-in feedback
> effects, optional areas, and the neutral pause domain. This document is
> the canonical description — implementation plans in `docs/plans/` are
> historical records only.

## My Day is the primary landing for every role

- After setup, **every role** (owner, member, child) lands on **My Day**
  (`/today`). The redirect is a route-level rule; no component carries a
  role-based landing decision.
- The **Dashboard** stays reachable as the secondary household overview on
  its explicit path (`/dashboard`) — household panels (wallet, floors,
  quick stats) live there, never on My Day.
- My Day shows exactly the C5f content set: due-today work, **catch-up**
  cards (one per chore with its attributed planned date and window), the
  **next agreed appointments** (read-only), a small **calm progress line**
  (confirmed streak with its pending state + shared weekly goal), and a
  **neutral overload clarification** when planned + outstanding work
  exceeds the member's daily capacity (inviting the owner to replan).
- A free day shows a **neutral empty state** ("Nothing is planned for
  today.") — no engagement prompt, no pressure.

## Catch-up is honest

A missed chore stays visible as catch-up with its original date and
window. Completing the shown occurrence **covers the miss without
relabeling it as on time** — the UI says so explicitly and never dresses
a catch-up up as a success streak.

## Feedback effects are personal opt-ins (default off)

- Sounds and celebration animations are **off by default** and controlled
  per person in the Settings (delivery preferences surface).
- `prefers-reduced-motion` **always overrides** an explicit animation
  opt-in (hard floor inside the effect modules).
- The **default completion feedback** is the accessible confirmation with
  undo (UndoSnackbar); quiet textual award toasts stay in-app. There are
  no modals, sounds, or confetti without the explicit opt-in.
- Celebration gating is event-driven: the effect modules' gates are
  re-synced from the preference store on every celebration event, so a
  mid-session preference change (or the late startup fetch) applies to
  the next effect.

## Notifications: delivery vs. attention

The Settings notification surface draws the line **once and explicitly**:
digests are *delivered* (Nextcloud notification or email, quiet times
apply); in-app notifications are *attention-only* and are never delayed
by quiet times.

## Optional areas

- The **arcade** and the **reward catalog** (WP8) are opt-in surfaces
  outside the daily routine. The personal `arcadeVisible` /
  `catalogVisible` preferences hide the surface and its navigation entry
  **immediately**; a direct link shows a neutral placeholder, never an
  auto-opt-in.
- The reward catalog has a second, independent gate: the owner-controlled
  household flag (default off, `PUT /house/catalog`). Browsing renders
  only while BOTH gates are open (fail-closed while a preference read is
  pending); owner administration in Settings and the persistent My
  Requests surface stay reachable with either gate closed.
- Opt-out is per person and lives in the Settings delivery-preferences
  surface.

## Pauses are neutral

- A pause is **neutral**: during a pause nothing counts for or against
  anyone. Reasons (illness, travel, overload, other) are labels, never
  justifications.
- Children create **pending requests** (effect-free until an owner
  decides); adults are acknowledged directly. Households can be paused as
  a whole; owners may record an illness retrospectively.
- The pause lifecycle commands (request, decide, shorten) are
  version-aware with one fresh command UUID per attempt; a 409 keeps the
  caller's draft. See `docs/engineering/notifications.md` for the digest
  side and the plan archive for the API contract details.

## Identity switches reset every house-scoped store

One orchestration (`src/composables/useStoreReset.js`) resets **all
fifteen** house-scoped stores before another identity is loaded — a
shared device never renders a previous login's data. Each store keeps its
own in-flight read generation guards; the composable adds the missing
caller, not a per-store mechanism. The store dependency graph stays a
DAG: no store module may import the reset composable.

## Ranking is shares, never amounts

The optional weekly ranking compares **on-time shares** of agreed public
work — never Coin amounts, never raw activity metrics. Provisional while
days are open, neutral for unavailable members, opt-in per person.
