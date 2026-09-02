---
description: Standard B code review with automatic commit and plan completion
---

Load the canonical `review`, `plan-management`, and `git-workflow` skills, then perform a **Standard B** review.

Parse an optional `--no-commit` flag as the canonical read-only override. Scope: the remaining `$ARGUMENTS` if given (path, commit range, and/or explicit active-plan path); otherwise capture the current staged, unstaged, and untracked changes without modifying the index. Reject an empty or ambiguous scope rather than pretending it proves plan completion.

The skills are authoritative. Batch decisions through the structured `question` tool with the recommendation first, resolve the owning plan, and run the complete finalization pipeline. Unless `--no-commit` is present, the pipeline performs every eligible transition and outcome-specific scoped commit; the override remains findings-only.
