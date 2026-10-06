/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from '@playwright/test'

test.describe('Availability Settings Flow', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)
		await expect(async () => {
			await page.goto('/apps/chorestrike/#/dashboard')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible', timeout: 10000 })
			await page.getByTestId('nav-scheduling').click()
			await page.locator('.scheduling-content').waitFor({ state: 'visible', timeout: 10000 })
		}).toPass({ timeout: 45000 })
	})

	test('loads availability settings section', async ({ page }) => {
		await expect(page.getByTestId('availability-settings')).toBeVisible()
		// Heading presence without locale coupling — profile users render
		// in their own Nextcloud language.
		await expect(page.locator('[data-testid="availability-settings"] h2')).toBeVisible()
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

	test('shows energy budget settings with save button', async ({ page }) => {
		await expect(page.getByTestId('energy-budget-settings')).toBeVisible()
		await expect(page.getByTestId('save-energy-budget-button')).toBeVisible()
	})

	test('can adjust energy budget with number input', async ({ page }) => {
		const input = page.getByTestId('energy-input-monday')
		await expect(input).toBeVisible()
		await input.fill('12')
		await expect(input).toHaveValue('12')
	})

	test('shows energy templates in budget settings', async ({ page }) => {
		await expect(page.getByTestId('template-light')).toBeVisible()
		await expect(page.getByTestId('template-moderate')).toBeVisible()
		await expect(page.getByTestId('template-heavy')).toBeVisible()
		await expect(page.getByTestId('template-weekend-focused')).toBeVisible()
	})
})
