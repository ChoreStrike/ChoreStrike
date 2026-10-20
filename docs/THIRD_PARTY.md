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
| `@nextcloud/*` (l10n, dialogs, event-bus, router, vue, axios, auth) | AGPL-3.0 (Nextcloud) | `src/**` — Nextcloud host integration. |
| `@playwright/test` (^1.58.2) | Apache-2.0 | `tests/e2e/**` — E2E test runner. |

## Game implementations

No third-party game code is vendored. The arcade games are original
implementations under the ChoreStrike MIT licence in
`src/components/Minigames/games/<id>/`, with original names and household
artwork (CS-PLAN-086 D-086-03); Crumb Snake reproduces only the public
snake-game convention (a growing line that collects items), Spot Sprint only
the generic tap-the-target convention (items appear on a grid and are tapped
before they vanish), Sock Match only the generic memory-pairs convention
(face-down cards turned two at a time), Tile Breaker only the generic
paddle-and-wall convention (a paddle bounces a ball against a wall of
blocks), Dust Runner only the generic grid-chase convention (an avatar clears
a floor while roaming chasers end a life on contact; its floors are original
plans of household rooms, not mazes), each drawn with its own inline shapes. The former `Game2048.vue` and `MemoryGame.vue` clean-room prototypes
were retired with the CS-PLAN-070 WP3 no-Coin arcade cutover.

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
