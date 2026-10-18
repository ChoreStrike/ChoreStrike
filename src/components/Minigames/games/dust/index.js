/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Registry entry of Dust Runner (unlock threshold 50, CS-PLAN-086 §3.1).
 * A canvas game with the `dpad` input mode: directions, swipes and the
 * on-screen pad steer the robot vacuum. Only this metadata is eager; the
 * game code is its own lazy chunk.
 */
export default {
	id: 'dust',
	icon: '🤖',
	input: 'dpad',
	accent: 'var(--cs-arcade-green)',
	name: () => t('chorestrike', 'Dust Runner'),
	description: () => t('chorestrike', 'Clear the dust from the hallway, the kitchen and the living room with the robot vacuum. Keep away from the dust bunnies — after a stop at the charging dock you can catch them.'),
	controls: () => t('chorestrike', 'Steer with the arrow keys, WASD, a swipe or the buttons; the robot drives on until furniture stops it. Space pauses.'),
	load: () => import(/* webpackChunkName: "game-dust" */ './game.js'),
}
