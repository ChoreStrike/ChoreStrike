/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Member management by an owner (CS-PLAN-038,
 * UX-003).
 *
 * Runs as `mom` (owner) against the `family` profile. Mutates member
 * state, so it lives in the serial DB pool like the other release-*
 * specs. Serial within the file: the role-change test restores teen's
 * role; the invite test needs the admin account to carry an email
 * (provisioned via occ below).
 *
 * Requires: Docker test environment with the `family` database profile.
 */

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { runOcc } from './fixtures/occ-runner.ts'

const ADMIN_EMAIL = 'admin@chorestrike.test'

test.describe.configure({ mode: 'serial' })

async function openMembersSection(page: import('@playwright/test').Page) {
	await page.goto('/apps/chorestrike/#/dashboard')
	await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
	await page.getByTestId('nav-settings').click()
	await page.getByTestId('settings-view').waitFor({ state: 'visible' })
	await page.getByTestId('members-section').waitFor({ state: 'visible' })
}

test.describe('Release: Members (owner)', () => {
	test.use({ databaseProfile: 'family' })

	test('invite by email adds an existing Nextcloud account to the house', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		// The invite lookup is email-based; give the (email-less) admin
		// account a known address first.
		runOcc(`user:setting admin settings email ${ADMIN_EMAIL}`)

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openMembersSection(page)

			await page.getByTestId('show-invite-button').click()
			await page.getByTestId('invite-email-input').fill(ADMIN_EMAIL)
			await page.getByTestId('invite-role-select').selectOption('member')

			const inviteResponse = page.waitForResponse((response) => response.request().method() === 'POST'
				&& response.url().includes('/api/v1/users/invite'))
			await page.getByTestId('invite-submit-button').click()
			expect((await inviteResponse).ok()).toBe(true)

			await expect(page.getByTestId('member-row-admin')).toBeVisible()
		} finally {
			await context.close()
		}
	})

	test('role change updates the member row', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const teen = seededData.data.users.find((user) => user.nextcloudUserId === 'teen')
		expect(teen).toBeDefined()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openMembersSection(page)

			const roleSelect = page.getByTestId('member-role-select-teen')
			await expect(roleSelect).toHaveValue('child')

			const putResponse = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& response.url().includes(`/api/v1/users/${teen.id}/role`))

			// Restore in the same test (serial): flip to member, assert, flip back.
			await roleSelect.selectOption('member')
			expect((await putResponse).ok()).toBe(true)
			await expect(page.getByTestId('member-role-badge-teen')).toHaveText('Member')

			const restoreResponse = page.waitForResponse((response) => response.request().method() === 'PUT'
				&& response.url().includes(`/api/v1/users/${teen.id}/role`))
			await roleSelect.selectOption('child')
			expect((await restoreResponse).ok()).toBe(true)
			await expect(page.getByTestId('member-role-badge-teen')).toHaveText('Child')
		} finally {
			await context.close()
		}
	})

	test('removing a member requires the styled confirm dialog', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await openMembersSection(page)

			await expect(page.getByTestId('member-row-friend')).toBeVisible()
			await page.getByTestId('member-remove-friend').click()

			// Shared ConfirmDialog (CS-PLAN-041; no native confirm() in the UI)
			await expect(page.getByTestId('confirm-dialog')).toBeVisible()
			await page.getByTestId('confirm-dialog-confirm').click()

			await expect(page.getByTestId('confirm-dialog')).toHaveCount(0)
			await expect(page.getByTestId('member-row-friend')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
