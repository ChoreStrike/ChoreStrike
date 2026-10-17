/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Registry entry of Crumb Snake. The `snake` id predates the household
 * re-theme and stays, so existing scores remain valid (CS-PLAN-086 §3.1).
 * Only this metadata is eager; the game code is its own lazy chunk.
 */
export default {
	id: 'snake',
	icon: '🧹',
	input: 'dpad',
	name: () => t('chorestrike', 'Crumb Snake'),
	description: () => t('chorestrike', 'Steer the vacuum hose, collect crumbs and keep clear of the furniture.'),
	controls: () => t('chorestrike', 'Steer with the arrow keys, WASD, a swipe or the buttons. Space pauses.'),
	load: () => import(/* webpackChunkName: "game-snake" */ './game.js'),
}
