/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { test, expect } from '@playwright/test'

// CS-PLAN-032: admin is a plain house *member* under the unconditionally
// seeded family profile, so this spec exercises the non-owner scheduling
// surface. Serial within the file: the persist test mutates admin's own
// weekday availability and the restore test flips it back — order matters.
test.describe.configure({ mode: 'serial' })

const weekDays = [
	'monday',
	'tuesday',
	'wednesday',
	'thursday',
	'friday',
	'saturday',
	'sunday',
] as const

// Cached across serial tests: the original monday state the persist test
// flips and the restore test puts back.
let originalMonday: boolean | null = null

// Reads the current user's role from the app's Pinia state. The app
// auto-initializes the user store through the authenticated axios client
// (requesttoken header), which a bare page.request cannot replicate — its
// GET would fail the Nextcloud CSRF check. Returns null when the role has
// not resolved (yet), which the caller treats as "unknown".
async function resolveUserRole(page: import('@playwright/test').Page): Promise<string | null> {
	return page.evaluate(() => {
		const app = (window as unknown as {
			chorestrike?: {
				pinia?: {
					state?: {
						value?: {
							user?: {
								currentUser?: {
									role?: string
								}
							}
						}
					}
				}
			}
		}).chorestrike

		return app?.pinia?.state?.value?.user?.currentUser?.role ?? null
	})
}

test.describe('Self Availability (member)', () => {
	test.beforeEach(async ({ page }) => {
		test.setTimeout(90000)

		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('networkidle')
		await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
		await page.getByTestId('nav-scheduling').click()
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })

		await expect.poll(() => resolveUserRole(page), { timeout: 10000 }).toBe('member')
	})

	test('shows the My Availability section with seven weekday toggles', async ({ page }) => {
		await expect(page.getByTestId('self-availability-settings')).toBeVisible()
		await expect(page.getByRole('heading', { name: 'My Availability' })).toBeVisible()

		for (const day of weekDays) {
			await expect(page.getByTestId(`self-availability-${day}`)).toBeAttached()
		}
	})

	test('hides the owner-only member grid for a non-owner', async ({ page }) => {
		await expect(page.getByTestId('self-availability-settings')).toBeVisible()

		await expect(page.getByTestId('availability-settings')).toHaveCount(0)
		// The misleading empty state of the owner grid must not leak to members.
		await expect(page.getByText('No Members Found')).toHaveCount(0)
	})

	test('persists a toggled weekday through the self-service save', async ({ page }) => {
		const monday = page.getByTestId('self-availability-monday')
		await expect(monday).toBeAttached()
		originalMonday = await monday.isChecked()

		// The toggle input is visually hidden (toggle-slider pattern); clicking
		// the visible label toggles the checkbox natively.
		const mondayLabel = page.locator('label', { has: page.getByTestId('self-availability-monday') })
		await mondayLabel.click()
		expect(await monday.isChecked()).toBe(!originalMonday)

		const saveButton = page.getByTestId('save-self-availability-button')
		await expect(saveButton).toBeEnabled()
		// The disabled state alone is already true while the save is in flight
		// and would let the reload race the request — wait for the actual PUT
		// round-trip instead.
		const putResponse = page.waitForResponse((response) =>
			response.request().method() === 'PUT'
			&& response.url().includes('/scheduling/preferences'))
		await saveButton.click()
		expect((await putResponse).ok()).toBe(true)
		await expect(saveButton).toBeDisabled()

		// Reload: the persisted value must survive the self-service PUT round-trip.
		await page.reload()
		await page.waitForLoadState('networkidle')
		await page.getByTestId('scheduling-view').waitFor({ state: 'visible' })
		const mondayAfterReload = page.getByTestId('self-availability-monday')
		await expect(mondayAfterReload).toBeAttached()
		expect(await mondayAfterReload.isChecked()).toBe(!originalMonday)
	})

	test('restores the original weekday availability', async ({ page }) => {
		const monday = page.getByTestId('self-availability-monday')
		await expect(monday).toBeAttached()
		const current = await monday.isChecked()

		if (current !== originalMonday) {
			const mondayLabel = page.locator('label', { has: page.getByTestId('self-availability-monday') })
			await mondayLabel.click()

			const saveButton = page.getByTestId('save-self-availability-button')
			await expect(saveButton).toBeEnabled()
			// Verify the restore PUT actually round-tripped so a failed save
			// cannot pass silently on local checkbox state alone.
			const putResponse = page.waitForResponse((response) =>
				response.request().method() === 'PUT'
				&& response.url().includes('/scheduling/preferences'))
			await saveButton.click()
			expect((await putResponse).ok()).toBe(true)
			await expect(saveButton).toBeDisabled()
		}

		expect(await monday.isChecked()).toBe(originalMonday)
	})
})
