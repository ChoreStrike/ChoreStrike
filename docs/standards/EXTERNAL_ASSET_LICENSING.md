# External Asset Licensing Policy

This document defines how third-party visual assets may be used in ChoreStrike.

## Scope

This policy applies to:

- Icon packs
- Illustrations
- Fonts
- Texture/background assets
- Any third-party visual files committed to this repository or shipped with app bundles

## Required Checks (Go/No-Go)

An external asset can be used only if all required checks are `Yes`.

| Check | Required | Result |
|---|---|---|
| Commercial use allowed | Yes | Pending |
| Redistribution in open source repository allowed | Yes | Pending |
| Redistribution in packaged Nextcloud app/store allowed | Yes | Pending |
| Modification/derivative use allowed | Yes | Pending |
| Attribution obligations understood | Yes | Pending |
| Attribution implemented in docs/app where required | Yes | Pending |
| No protected third-party brand elements included | Yes | Pending |

If any required check fails, the asset must not be used.

## Evidence Requirements

For each third-party asset source, store:

1. Source URL
2. License name and version
3. Purchase/license proof reference (if paid)
4. Exact allowed uses excerpt
5. Exact restricted uses excerpt
6. Attribution text (if required)
7. Decision date and reviewer

## Attribution Rules

When attribution is required:

- Add attribution text to project documentation.
- Keep attribution near distribution metadata when practical.
- Preserve author/license details exactly as required by the license.

## Current Review: Vector Icon Pack (itch.io) - REQUIRES MANUAL REVIEW

**Status**: ⛔ BLOCKED - Awaiting project owner license verification

- Source: `https://rhosgfx.itch.io/vector-icon-pack`
- License: Must be verified by project owner
- Purchase/license proof: Check itch.io purchase receipt or asset download page

**Required Actions for volker:**
1. ✅ Review license terms from itch.io download/purchase
2. ⏳ Verify redistribution rights for open source and Nextcloud app store
3. ⏳ Check attribution requirements
4. ⏳ Update this document with findings (use template below)
5. ⏳ If incompatible, select from fallback options below

**Instructions:**
- Locate the license file or terms from your itch.io purchase/download
- Fill out the "Decision Log" section below with the exact license terms
- Mark each check in the "Preliminary Decision Matrix" as Yes/No based on license
- If all checks pass, move status to "✅ APPROVED"
- If any check fails, move status to "❌ REJECTED" and select a fallback option

### Preliminary Decision Matrix

| Topic | Status | Note |
|---|---|---|
| Use in private development | Pending | Depends on granted rights |
| Commit assets to public repository | Pending | Must explicitly allow redistribution |
| Ship assets in app package/store | Pending | Must explicitly allow redistribution |
| Required attribution text | Pending | Must follow seller license text exactly |

**Fallback Options if Current Pack Incompatible:**
1. **Lucide Icons** (https://lucide.dev) - MIT License, SVG, 1000+ icons
2. **Phosphor Icons** (https://phosphoricons.com) - MIT License, variable weight
3. **Heroicons** (https://heroicons.com) - MIT License, SVG set by Tailwind Labs

---

### Decision Log: Vector Icon Pack (itch.io) - TO BE COMPLETED BY VOLKER

**Asset Source:** Vector Icon Pack from itch.io  
**Source URL:** https://rhosgfx.itch.io/vector-icon-pack  
**License Name:** _[Fill in from itch.io license file]_  
**Purchase Date:** _[Fill in]_  
**License File Location:** _[e.g., downloaded zip, email, itch.io library]_

**License Terms Analysis:**
- **Commercial use allowed:** ⏳ Pending (Yes/No)
- **Open source redistribution allowed:** ⏳ Pending (Yes/No)
- **App-store/package redistribution allowed:** ⏳ Pending (Yes/No)
- **Modification/derivative use allowed:** ⏳ Pending (Yes/No)
- **Attribution required:** ⏳ Pending (Yes/No)
- **Required attribution text:** _[Copy exact text if required, or "None"]_

**Exact License Excerpt (Allowed Uses):**
```
[Paste relevant section from license file here]
```

**Exact License Excerpt (Restricted Uses):**
```
[Paste relevant section from license file here]
```

**Reviewer:** volker  
**Review Date:** _[Fill in when completed]_  
**Final Decision:** ⏳ Pending (Approved/Rejected)  
**Notes:** _[Any additional observations or concerns]_

---

## Fallback Strategy

If the current pack is incompatible:

1. Use a permissive icon set with explicit redistribution rights (for example MIT/Apache-2.0/CC-BY with compliant attribution).
2. Prefer SVG-only packs for easy theming and optimization.
3. Store license files in `docs/licenses/` and reference them from this policy.

## Decision Log Template

Copy this section for each asset source:

```markdown
### Asset Source: <name>
- Source URL:
- License:
- Commercial use: Yes/No
- Open source redistribution: Yes/No
- App-store/package redistribution: Yes/No
- Modification allowed: Yes/No
- Attribution required: Yes/No
- Required attribution text:
- Reviewer:
- Date:
- Final decision: Approved/Rejected
- Notes:
```
