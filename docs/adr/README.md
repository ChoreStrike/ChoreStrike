# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records for ChoreWars.

## What is an ADR?

An Architecture Decision Record (ADR) captures an important architectural decision made along with its context and consequences.

## Format

Each ADR should follow this structure:

```markdown
# ADR-XXX: Title

**Status**: [Proposed | Accepted | Deprecated | Superseded]  
**Date**: YYYY-MM-DD  
**Deciders**: [Names or roles]

## Context

What is the issue we're seeing that is motivating this decision or change?

## Decision

What is the change that we're proposing and/or doing?

## Consequences

What becomes easier or more difficult to do because of this change?

### Positive
- Benefit 1
- Benefit 2

### Negative
- Drawback 1
- Drawback 2

### Neutral
- Other consideration 1

## Alternatives Considered

What other options were considered and why were they rejected?

## References

- Link to related discussions
- Link to relevant documentation
```

## Naming Convention

Files should be named: `ADR-XXX-short-title.md`

Example: `ADR-001-use-vue3-for-frontend.md`

## Current ADRs

- None yet - this is a new project!

---

**Note**: ADRs are immutable once accepted. If a decision changes, create a new ADR that supersedes the old one.
