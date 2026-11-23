<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

# Calm Core UX

> The product semantics of the calm core surfaces (CS-PLAN-070 WP6,
> CS-PLAN-090): the one landing page for every role, neutral rhythm
> presentation, feedback effects, optional areas, and the neutral pause
> domain. This document is the canonical description — implementation
> plans in `docs/plans/` are historical records only.

## Today is the one landing page for every role

- After setup, **every role** (owner, member, child) lands on **Today**
  (`/today`). The redirect is a route-level rule; no component carries a
  role-based landing decision.
- There is no second overview. The Dashboard merged into Today
  (CS-PLAN-090 WP-B, D-090-04); its address (`/dashboard`) redirects, so
  bookmarks keep working. Its tiles, the house-health bar and the room
  cards left the screens (D-090-11): house health is in Insights, rooms
  are reached through the tasks.
- Today shows, top to bottom:
  1. a **status line** about the viewer: open work against today's own
     energy, the shared weekly goal, the confirmed streak with its pending
     state, and the own available Coins. A value no read has delivered
     shows a dash, never a zero. The line stays about the viewer when the
     list shows everyone;
  2. a **neutral overload clarification** when the viewer's own open work
     exceeds their energy for the day (inviting the owner to replan);
  3. the **overdue block**: one row per chore with an unfulfilled miss,
     with its attributed planned date, the end of its window and the days
     since. It is the one loud element of the page and always comes first;
  4. **Due today**;
  5. **Also possible today**, collapsed behind one line: work whose flex
     window is open although it is planned for another day.
- Upcoming appointments are not listed on Today; the calendar shows them.
- **My tasks** and **Everyone** filter the rows. A row belongs to the
  task's assignees; there is no per-cycle handover (ADR-021).
