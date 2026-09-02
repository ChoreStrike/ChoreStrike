# External Asset Licensing Policy

How third-party visual assets (icons, illustrations, fonts, textures, any visual
files committed or shipped) may be used in ChoreStrike. The decision log below
and the exact license texts in `docs/licenses/` are the tracked evidence.

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
| [Lucide Icons](https://lucide.dev) | ISC | ✅ **APPROVED** | 2026-02-25 | Replacement icon set. Re-verified 2026-09-02 by the standards review against `lucide-vue-next@0.474.0` in `package-lock.json` and its bundled `LICENSE`; tracked evidence `docs/licenses/lucide-LICENSE.txt`, SHA-256 `1e7290b35280a048667bbf0ebabac1c7fd52a75300e8b2946ac165715997f2bc`. |
| [Microsoft Fluent UI Emoji](https://github.com/microsoft/fluentui-emoji) | MIT | ✅ **APPROVED** | 2026-02-25 | Avatars/room/task/gamification graphics. Attribution: keep `docs/licenses/fluentui-emoji-LICENSE.txt`. |

## Fallback Strategy

If a candidate is incompatible: use a permissive set with explicit redistribution
rights (MIT/Apache-2.0/CC-BY with compliant attribution), prefer SVG-only packs
for theming, and store license files in `docs/licenses/`.
