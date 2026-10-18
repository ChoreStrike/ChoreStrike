/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Registry entry of Spot Sprint, the always-open entry game (unlock
 * threshold 0, CS-PLAN-086 §3.1). A DOM game played by tapping tiles; only
 * this metadata is eager, the game code is its own lazy chunk.
 */
export default {
	id: 'spots',
	icon: '🧽',
	input: 'tap',
	accent: 'var(--cs-arcade-yellow)',
	renderer: 'dom',
	name: () => t('chorestrike', 'Spot Sprint'),
	description: () => t('chorestrike', 'Wipe the stains before they dry. A golden sponge doubles your points — leave the socks alone.'),
	controls: () => t('chorestrike', 'Tap a stain, or move with the arrow keys and wipe with Enter. Space pauses.'),
	load: () => import(/* webpackChunkName: "game-spots" */ './game.js'),
}
