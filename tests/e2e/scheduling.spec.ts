/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'
import { houseDateOffset } from './fixtures/house-clock.js'
import { appRequest, openCalendarWeek, openMemberPlanning, openPlanningSettings } from './fixtures/planning.ts'

// CS-PLAN-091 WP-C (ADR-022): the weekly plan's page is gone. The calendar
// shows the live suggestion; planning is a matter of the settings.
test.describe('Planning surfaces', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/today')
			await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 10000 })
		}).toPass({ timeout: 45000 })
	})

	test('planning lives in the settings: every member\'s week for the owner, the own days and energy for everyone', async ({ page }) => {
		await openMemberPlanning(page)
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		// The own days and energy are personal (CS-PLAN-090 WP-D), not part of the owner's view.
		await expect(page.getByTestId('energy-budget-settings')).toHaveCount(0)

		await openPlanningSettings(page)
		await expect(page.getByTestId('self-availability-settings')).toBeVisible()
		await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
		await expect(page.getByTestId('schedule-planner')).toHaveCount(0)
	})

	test('the address of the weekly plan lands on the calendar', async ({ page }) => {
		await expect(page.getByTestId('nav-scheduling')).toHaveCount(0)

		await page.goto('/apps/chorestrike/#/scheduling')

		await expect(page.getByTestId('calendar-view')).toBeVisible({ timeout: 20000 })
		await expect(page).toHaveURL(/#\/calendar/)
		await expect(page.getByTestId('scheduling-view')).toHaveCount(0)
		await expect(page.getByTestId('calendar-open-plan')).toHaveCount(0)
	})

	test('shows availability grid with 7 day slots', async ({ page }) => {
		await openMemberPlanning(page)
		const slots = page.getByTestId('availability-slot')
		await expect(slots.first()).toBeVisible()
		expect(await slots.count()).toBeGreaterThanOrEqual(7)
	})

	test('shows energy budget sliders for each day', async ({ page }) => {
		await openPlanningSettings(page)
		await expect(page.getByTestId('energy-slider-monday')).toBeVisible()
		await expect(page.getByTestId('energy-slider-sunday')).toBeVisible()
	})

	test('shows energy budget number inputs', async ({ page }) => {
		await openPlanningSettings(page)
		await expect(page.getByTestId('energy-input-monday')).toBeVisible()
		await expect(page.getByTestId('energy-input-sunday')).toBeVisible()
	})

	test('can interact with energy slider', async ({ page }) => {
		await openPlanningSettings(page)
		const slider = page.getByTestId('energy-slider-monday')
		await expect(slider).toBeVisible()
		await slider.fill('15')
		await expect(page.getByTestId('energy-input-monday')).toHaveValue('15')
	})

	test('the calendar week shows the suggestion with its navigation', async ({ page }) => {
		await openCalendarWeek(page)
		await expect(page.getByTestId('calendar-prev')).toBeVisible()
		await expect(page.getByTestId('calendar-next')).toBeVisible()
		await expect(page.getByTestId('calendar-hint')).toBeVisible()
		await expect(page.locator('[data-testid^="calendar-agenda-day-"]')).toHaveCount(7)
	})

	// CS-PLAN-091 / ADR-022: nothing is previewed or applied any more.
	test('offers no plan preview any more', async ({ page }) => {
		await openCalendarWeek(page)
		await expect(page.getByTestId('preview-plan-button')).toHaveCount(0)
		await expect(page.getByTestId('apply-plan-button')).toHaveCount(0)
		await expect(page.getByTestId('planner-task-list')).toHaveCount(0)
	})
})

