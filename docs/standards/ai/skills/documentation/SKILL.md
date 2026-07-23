---
name: documentation
description: Writing and updating ChoreStrike documentation
---

# Skill: Documentation

**Load when:** Writing or updating docs, READMEs, ADRs, or plans.

**Canonical references:**
- [`../../README.md`](../../../../README.md) — documentation map & reading order
- [`project-rules.md`](../../rules/project-rules.md) — language & archive rules

---

## Principles

- **English only** for all docs and comments (chat with the user may be German).
- Write for a public audience: ChoreStrike is MIT and everything outside `/private/` and `archive/` is public.
- Be concise and scannable. Lead with what the reader needs; push depth into sub-pages.
- Keep links relative and valid. Update cross-links when you move or rename a file.

## Where Things Go

| Content | Location |
|---------|----------|
| Architecture decisions | `docs/adr/` (one ADR per file, numbered) |
| Concept / design context | `docs/concepts/` |
| Implementation plans | `docs/plans/{new,active,onhold,done}/` |
| Standards & guidelines | `docs/standards/guidelines/` |
| AI rules / skills | `docs/standards/ai/{rules,skills}/` |
| API reference | `docs/api/README.md`, `docs/api/` |
| Backlog & tasks | `docs/backlog/` |

## Rules

- Don't duplicate — link to the canonical source instead.
- When you remove a doc from the repo, move it to `archive/YYYY-MM/<name>/` (gitignored), don't delete it.
- Update the relevant index (`docs/README.md`, `docs/standards/guidelines/README.md`) when adding a new doc.
- Keep "current counts" (test inventories, statuses) out of prose where possible — they drift fast; prefer pointing to commands.

## ADR Format (short)

`# ADR-NNNN: Title` → Context → Decision → Consequences. One decision per file.
