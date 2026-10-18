/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Registry entry of Tile Breaker (unlock threshold 25, CS-PLAN-086 §3.1).
 * A canvas game with the `paddle` input mode: held arrow keys and the
 * pointer position move the squeegee. Only this metadata is eager; the
 * game code is its own lazy chunk.
 */
export default {
	id: 'tiles',
	icon: '🧼',
	input: 'paddle',
	name: () => t('chorestrike', 'Tile Breaker'),
	description: () => t('chorestrike', 'Bounce the soap bubble off the squeegee and scrub every grimy tile. Catch the helpers that fall — but dodge the mud.'),
	controls: () => t('chorestrike', 'Move the squeegee with the arrow keys, the mouse or your finger on the board. Enter launches the bubble, Space pauses.'),
	load: () => import(/* webpackChunkName: "game-tiles" */ './game.js'),
}
