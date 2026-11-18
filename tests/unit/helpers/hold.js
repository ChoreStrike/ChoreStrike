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
	holdButton(wrapper, selector).vm.$emit('confirm')
	await flushPromises()
}

/**
 * The short tap on a tappable HoldButton (CS-PLAN-091 WP-B2): it opens the
 * picker instead of completing.
 * @param {object} wrapper Mounted wrapper containing the hold button.
 * @param {string} selector Selector of the inner button (e.g. a data-testid).
 * @return {Promise<void>}
 */
export async function tapHold(wrapper, selector) {
	const hold = holdButton(wrapper, selector)
	if (!hold.props('tappable')) {
		throw new Error(`The hold button ${selector} takes no tap`)
	}
	hold.vm.$emit('tap')
	await flushPromises()
}

function holdButton(wrapper, selector) {
	const hold = wrapper.findAllComponents(HoldButton).find(candidate => candidate.find(selector).exists())
	if (!hold) {
		throw new Error(`No hold button matches ${selector}`)
	}
	return hold
}