- A free day shows a **neutral empty state** ("Nothing is planned for
  today.") — no engagement prompt, no pressure.
- A row whose completion just succeeded keeps its place for a moment and
  shows its check mark, also when the task recurs and leaves the list
  afterwards (D-090-30).
- For the owner of a house without floors the **setup wizard** opens on
  Today by itself, unless "Start empty" was chosen for this house before;
  *Set up your home* stays on Today and under *Floors & Rooms* in the
  Settings while the house is empty (D-090-12).

> **Releases B and C (CS-PLAN-091, `0.8.0-alpha.5`):** *Today*
> shows the live suggestion ([ADR-022](../adr/ADR-022-live-suggestion.md))
> instead of *Due today*, a section for bonus tasks and a running bonus period.
> The overdue block keeps one red entry per task with the end of its window;
> there are no catch-up entries ([ADR-021](../adr/ADR-021-derived-due-windows.md)).
> A short tap on *Done* opens the picker for who did it and which sub-tasks are
> finished; holding completes everything. The overload line no longer invites
> the owner to replan: capacity only shows overload.

## Catch-up is honest

> **Replaced by ADR-021:** a missed chore is one red entry until it is done;
> doing it late counts as late, never as on time. The text below describes the
> stored catch-up entries of the earlier model.

A missed chore stays visible as catch-up with its original date and
window. Completing the shown occurrence **covers the miss without
relabeling it as on time** — the UI says so explicitly and never dresses
a catch-up up as a success streak.

## Completion needs a deliberate press (hold-to-confirm)

- **Done** (Today and the task lists) confirms
  only after a **600 ms press-and-hold** — pointer, finger or held
  Enter/Space ([CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md)
  D-084-05). A ring fills while holding; `prefers-reduced-motion` shows a
  static pressed state instead.
- Releasing early, sliding off, scrolling or moving focus away sends
  **nothing**. A short tap or a plain click shows an inline "Press and hold
  to confirm" hint instead of failing silently; screen readers get the same
  hint as the button description (VoiceOver/TalkBack: double-tap and hold).
- Destructive actions keep `ConfirmDialog`; **Undo** stays a single tap
  because it is the safety net; dialog confirm buttons stay single-click.
  The overdue-claim dialog went with CS-PLAN-091 WP-B6: a short tap on
  *Done* opens the picker of who did the work.
- One completion request per room task at a time: every completion
  surface reads the shared in-flight state of the room-task store, and a
  concurrent call joins the pending request instead of sending a second.

## Feedback effects: animation on by default, sound an opt-in

- The celebration animation is **on by default** and sounds are **off**
  (CS-PLAN-090, D-090-05). Each person controls both in the Settings
  (delivery preferences surface); the Sound and Effects switches on an
  arcade game's start, pause and game-over screen write the same
  preference (CS-PLAN-086 WP-G).
- A member without stored preferences reads the default, and every path
  that creates the preference row (a first save, ranking consent) writes
  the delivery fields. So saving another setting first never switches the
  animation off, and switching it off as a first save holds. Until the
  preferences are read, the animation stays off, so a stored "off" is never
  overridden while the read is pending or after it failed (RF-090-13).
- The update that brings this default (`0.8.0-alpha.5`, migration step
  `Version1034`) switches the animation on once for every member who
  already has a row, a deliberate "off" included: a stored "off" cannot
  be told apart from a value nobody chose (D-090-16). A member can switch
  it off again afterwards; sound is not touched.
- `prefers-reduced-motion` **always overrides** the animation switch
  (hard floor inside the effect modules).
- A completion is **always acknowledged**, whatever the switches say
  ([CS-PLAN-084](../plans/done/cs-plan-084-family-trial-ux-baseline.md)
  D-084-03, extended by CS-PLAN-090 WP-F): the hold ring, the confirmation
  with undo (UndoSnackbar) with a short check-mark pop and the coins the
  viewer earned with this completion, a brief highlight of the row, and
  one short vibration where the browser supports it (Android; iOS Safari
  has no web vibration). The pop and the highlight stay static under
  `prefers-reduced-motion`. Textual award toasts stay in-app, and a new
  title gets one too. There are no modals; confetti follows the animation
  switch and sound needs its opt-in.
- A completion never plays a streak "milestone": 7/30/100-day streak
  milestones were never implemented (gamification concept, "Earlier Streak
  Milestone Ideas"); rhythm recognition celebrates through medal, title and
  weekly-goal events.
- Failure toasts of the completion journey (complete, claim, undo, history
  correction, My Day checklist) append a short reference — the
  stable API `code`, else `HTTP <status>`, else `NETWORK` — e.g. "Could not
  complete the task (CONFLICT)", so a reported failure can be matched to the
  server log without exposing messages or stack data.
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
- Arcade games unlock as permanent milestones of finished chores
  ([ADR-019](../adr/ADR-019-arcade-unlocks-from-completion-history.md)).
  A locked card shows one neutral progress line — no countdown,
  notification or daily pull — and keeps its leaderboard readable. Owners
  can switch the unlock gate off (`PUT /house/arcade-gate`, default on),
  which opens every game for everyone; the personal `arcadeVisible`
  preference stays independent.
- The reward catalog has a second, independent gate: the owner-controlled
  household flag (default off, `PUT /house/catalog`). Browsing renders
  only while BOTH gates are open (fail-closed while a preference read is
  pending); owner administration in Settings and the persistent My
  Requests surface stay reachable with either gate closed.
- Opt-out is per person and lives in the Settings delivery-preferences
  surface.

## Absences are neutral

- An absence is a **period, nothing more**
  ([ADR-020](../adr/ADR-020-absences-without-requests.md)): during it nothing
  is planned for the person and nothing counts for or against anyone. The
  interface asks for no reason and shows none.
- Everyone, children included, enters an absence for themselves on the
  **Absence** view and it takes effect at once. Everyone sees all entries; a
  member deletes their own and the owner any, as long as it has not ended.
  Deleting keeps today and frees the days from tomorrow.
- Owners enter an absence for the whole household and may enter one for
  someone else afterwards. Requests stored before ADR-020 stay decidable
  there until they are closed.
- The commands (enter, delete, decide) are
  version-aware with one fresh command UUID per attempt; a 409 keeps the
  caller's draft. See `docs/engineering/notifications.md` for the digest
  side and the plan archive for the API contract details.

## Identity switches reset every house-scoped store

One orchestration (`src/composables/useStoreReset.js`) resets **all
seventeen** house-scoped stores before another identity is loaded — a
shared device never renders a previous login's data. Each store keeps its
own in-flight read generation guards; the composable adds the missing
caller, not a per-store mechanism. The store dependency graph stays a
DAG: no store module may import the reset composable.

## Ranking is shares, never amounts

The optional weekly ranking compares **on-time shares** of public work
(stored occurrences before the cutover, cycles from it on, ADR-021 §5) —
never Coin amounts, never raw activity metrics. Provisional while
days are open, neutral for unavailable members, opt-in per person.
