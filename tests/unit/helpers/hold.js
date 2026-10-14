/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { flushPromises } from '@vue/test-utils'
import HoldButton from '../../../src/components/Common/HoldButton.vue'

/**
 * Complete the press-and-hold gesture of the HoldButton whose inner button
 * matches `selector` (CS-PLAN-084 WP-E). View specs confirm through the
 * component contract; the gesture itself is covered by HoldButton.spec.js.
 * @param {object} wrapper Mounted wrapper containing the hold button.
 * @param {string} selector Selector of the inner button (e.g. a data-testid).
 * @return {Promise<void>}
 */
export async function confirmHold(wrapper, selector) {
	const hold = wrapper.findAllComponents(HoldButton).find(candidate => candidate.find(selector).exists())
	if (!hold) {
		throw new Error(`No hold button matches ${selector}`)
	}
	hold.vm.$emit('confirm')
	await flushPromises()
}
