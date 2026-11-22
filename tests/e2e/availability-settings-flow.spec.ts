/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { expect, test } from '@playwright/test'
import { appRequest, openMemberPlanning, openPlanningSettings } from './fixtures/planning.ts'

// CS-PLAN-091 WP-C: the owner's view of every member's week moved from the
// weekly plan's page to Settings → Household → Planning of the members.
test.describe('Availability Settings Flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/today')
			await page.getByTestId('today-view').waitFor({ state: 'visible', timeout: 10000 })
			await openMemberPlanning(page)
		}).toPass({ timeout: 60000 })
	})

	test('loads availability settings section', async ({ page }) => {
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		// Heading presence without locale coupling — profile users render
		// in their own Nextcloud language.
		await expect(page.locator('[data-testid="availability-settings"] h2')).toBeVisible()
	})

	test('the address of the category brings it back after a reload', async ({ page }) => {
		await expect(page).toHaveURL(/category=member-planning/)

		await page.reload()

		await expect(page.getByTestId('settings-category-member-planning')).toHaveAttribute('aria-expanded', 'true', { timeout: 30000 })
		await expect(page.getByTestId('availability-settings')).toBeVisible({ timeout: 30000 })
	})

	test('displays availability grid with weekday slots', async ({ page }) => {
		// One grid per member card: scope to the first to satisfy strict mode.
		const firstGrid = page.getByTestId('availability-grid').first()
		await expect(firstGrid).toBeVisible()
		const slots = firstGrid.locator('[data-testid="availability-slot"]')
		expect(await slots.count()).toBe(7)
	})

	test('shows edit button for current user availability', async ({ page }) => {
		const editButton = page.getByTestId('edit-availability-button').first()
		await expect(editButton).toBeVisible()
		await editButton.click()
		await expect(page.getByTestId('edit-availability-modal')).toBeVisible()
	})

	test('can toggle weekday availability in edit modal', async ({ page }) => {
		const editButton = page.getByTestId('edit-availability-button').first()
		await expect(editButton).toBeVisible()

		await editButton.click()
		await expect(page.getByTestId('edit-availability-modal')).toBeVisible()

		const mondayToggle = page.locator('label')
			.filter({ has: page.getByTestId('availability-monday') })
		await expect(mondayToggle).toBeVisible()
		await mondayToggle.click()

		await page.getByTestId('cancel-edit-button').click()
	})

	test('shows energy budget inputs in edit modal', async ({ page }) => {
		const editButton = page.getByTestId('edit-availability-button').first()
		await expect(editButton).toBeVisible()

		await editButton.click()
		await expect(page.getByTestId('edit-availability-modal')).toBeVisible()
		await expect(page.getByTestId('budget-monday-input')).toBeVisible()
		await expect(page.getByTestId('budget-sunday-input')).toBeVisible()
	})

	test('can apply quick templates', async ({ page }) => {
		const editButton = page.getByTestId('edit-availability-button').first()
		await expect(editButton).toBeVisible()

		await editButton.click()
		await expect(page.getByTestId('edit-availability-modal')).toBeVisible()

		await page.getByTestId('template-balanced').click()
		await page.getByTestId('template-weekend-warrior').click()
		await page.getByTestId('template-minimal').click()

		await page.getByTestId('cancel-edit-button').click()
	})

	// ADR-022 decision 5 and ADR-012 D5: the owner sets a member's preferred
	// days and a child's daily limit; the member's card shows both.
	test('the owner sets a member\'s preferred days and daily limit, and the card shows them', async ({ page }) => {
		const members = (await appRequest(page, 'GET', '/scheduling/members')).body.data as Array<{ userId: string, preferredWeekdays: string[], maxDailyTasks: number | null }>
		const stored = members.find((member) => member.userId === 'kid')!
		expect(stored, 'the family seed has the child').toBeTruthy()
		const day = stored.preferredWeekdays.includes('saturday') ? 'sunday' : 'saturday'
		const limit = stored.maxDailyTasks === 3 ? 4 : 3
		const card = page.locator('.member-card').filter({ hasText: 'Kid' }).first()

		try {
			await card.getByTestId('edit-availability-button').click()
			await expect(page.getByTestId('edit-availability-modal')).toBeVisible()
			// The toggle inputs are visually hidden — click their labels.
			await page.locator('label').filter({ has: page.getByTestId(`preferred-${day}`) }).click()
			await expect(page.getByTestId(`preferred-${day}`)).toBeChecked()
			if (!(await page.getByTestId('limit-enabled-toggle').isChecked())) {
				await page.locator('label').filter({ has: page.getByTestId('limit-enabled-toggle') }).click()
			}
			await page.getByTestId('limit-value-input').fill(String(limit))

			const put = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& response.url().includes('/api/v1/scheduling/members/kid'))
			await page.getByTestId('save-availability-button').click()
			const response = await put
			expect(response.ok()).toBe(true)
			expect(response.request().postDataJSON()).toMatchObject({
				preferredWeekdays: [...stored.preferredWeekdays, day].sort((a, b) => weekOrder(a) - weekOrder(b)),
				maxDailyTasks: limit,
			})
			await expect(page.getByTestId('edit-availability-modal')).toHaveCount(0)

			await expect(card.getByTestId('availability-preferred')).toHaveCount(stored.preferredWeekdays.length + 1)
			await expect(card.getByTestId('member-task-limit')).toContainText(String(limit))
		} finally {
			const restored = await appRequest(page, 'PUT', '/scheduling/members/kid', {
				preferredWeekdays: stored.preferredWeekdays,
				maxDailyTasks: stored.maxDailyTasks,
			})
			expect(restored.status).toBe(200)
		}
	})

	test('shows energy budget settings with save button', async ({ page }) => {
		await openPlanningSettings(page)
		await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
		await expect(page.getByTestId('save-energy-budget-button')).toBeVisible()
	})

	test('can adjust energy budget with number input', async ({ page }) => {
		await openPlanningSettings(page)
		const input = page.getByTestId('energy-input-monday')
		await expect(input).toBeVisible()
		await input.fill('12')
		await expect(input).toHaveValue('12')
	})

	test('shows energy templates in budget settings', async ({ page }) => {
		await openPlanningSettings(page)
		await expect(page.getByTestId('template-light')).toBeVisible()
		await expect(page.getByTestId('template-moderate')).toBeVisible()
		await expect(page.getByTestId('template-heavy')).toBeVisible()
		await expect(page.getByTestId('template-weekend-focused')).toBeVisible()
	})
})

const WEEK = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']

/** The place of a weekday in the week, Monday first. */
function weekOrder(day: string): number {
	return WEEK.indexOf(day)
}
