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

## Current Review: Vector Icon Pack (itch.io) - REJECTED

**Status**: ❌ REJECTED - Incompatible with open-source redistribution

- Source: `https://rhosgfx.itch.io/vector-icon-pack`
- License: RhosGFX Commercial Use License Agreement (CULA)
- Review Date: 2026-02-25

**Rejection Reason:**
CULA Section 1.iii explicitly prohibits redistribution: "This license does not permit the resale, redistribution, or sublicensing of the Materials, whether in original or modified form."

**Impact on ChoreStrike:**
- ❌ Cannot commit icons to public Git repository (= redistribution)
- ❌ Cannot include icons in Nextcloud App Store packages (= redistribution)
- ❌ Cannot distribute icons with GPL-licensed source code (GPL requires full source access)
- ❌ Icons would be "easily extractable" from app package (explicitly forbidden per CULA Section 2)

**Next Action:**
✅ Switch to **Lucide Icons** (MIT License) - see approved decision below

### CULA License Analysis

| Topic | CULA Allows? | Section | Impact |
|---|---|---|---|
| Use in private development | ✅ Yes | 1.i | Allowed for personal/commercial projects |
| Commit assets to public repository | ❌ No | 1.iii, 3.i | "redistribution" explicitly forbidden |
| Ship assets in app package/store | ❌ No | 1.iii, 2, 3.i | Icons would be "easily extractable" |
| Modification/adaptation | ❌ No | 3.i | "adapt" explicitly forbidden |
| Required attribution | N/A | - | Not relevant due to incompatibility |

---

### Decision Log: Vector Icon Pack (itch.io) - COMPLETED

**Asset Source:** Vector Icon Pack from itch.io  
**Source URL:** https://rhosgfx.itch.io/vector-icon-pack  
**License Name:** RhosGFX Commercial Use License Agreement (CULA)  
**Purchase Date:** Prior to 2026-02-25  
**License File Location:** CULA-preview.pdf

**License Terms Analysis:**
- **Commercial use allowed:** ✅ Yes
- **Open source redistribution allowed:** ❌ No
- **App-store/package redistribution allowed:** ❌ No
- **Modification/derivative use allowed:** ❌ No
- **Attribution required:** N/A (license incompatible)

**Exact License Excerpt (Allowed Uses):**
```
Section 1.i: "Licensor grants Licensee a perpetual, non-exclusive, non-transferable,
non-assignable license to use any RhosGFX icon pack purchased by Licensee (the
'Materials') in both personal and commercial projects."
```

**Exact License Excerpt (Restricted Uses):**
```
Section 1.iii: "This license does not permit the resale, redistribution, or sublicensing
of the Materials, whether in original or modified form."

Section 3.i: "You may not resell, redistribute, sublicense, lend, rent, decompile,
reverse-engineer, disassemble, or adapt the Materials except as explicitly permitted
by this Agreement."

Section 2: "This license is only required if: You are including RhosGFX icons in UI packs,
game kits, software, or other digital assets that are distributed or sold in a form where
the icons are easily extractable."
```

**Reviewer:** AI Agent (volker confirmed)  
**Review Date:** 2026-02-25  
**Final Decision:** ❌ REJECTED  
**Notes:** CULA explicitly forbids redistribution in any form. ChoreStrike as GPL-licensed Nextcloud app requires icons to be redistributable in source code and app packages. Icons would be "easily extractable" from app package, which CULA Section 2 explicitly prohibits. Switching to MIT-licensed alternative (Lucide Icons) is required.

---

## ✅ APPROVED: Lucide Icons (Replacement)

**Status**: ✅ APPROVED - MIT License compatible with open-source

**Asset Source:** Lucide Icons  
**Source URL:** https://lucide.dev  
**License Name:** MIT License  
**License URL:** https://github.com/lucide-icons/lucide/blob/main/LICENSE

**License Terms Analysis:**
- **Commercial use allowed:** ✅ Yes
- **Open source redistribution allowed:** ✅ Yes
- **App-store/package redistribution allowed:** ✅ Yes
- **Modification/derivative use allowed:** ✅ Yes
- **Attribution required:** ✅ Yes (MIT license file must be included)

**MIT License Summary:**
```
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software...
```

**Required Attribution:**
- Include `LICENSE` file from Lucide repository in `docs/licenses/lucide-LICENSE.txt`
- Add entry to project README or CREDITS file

**Implementation Plan:**
1. Download Lucide Icons from https://lucide.dev or npm package `lucide-vue-next`
2. Copy MIT LICENSE to `docs/licenses/lucide-LICENSE.txt`
3. Replace existing icon references in Vue components
4. Update README with Lucide attribution

**Reviewer:** AI Agent  
**Review Date:** 2026-02-25  
**Final Decision:** ✅ APPROVED

---

## ✅ APPROVED: Microsoft Fluent UI Emoji (Graphics / Avatars / Icons)

**Status**: ✅ APPROVED - MIT License compatible with open-source

**Asset Source:** Microsoft Fluent UI Emoji  
**Source URL:** https://github.com/microsoft/fluentui-emoji  
**License Name:** MIT License  
**License URL:** https://github.com/microsoft/fluentui-emoji/blob/main/LICENSE

**License Terms Analysis:**
- **Commercial use allowed:** ✅ Yes
- **Open source redistribution allowed:** ✅ Yes
- **App-store/package redistribution allowed:** ✅ Yes
- **Modification/derivative use allowed:** ✅ Yes
- **Attribution required:** ✅ Yes (MIT license file must be included)

**MIT License Summary:**
```
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software...
```

**Required Attribution:**
- Include `LICENSE` file from Fluent UI Emoji repository in `docs/licenses/fluentui-emoji-LICENSE.txt`
- Add entry to project README or CREDITS file

**Implementation Plan:**
1. Download required emojis (SVG/PNG) from the GitHub repository (e.g., 3D or Color variants)
2. Copy MIT LICENSE to `docs/licenses/fluentui-emoji-LICENSE.txt`
3. Use for rooms, tasks, avatars, monsters, and gamification elements (sparks, energy, medals)
4. Update README with Fluent UI Emoji attribution

**Reviewer:** AI Agent (volker confirmed)  
**Review Date:** 2026-02-25  
**Final Decision:** ✅ APPROVED

---

## Fallback Strategy (For Future Reference)

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
