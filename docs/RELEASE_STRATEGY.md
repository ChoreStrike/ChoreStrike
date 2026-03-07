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

---

## Exit Criteria Overview

Detailed checklists should be refined after the workflow is agreed, but the guiding milestone gates are:

### v0.8 Alpha Gate
- Core flows work on a personal Nextcloud instance.
- No known data-loss issue.
- No critical blocker in daily use.
- Bugs found in alpha are documented in backlog or issue tracking.

### v0.9 Beta Gate
- Private tester onboarding is possible.
- Core flows are reproducible across more than one setup.
- Known issues and installation steps are documented.
- Feedback intake and prioritization are operational.

### v1.0 Stable Gate
- Stable supported workflow is clearly documented.
- No open P0 issues.
- No unresolved critical regression in core flows.
- Release notes, known issues, and install guidance are ready.
- App Store submission is evaluated separately, not assumed.

---

## Immediate Next Steps

1. Keep `v0.8 Alpha` focused on personal testing.
2. Prepare a lightweight private beta process for `v0.9`.
3. Finalize issue templates and release checklists before broad beta.
4. Decide on App Store timing only after `v1.0` has proven stable.
