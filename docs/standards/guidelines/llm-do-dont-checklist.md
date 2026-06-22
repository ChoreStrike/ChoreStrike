# LLM Do/Don't Checklist

> One-page final gate. Run before every code submission.
> Source: [`../ai/rules/project-rules.md`](../ai/rules/project-rules.md).

---

## Fast Gate

1. **Architecture:** Business logic in services (not controllers)? Persistence in mappers/entities?
2. **Authorization:** New/changed endpoints check house membership and ownership? No `@PublicPage`?
3. **Input validation:** All user input validated at the controller boundary?
4. **SQL/XSS:** Queries use `createNamedParameter()`? No `v-html` with user content?
5. **i18n:** Every user-visible string uses `t()`? `l10n/` artifacts updated?
6. **Logging:** `AppLogger` / `logger.js` used? No raw `console.*`? No secrets logged?
7. **Tests:** Feature/fix includes a test (unit/integration/E2E as appropriate)?
8. **Minimal diff:** No unrelated renames, reordering, or formatting churn?
9. **No leftovers:** No committed secrets, private content, build artifacts, or dead debug code?
10. **Backlog:** `docs/backlog/ACTIVE_TASKS.md` updated? Ambiguities logged, not guessed?
11. **Green build:** `npm test` + lint + backend tests pass (or Docker run is green)?

## Don't

- Don't add business logic to controllers or routes.
- Don't trust client input or client-sent localization.
- Don't log full request bodies, tokens, passwords, or private notes.
- Don't suppress warnings or swallow exceptions without structured handling.
- Don't commit to `main`/`master` directly — use a branch and a reviewable change.
