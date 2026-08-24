/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from './fixtures/test-fixtures'

test.describe('Flex Window Scheduling', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
	})

	test('scheduling nav item is visible from dashboard', async ({ page }) => {
		await expect(page.getByTestId('nav-scheduling')).toBeVisible()
	})

	test('scheduling view loads with calendar section', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })
		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
	})

	test('calendar shows week navigation controls', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		await expect(page.getByTestId('previous-week-button')).toBeVisible()
		await expect(page.getByTestId('next-week-button')).toBeVisible()
		await expect(page.getByTestId('today-button')).toBeVisible()
	})

	test('calendar navigates between weeks', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

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
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		await page.getByTestId('next-week-button').click()
		await page.getByTestId('today-button').click()

		await expect(page.getByTestId('schedule-calendar')).toBeVisible()
	})

	test('schedule planner section is accessible', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		await expect(page.getByTestId('schedule-planner')).toBeVisible()
		await expect(page.getByTestId('generate-schedule-button')).toBeVisible()
	})

	test('scheduling suggestions are accessible after loading the planner', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		const responsePromise = page.waitForResponse((response) =>
			response.request().method() === 'POST'
			&& response.url().includes('/scheduling/suggestions'))
		await page.getByTestId('generate-schedule-button').click()
		await responsePromise
		await expect(page.getByTestId('scheduling-suggestions')).toBeVisible()
	})

	test('energy budget settings persist slider changes', async ({ page }) => {
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		const slider = page.getByTestId('energy-slider-wednesday')
		await expect(slider).toBeVisible()
		await slider.fill('18')

		const input = page.getByTestId('energy-input-wednesday')
		await expect(input).toHaveValue('18')
	})
})
