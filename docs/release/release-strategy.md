# ChoreStrike Release Strategy

## Goal

Define a slow and low-risk release path for ChoreStrike before any public Nextcloud App Store submission.

## Agreed Order

1. **A - Release Strategy**
   - Define the staged rollout from alpha to stable.
2. **B - Issue and Release Workflow**
   - Define how bugs, feedback, validation, and release decisions move through the project.
3. **C - Exit Criteria**
   - Define what must be true before each release milestone is allowed.

This order is intentional. ChoreStrike should first define the release path, then the operating workflow around that path, and only then lock the milestone criteria.

---

## Stage 1: v0.8 Alpha

**Audience**: Internal only, primarily the project owner.

**Purpose**:
- Validate daily real-world usage.
- Catch blocking bugs in core flows.
- Identify setup, migration, and UX friction before outside testers are invited.

**Scope**:
- Manual installation on a personal Nextcloud instance.
- Core room, floor, task, scheduling, and household flows.
- Regression tracking for bugs found during real usage.

**Non-Goals**:
- Public distribution.
- App Store submission.
- Broad compatibility guarantees.

---

## Stage 2: v0.9 Beta

**Audience**: Small private tester group first, then possibly small community outreach.

**Purpose**:
- Validate ChoreStrike with external users and different usage patterns.
- Verify upgrade behavior and test reproducibility on more than one setup.
- Gather structured feedback before a stable release.

**Scope**:
- Private beta rollout to trusted testers.
- Feedback collection with simple bug and improvement categories.
- Optional later expansion to forum-based discovery if private beta is stable.

**Non-Goals**:
- Immediate App Store publication.
- Large-scale support commitments.

---

## Stage 3: v1.0 Stable

**Audience**: Stable self-hosted/manual-install users.

**Purpose**:
- Mark the first release that is considered stable for normal use.
- Freeze the supported core workflows.
- Provide a predictable foundation for later public distribution.

**Scope**:
- Stable release notes.
- Known issues documentation.
- Manual install and upgrade guidance.
- Defined support expectations and issue handling.

**Important Note**:
- `v1.0.0` does **not** automatically imply immediate Nextcloud App Store submission.
- App Store readiness may be deferred to a later `v1.1.x` or `v1.2.x` release after broader validation.

---

## App Store Position

The Nextcloud App Store should be treated as a separate readiness milestone after stable release quality is proven.

Before App Store submission, ChoreStrike should have:
- Stable installation and upgrade behavior.
- Documented supported workflows.
- Clear bug reporting and triage process.
- Sufficient manual and automated validation.
- Current screenshots, release notes, and support documentation.

---

## Minimal Issue and Release Workflow

### Retained Trial Data