// CS-PLAN-033: the save flows mutate dad's own planning and restore it
// afterwards — mutate→assert→restore in one test with try/finally, so the
// seeded value is restored even when an assertion fails (a serial second test
// would be skipped after a failure and leave the seed mutated).
test.describe('Scheduling save flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 30000 })
		await openPlanningSettings(page)
	})

	test('persists a changed energy budget with exactly one bulk PUT and restores the seed', async ({ page }) => {
		const slider = page.getByTestId('energy-slider-monday')
		await expect(slider).toBeVisible()

		let originalMonday: string | null = null

		try {
			originalMonday = await slider.inputValue()
			const changed = originalMonday === '15' ? '16' : '15'
			await slider.fill(changed)

			let putCount = 0
			page.on('request', (request) => {
				if (request.method() === 'PUT' && request.url().includes('/scheduling/preferences')) {
					putCount++
				}
			})

			const putResponse = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& response.url().includes('/scheduling/preferences'))
			await page.getByTestId('save-energy-budget-button').click()
			expect((await putResponse).ok()).toBe(true)
			// The success toast renders in the viewer's language — pin the
			// toast, not its translated text.
			await expect(page.locator('.toast-success').first()).toBeVisible()
			// Exactly one bulk PUT — the per-day fallback is gone.
			expect(putCount).toBe(1)

			// Reload: the persisted value must survive the bulk PUT round-trip.
			await page.reload()
			await page.waitForLoadState('networkidle')
			await page.getByTestId('planning-settings').waitFor({ state: 'visible' })
			await expect(page.getByTestId('energy-slider-monday')).toHaveValue(changed)
		} finally {
			if (originalMonday !== null) {
				const current = await slider.inputValue()
				if (current !== originalMonday) {
					await slider.fill(originalMonday)

					const putResponse = page.waitForResponse((response) => response.request().method() === 'PUT'
						&& response.url().includes('/scheduling/preferences'))
					await page.getByTestId('save-energy-budget-button').click()
					expect((await putResponse).ok()).toBe(true)
				}
			}
		}
	})

	// ADR-022 decision 5: a star marks a preferred day; it is saved with the energy.
	test('saves a preferred day with the energy, keeps it over a reload and restores the seed', async ({ page }) => {
		const star = page.getByTestId('preferred-wednesday')
		await expect(star).toBeVisible()
		await expect(page.getByTestId('preferred-days-hint')).toBeVisible()
		const save = async (): Promise<{ preferredWeekdays?: string[] }> => {
			const put = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& response.url().includes('/scheduling/preferences'))
			await page.getByTestId('save-energy-budget-button').click()
			const response = await put
			expect(response.ok()).toBe(true)
			return response.request().postDataJSON()
		}
		const before = await star.getAttribute('aria-pressed')
		const after = before === 'true' ? 'false' : 'true'

		try {
			await star.click()
			await expect(star).toHaveAttribute('aria-pressed', after)
			const sent = await save()
			expect(sent.preferredWeekdays?.includes('wednesday')).toBe(after === 'true')

			await page.reload()
			await page.getByTestId('planning-settings').waitFor({ state: 'visible' })
			await expect(page.getByTestId('preferred-wednesday')).toHaveAttribute('aria-pressed', after)
		} finally {
			if ((await page.getByTestId('preferred-wednesday').getAttribute('aria-pressed')) !== before) {
				await page.getByTestId('preferred-wednesday').click()
				await save()
			}
		}
	})
})

// CS-PLAN-091 / ADR-021, ADR-022: the plan preview/apply pair is gone with
// the stored timeline, and the live suggestion is a read. The preferences
// and member routes stay; the owner's own session proves the retired pair
// is not just hidden in the UI.
test.describe('Retired planner routes', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 30000 })
	})

	test('the plan preview and apply routes answer 404 while the preferences stay', async ({ page }) => {
		const headers = { Accept: 'application/json' }
		const preview = await page.request.post('/apps/chorestrike/api/v1/scheduling/suggestions', {
			data: { startDate: '2026-11-18', roomTaskIds: [1], overrides: {} },
			headers,
		})
		expect(preview.status()).toBe(404)
		const apply = await page.request.post('/apps/chorestrike/api/v1/scheduling/apply', {
			data: {
				startDate: '2026-11-18',
				roomTaskIds: [1],
				overrides: {},
				previewVersion: 'retired',
				commandId: 'c0ffee00-0000-4000-8000-000000000001',
				consent: true,
			},
			headers,
		})
		expect(apply.status()).toBe(404)

		expect((await appRequest(page, 'GET', '/scheduling/preferences')).status).toBe(200)
	})

	test('the suggestion is read for the 14 days from today and cut to them', async ({ page }) => {
		const today = houseDateOffset(0, 'UTC')
		const last = houseDateOffset(13, 'UTC')

		const horizon = await appRequest(page, 'GET', `/scheduling/suggestion?from=${today}&to=${last}`)
		expect(horizon.status).toBe(200)
		const data = horizon.body.data as { days: Array<{ day: string, entries: unknown[] }>, overdue: unknown[], members: Array<{ userId: string, day: string }> }
		expect(data.days.map((day) => day.day)).toEqual(Array.from({ length: 14 }, (_, offset) => houseDateOffset(offset, 'UTC')))
		expect(Array.isArray(data.overdue)).toBe(true)
		// The owner reads every member's load, one row per member and day.
		expect(new Set(data.members.map((row) => row.userId)).size).toBeGreaterThan(1)

		// A day that ran over midnight, or a week past the horizon, keeps the days that remain (D-091-139).
		const daysOf = async (from: string, to: string) => {
			const cut = await appRequest(page, 'GET', `/scheduling/suggestion?from=${from}&to=${to}`)
			expect(cut.status, `${from}..${to}`).toBe(200)
			return (cut.body.data as { days: Array<{ day: string }> }).days.map((day) => day.day)
		}
		expect(await daysOf(houseDateOffset(-1, 'UTC'), today)).toEqual([today])
		expect(await daysOf(today, houseDateOffset(15, 'UTC'))).toHaveLength(14)
		expect(await daysOf(houseDateOffset(14, 'UTC'), houseDateOffset(16, 'UTC'))).toEqual([])

		for (const [from, to] of [[last, today], ['today', last]]) {
			const refused = await appRequest(page, 'GET', `/scheduling/suggestion?from=${from}&to=${to}`)
			expect(refused.status, `${from}..${to}`).toBe(400)
			expect(refused.body.error?.code).toBe('VALIDATION_ERROR')
		}
	})
})
