/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from '@playwright/test'

test.describe('Scheduling UI', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible', timeout: 10000 })
			await page.getByTestId('nav-scheduling').click()
			await page.getByTestId('scheduling-view').waitFor({ state: 'visible', timeout: 10000 })
		}).toPass({ timeout: 45000 })
	})

	test('loads the scheduling view with all sections', async ({ page }) => {
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
		await expect(page.getByTestId('schedule-planner')).toBeVisible()
		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
	})

	test('displays the scheduling header', async ({ page }) => {
		// Heading presence without locale coupling — profile users render
		// in their own Nextcloud language.
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
		await expect(page.getByTestId('scheduling-view').locator('h1')).toBeVisible()
	})

	test('navigates to scheduling via nav item', async ({ page }) => {
		await page.getByTestId('nav-dashboard').click()
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
		await page.getByTestId('nav-scheduling').click()
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
	})

	test('shows availability grid with 7 day slots', async ({ page }) => {
		const slots = page.getByTestId('availability-slot')
		await expect(slots.first()).toBeVisible()
		expect(await slots.count()).toBeGreaterThanOrEqual(7)
	})

	test('shows energy budget sliders for each day', async ({ page }) => {
		await expect(page.getByTestId('energy-slider-monday')).toBeVisible()
		await expect(page.getByTestId('energy-slider-sunday')).toBeVisible()
	})

	test('shows energy budget number inputs', async ({ page }) => {
		await expect(page.getByTestId('energy-input-monday')).toBeVisible()
		await expect(page.getByTestId('energy-input-sunday')).toBeVisible()
	})

	test('can interact with energy slider', async ({ page }) => {
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

	test('shows the owner plan planner section (CS-PLAN-065)', async ({ page }) => {
		await expect(page.getByTestId('schedule-planner')).toBeVisible()
		// The real-task plan preview is opt-in per selection.
		await expect(page.getByTestId('preview-plan-button')).toBeVisible()
		await expect(page.getByTestId('planner-task-list')).toBeVisible()
	})

	test('the plan preview stays disabled until a task is selected', async ({ page }) => {
		await expect(page.getByTestId('preview-plan-button')).toBeDisabled()
	})
})

// CS-PLAN-033: the save flow mutates dad's own energy budget and restores
// it afterwards — mutate→assert→restore in one test with try/finally, so the
// seeded value is restored even when an assertion fails (a serial second test
// would be skipped after a failure and leave the seed mutated).
test.describe('Scheduling save flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible', timeout: 10000 })
			await page.getByTestId('nav-scheduling').click()
			await page.getByTestId('scheduling-view').waitFor({ state: 'visible', timeout: 10000 })
		}).toPass({ timeout: 45000 })
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
			await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })
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
