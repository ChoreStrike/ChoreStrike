/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it } from '@jest/globals'
import { FLEX_DAYS_SANITY_MAX, flexPeriodDays, maxFlexDays } from '../../../src/utils/flexDays.js'

// FEAT-UX-039 (CS-PLAN-088 D-088-12): the form mirrors FlexDaysPolicy.php.
describe('maxFlexDays', () => {
	const rule = (over = {}) => ({ frequencyType: 'weekly', frequencyValue: 1, recurrenceMode: 'recurring', schedulingMode: 'dynamic', weekdays: [], ...over })

	it.each([
		['daily', { frequencyType: 'daily' }, 1],
		['every 3 days (rounded up)', { frequencyType: 'daily', frequencyValue: 3 }, 2],
		['every 10 days', { frequencyType: 'daily', frequencyValue: 10 }, 5],
		['weekly', {}, 4],
		['every 2 weeks', { frequencyValue: 2 }, 7],
		['every 6 months', { frequencyType: 'monthly', frequencyValue: 6 }, 90],
		['legacy custom (one week)', { frequencyType: 'custom', frequencyValue: 5 }, 4],
		['one-time', { recurrenceMode: 'once' }, 7],
		['fixed Mon+Thu (smallest gap 3)', { schedulingMode: 'static', weekdays: ['monday', 'thursday'] }, 2],
		['fixed Fri+Mon across the week end', { schedulingMode: 'static', weekdays: ['friday', 'monday'] }, 2],
		['fixed single weekday every 2 weeks', { schedulingMode: 'static', frequencyValue: 2, weekdays: ['wednesday'] }, 7],
		['weekdays of a completion-relative rule are ignored', { weekdays: ['monday', 'thursday'] }, 4],
		['the sanity bound', { frequencyType: 'monthly', frequencyValue: 100 }, FLEX_DAYS_SANITY_MAX],
	])('%s', (_label, over, expected) => {
		expect(maxFlexDays(rule(over))).toBe(expected)
	})

	it('treats a blank interval as one', () => {
		expect(flexPeriodDays({ frequencyType: 'daily', frequencyValue: '' })).toBe(1)
	})
})
