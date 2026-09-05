/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * E2E — first-run setup wizard journey (CS-PLAN-062 §3.6, WP3 exit gate).
 *
 * Runs against the `emptyhouse` profile (reset only — no ChoreStrike rows,
 * no memberships) in the serial DB pool: the family Nextcloud accounts
 * (mom/dad) survive reseeds, so the journey authenticates real users whose
 * first request bootstraps them to owner (ADR-010 invite-only membership).
 *
 * Journey:
 *  1. mom logs in on the wiped instance → bootstraps to owner → the wizard
 *     auto-opens (owner-gated Dashboard entry).
 *  2. Every wizard step is completed through the UI: house name, floors/
 *     rooms, level, preview, apply.
 *  3. The rendered result panel and the persisted structure are asserted
 *     (exactly the previewed floors/rooms, house name set, one owner).
 *  4. dad is invited by email (provisioned via the occ-runner precedent
 *     from members-owner.spec.ts) → per-recipient success row; dad's own
 *     context sees the member view (no owner controls).
 *  5. Forced failures: a second apply racing the still-open wizard renders
 *     the recoverable "already set up" state (409), and an invalid-assignee
 *     apply proves the 400 path — both with zero additional structure.
 *
 * Requires: Docker test environment (test mode on for the `emptyhouse`
 * profile seed).
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { runOcc } from './fixtures/occ-runner'

const DAD_EMAIL = 'dad@chorestrike.test'

test.describe.configure({ mode: 'serial' })

