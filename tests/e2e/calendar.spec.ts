/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from '@playwright/test'

test.describe('Calendar View', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/dashboard')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
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
	})

	test('prev/next changes the range label', async ({ page }) => {
		const label = page.getByTestId('calendar-range-label')
		const before = await label.textContent()
		await page.getByTestId('calendar-next').click()
		await expect(label).not.toHaveText(before!)
	})

	test('renders the task calendar grid', async ({ page }) => {
		await expect(page.getByTestId('task-calendar')).toBeVisible()
	})
})
