# Third-Party Code & Assets

> ChoreStrike vendors a small set of third-party libraries and assets.
> Every entry below carries the original licence and the location where
> the project uses it. Pinned versions live in `package.json` /
> `composer.json`; this document is the human-readable map.

## Runtime libraries (npm)

| Library | Licence | Where used |
|---|---|---|
| `@fluentui/svg-icons` (^1.1.320) | MIT (Microsoft) | `src/components/Common/Icon.vue` — local SVG icon registry. |
| `canvas-confetti` (^1.9.4) | MIT | `src/utils/confetti.js` — celebration burst. |
| `howler` (^2.2.4) | MIT | `src/utils/sound.js` — sound wrapper (currently Web-Audio fallback; howler kept as the upgrade path for vendored CC0 assets). |
| `lucide-vue-next` (^0.474.0) | ISC (Lucide) | Older icon fallback path. |
| `pinia` (^2.2.6) | MIT | `src/store/*` — state management. |
| `vue` (^3.5.13) | MIT | `src/**` — UI framework. |
| `@nextcloud/*` (l10n, dialogs, event-bus, router, vue, axios, auth, initial-state) | AGPL-3.0 (Nextcloud) | `src/**` — Nextcloud host integration. |
| `@playwright/test` (^1.58.2) | Apache-2.0 | `tests/e2e/**` — E2E test runner. |

## Vendored game implementations (Phase 5)

The two new minigames introduced in Phase 5 — `Game2048.vue` and
`MemoryGame.vue` — are **clean-room re-implementations** of the
public game designs. **No third-party code is vendored**; only the
public game-design conventions (4×4 sliding/merging tiles; 16-card
concentration) are reproduced. The implementations are licensed
under the ChoreStrike MIT licence and live at:

- `src/components/Minigames/Game2048.vue`
- `src/components/Minigames/MemoryGame.vue`

Both reuse the existing `useMinigameStore` (generic over `game_id`)
for scores, coins and leaderboards, so the Coins economy stays
shared with the previously-shipped Snake game.

## Sound assets

No third-party audio assets are bundled in the repository yet. Sound
effects are synthesised at runtime by `src/utils/sound.js` (Web Audio
API). When CC0 sound assets are vendored under `img/sounds/` in a
later phase, they will be listed here with attribution.

## Trademarks

- **Nextcloud** is a trademark of Nextcloud GmbH. ChoreStrike is an
  independent community app and is not affiliated with Nextcloud GmbH.
- **Microsoft Fluent UI** icons are © Microsoft Corporation, used
  under the MIT licence via `@fluentui/svg-icons`.