Retention started at one **explicitly adopted post-WP9 technical baseline**, not
automatically at alpha/beta, a tag, or plan approval: `0.8.0-alpha.1`, adopted
by the operator with its deployment on 2026-10-17
([adoption record](../engineering/deployment.md#first-retained-baseline),
[whole-instance recovery procedure](../engineering/deployment.md#rollback)).
Persisted facts are preserved by default; applied migrations are frozen and
future changes require populated-baseline upgrade/provider parity evidence.
Earlier disposable installations are not supported upgrade sources.

Technical readiness, operator adoption, personal-server validation and voluntary
family evidence are separate decisions. Synthetic recovery does not close
RELEASE-002 or authorize production deployment. The explicit 2026-10-04 H7/H-T11
rescoping exempts operator-only self-testing and CS-PLAN-081 completion from the
reporting-contact gate, not from technical readiness or adoption safeguards.
Since 2026-10-18 security reports use GitHub private vulnerability reporting
only and no mailbox is published. BUG-SEC-001 remains open for the deferred
legal/privacy notices before later external onboarding/public release. GPG is optional; no mandatory key or
decryption evidence is implied.

### Issue Types

- **Bug**: Something does not work as intended.
- **Regression**: Something worked before and is now broken.
- **Improvement**: Existing behavior works but should be clearer or easier to use.
- **Feature**: New capability not yet implemented.

### Status Flow

- **Inbox**
- **Confirmed**
- **In Progress**
- **Review Ready**
- **Done**
- **Deferred**

### Priority Levels

- **P0**: Data loss, security issue, or app unusable.
- **P1**: Core workflow broken with high impact.
- **P2**: Standard bug or usability issue.
- **P3**: Nice-to-have enhancement.

### Release Rhythm

- Use tagged releases for every testable milestone.
- Recommended examples:
  - `v0.8.0-alpha.1`
  - `v0.8.0-alpha.2`
  - `v0.9.0-beta.1`
  - `v1.0.0`

### Operational Workflow

1. **Capture**
   - Record every bug, regression, improvement, or feature request in the backlog or issue tracker.
   - For alpha, this can stay lightweight and backlog-driven.
   - For beta, every external report should be logged in a reproducible form.

2. **Confirm**
   - Reproduce the issue when possible.
   - Assign type and priority.
   - Mark invalid or non-reproducible reports clearly instead of leaving them ambiguous.

3. **Plan**
   - Decide whether the issue belongs in the current milestone or should be deferred.
   - P0 and P1 issues block release candidates.
   - P2 and P3 issues may be deferred if documented and accepted.

4. **Implement**
   - Fix the root cause where feasible.
   - Add regression coverage for confirmed bugs when practical.
   - Update relevant docs or known-issues notes when behavior changes.

5. **Validate**
   - Re-run targeted tests, lint checks, and manual verification for the affected workflow.
   - For beta and stable candidates, validate installation or upgrade impact if relevant.

6. **Release Decision**
   - Promote the next alpha, beta, or stable tag only after open blocking issues are resolved or explicitly deferred.
   - Record the release decision, known issues, and validation summary in release notes or backlog documentation.

### Phase-Specific Workflow Rules

#### v0.8 Alpha
- Keep issue handling lightweight and fast.
- Focus on finding blockers in personal real-world usage.
- Create a new alpha tag when a meaningful bundle of fixes is ready.

#### v0.9 Beta
- Require reproducible tester feedback where possible.
- Track onboarding problems, upgrade issues, and environment-specific bugs explicitly.
- Publish new beta tags only after reviewing private tester feedback and validating critical fixes.

#### v1.0 Stable
- Treat release candidates as documentation-backed builds.
- Require clear known issues, stable install guidance, and no open blocking regressions.
- Evaluate App Store readiness as a separate go/no-go decision after stable validation.

---

## Exit Criteria Overview

The following milestone gates define the minimum quality bar before a release can be promoted.

> **Manual release test gate (all stages):** every release tag requires a completed
> Execution & Sign-off record from the authoritative
> [`../testing/release-test-plan.md`](../testing/release-test-plan.md) (stage-mapped
> per its §1.2 stage map). No completed manual record → no tag. Completed records
> are archived under [`../backlog/archive/`](../backlog/archive/).

### v0.8 Alpha Gate
- Manual install succeeds on the personal Nextcloud instance.
- Core flows work in real usage: floor creation, room creation, task creation, task completion, and core scheduling screens.
- No known data-loss issue is open.
- No unresolved P0 issue exists.
- Any open P1 issue is explicitly accepted for alpha and documented.
- Bugs found during alpha are recorded in backlog or issue tracking.
- A completed manual release test record exists for the alpha stage protocol ([`../testing/release-test-plan.md`](../testing/release-test-plan.md)).
- A short alpha validation note exists before tagging the release.

### v0.9 Beta Gate
- Private tester onboarding is possible without direct developer intervention for every step.
- Core flows are reproducible across more than one setup or tester environment.
- Install, update, and rollback steps are documented well enough for private testers.
- Known issues are documented before the beta tag is published.
- Feedback intake is operational with clear bug, regression, improvement, and feature categories.
- No unresolved P0 issue exists.
- No unresolved P1 issue remains in the supported beta workflows unless explicitly documented and accepted.
- Critical fixes from earlier alpha usage have been revalidated.
- A completed manual release test record exists for the beta stage protocol ([`../testing/release-test-plan.md`](../testing/release-test-plan.md)).

### v1.0 Stable Gate
- Supported workflows are clearly documented.
- Release notes, known issues, installation guidance, and upgrade guidance are ready.
- No open P0 issue exists.
- No unresolved P1 regression remains in core supported workflows.
- Linting is clean for the supported code paths.
- Targeted automated tests and manual validation for supported workflows have been completed.
- The stable release is judged fit for manual-install users even without App Store distribution.
- A completed manual release test record exists for the stable stage protocol, **including the upgrade/migration smoke on a personal instance** ([`../testing/release-test-plan.md`](../testing/release-test-plan.md) §16).
- App Store submission is evaluated separately and documented as a distinct go/no-go decision.

## Exit Criteria Checklist Format

For each release candidate, ChoreStrike should capture:

- **Build/Version**: candidate tag or version number
- **Validated Workflows**: the specific user flows checked before release
- **Manual test record**: completed sign-off record from [`../testing/release-test-plan.md`](../testing/release-test-plan.md)
- **Open Risks**: accepted non-blocking issues
- **Blocked By**: unresolved release-stopping issues, if any
- **Decision**: promote, hold, or defer

---

## Immediate Next Steps

1. Keep `v0.8 Alpha` focused on personal testing.
2. Prepare a lightweight private beta process for `v0.9`.
3. Finalize issue templates and release checklists before broad beta.
4. Decide on App Store timing only after `v1.0` has proven stable.
