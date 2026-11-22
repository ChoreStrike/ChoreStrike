/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect } from '@jest/globals'
import { windowEndLabel, windowRangeLabel } from '../../../src/utils/windowLabels.js'

// CS-PLAN-091 WP-C: Today and the calendar name the window, not a planned day.
describe('windowEndLabel / windowRangeLabel', () => {
	const weekday = (key) => {
		const [year, month, day] = key.split('-').map(Number)
		return new Date(year, month - 1, day).toLocaleDateString(undefined, { weekday: 'short' })
	}

	it('says "only today" on the last day and names the weekday within a week', () => {
		expect(windowEndLabel('2026-11-25', '2026-11-25')).toBe('only today')
		expect(windowEndLabel('2026-11-27', '2026-11-25')).toBe(`until ${weekday('2026-11-27')}`)
		expect(windowEndLabel('2026-12-01', '2026-11-25')).toBe(`until ${weekday('2026-12-01')}`)
	})

	it('names the date from a week ahead on, so the weekday is never ambiguous', () => {
		expect(windowEndLabel('2026-12-02', '2026-11-25')).toMatch(/^until .*2/)
		expect(windowEndLabel('2026-12-02', '2026-11-25')).not.toBe(`until ${weekday('2026-12-02')}`)
	})

	it('is empty without a window and once the window has ended', () => {
		expect(windowEndLabel(undefined, '2026-11-25')).toBe('')
		expect(windowEndLabel(null, '2026-11-25')).toBe('')
		expect(windowEndLabel('2026-11-24', '2026-11-25')).toBe('')
	})

	it('names a single day, the two weekdays of a short window and the dates of a long one', () => {
		expect(windowRangeLabel('2026-11-25', '2026-11-25')).toBe(`only ${weekday('2026-11-25')}`)
		expect(windowRangeLabel('2026-11-25', '2026-11-27')).toBe(`${weekday('2026-11-25')}–${weekday('2026-11-27')}`)
		expect(windowRangeLabel('2026-11-07', '2026-11-21')).toMatch(/7.*–.*21/)
		expect(windowRangeLabel(null, '2026-11-21')).toBe('')
	})
})
