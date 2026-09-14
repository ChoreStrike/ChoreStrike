/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Canonical household journey (CS-PLAN-068 §3.3) — replaces the retired
 * gui-scratch-flow spec (parent decision D5).
 *
 * ONE test with named test.step phases and ONE database owner
 * (`chromium-serial-db`, `emptyhouse` profile): the run boots an unowned
 * instance, and every app-state mutation afterwards happens through the
 * real UI. Browser API reads capture before/after snapshots and responses
 * only — they never create memberships, schedule, complete, undo or repair
 * the state under test. API-driven negative/fault-injection proofs stay in
 * the focused specs (scheduling-apply-flow, validation, error-handling,
 * family-core-ux, workflow-state-reconciliation), which pin duplicate
 * requests, removed membership, stale previews and failure recovery.
 *
 * Deterministic regardless of the run's weekday: the candidate task is
 * created with its first due day in the future inside a valid flex window
 * and the owner explicitly moves it to house-local TODAY and assigns it to
 * the child through the scheduling UI, so My Day is provably executable on
 * any calendar day.
 *
 * Requires: Docker test environment, test mode on (family NC accounts from
 * auth.setup survive the `emptyhouse` reset).
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { runOcc } from './fixtures/occ-runner'
import { expandAllFloors } from './fixtures/dashboard-floors'
import { journeyClock } from './fixtures/house-clock.js'
import { addDaysToKey } from '../../src/utils/dateKeys.js'
import type { Browser, Page } from '@playwright/test'

const DAD_EMAIL = 'dad@chorestrike.test'
const KID_EMAIL = 'kid@chorestrike.test'
const HOUSE_NAME = 'Journey Home'
const CUSTOM_TASK = 'Journey Dishes'
const ADMIN_TASK = 'Journey Admin Audit'

/** Open the app and wait for the shell. */
async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/')
	await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
}

