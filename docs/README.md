# Documentation Map

Use this document to find the right ChoreStrike documentation quickly.

## Start Here

- [`../AGENTS.md`](../AGENTS.md) — required bootstrap for AI agents and coding assistants
- [`engineering/development.md`](engineering/development.md) — local setup, commands, and contributor workflow
- [`engineering/architecture.md`](engineering/architecture.md) — system boundaries, layers, and responsibilities
- [`engineering/testing.md`](engineering/testing.md) — active suites, release-gate coverage, and test strategy
- [`api/README.md`](api/README.md) and [`api/`](api/) — API contracts and feature-specific API notes

## Community & Meta

Hosted here (this directory) so GitHub/Forgejo auto-detect them; linked from the root `README.md`:

- [`CONTRIBUTING.md`](CONTRIBUTING.md) — contribution guide
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) — contributor covenant
- [`SECURITY.md`](SECURITY.md) — vulnerability reporting policy
- [`THIRD_PARTY.md`](THIRD_PARTY.md) — vendored third-party libraries and licences

## Standards & Guidelines

- [`standards/README.md`](standards/README.md) — engineering standards index
- [`standards/ai/rules/project-rules.md`](standards/ai/rules/project-rules.md) — canonical rule set
- [`standards/ai/skills/`](standards/ai/skills/) — load-on-demand AI skills
- [`standards/guidelines/`](standards/guidelines/README.md) — coding conventions, testing, security, i18n, logging, design, external-asset licensing, AI guidance, agent spawning

## Workflow, Review & Plans

- [Pre-WP9 hardening and retained-data readiness](plans/done/cs-plan-081-pre-wp9-hardening-and-retained-data-readiness.md) - delivered preparation (CS-PLAN-081, final Full `PASS-WITH-FIXES` 2026-10-05): bounded hardening and a future retained-data boundary/recovery contract; H6-05 evidence-bound disposition accepted, H7/H-T11 explicitly user-rescoped, not passed. WP9 matrix/browser-pair/human evidence, deployment and baseline adoption remain subsequent work; later reporting contact (BUG-SEC-001) and possible GitHub move (TECH-REPO-001) stay in the [backlog](backlog/backlog.md).
- [`backlog/ACTIVE_TASKS.md`](backlog/ACTIVE_TASKS.md) — live task coordination
- [`backlog/README.md`](backlog/README.md) — backlog structure and update workflow
- [`plans/`](plans/README.md) — implementation plans (`new/`, `active/`, `onhold/`, `done/`); temporary working docs — durable outcomes are integrated into `concepts/`, `adr/`, `engineering/`, `api/`, `standards/`
- [`engineering/review-playbook.md`](engineering/review-playbook.md) — reusable review workflow and checklist

## Testing, Release & Operations

- [`engineering/deployment.md`](engineering/deployment.md) — installation, deployment, and rollback guide
- [`testing/release-test-plan.md`](testing/release-test-plan.md) — authoritative manual release test plan + per-release sign-off record (release gate)
- [`release/release-strategy.md`](release/release-strategy.md) — release and versioning strategy
- [`release/roadmap.md`](release/roadmap.md) — project roadmap
- [`operations/realtime-updates.md`](operations/realtime-updates.md) — realtime update mechanism notes

## Product and Concept Context

- [WP9 cross-database verification and family evidence](plans/active/cs-plan-080-wp9-cross-db-and-family-evidence.md) - active (drafted 2026-10-02, Standard A `PASS` 2026-10-03): isolated MariaDB/PostgreSQL/SQLite proofs, two consecutive combined browser gates and voluntary comprehension/14-day everyday-use evidence; no runtime verification or family trial claimed.

