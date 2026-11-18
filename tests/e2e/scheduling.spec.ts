/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'
import { openPlanningSettings, openWeeklyPlan } from './fixtures/planning.ts'

test.describe('Scheduling UI', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/today')
			await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 10000 })
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })
	})

	test('loads the scheduling view with all sections', async ({ page }) => {
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		await expect(page.getByTestId('schedule-planner')).toHaveCount(0)
		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
		// The own days and energy moved to the personal settings (WP-D).
		await expect(page.getByTestId('energy-budget-settings')).toHaveCount(0)
		await expect(page.getByTestId('self-availability-settings')).toHaveCount(0)
	})

	test('displays the scheduling header', async ({ page }) => {
		// Heading presence without locale coupling — profile users render
		// in their own Nextcloud language.
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
		await expect(page.getByTestId('scheduling-view').locator('h1')).toBeVisible()
	})

	test('navigates to the weekly plan through the calendar', async ({ page }) => {
		await page.getByTestId('nav-today').click()
		await page.getByTestId('today-view').waitFor({ state: 'visible' })
		await expect(page.getByTestId('nav-scheduling')).toHaveCount(0)
		await openWeeklyPlan(page)
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
	})

	test('shows availability grid with 7 day slots', async ({ page }) => {
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

	test('shows schedule calendar with navigation', async ({ page }) => {
		await expect(page.getByTestId('previous-week-button')).toBeVisible()
		await expect(page.getByTestId('next-week-button')).toBeVisible()
		await expect(page.getByTestId('today-button')).toBeVisible()
	})

	// CS-PLAN-091 / ADR-021: the plan preview and its apply step are retired.
	test('offers no plan preview any more', async ({ page }) => {
		await expect(page.getByTestId('preview-plan-button')).toHaveCount(0)
		await expect(page.getByTestId('planner-task-list')).toHaveCount(0)
	})
})

// CS-PLAN-033: the save flow mutates dad's own energy budget and restores
// it afterwards — mutate→assert→restore in one test with try/finally, so the
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
})

// CS-PLAN-091 / ADR-021: the plan preview/apply pair is gone with the
// stored timeline. The preferences and member routes stay; the owner's own
// session proves the retired pair is not just hidden in the UI.
test.describe('Retired planner routes', () => {
	test('the plan preview and apply routes answer 404 while the preferences stay', async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 30000 })

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

		// The preferences read is a CSRF-checked route: read it the way the
		// app does, with the page's request token.
		const preferencesStatus = await page.evaluate(async () => {
			const response = await fetch('/apps/chorestrike/api/v1/scheduling/preferences', {
				headers: {
					Accept: 'application/json',
					requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken,
				},
			})
			return response.status
		})
		expect(preferencesStatus).toBe(200)
	})
})