test.describe('Canonical household journey', () => {
	test.use({ databaseProfile: 'emptyhouse' })

	test.describe.configure({ mode: 'serial' })

	// The journey deliberately leaves its state behind; the serial pool
	// continues with seed-coupled specs that expect the family profile, so
	// restore the canonical seed after the journey's contexts are closed.
	test.afterAll(async ({ databaseManager }) => {
		await databaseManager.seed('family')
	})

	test('canonical household journey: first owner, setup, invitations, tasks, scheduling, child My Day, completion and undo', async ({ browser, seededData }) => {
		test.setTimeout(420000)
		expect(seededData).not.toBeNull()
		const clock = journeyClock()
		const houseLocalDate = (offset: number) => addDaysToKey(clock.today, offset)

		// Pre-completion baselines captured in the execution phase; the undo
		// phase proves their exact restoration.
		let baselineCoins = 0
		let baselineContribution = 0
		let baselineCleanliness = 0
		let kitchenRoomId = 0
		let flexRoomTaskId = 0

		// ----------------------------------------------------------------
		// Phase 1 — First owner (unbootstrapped instance)
		// ----------------------------------------------------------------
		await test.step('first owner bootstraps exactly one house with exactly one owner', async () => {
			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await openApp(page)
				// No app household existed: the owner-gated wizard auto-opens
				// (after the house bootstrap round-trip).
				await expect(page.getByTestId('setup-wizard')).toBeVisible({ timeout: 20000 })

				// The bootstrap created exactly one membership — the owner —
				// and no structure yet. Read-only proof through the session API.
				const membersResponse = await page.request.get('/apps/chorestrike/api/v1/users')
				expect(membersResponse.ok()).toBeTruthy()
				const members = (await membersResponse.json()).data
				expect(members).toHaveLength(1)
				expect(members[0].nextcloudUserId).toBe('mom')
				expect(members[0].role).toBe('owner')

				const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
				expect((await floorsResponse.json()).data).toHaveLength(0)

				// A reload is idempotent: still one owner, wizard still open —
				// no accidental second bootstrap or duplicate house.
				await page.reload()
				await expect(page.getByTestId('setup-wizard')).toBeVisible({ timeout: 20000 })
				const membersAfterReload = await (await page.request.get('/apps/chorestrike/api/v1/users')).json()
				expect(membersAfterReload.data).toHaveLength(1)
			} finally {
				await context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 2 — Setup wizard (structure + adult invitation, one session)
		// ----------------------------------------------------------------
		await test.step('the wizard creates the announced structure and invites the adult member', async () => {
			// The invitation lookup is email-based — provision both addresses
			// first (same occ precedent as members-owner.spec.ts).
			runOcc(`user:setting dad settings email ${DAD_EMAIL}`)
			runOcc(`user:setting kid settings email ${KID_EMAIL}`)

			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await openApp(page)
				await expect(page.getByTestId('setup-wizard')).toBeVisible({ timeout: 20000 })

				await page.getByTestId('wizard-house-name').fill(HOUSE_NAME)
				await page.getByTestId('wizard-floor-count').fill('1')
				await page.getByTestId('wizard-rooms-per-floor').fill('2')
				await page.getByTestId('wizard-level-putzteufel').click()
				await page.getByTestId('wizard-next').click()

				await page.getByTestId('wizard-step-rooms').waitFor({ state: 'visible' })
				await page.getByTestId('wizard-room-name-0-0').fill('Kitchen')
				await page.getByTestId('wizard-room-type-0-0').selectOption('kitchen')
				await page.getByTestId('wizard-room-name-0-1').fill('Bedroom')
				await page.getByTestId('wizard-room-type-0-1').selectOption('bedroom')
				await page.getByTestId('wizard-household').uncheck()
				await page.getByTestId('wizard-hallways').uncheck()
				await page.getByTestId('wizard-next').click()

				await page.getByTestId('wizard-step-preview').waitFor({ state: 'visible' })
				await expect(page.getByTestId('wizard-room-task-count-0-0')).toContainText('8')
				await expect(page.getByTestId('wizard-room-task-count-0-1')).toContainText('5')
				await expect(page.getByTestId('wizard-preview-task-count')).toContainText('13')
				await page.getByTestId('wizard-apply').click()

				await page.getByTestId('wizard-step-invite').waitFor({ state: 'visible' })
				await expect(page.getByTestId('wizard-apply-result')).toBeVisible()
				await expect(page.getByTestId('wizard-result-house-name')).toContainText(HOUSE_NAME)

				// The persisted structure matches exactly what was previewed.
				const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
				const floors = (await floorsResponse.json()).data
				expect(floors).toHaveLength(1)
				const roomNames = floors[0].rooms.map((room: { name: string }) => room.name)
				expect(roomNames.sort()).toEqual(['Bedroom', 'Kitchen'])
				const occurrences = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
				expect((await occurrences.json()).data).toHaveLength(13)

				// Wizard invite step: dad joins as a member.
				await page.getByTestId('wizard-invite-email-0').fill(DAD_EMAIL)
				const inviteResponse = page.waitForResponse((response) =>
					response.request().method() === 'POST'
					&& response.url().includes('/api/v1/users/invite'))
				await page.getByTestId('wizard-send-invites').click()
				expect((await inviteResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('wizard-invite-status-0')).toContainText('Dad')
				await page.getByTestId('wizard-finish').click()
				await expect(page.getByTestId('setup-wizard')).toHaveCount(0)
				await expect(page.getByTestId('floor-list')).toBeVisible()
			} finally {
				await context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 3 — Invitations (child invite via members UI; re-entry roles)
		// ----------------------------------------------------------------
		await test.step('the owner invites the child via the members UI; roles and invite-only membership hold on re-entry', async () => {
			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await openApp(page)

				// Members UI: kid joins as a CHILD.
				await page.getByTestId('nav-settings').click()
				await expect(page.getByTestId('settings-view')).toBeVisible({ timeout: 20000 })
				const zone = page.getByTestId('house-timezone-select')
				await expect(zone).toBeVisible()
				const originalHouse = await page.request.get('/apps/chorestrike/api/v1/house')
				await expect(zone).toHaveValue((await originalHouse.json()).data.timezone)
				if ((await zone.inputValue()) !== clock.timeZone) {
					await zone.selectOption(clock.timeZone)
					const saved = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().endsWith('/api/v1/house'))
					await page.getByTestId('save-house-timezone').click()
					expect((await saved).ok()).toBeTruthy()
				}
				const house = await page.request.get('/apps/chorestrike/api/v1/house')
				expect((await house.json()).data.timezone).toBe(clock.timeZone)
				await page.getByTestId('show-invite-button').click()
				await page.getByTestId('invite-email-input').fill(KID_EMAIL)
				await page.getByTestId('invite-role-select').selectOption('child')
				const childInviteResponse = page.waitForResponse((response) =>
					response.request().method() === 'POST'
					&& response.url().includes('/api/v1/users/invite'))
				await page.getByTestId('invite-submit-button').click()
				expect((await childInviteResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('member-row-kid')).toBeVisible({ timeout: 15000 })
				await expect(page.getByTestId('member-role-badge-kid')).toContainText(/child/i)
			} finally {
				await context.close()
			}

			// dad's isolated session enters with the expected membership+role.
			const dad = await createUserContext(browser, 'dad')
			try {
				await openApp(dad.page)
				await expect(dad.page.getByTestId('floor-list')).toBeVisible()
				await expect(dad.page.getByTestId('open-setup-wizard')).toHaveCount(0)
				const current = await (await dad.page.request.get('/apps/chorestrike/api/v1/users/current')).json()
				expect(current.data.role).toBe('member')
			} finally {
				await dad.context.close()
			}

			// A non-invited account does NOT auto-enroll: the app is replaced
			// by the explicit not-authorized state (invite-only membership,
			// removed vs. never-invited deliberately indistinguishable), and
			// the members list stays exactly the invited three.
			const teen = await createUserContext(browser, 'teen')
			try {
				await teen.page.goto('/apps/chorestrike/')
				await expect(teen.page.getByTestId('not-authorized-state')).toBeVisible({ timeout: 20000 })
			} finally {
				await teen.context.close()
			}
			const ownerProbe = await createUserContext(browser, 'mom')
			try {
				const members = await (await ownerProbe.page.request.get('/apps/chorestrike/api/v1/users')).json()
				const identities = members.data.map((entry: { nextcloudUserId: string }) => entry.nextcloudUserId).sort()
				expect(identities).toEqual(['dad', 'kid', 'mom'])
			} finally {
				await ownerProbe.context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 4 — Tasks and assignments (custom + template, through the UI)
		// ----------------------------------------------------------------
		await test.step('custom and template-based room tasks are created through the UI and persist exactly', async () => {
			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await openApp(page)

				// The Dashboard renders floors COLLAPSED — room cards appear
				// only after expanding (CS-PLAN-050 fixture).
				await expandAllFloors(page)

				// ---- Custom task with instructions, flex window and assignee.
				await page.getByTestId('room-card-Kitchen').first().click()
				await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 20000 })
				await page.getByTestId('add-task-button').click()

				// The unified picker opens on the template tab — switch to the
				// custom form.
				const picker = page.getByTestId('add-task-picker')
				await expect(picker).toBeVisible()
				await page.getByTestId('picker-tab-custom').click()

				await page.getByTestId('task-name-input').fill(CUSTOM_TASK)
				await page.getByTestId('task-instructions-input').fill('Rinse everything, then load the racks.')
				await page.getByTestId('task-energy-select').selectOption('2')
				await page.getByTestId('task-frequency-type').selectOption('daily')
				// The flex candidate starts on a FUTURE day inside a valid
				// flex window (due +2, flex 3) — movable to house-local today.
				await page.getByTestId('task-next-due-input').fill(houseLocalDate(2))
				// Deliberately UNASSIGNED: the owner assigns the child through
				// the scheduling UI in phase 5 (plan §3.3) — the preview needs
				// an unplaced candidate to propose a placement for.
				await page.getByTestId('task-flex-days-input').fill('3')
				await page.getByTestId('save-task-button').click()
				await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 20000 })

				// ---- Second custom task: admin-only visibility probe.
				await page.getByTestId('add-task-button').click()
				const picker2 = page.getByTestId('add-task-picker')
				await expect(picker2).toBeVisible()
				await page.getByTestId('picker-tab-custom').click()
				await page.getByTestId('task-name-input').fill(ADMIN_TASK)
				await page.getByTestId('task-energy-select').selectOption('1')
				await page.getByTestId('task-visibility-select').selectOption('admin-only')
				await page.getByTestId('save-task-button').click()
				await expect(page.getByTestId('task-name-input')).toHaveCount(0, { timeout: 20000 })

				// ---- Template-based task through the picker UI.
				await page.getByTestId('add-task-button').click()
				await expect(page.getByTestId('add-task-picker')).toBeVisible()
				await page.getByTestId('picker-level-putzmuffel').click()
				await page.setViewportSize({ width: 390, height: 844 })
				await page.getByTestId('picker-level-putzteufel').click()
				await page.getByTestId('picker-level-putzmuffel').click()
				const levelTarget = await page.getByTestId('picker-level-putzmuffel').boundingBox()
				expect(levelTarget!.height).toBeGreaterThanOrEqual(44)
				expect(levelTarget!.width).toBeGreaterThanOrEqual(44)
				const templateResponse = page.waitForResponse((response) =>
					response.request().method() === 'POST'
					&& response.url().includes('/api/v1/room-tasks'))
				await page.getByTestId('picker-template-kitchen_sink').click()
				const templateCreated = await templateResponse
				expect(templateCreated.ok()).toBeTruthy()
				const templateTask = (await templateCreated.json()).data.task
				expect(templateTask.frequencyType).toBe('weekly')
				expect(templateTask.frequencyValue).toBe(2)
				expect(templateTask.estimatedMinutes).toBe(7)
				await page.setViewportSize({ width: 1280, height: 720 })
				await expect(page.getByTestId('add-task-picker')).toHaveCount(0, { timeout: 20000 })

				// ---- Persisted reads and a reload match the submitted values.
				// The definition list carries name/instructions; the room-task
				// rows are FLAT (taskId + assignees, no nested task object).
				const taskResponse = await page.request.get('/apps/chorestrike/api/v1/tasks')
				const definitions = (await taskResponse.json()).data as Array<{ id: number; name: string; instructions?: string }>
				const definition = definitions.find((task) => task.name === CUSTOM_TASK)
				expect(definition).toBeDefined()
				expect(definition!.instructions).toBe('Rinse everything, then load the racks.')

				const kitchen = await kitchenRoom(page)
				kitchenRoomId = kitchen.id
				const roomTasksResponse = await page.request.get(`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchen.id}`)
				const roomTasks = (await roomTasksResponse.json()).data as Array<{
					id: number
					taskId: number
					assignees: string[]
					nextDue: string
				}>
				const custom = roomTasks.find((roomTask) => roomTask.taskId === definition!.id)
				expect(custom, 'the submitted aggregate materialized the room task').toBeDefined()
				flexRoomTaskId = custom!.id
				expect(custom!.assignees).toEqual([])
				expect(custom!.nextDue.startsWith(houseLocalDate(2))).toBeTruthy()

				await page.reload()
				await expect(page.getByTestId('room-detail-view')).toBeVisible({ timeout: 20000 })
				await expect(page.getByTestId('room-task-list').getByText(CUSTOM_TASK)).toBeVisible({ timeout: 15000 })

				// Checklist input through the owner edit surface (TaskManager);
				// the editor opens via the add-item trigger (same pattern as
				// task-checklist-and-features.spec.ts).
				await page.getByTestId('nav-tasks').click()
				await expect(page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
				const taskRow = page.locator('[data-testid="task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				await expect(taskRow).toBeVisible({ timeout: 15000 })
				await taskRow.locator('button[title="Show details"]').click()
				await expect(page.getByTestId('task-checklist')).toBeVisible({ timeout: 15000 })
				await page.locator('.add-item-trigger').first().click()
				await page.getByTestId('checklist-new-item-input').fill('Rinse plates')
				await page.getByTestId('checklist-add-item-button').click()
				await expect(page.locator('[data-testid^="checklist-item-"]')).toHaveCount(1, { timeout: 15000 })
				// The inline editor closes per item — reopen it for the second.
				await page.locator('.add-item-trigger').first().click()
				await page.getByTestId('checklist-new-item-input').fill('Load the racks')
				await page.getByTestId('checklist-add-item-button').click()
				await expect(page.locator('[data-testid^="checklist-item-"]')).toHaveCount(2, { timeout: 15000 })
			} finally {
				await context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 5 — Scheduling: preview (no writes) → explicit overrides → apply
		// ----------------------------------------------------------------
		await test.step('owner previews, moves the candidate to house-local today for the child, and applies', async () => {
			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await page.goto('/apps/chorestrike/#/scheduling')
				await expect(page.getByTestId('scheduling-view')).toBeVisible({ timeout: 20000 })
				await expect(page.getByTestId('schedule-planner')).toBeVisible({ timeout: 20000 })

				// Configure sufficient availability + child limit through the
				// existing owner controls first: the Kid card gets an explicit
				// 2-tasks/day limit and an explicit full-week budget.
				const kidCard = page.locator('.member-card', { hasText: 'Kid' }).first()
				await expect(kidCard).toBeVisible({ timeout: 15000 })
				await kidCard.getByTestId('edit-availability-button').click()
				await expect(page.getByTestId('edit-availability-modal')).toBeVisible()
				for (const day of ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']) {
					await page.getByTestId(`budget-${day}-input`).fill('10')
				}
				// The toggle input is visually hidden — click its label.
				await page.locator('label:has([data-testid="limit-enabled-toggle"])').click()
				await page.getByTestId('limit-value-input').fill('2')
				const availabilityResponse = page.waitForResponse((response) =>
					response.request().method() === 'PUT'
					&& response.url().includes('/api/v1/scheduling/'))
				await page.getByTestId('save-availability-button').click()
				expect((await availabilityResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('edit-availability-modal')).toHaveCount(0)

				// Select the candidate and preview.
				const taskCheckbox = page.getByTestId(`planner-task-checkbox-${flexRoomTaskId}`)
				await expect(taskCheckbox).toBeAttached({ timeout: 15000 })
				await taskCheckbox.check()
				await page.getByTestId('preview-plan-button').click()
				await expect(page.getByTestId('plan-preview')).toBeVisible({ timeout: 20000 })
				const changeRow = page.getByTestId(`plan-change-${flexRoomTaskId}`)
				await expect(changeRow).toBeVisible()

				// No writes during preview: the room task still carries its
				// future due date and the pre-preview assignee set.
				const beforeApplyResponse = await page.request.get(
					`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchenRoomId}`,
				)
				const beforeApply = ((await beforeApplyResponse.json()).data as Array<{ id: number; nextDue: string; assignees: string[] }>)
					.find((roomTask) => roomTask.id === flexRoomTaskId)
				expect(beforeApply!.nextDue.startsWith(houseLocalDate(2))).toBeTruthy()
				expect(beforeApply!.assignees).toEqual([])

				// Explicit overrides: move to house-local TODAY, assign Kid.
				// Any override edit invalidates the preview (stale gate) — the
				// UI requires a fresh preview before the apply unlocks.
				await changeRow.getByTestId(`planner-assignee-${flexRoomTaskId}`).selectOption('kid')
				await changeRow.getByTestId(`planner-date-${flexRoomTaskId}`).selectOption(houseLocalDate(0))
				await page.getByTestId('preview-plan-button').click()
				await expect(page.getByTestId('plan-preview')).toBeVisible({ timeout: 20000 })

				const applyResponse = page.waitForResponse((response) =>
					response.request().method() === 'POST'
					&& response.url().includes('/api/v1/scheduling/apply'))
				await page.getByTestId('apply-plan-button').click()
				expect((await applyResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('plan-applied')).toBeVisible({ timeout: 20000 })

				// The APPLIED result: due today, assigned to the child.
				const afterApplyResponse = await page.request.get(
					`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchenRoomId}`,
				)
				const afterApply = ((await afterApplyResponse.json()).data as Array<{ id: number; nextDue: string; assignees: string[] }>)
					.find((roomTask) => roomTask.id === flexRoomTaskId)
				expect(afterApply!.nextDue.startsWith(houseLocalDate(0))).toBeTruthy()
				expect(afterApply!.assignees).toEqual(['kid'])

				// Calendar and My Day reconcile from the applied result.
				await expect(page.getByTestId('schedule-calendar')).toBeVisible()
				await page.getByTestId('nav-calendar').click()
				await expect(page.getByTestId('calendar-view')).toBeVisible()
				await page.getByTestId(`calendar-day-${clock.today}`).click()
				await expect(page.getByTestId('calendar-day-list')).toContainText(CUSTOM_TASK)
			} finally {
				await context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 6 — Child execution on My Day (checklist + complete).
		// The kid session stays OPEN: the queued-undo snackbar is per-session
		// UI state, so phase 7 undoes it in the same session.
		// ----------------------------------------------------------------
		let kidSession: { context: import('@playwright/test').BrowserContext; page: Page } | null = null
		let receiptRewards: Record<string, unknown> | null = null
		await test.step('the child executes the assigned task on My Day, completes it and queues the undo', async () => {
			// Independently captured before-state (read-only API snapshots).
			const kidPreview = await createUserContext(browser, 'kid')
			try {
				// A child's bare-root visit lands on My Day (CS-PLAN-066 D4).
				await kidPreview.page.goto('/apps/chorestrike/')
				await expect(kidPreview.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })

				const row = kidPreview.page.locator('[data-testid="today-task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				await expect(row).toBeVisible({ timeout: 20000 })
				await expect(row).toContainText('Kitchen')
				await expect(row).toContainText('Rinse everything, then load the racks.')

				// Checklist progress is usable and persists per occurrence; the
				// checkbox input is visually hidden — click its label.
				await kidPreview.page.getByTestId(`today-checklist-toggle-${flexRoomTaskId}`).click()
				const checklistItem = kidPreview.page.locator('[data-testid^="checklist-item-"]').first()
				await expect(checklistItem).toBeVisible({ timeout: 15000 })
				await checklistItem.locator('label.item-checkbox').click()
				await expect(checklistItem.locator('input[type="checkbox"]')).toBeChecked({ timeout: 15000 })
			} finally {
				await kidPreview.context.close()
			}

			baselineCoins = await walletOf(browser)
			baselineContribution = (await userSnapshot(browser)).contribution
			baselineCleanliness = await kitchenCleanliness(browser)

			// Complete through the UI and capture the immutable receipt — in
			// the session that stays open for the queued undo (phase 7). The
			// snackbar auto-dismisses after 5s, so the persisted projections
			// are read through THIS session's request context (fast).
			kidSession = await createUserContext(browser, 'kid')
			const kidExecutor = kidSession
			try {
				await kidExecutor.page.goto('/apps/chorestrike/')
				await expect(kidExecutor.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				const row = kidExecutor.page.locator('[data-testid="today-task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				await expect(row).toBeVisible({ timeout: 20000 })

				const completeResponsePromise = kidExecutor.page.waitForResponse((response) => {
					const pathname = new URL(response.url()).pathname
					return response.request().method() === 'POST'
						&& pathname === `/apps/chorestrike/api/v1/room-tasks/${flexRoomTaskId}/complete`
				})
				await kidExecutor.page.getByTestId(`today-complete-button-${flexRoomTaskId}`).click()
				const receipt = await (await completeResponsePromise).json()
				expect(receipt.success).toBe(true)
				const rewards = receipt.data.rewards
				receiptRewards = rewards

				// Exact credited deltas against the independently captured
				// before-state (never a value compared to itself).
				expect(rewards.energyCost).toBe(2)
				expect(rewards.contributionScore).toBe(2)
				expect(rewards.cleanlinessChange).toBe(20)
				expect(rewards.teamworkBonus).toBe(0)
				expect(rewards.coins).toBe(30)
				expect(rewards.isCombo).toBe(false)
				expect(rewards.totalCoins).toBe(baselineCoins + 30)
				expect(rewards.totalContribution).toBe(baselineContribution + 2)
				expect(rewards.currentStreak).toBe(1)
				expect(receipt.data.room.cleanliness).toBe(Math.min(100, baselineCleanliness + 20))
			} catch (err) {
				await kidSession.context.close()
				kidSession = null
				throw err
			}
		})

		// ----------------------------------------------------------------
		// Phase 7 — Undo and reload (ADR-011 projections)
		// ----------------------------------------------------------------
		await test.step('the queued undo restores the exact before-state live and after reload', async () => {
			// The undo happens in the SAME session that completed — the
			// queued-undo snackbar is per-session UI state and auto-dismisses
			// after 5s, so the persisted projections are read through the kid
			// session's own primed request context right around the click.
			const kid = kidSession
			expect(kid).not.toBeNull()
			try {
				// The queued Undo control for THIS completion is offered…
				const snackbar = kid.page.getByTestId(`undo-snackbar-undo-button-${flexRoomTaskId}`)
				await expect(snackbar).toBeVisible({ timeout: 5000 })

				// Persisted projections right after the completion (fast read).
				const coinsAfter = await (await kid.page.request.get('/apps/chorestrike/api/v1/minigames/coins')).json()
				expect(coinsAfter.data.currentCoins).toBe(baselineCoins + (receiptRewards!.coins as number))

				const undoResponsePromise = kid.page.waitForResponse((response) => {
					const pathname = new URL(response.url()).pathname
					return response.request().method() === 'POST'
						&& pathname === `/apps/chorestrike/api/v1/room-tasks/${flexRoomTaskId}/undo`
				})
				await snackbar.click()
				const undoBody = await (await undoResponsePromise).json()
				expect(undoBody.data.undone).toBe(true)

				// …the row is active again on My Day.
				const row = kid.page.locator('[data-testid="today-task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				await expect(row).toBeVisible({ timeout: 20000 })
				await expect(kid.page.getByTestId(`today-complete-button-${flexRoomTaskId}`)).toBeVisible()
			} finally {
				await kid.context.close()
			}

			// …and after a RELOAD the authoritative state still matches the
			// pre-completion baseline (persisted inverse, not a client trick):
			// wallet, contribution, streak, cleanliness and the occurrence.
			const kidAgain = await createUserContext(browser, 'kid')
			try {
				await kidAgain.page.goto('/apps/chorestrike/')
				await expect(kidAgain.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				const current = await (await kidAgain.page.request.get('/apps/chorestrike/api/v1/users/current')).json()
				expect(current.data.contributionScore).toBe(baselineContribution)
				expect(current.data.currentStreak).toBe(0)
				expect(await walletOf(browser)).toBe(baselineCoins)
				expect(await kitchenCleanliness(browser)).toBe(baselineCleanliness)
				const roomTasksResponse = await kidAgain.page.request.get(
					`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchenRoomId}`,
				)
				const restored = ((await roomTasksResponse.json()).data as Array<{ id: number; lastCompleted: string | null; isActive: boolean; nextDue: string }>)
					.find((roomTask) => roomTask.id === flexRoomTaskId)
				expect(restored!.isActive).toBe(true)
				expect(restored!.lastCompleted).toBeNull()
				expect(restored!.nextDue.startsWith(houseLocalDate(0))).toBeTruthy()
				await kidAgain.page.getByTestId(`today-checklist-toggle-${flexRoomTaskId}`).click()
				const progress = kidAgain.page.locator('[data-testid^="checklist-item-"] input[type="checkbox"]')
				await expect(progress).toHaveCount(2)
				await expect(progress.nth(0)).toBeChecked()
				await expect(progress.nth(1)).not.toBeChecked()
			} finally {
				await kidAgain.context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 8 — Identity / retry: roles and visibility stay authoritative
		// ----------------------------------------------------------------
		await test.step('re-entering as owner/member/child keeps state and visibility authoritative without another reset', async () => {
			// Owner: sees both tasks, including the admin-only one.
			const owner = await createUserContext(browser, 'mom')
			try {
				await owner.page.goto('/apps/chorestrike/#/tasks')
				await expect(owner.page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
				await expect(owner.page.getByTestId('task-manager-view').getByText(ADMIN_TASK)).toBeVisible({ timeout: 15000 })
			} finally {
				await owner.context.close()
			}

			// Member: the admin-only task is invisible (ADR-010 visibility).
			const member = await createUserContext(browser, 'dad')
			try {
				await member.page.goto('/apps/chorestrike/#/tasks')
				await expect(member.page.getByTestId('task-manager-view')).toBeVisible({ timeout: 20000 })
				await expect(member.page.getByTestId('task-manager-view').getByText(ADMIN_TASK)).toHaveCount(0)
				// The membership list still carries exactly the invited three.
				const members = await (await member.page.request.get('/apps/chorestrike/api/v1/users')).json()
				const identities = members.data.map((entry: { nextcloudUserId: string }) => entry.nextcloudUserId).sort()
				expect(identities).toEqual(['dad', 'kid', 'mom'])
			} finally {
				await member.context.close()
			}

			// Child: the candidate is STILL scheduled for today (the undone
			// completion restored — never recalculated — its occurrence).
			const child = await createUserContext(browser, 'kid')
			try {
				await child.page.goto('/apps/chorestrike/')
				await expect(child.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				const row = child.page.locator('[data-testid="today-task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				await expect(row).toBeVisible({ timeout: 15000 })
				await expect(child.page.getByTestId(`today-complete-button-${flexRoomTaskId}`)).toBeVisible()
			} finally {
				await child.context.close()
			}
		})
	})
})

// ---------------------------------------------------------------------------
// Read-only snapshot helpers (browser API reads — never mutations)
// ---------------------------------------------------------------------------

interface KitchenRoom {
	id: number
	name: string
	cleanliness: number
}

async function kitchenRoom(page: Page): Promise<KitchenRoom> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	const rooms = ((await floorsResponse.json()).data as Array<{ rooms: KitchenRoom[] }>)
		.flatMap((floor) => floor.rooms)
	const kitchen = rooms.find((room) => room.name === 'Kitchen')
	if (!kitchen) {
		throw new Error('read-only snapshot failed: no Kitchen room in the journey house')
	}
	return kitchen
}

async function kitchenCleanliness(browser: Browser): Promise<number> {
	const session = await createUserContext(browser, 'mom')
	try {
		return (await kitchenRoom(session.page)).cleanliness
	} finally {
		await session.context.close()
	}
}

async function walletOf(browser: Browser): Promise<number> {
	const session = await createUserContext(browser, 'kid')
	try {
		const response = await session.page.request.get('/apps/chorestrike/api/v1/minigames/coins')
		const body = await response.json()
		return body.data.currentCoins
	} finally {
		await session.context.close()
	}
}

async function userSnapshot(browser: Browser): Promise<{ coins: number; contribution: number; streak: number }> {
	const session = await createUserContext(browser, 'kid')
	try {
		const current = await (await session.page.request.get('/apps/chorestrike/api/v1/users/current')).json()
		return {
			coins: current.data.coins,
			contribution: current.data.contributionScore,
			streak: current.data.currentStreak,
		}
	} finally {
		await session.context.close()
	}
}
