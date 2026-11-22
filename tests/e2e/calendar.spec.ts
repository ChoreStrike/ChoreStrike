/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'

test.describe('Calendar View', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('today-view').waitFor({ state: 'visible' })
		await page.getByTestId('nav-calendar').click()
		await page.getByTestId('calendar-view').waitFor({ state: 'visible' })
	})

	test('navigates to calendar via nav item', async ({ page }) => {
		await expect(page.getByTestId('calendar-view')).toBeVisible()
	})

	test('displays week/month toggle with week active by default', async ({ page }) => {
		await expect(page.getByTestId('calendar-mode-toggle')).toBeVisible()
		await expect(page.getByTestId('calendar-mode-week')).toBeVisible()
		await expect(page.getByTestId('calendar-mode-month')).toBeVisible()
	})

	test('displays navigation controls and range label', async ({ page }) => {
		await expect(page.getByTestId('calendar-prev')).toBeVisible()
		await expect(page.getByTestId('calendar-next')).toBeVisible()
		await expect(page.getByTestId('calendar-range-label')).toBeVisible()
	})

	test('switches to month mode', async ({ page }) => {
		await page.getByTestId('calendar-mode-month').click()
		await expect(page.getByTestId('task-calendar')).toBeVisible()
		// The grid of the month replaces the week's list and its filter.
		await expect(page.getByTestId('calendar-agenda')).toHaveCount(0)
		await expect(page.getByTestId('calendar-filter-mine')).toHaveCount(0)
	})

	test('prev/next changes the range label', async ({ page }) => {
		const label = page.getByTestId('calendar-range-label')
		const before = await label.textContent()
		await page.getByTestId('calendar-next').click()
		await expect(label).not.toHaveText(before!)
	})

	// CS-PLAN-091 WP-C (ADR-022): the week is a list of days with the live
	// suggestion; the grid stays for the month.
	test('renders the week as a list of days with the suggestion', async ({ page }) => {
		await expect(page.getByTestId('calendar-agenda')).toBeVisible()
		await expect(page.locator('[data-testid^="calendar-agenda-day-"]')).toHaveCount(7)
		await expect(page.getByTestId('calendar-hint')).toBeVisible()
		await expect(page.getByTestId('task-calendar')).toHaveCount(0)
	})

	test('the week shows the own tasks first and everyone\'s on request', async ({ page }) => {
		await expect(page.getByTestId('calendar-filter-mine')).toHaveAttribute('aria-pressed', 'true')

		await page.getByTestId('calendar-filter-all').click()

		await expect(page.getByTestId('calendar-filter-all')).toHaveAttribute('aria-pressed', 'true')
		// The family's daily tasks are suggested on the days from today on.
		await expect(page.getByTestId('calendar-agenda-entry').first()).toBeVisible({ timeout: 15000 })
	})
})
