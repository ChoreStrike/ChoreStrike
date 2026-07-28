/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from './fixtures/test-fixtures'

test.describe('Scheduling UI', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })
	})

	test('loads the scheduling view with all sections', async ({ page }) => {
		await expect(page.getByTestId('scheduling-view')).toBeVisible()
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
		await expect(page.getByTestId('schedule-preview')).toBeVisible()
		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
		await expect(page.getByTestId('scheduling-suggestions')).toBeVisible()
	})

	test('displays the scheduling header', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Smart Scheduling' })).toBeVisible()
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

	test('shows schedule preview section', async ({ page }) => {
		await expect(page.getByTestId('schedule-preview')).toBeVisible()
		await expect(page.getByTestId('generate-schedule-button')).toBeVisible()
	})

	test('shows scheduling suggestions section', async ({ page }) => {
		await expect(page.getByTestId('scheduling-suggestions')).toBeVisible()
	})
})