- [`concepts/product-vision.md`](concepts/product-vision.md) — product intent and positioning
- [Family rhythm and reward clarification](plans/active/cs-plan-070-family-rhythm-and-reward-clarification.md) - active program; WP0 delivered, [WP1 occurrence timeline](plans/done/cs-plan-072-wp1-occurrence-timeline.md) delivered, [WP2 agreements/capacity/pauses](plans/done/cs-plan-073-wp2-agreements-capacity-pauses.md) delivered (final Standard B `PASS-WITH-FIXES` 2026-09-25). Implementation history and review records: [CODE_REVIEW_2026-09-25.md](backlog/archive/CODE_REVIEW_2026-09-25.md).
- [WP3 work accounting and replay](plans/done/cs-plan-074-wp3-work-accounting-and-replay.md) - **delivered** (final Standard B re-review `PASS-WITH-FIXES` 2026-09-27, plan in `done/`; ADR-014, CR-SVC-63 closed): actual shares, exact minor-unit Coins, targeted correction, room journal/replay and minimal client cutover.
- [WP4 rhythm, goals and ranking](plans/done/cs-plan-075-wp4-rhythm-goals-and-ranking.md) - **delivered** (final Standard B `PASS-WITH-FIXES` 2026-09-29, plan in `done/`; ADR-015): rhythm/goal read contract, consent-filtered ranking, single-series retained recognition and retired-mechanic removal. Review record: [CODE_REVIEW_2026-09-29.md](backlog/archive/CODE_REVIEW_2026-09-29.md).
- [WP5 preferences and digest delivery](plans/done/cs-plan-076-wp5-preferences-and-digest-delivery.md) - **delivered** (final Standard B `PASS-WITH-FIXES` 2026-09-30): self-only preferences, DST-aware quiet schedule, transactional organizational queue and one bounded daily digest; pressure notifications retired (ADR-016). Durable docs: [`engineering/notifications.md`](engineering/notifications.md), [`api/notifications.md`](api/notifications.md), [`concepts/notifications.md`](concepts/notifications.md). [Review record](backlog/archive/CODE_REVIEW_2026-09-30.md).
- [WP6 calm core UI](plans/done/cs-plan-077-wp6-calm-core-ui.md) - **delivered** (final Standard B `PASS-WITH-FIXES` 2026-10-01): all-age My Day landing, preference-gated calm feedback, full notification/optional-area preferences, pause UI, occurrence agreement entry points, shared-device store reset and no-Coin UI closure (T9/T10 presentation). Durable docs: [`concepts/calm-core-ux.md`](concepts/calm-core-ux.md). [Review record](backlog/archive/CODE_REVIEW_2026-10-01.md).
- [WP7 catalog and request backend](plans/done/cs-plan-078-wp7-catalog-and-request-backend.md) - **delivered** (final Standard B `PASS-WITH-FIXES` 2026-10-01): optional catalog, exact reservations/settlements/refunds, current-role request lifecycle, private reads, departure/rejoin/erasure and transactional digest integration (ADR-017); UI stays WP8, final evidence WP9.
- [WP8 reward UI and onboarding](plans/done/cs-plan-079-wp8-reward-ui-and-onboarding.md) - **delivered** (final Standard B `PASS-WITH-FIXES` 2026-10-02): catalog/admin and persistent request surfaces behind two independent gates, exact decimal prices, current-role C4 attestations, uncertain-command same-key replay, conflict-safe drafts, shared-device reset and essential onboarding/help (T11 `family-rewards.spec.ts`); FEAT-UX-026 closed, final evidence stays WP9. [Review record](backlog/archive/CODE_REVIEW_2026-10-02.md).
- [`concepts/notifications.md`](concepts/notifications.md) — notification product intent (one opt-in daily summary, quiet by default)
- [`concepts/design.md`](concepts/design.md) — foundational concept & UX design document
- [`concepts/ui-use-cases.md`](concepts/ui-use-cases.md) — use-case/workflow inventory of required GUI elements & actions (with endpoint + status mapping)
- [`concepts/gamification-and-rewards.md`](concepts/gamification-and-rewards.md) — reward model and motivation design
- [`concepts/energy-and-balancing.md`](concepts/energy-and-balancing.md) — balancing principles and energy model
- [`concepts/task-model-and-sync.md`](concepts/task-model-and-sync.md) — task data model and synchronization ideas
- [`concepts/house-setup-and-templates.md`](concepts/house-setup-and-templates.md) — first-run setup wizard & room-type cleaning template catalog (CS-PLAN-027)
- [`concepts/i18n-and-content-policy.md`](concepts/i18n-and-content-policy.md) — wording and content policy context
- [`adr/README.md`](adr/README.md) — architecture decision records index

## Rule of Thumb

Read only the documents relevant to your current task. `AGENTS.md` is the only mandatory bootstrap document.