test.describe('Setup wizard journey (empty house)', () => {
	test.use({ databaseProfile: 'emptyhouse' })

	// The journey deliberately leaves the `emptyhouse` state behind; the
	// serial pool continues with seed-coupled specs (e.g.
	// task-checklist-and-features) that expect the family profile, so
	// restore the canonical seed after the journey.
	test.afterAll(async ({ databaseManager }) => {
		await databaseManager.seed('family')
	})

	test('owner completes the wizard through the UI and invites dad', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			// mom's first request bootstraps her to owner; the empty house
			// auto-opens the wizard on the Dashboard.
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			const wizard = page.getByTestId('setup-wizard')
			await expect(wizard).toBeVisible()

			// Step 1: quick start.
			await page.getByTestId('wizard-house-name').fill('Journey Home')
			await page.getByTestId('wizard-floor-count').fill('1')
			await page.getByTestId('wizard-rooms-per-floor').fill('2')
			await page.getByTestId('wizard-next').click()

			// Step 2: room list (generated; rename the first room).
			await page.getByTestId('wizard-step-rooms').waitFor({ state: 'visible' })
			await page.getByTestId('wizard-room-name-0-0').fill('Living Room')
			await page.getByTestId('wizard-next').click()

			// Step 3: preview → apply.
			await page.getByTestId('wizard-step-preview').waitFor({ state: 'visible' })
			await page.getByTestId('wizard-apply').click()

			// Step 4: the result panel reports the structural outcome.
			await page.getByTestId('wizard-step-invite').waitFor({ state: 'visible' })
			await expect(page.getByTestId('wizard-apply-result')).toBeVisible()
			await expect(page.getByTestId('wizard-result-house-name')).toContainText('Journey Home')

			// The persisted structure matches exactly what was previewed:
			// 1 floor, the 2 named rooms + the auto hallway + household room.
			const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
			expect(floorsResponse.ok()).toBeTruthy()
			const floorsBody = await floorsResponse.json()
			expect(floorsBody.data).toHaveLength(1)
			const roomNames = floorsBody.data[0].rooms.map((room: { name: string }) => room.name)
			expect(roomNames).toContain('Living Room')
			expect(roomNames).toHaveLength(4)

			const houseResponse = await page.request.get('/apps/chorestrike/api/v1/house')
			const houseBody = await houseResponse.json()
			expect(houseBody.data.name).toBe('Journey Home')

			const membersResponse = await page.request.get('/apps/chorestrike/api/v1/users')
			const membersBody = await membersResponse.json()
			expect(membersBody.data).toHaveLength(1)
			expect(membersBody.data[0].role).toBe('owner')

			// Invite dad: the lookup is email-based, so provision his address
			// first (same occ precedent as members-owner.spec.ts).
			runOcc(`user:setting dad settings email ${DAD_EMAIL}`)
			await page.getByTestId('wizard-invite-email-0').fill(DAD_EMAIL)

			const inviteResponse = page.waitForResponse((response) =>
				response.request().method() === 'POST'
				&& response.url().includes('/api/v1/users/invite'))
			await page.getByTestId('wizard-send-invites').click()
			expect((await inviteResponse).ok()).toBeTruthy()

			// Per-recipient success row names the joined user.
			await expect(page.getByTestId('wizard-invite-status-0')).toContainText('Dad')

			// Finish → the Dashboard reloads the created structure.
			await page.getByTestId('wizard-finish').click()
			await expect(wizard).toHaveCount(0)
			await expect(page.getByTestId('floor-list')).toBeVisible()
		} finally {
			await context.close()
		}

		// dad's own context sees the member view: no owner setup entry.
		const { context: dadContext, page: dadPage } = await createUserContext(browser, 'dad')
		try {
			await dadPage.goto('/apps/chorestrike/')
			await dadPage.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await expect(dadPage.getByTestId('open-setup-wizard')).toHaveCount(0)
			await expect(dadPage.getByTestId('floor-list')).toBeVisible()

			const currentResponse = await dadPage.request.get('/apps/chorestrike/api/v1/users/current')
			const currentBody = await currentResponse.json()
			expect(currentBody.data.role).toBe('member')
		} finally {
			await dadContext.close()
		}
	})

	test('forced failures keep the structure and offer recovery', async ({ browser, seededData }) => {
		// The seededData fixture reset the DB again — a fresh empty house.
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await page.goto('/apps/chorestrike/')
			await page.getByTestId('dashboard-view').waitFor({ state: 'visible' })
			await expect(page.getByTestId('setup-wizard')).toBeVisible()

			// Prepare the wizard input, but do NOT apply yet.
			await page.getByTestId('wizard-house-name').fill('Race Home')
			await page.getByTestId('wizard-floor-count').fill('1')
			await page.getByTestId('wizard-rooms-per-floor').fill('1')
			await page.getByTestId('wizard-next').click()
			await page.getByTestId('wizard-step-rooms').waitFor({ state: 'visible' })
			await page.getByTestId('wizard-next').click()
			await page.getByTestId('wizard-step-preview').waitFor({ state: 'visible' })

			// A "second tab" wins the race while the wizard sits on the
			// preview step.
			const raceResponse = await page.request.post('/apps/chorestrike/api/v1/setup/apply', {
				data: {
					houseName: 'Race Winner',
					intensityLevel: 'putzteufel',
					floors: [{ name: 'Winner Floor', rooms: [{ name: 'Kitchen', roomType: 'kitchen' }] }],
				},
				headers: { Accept: 'application/json' },
			})
			expect(raceResponse.status()).toBe(200)

			// The losing UI apply hits the in-transaction gate → the wizard
			// renders the recoverable already-set-up state.
			await page.getByTestId('wizard-apply').click()
			await expect(page.getByTestId('wizard-already-set-up')).toBeVisible()

			// "Continue to the app" closes the wizard and reloads the winner's
			// structure.
			await page.getByTestId('wizard-continue').click()
			await expect(page.getByTestId('setup-wizard')).toHaveCount(0)
			await expect(page.getByTestId('floor-list')).toBeVisible()

			// Exactly the winner's structure: one floor, the winner's name.
			const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
			const floorsBody = await floorsResponse.json()
			expect(floorsBody.data).toHaveLength(1)
			const floorCount = floorsBody.data.length

			const houseResponse = await page.request.get('/apps/chorestrike/api/v1/house')
			const houseBody = await houseResponse.json()
			expect(houseBody.data.name).toBe('Race Winner')

			// Invalid-assignee variant: membership validation fails before the
			// gate (400), and no additional structure appears.
			const invalidResponse = await page.request.post('/apps/chorestrike/api/v1/setup/apply', {
				data: {
					intensityLevel: 'putzteufel',
					floors: [{ name: 'Extra Floor', rooms: [{ name: 'X', roomType: 'kitchen', assigneeUserId: 'ghost-user-xyz' }] }],
				},
				headers: { Accept: 'application/json' },
			})
			expect(invalidResponse.status()).toBe(400)
			const invalidBody = await invalidResponse.json()
			expect(invalidBody.error.code).toBe('VALIDATION_ERROR')

			const afterResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
			const afterBody = await afterResponse.json()
			expect(afterBody.data).toHaveLength(floorCount)
		} finally {
			await context.close()
		}
	})
})
