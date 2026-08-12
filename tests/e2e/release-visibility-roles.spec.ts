/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Visibility & Roles (CS-PLAN-024, Phase 3).
 *
 * Covers the release-test-plan.md §7 "Change role" row, which had no
 * automated home. The private / admin-only / child-filtering rows stay with
 * `private-tasks` / `admin-only-tasks` / `multi-user-interactions`
 * (extend-first, CS-PLAN-024 §2); invite/remove stay manual (they need real
 * Nextcloud account provisioning — release-test-plan §1.1).
 *
 * Contract verified:
 *  - An owner can change a member's role and the change persists.
 *  - A non-owner cannot change roles (403).
 *  - The role change affects visibility: a child→owner promotion reveals a
 *    previously-hidden admin-only task to that user.
 *
 * Seed-mutating (role change + task creation): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db). The family re-seed per test
 * restores teen's role, so no manual cleanup is required between runs.
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface HouseUserJson {
	id: number
	nextcloudUserId: string
	role: string
}

interface TaskJson {
	id: number
	visibilityType?: string
	ownerUserId?: string
}

/** Look up a house user's internal id + role by Nextcloud user id. */
async function findHouseUser(page: Page, nextcloudUserId: string): Promise<HouseUserJson | null> {
	const response = await page.request.get('/apps/chorestrike/api/v1/users', {
		headers: { Accept: 'application/json' },
	})
	if (response.status() !== 200) {
		return null
	}
	const body = await response.json()
	const users: HouseUserJson[] = body.data || []
	return users.find((u) => u.nextcloudUserId === nextcloudUserId) ?? null
}

/** All visible tasks for the acting user's current role. */
async function fetchVisibleTasks(page: Page): Promise<TaskJson[]> {
	const response = await page.request.get('/apps/chorestrike/api/v1/tasks', {
		headers: { Accept: 'application/json' },
	})
	if (response.status() !== 200) {
		return []
	}
	const body = await response.json()
	return (body.data || []) as TaskJson[]
}

test.describe('Release: Visibility & Roles', () => {
	test.use({ databaseProfile: 'family' })

	test('owner can change a role and it persists; non-owner is denied', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const owner = await createUserContext(browser, 'mom')
		const member = await createUserContext(browser, 'friend')
		try {
			const teen = await findHouseUser(owner.page, 'teen')
			expect(teen).not.toBeNull()
			expect(teen!.role).toBe('child')

			// Owner changes teen: child → member.
			const changeResponse = await owner.page.request.put(
				`/apps/chorestrike/api/v1/users/${teen!.id}/role`,
				{ data: { role: 'member' }, headers: { Accept: 'application/json' } },
			)
			expect(changeResponse.status()).toBe(200)
			expect((await changeResponse.json()).data.role).toBe('member')

			// Persistence: re-fetch the user list and confirm the role held.
			const teenAfter = await findHouseUser(owner.page, 'teen')
			expect(teenAfter!.role).toBe('member')

			// Non-owner (friend, a plain member) cannot change roles.
			const deniedResponse = await member.page.request.put(
				`/apps/chorestrike/api/v1/users/${teen!.id}/role`,
				{ data: { role: 'child' }, headers: { Accept: 'application/json' } },
			)
			expect(deniedResponse.status()).toBe(403)
		} finally {
			await owner.context.close()
			await member.context.close()
		}
	})

	test('promoting a child to owner reveals an admin-only task to them', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const owner = await createUserContext(browser, 'mom')
		const teenCtx = await createUserContext(browser, 'teen')
		try {
			const teen = await findHouseUser(owner.page, 'teen')
			expect(teen).not.toBeNull()

			// Owner creates an admin-only (owner-only) task.
			const createResponse = await owner.page.request.post('/apps/chorestrike/api/v1/tasks', {
				data: { name: 'Promotion Visibility Probe', energyCost: 5, visibilityType: 'admin-only' },
				headers: { Accept: 'application/json' },
			})
			if (createResponse.status() !== 200) {
				test.skip(true, 'Could not create admin-only task')
				return
			}
			const createdTask = (await createResponse.json()).data as TaskJson

			// As a child, teen cannot see the admin-only task.
			const visibleAsChild = await fetchVisibleTasks(teenCtx.page)
			expect(visibleAsChild.find((t) => t.id === createdTask.id)).toBeUndefined()

			// Promote teen to owner.
			const promote = await owner.page.request.put(
				`/apps/chorestrike/api/v1/users/${teen!.id}/role`,
				{ data: { role: 'owner' }, headers: { Accept: 'application/json' } },
			)
			expect(promote.status()).toBe(200)

			// As an owner, teen now sees the admin-only task.
			const visibleAsOwner = await fetchVisibleTasks(teenCtx.page)
			expect(visibleAsOwner.find((t) => t.id === createdTask.id)).toBeDefined()
		} finally {
			await owner.context.close()
			await teenCtx.context.close()
		}
	})
})
