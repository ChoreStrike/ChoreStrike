@AGENTS.md
@docs/standards/ai/rules/project-rules.md

## Claude Code Notes

- Skills, commands, and the `reviewer` subagent under `.claude/` are generated pointers/copies of `docs/standards/ai/` — edit the canonical files, then run `npm run agents:sync`.
- Tool mapping: structured question → `AskUserQuestion`; `explore` → `Explore` agent; `reviewer` → project agent `reviewer`; worktree → `Agent` with `isolation: "worktree"`.
- Shells: PowerShell 5.1 (`$env:X='y'; cmd`, no `&&`) and Git Bash (`X=y cmd`) are both available; Docker builds need a timeout ≥ 300 s or a background run.
