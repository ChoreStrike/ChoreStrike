# External Asset Licensing Policy

How third-party visual assets (icons, illustrations, fonts, textures, any visual
files committed or shipped) may be used in ChoreStrike. Full decision-log details
(including license excerpts) are in `docs/licenses/` and the 2026-02-25 review record.

## Go/No-Go Checks

An external asset may be used only if **all** of these are `Yes`:

- Commercial use allowed
- Redistribution in open-source repository allowed
- Redistribution in packaged Nextcloud app / App Store allowed
- Modification / derivative use allowed
- Attribution obligations understood **and** implemented
- No protected third-party brand elements included

If any check fails, the asset must not be used.

## Evidence per Asset

Store for each source: source URL, license name/version, purchase proof (if paid),
exact allowed/restricted-use excerpts, attribution text (if required), decision
date + reviewer. Keep license files in `docs/licenses/` and reference them from
README/CREDITS where attribution is required.

## Decision Log

| Asset | License | Decision | Date | Notes |
|---|---|---|---|---|
| Vector Icon Pack (itch.io, RhosGFX) | RhosGFX CULA | ❌ **REJECTED** | 2026-02-25 | CULA §1.iii forbids any redistribution/adaptation; icons "easily extractable" from app packages (§2) — incompatible with the public repo and App Store. |
| [Lucide Icons](https://lucide.dev) | MIT | ✅ **APPROVED** | 2026-02-25 | Replacement icon set. Attribution: keep `docs/licenses/lucide-LICENSE.txt`; npm package `lucide-vue-next`. |
| [Microsoft Fluent UI Emoji](https://github.com/microsoft/fluentui-emoji) | MIT | ✅ **APPROVED** | 2026-02-25 | Avatars/room/task/gamification graphics. Attribution: keep `docs/licenses/fluentui-emoji-LICENSE.txt`. |

## Fallback Strategy

If a candidate is incompatible: use a permissive set with explicit redistribution
rights (MIT/Apache-2.0/CC-BY with compliant attribution), prefer SVG-only packs
for theming, and store license files in `docs/licenses/`.
