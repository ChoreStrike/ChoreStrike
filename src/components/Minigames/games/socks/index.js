/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Registry entry of Sock Match, the untimed accessible game without a fail
 * state (unlock threshold 3, CS-PLAN-086 §3.1). A DOM game played by
 * tapping socks; a round ends when every pair is found, so it names that
 * end instead of "Game Over". Only this metadata is eager, the game code
 * is its own lazy chunk.
 */
export default {
	id: 'socks',
	icon: '🧦',
	input: 'tap',
	accent: 'var(--cs-arcade-coral)',
	renderer: 'dom',
	name: () => t('chorestrike', 'Sock Match'),
	description: () => t('chorestrike', 'Find the matching socks in the laundry basket. No clock — the fewer wrong guesses, the more points.'),
	controls: () => t('chorestrike', 'Tap two socks to turn them, or move with the arrow keys and turn with Enter. Space pauses.'),
	overTitle: () => t('chorestrike', 'All pairs found!'),
	overAnnouncement: (score) => t('chorestrike', 'All pairs found. Score: {score}', { score }),
	load: () => import(/* webpackChunkName: "game-socks" */ './game.js'),
}
