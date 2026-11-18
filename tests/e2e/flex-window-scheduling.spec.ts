/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'
import { openPlanningSettings, openWeeklyPlan } from './fixtures/planning.ts'

test.describe('Flex Window Scheduling', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/#/today')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('today-view').waitFor({ state: 'visible' })
	})

	// CS-PLAN-090 D-090-14: the weekly plan left the navigation and is
	// reached from the calendar header, with a way back.
	test('the weekly plan opens from the calendar and leads back to it', async ({ page }) => {
		await expect(page.getByTestId('nav-scheduling')).toHaveCount(0)

		await openWeeklyPlan(page)
		await expect(page).toHaveURL(/#\/scheduling/)

		await page.getByTestId('scheduling-back').click()
		await expect(page.getByTestId('calendar-view')).toBeVisible({ timeout: 15000 })
	})

	test('scheduling view loads with calendar section', async ({ page }) => {
		await expect(async () => {
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })
		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
	})

	test('calendar shows week navigation controls', async ({ page }) => {
		await expect(async () => {
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })

		await expect(page.getByTestId('previous-week-button')).toBeVisible()
		await expect(page.getByTestId('next-week-button')).toBeVisible()
		await expect(page.getByTestId('today-button')).toBeVisible()
	})

	test('calendar navigates between weeks', async ({ page }) => {
		await expect(async () => {
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })

		const weekRange = page.getByTestId('week-range')
		const initialText = await weekRange.textContent()

		await page.getByTestId('next-week-button').click()
		const nextText = await weekRange.textContent()
		expect(nextText).not.toBe(initialText)

		await page.getByTestId('previous-week-button').click()
		const backText = await weekRange.textContent()
		expect(backText).toBe(initialText)
	})

	test('calendar returns to current week with today button', async ({ page }) => {
		await expect(async () => {
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })

		await page.getByTestId('next-week-button').click()
		await page.getByTestId('today-button').click()

		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
	})

	// CS-PLAN-091 / ADR-021: the schedule planner (preview and apply of a
	// generated plan) is retired; the weekly plan keeps its calendar.
	test('the weekly plan has no schedule planner any more', async ({ page }) => {
		await expect(async () => {
			await openWeeklyPlan(page)
		}).toPass({ timeout: 45000 })

		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
		await expect(page.getByTestId('schedule-planner')).toHaveCount(0)
		await expect(page.getByTestId('preview-plan-button')).toHaveCount(0)
		await expect(page.getByTestId('apply-plan-button')).toHaveCount(0)
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
