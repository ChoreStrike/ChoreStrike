# [ID]: [Title]

> **Status:** Plan (not started)
> **Effort:** [XS / S / M / L / XL]
> **Priority:** P[1-3]
> **Depends on:** [prerequisites / none]
> **Related backlog items:** [IDs created in `docs/backlog/backlog.md`]

## 1. Problem Summary

What is the current state, and why does it need to change?

## 2. Strategy

How will the problem be solved? Outline the approach in a few sentences.

## 3. Backend / Frontend Changes

- **Backend** (`lib/`): controllers, services, mappers, migrations.
- **Frontend** (`src/`): views, components, stores, services.
- **Cross-layer contracts** affected (payload ↔ store ↔ component ↔ selectors).

## 4. Tests

- Unit (PHPUnit / Jest)
- Integration (PHPUnit, Docker)
- E2E (Playwright) — which specs / profiles

## 5. File Manifest

New and modified files (and anything moved to `archive/`).

```
new:      path/to/file
modified: path/to/file
archived: archive/2026-06/<name>/old-file
```

## 6. Implementation Order

Ordered, reviewable steps.

1. …
2. …

## 7. Risks & Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| …    | …         | …          |

## 8. Acceptance Criteria

- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Build gate green (`npm test` + lint + backend tests)
- [ ] Required tests ran without new or unapproved skips
- [ ] All implementation decisions and review questions resolved and recorded
- [ ] Owning backlog items updated or archived according to their actual completion state
- [ ] Durable concepts/decisions integrated into the permanent docs (`docs/concepts/`, `docs/adr/`, `docs/engineering/`, `docs/api/`, `docs/standards/`) — plans are temporary
- [ ] Final Standard B or Full review is `PASS` or `PASS-WITH-FIXES`
- [ ] `docs/backlog/ACTIVE_TASKS.md` updated
