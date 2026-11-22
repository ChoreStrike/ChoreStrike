/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'
import { houseDateOffset } from './fixtures/house-clock.js'
import { openCalendarWeek, openPlanningSettings } from './fixtures/planning.ts'

// CS-PLAN-091 WP-C (ADR-022): the calendar's week is the live suggestion,
// each task on a day inside its flex window. The weekly plan's page with its
// own week grid is gone.
test.describe('Flex Window Scheduling', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('today-view').waitFor({ state: 'visible' })
	})

	test('the calendar opens from the navigation and links no weekly plan', async ({ page }) => {
		await expect(page.getByTestId('nav-scheduling')).toHaveCount(0)

		await page.getByTestId('nav-calendar').click()

		await expect(page.getByTestId('calendar-view')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('calendar-agenda')).toBeVisible({ timeout: 15000 })
		await expect(page.getByTestId('calendar-open-plan')).toHaveCount(0)
	})

	test('the calendar week is a list of days with the suggestion', async ({ page }) => {
		await openCalendarWeek(page)

		await expect(page.locator('[data-testid^="calendar-agenda-day-"]')).toHaveCount(7)
		await expect(page.getByTestId('calendar-hint')).toBeVisible()
		await expect(page.getByTestId('task-calendar')).toHaveCount(0)
	})

	test('calendar shows week navigation controls and the filter', async ({ page }) => {
		await openCalendarWeek(page)

		await expect(page.getByTestId('calendar-prev')).toBeVisible()
		await expect(page.getByTestId('calendar-next')).toBeVisible()
		await expect(page.getByTestId('calendar-range-label')).toBeVisible()
		await expect(page.getByTestId('calendar-filter-mine')).toHaveAttribute('aria-pressed', 'true')
		await expect(page.getByTestId('calendar-filter-all')).toHaveAttribute('aria-pressed', 'false')
	})

	test('calendar navigates between weeks', async ({ page }) => {
		await openCalendarWeek(page)

		const range = page.getByTestId('calendar-range-label')
		const initialText = await range.textContent()

		await page.getByTestId('calendar-next').click()
		await expect(range).not.toHaveText(initialText!)

		await page.getByTestId('calendar-prev').click()
		await expect(range).toHaveText(initialText!)
	})

	test('today is marked in the current week and carries the own energy', async ({ page }) => {
		await openCalendarWeek(page)
		const today = houseDateOffset(0, 'UTC')

		const section = page.getByTestId(`calendar-agenda-day-${today}`)
		await expect(section).toBeVisible()
		await expect(section).toHaveClass(/today/)
		// Placed energy against the day's energy, as "placed/budget".
		await expect(page.getByTestId(`calendar-agenda-load-${today}`)).toHaveText(/^\d+\/\d+$/)

		// A week ahead no day is today, and the list comes back to it.
		await page.getByTestId('calendar-next').click()
		await expect(page.getByTestId(`calendar-agenda-day-${today}`)).toHaveCount(0)
		await page.getByTestId('calendar-prev').click()
		await expect(page.getByTestId(`calendar-agenda-day-${today}`)).toHaveClass(/today/)
	})

	test('beyond the horizon the week shows what is due and says where the suggestion ends', async ({ page }) => {
		await openCalendarWeek(page)
		await expect(page.getByTestId('calendar-agenda-beyond')).toHaveCount(0)

		// Three weeks ahead every day lies beyond the 14 days of the suggestion.
		for (let week = 0; week < 3; week++) {
			await page.getByTestId('calendar-next').click()
		}

		await expect(page.getByTestId('calendar-agenda-beyond')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-testid^="calendar-agenda-load-"]')).toHaveCount(0)
		// The daily seed tasks are due on every day, also there.
		await expect(page.getByTestId('calendar-filter-all')).toBeVisible()
		await page.getByTestId('calendar-filter-all').click()
		await expect(page.getByTestId('calendar-agenda-entry').first()).toBeVisible({ timeout: 15000 })
	})

	test('energy budget settings persist slider changes', async ({ page }) => {
		await openPlanningSettings(page)

		const slider = page.getByTestId('energy-slider-wednesday')
		await expect(slider).toBeVisible()
		await slider.fill('18')

		const input = page.getByTestId('energy-input-wednesday')
		await expect(input).toHaveValue('18')
	})
})
