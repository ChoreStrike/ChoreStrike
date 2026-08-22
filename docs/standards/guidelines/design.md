# ChoreStrike Design & Branding Guidelines

## 1. Core Philosophy
"Clean & Alert" (Modern & Vibrant)
This theme combines the clean feel of a productivity app with the energy of a game. It avoids the classic, boring "cleaning bucket blue" and instead relies on fresh, lively contrasts.

## 2. Color Palette
- **Primary (Ocean Blue):** `#0984E3` (Alternative for more turquoise: `#00CEC9`)
  - *Usage:* Base UI, headers, calm areas, standard tasks.
- **Action (Coral Pop):** `#FF7675` (Alternative: `#FAB1A0`)
  - *Usage:* Buttons, alerts, enemies ("The Dust"), gamification elements.
- **Highlight (Sunny Yellow):** `#FDCB6E`
  - *Usage:* Medals, rewards, shields, glowing windows.
- **Background/Neutral:** Light cream or pure white for Light Mode; deep navy/dark gray for Dark Mode.

## 3. Typography & Styling
- **Fonts:** Inherited globally from the Nextcloud system (usually system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto). We do **not** define our own custom fonts to ensure native integration and keep things simple.
- **Shapes:** Slightly rounded corners (border-radius: 8px to 12px) for a friendly, "game-like" look.

## 4. Brand Assets & AI Generation
The primary visual style for the project's branding (Logo, Repo Avatar) is **Modern Isometric 3D**. 

*(Note: If a reference image is provided, it serves as the base structure for these prompts via Image-to-Image generation).*

### Required Asset Sizes:
- **App Logo (for SVG tracing):** 512x512px or 1024x1024px (1:1 Aspect Ratio) -> will be scaled down/vectorized to 64x64px later.
- **Codeberg Repo Avatar:** 256x256px or 512x512px (1:1 Aspect Ratio).
- **README Header / Banner:** 1200x400px (3:1 Aspect Ratio).

### Primary Style: "Modern Isometric 3D" (Clean & Gamified)
> **Prompt:** A stylized, modern isometric 3D app icon of a cozy house that acts as a cute defense base. The house has clean ocean blue walls, a vibrant coral-orange roof, and glowing sunny yellow windows. A futuristic, slightly magical broom stands next to the door like a guard. Clean solid background, UI design, dribbble style, soft studio lighting, high resolution, vibrant colors.

### Alternative / Iterative Prompts:
**Flat Vector Minimalist (App-Focused)**
> **Prompt:** Minimalist flat vector app icon of a house combined with a shield shape. The house is bright ocean blue with a warm coral-pink roof and a bright yellow star emblem on the front. Two crossed brooms behind the house like swords. Clean bold lines, solid colors, modern SaaS logo design, white background, no gradients, highly readable at small sizes.

**16-Bit Pixel Art (Retro Tower Defense)**
> **Prompt:** 16-bit pixel art of a cute fortress house in ocean blue and coral orange, defending against tiny, cute dust monsters. Bright sunny yellow highlights from the windows. The house has a broom mounted on the roof like a playful defense turret. Cozy retro gaming aesthetic, vibrant colors, clear silhouette, isometric perspective, clean pixel perfect lines.

## 5. Feedback Patterns (CS-PLAN-041)

Canonical interaction-feedback rules; every destructive or mutating action
follows one of these three patterns. No native confirm(), no silent saves.

| Situation | Pattern | Implementation |
|---|---|---|
| Destructive action (task/floor delete, member remove) | Styled confirmation dialog | shared src/components/Common/ConfirmDialog.vue (data-testid="confirm-dialog", danger styling, loading state) |
| Async mutation (saves, renames, deletes) | Success/error toast | showSuccess/showError from @nextcloud/dialogs; error toasts on every failure path, never raw logger-only |
| Completion of a task | Undo snackbar (~5 s) | global src/components/Common/UndoSnackbar.vue — *Undo* reverts via the uncomplete/reactivate endpoints, dismiss/timeout hides without effect |

Rules:

- Controls must never lie: a filter that cannot change the output is removed,
  not decorated (see the Leaderboard period filter removal).
- Prices and other authoritative data come from the server catalog; when the
  catalog is unavailable the action renders a disabled state with an explicit
  error instead of stale values.
- Every new user-visible string goes through `t()` with `l10n/` artifacts
  updated in the same change.
