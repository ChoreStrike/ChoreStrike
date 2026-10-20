/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Press-and-hold helpers for HoldButton controls (CS-PLAN-084 WP-E:
 * Done and Quick clean confirm only after a 600 ms hold).
 *
 * The hold ends on the component's own state, never on a fixed sleep:
 * the press is released once the button reports `data-hold-state=
 * "confirmed"` or has left the DOM (a completed row drops its button).
 */

import type { Locator } from '@playwright/test'

export type HoldInput = 'pointer' | 'keyboard'

const MAX_ATTEMPTS = 3

/**
 * Press and hold `locator` until it confirms, then release.
 * `pointer` uses the mouse (also on touch-enabled mobile contexts, where
 * it yields the same pointer events as a held finger); `keyboard` focuses
 * the control and holds Enter. A press the component aborted (a late
 * layout shift moved the button away from the pointer, or the press
 * missed it) sent nothing and is repeated, like a person pressing again.
 */
export async function holdToConfirm(locator: Locator, input: HoldInput = 'pointer'): Promise<void> {
	for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
		if (await pressUntilSettled(locator, input) !== 'idle') {
			return
		}
	}
	throw new Error(`Hold was aborted ${MAX_ATTEMPTS} times without confirming`)
}

/** One press: resolves with `confirmed`, `detached` or `idle` (aborted). */
async function pressUntilSettled(locator: Locator, input: HoldInput): Promise<string> {
	const page = locator.page()
	await locator.scrollIntoViewIfNeeded()
	const handle = await locator.elementHandle()
	if (!handle) {
		throw new Error('Hold target is not attached')
	}
	try {
		if (input === 'keyboard') {
			await locator.focus()
			await page.keyboard.down('Enter')
		} else {
			await locator.hover()
			await page.mouse.down()
		}
		const outcome = await page.waitForFunction((element) => {
			if (!element.isConnected) {
				return 'detached'
			}
			const state = element.getAttribute('data-hold-state')
			return state === 'holding' ? false : state
		}, handle, { timeout: 5000 })
		return String(await outcome.jsonValue())
	} finally {
		if (input === 'keyboard') {
			await page.keyboard.up('Enter')
		} else {
			await page.mouse.up()
		}
		await handle.dispose()
	}
}
