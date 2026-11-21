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
 * the focused specs (validation, error-handling, family-core-ux,
 * workflow-state-reconciliation), which pin duplicate requests, removed
 * membership, stale targets and failure recovery.
 *
 * Deterministic regardless of the run's weekday: the candidate task is
 * created with its first day set to house-local TODAY (ADR-021: the first
 * date hangs the first window on that day) and assigned to the child in the
 * same form, so My Day is provably executable on any calendar day.
 *
 * Requires: Docker test environment, test mode on (family NC accounts from
 * auth.setup survive the `emptyhouse` reset).
 */

import type { Browser, BrowserContext, Page } from '@playwright/test'

import { addDaysToKey } from '../../src/utils/dateKeys.js'
import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { holdToConfirm } from './fixtures/hold-to-confirm.ts'
import { journeyClock } from './fixtures/house-clock.js'
import { runOcc } from './fixtures/occ-runner.ts'
import { listRooms, openRoom, roomGroup } from './fixtures/rooms.ts'
import { openSettingsCategory } from './fixtures/settings.ts'
import { addSubTasks, openFormMore, openRowDetails, setFlexDays } from './fixtures/task-form.ts'

const DAD_EMAIL = 'dad@chorestrike.test'
const KID_EMAIL = 'kid@chorestrike.test'
const HOUSE_NAME = 'Journey Home'
const CUSTOM_TASK = 'Journey Dishes'
const ADMIN_TASK = 'Journey Admin Audit'

/** Open the app and wait for the shell. */
async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/chorestrike/#/today')
	await expect(page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
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
		let cycleKeyBefore = ''
		let completionIdForUndo = 0

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
				const roomTaskRows = await page.request.get('/apps/chorestrike/api/v1/room-tasks')
				expect((await roomTaskRows.json()).data).toHaveLength(13)

				// Wizard invite step: dad joins as a member.
				await page.getByTestId('wizard-invite-email-0').fill(DAD_EMAIL)
				const inviteResponse = page.waitForResponse((response) => response.request().method() === 'POST'
					&& response.url().includes('/api/v1/users/invite'))
				await page.getByTestId('wizard-send-invites').click()
				expect((await inviteResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('wizard-invite-status-0')).toContainText('Dad')
				await page.getByTestId('wizard-finish').click()
				await expect(page.getByTestId('setup-wizard')).toHaveCount(0)
				// The house is set up: Today stays, without the way back in.
				await expect(page.getByTestId('today-view')).toBeVisible()
				await expect(page.getByTestId('open-setup-wizard')).toHaveCount(0)
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
				await openSettingsCategory(page, 'house')
				const zone = page.getByTestId('house-timezone-select')
				await expect(zone).toBeVisible()
				const originalHouse = await page.request.get('/apps/chorestrike/api/v1/house')
				await expect(zone).toHaveValue((await originalHouse.json()).data.timezone)
				if ((await zone.inputValue()) !== clock.timeZone) {
					await zone.selectOption(clock.timeZone)
					const saved = page.waitForResponse((response) => response.request().method() === 'PUT' && response.url().endsWith('/api/v1/house'))
					await page.getByTestId('save-house-timezone').click()
					expect((await saved).ok()).toBeTruthy()
				}
				const house = await page.request.get('/apps/chorestrike/api/v1/house')
				expect((await house.json()).data.timezone).toBe(clock.timeZone)
				await openSettingsCategory(page, 'members')
				await page.getByTestId('show-invite-button').click()
				await page.getByTestId('invite-email-input').fill(KID_EMAIL)
				await page.getByTestId('invite-role-select').selectOption('child')
				const childInviteResponse = page.waitForResponse((response) => response.request().method() === 'POST'
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
				expect((await listRooms(dad.page)).map((room) => room.name)).toContain('Kitchen')
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

				// ---- Custom task with instructions, flex window, assignee and
				// sub-tasks: one save of the one form (CS-PLAN-091 WP-B6), which
				// a room heading opens with the room chosen.
				const kitchenGroup = roomGroup(page, await openRoom(page, 'Kitchen'))
				await kitchenGroup.getByTestId('room-add-task-button').click()
				await expect(page.getByTestId('task-form')).toBeVisible()

				await page.getByTestId('task-name-input').fill(CUSTOM_TASK)
				await page.getByTestId('task-energy-select').selectOption('2')
				// Weekly: flex 3 fits its cap of 4; a daily rule caps at 1
				// (FEAT-UX-039, half the period rounded up).
				await page.getByTestId('task-frequency-unit').selectOption('week')
				// The candidate's first window hangs on house-local TODAY
				// (flex 3 gives it a ± 3 day window) and it is assigned to
				// the child right here, so the child's My Day shows it.
				await page.getByTestId('task-next-due-input').fill(houseLocalDate(0))
				await page.getByTestId('assignee-chip-kid').click()
				await setFlexDays(page, 3)
				await addSubTasks(page, ['Rinse plates', 'Load the racks'])
				await openFormMore(page)
				await page.getByTestId('task-instructions-input').fill('Rinse everything, then load the racks.')
				const customResponse = page.waitForResponse((response) => response.request().method() === 'POST'
					&& new URL(response.url()).pathname.endsWith('/api/v1/room-tasks'))
				await page.getByTestId('save-task-button').click()
				const customCreated = await customResponse
				expect(customCreated.ok(), await customCreated.text()).toBeTruthy()
				expect(customCreated.request().postDataJSON().checklistItems).toEqual([{ label: 'Rinse plates' }, { label: 'Load the racks' }])
				await expect(page.getByTestId('task-form')).toHaveCount(0, { timeout: 20000 })

				// ---- Second custom task: admin-only visibility probe.
				await kitchenGroup.getByTestId('room-add-task-button').click()
				await expect(page.getByTestId('task-form')).toBeVisible()
				await page.getByTestId('task-name-input').fill(ADMIN_TASK)
				await page.getByTestId('task-energy-select').selectOption('1')
				await openFormMore(page)
				await page.getByTestId('task-visibility-select').selectOption('admin-only')
				await page.getByTestId('save-task-button').click()
				await expect(page.getByTestId('task-form')).toHaveCount(0, { timeout: 20000 })

				// ---- Template-based task: a template only fills the form
				// (FEAT-UX-043); its targets stay tap-sized on a phone.
				await kitchenGroup.getByTestId('room-add-task-button').click()
				await expect(page.getByTestId('task-form')).toBeVisible()
				await page.setViewportSize({ width: 390, height: 844 })
				await page.getByTestId('task-template-open').click()
				await page.getByTestId('task-template-room-type').selectOption('kitchen')
				const templateTarget = await page.getByTestId('task-template-kitchen_sink').boundingBox()
				expect(templateTarget!.height).toBeGreaterThanOrEqual(44)
				await page.getByTestId('task-template-kitchen_sink').click()
				await expect(page.getByTestId('task-name-input')).toHaveValue('Clean kitchen sink')
				const templateResponse = page.waitForResponse((response) => response.request().method() === 'POST'
					&& new URL(response.url()).pathname.endsWith('/api/v1/room-tasks'))
				await page.getByTestId('save-task-button').click()
				const templateCreated = await templateResponse
				expect(templateCreated.ok()).toBeTruthy()
				const templateTask = (await templateCreated.json()).data.task
				expect(templateTask.name).toBe('Clean kitchen sink')
				expect(templateTask.frequencyType).toBe('weekly')
				expect(templateTask.frequencyValue).toBe(1)
				await page.setViewportSize({ width: 1280, height: 720 })
				await expect(page.getByTestId('task-form')).toHaveCount(0, { timeout: 20000 })

				// ---- Persisted reads and a reload match the submitted values.
				// The definition list carries name/instructions; the room-task
				// rows are FLAT (taskId + assignees, no nested task object).
				const taskResponse = await page.request.get('/apps/chorestrike/api/v1/tasks')
				const definitions = (await taskResponse.json()).data as Array<{ id: number, name: string, instructions?: string }>
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
					idealDueDate: string
					nextDue: string
					cycle: { cycleKey: string, state: string, centreOn: string, windowStartOn: string, windowEndOn: string }
				}>
				const custom = roomTasks.find((roomTask) => roomTask.taskId === definition!.id)
				expect(custom, 'the submitted aggregate materialized the room task').toBeDefined()
				flexRoomTaskId = custom!.id
				expect(custom!.assignees).toEqual(['kid'])
				// ADR-021: the cycle hangs on the first day; ± 3 flex days make
				// its window, and `nextDue` only projects the window's last day.
				expect(custom!.cycle).toMatchObject({
					cycleKey: `d${houseLocalDate(0)}`,
					state: 'open',
					centreOn: houseLocalDate(0),
					windowStartOn: houseLocalDate(-3),
					windowEndOn: houseLocalDate(3),
				})
				expect(custom!.idealDueDate.startsWith(houseLocalDate(0))).toBeTruthy()
				expect(custom!.nextDue.startsWith(houseLocalDate(3))).toBeTruthy()
				cycleKeyBefore = custom!.cycle.cycleKey

				await page.reload()
				await expect(page.getByTestId('room-task-list')).toBeVisible({ timeout: 20000 })
				await expect(page.getByTestId('room-task-list').getByText(CUSTOM_TASK)).toBeVisible({ timeout: 15000 })

				// The sub-tasks the form sent belong to the definition, in order.
				const items = await page.request.get(`/apps/chorestrike/api/v1/checklists/task/${definition!.id}`)
				expect(((await items.json()).data as Array<{ label: string }>).map((item) => item.label)).toEqual(['Rinse plates', 'Load the racks'])
			} finally {
				await context.close()
			}
		})

		// ----------------------------------------------------------------
		// Phase 5 — Planning: the child's availability and limit, the weekly
		// calendar (ADR-021: the plan preview/apply step is retired)
		// ----------------------------------------------------------------
		await test.step('owner sets the child availability and limit, and the calendar shows the candidate for today', async () => {
			const { context, page } = await createUserContext(browser, 'mom')
			try {
				await page.goto('/apps/chorestrike/#/scheduling')
				await expect(page.getByTestId('scheduling-view')).toBeVisible({ timeout: 20000 })
				await expect(page.getByTestId('schedule-calendar')).toBeVisible({ timeout: 20000 })
				await expect(page.getByTestId('schedule-planner')).toHaveCount(0)

				// Configure availability + child limit through the owner
				// controls: the Kid card gets an explicit 2-tasks/day limit
				// and an explicit full-week budget.
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
				const availabilityResponse = page.waitForResponse((response) => response.request().method() === 'PUT'
					&& response.url().includes('/api/v1/scheduling/'))
				await page.getByTestId('save-availability-button').click()
				expect((await availabilityResponse).ok()).toBeTruthy()
				await expect(page.getByTestId('edit-availability-modal')).toHaveCount(0)

				// The range read names the candidate's cycle on its day.
				const rangeResponse = await page.request.get(`/apps/chorestrike/api/v1/room-tasks/range?from=${houseLocalDate(0)}&to=${houseLocalDate(0)}`)
				expect(rangeResponse.status()).toBe(200)
				const range = (await rangeResponse.json()).data.scheduled as Array<{ roomTask: { id: number }, cycles: Array<{ date: string, cycleKey: string, current: boolean }> }>
				const scheduled = range.find((row) => row.roomTask.id === flexRoomTaskId)
				expect(scheduled?.cycles, 'the candidate is scheduled for today').toEqual([
					expect.objectContaining({ date: houseLocalDate(0), cycleKey: cycleKeyBefore, current: true }),
				])

				// The calendar shows the candidate on today.
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
		let kidSession: { context: BrowserContext, page: Page } | null = null
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
				// The opened row shows the instructions and the sub-tasks (CS-PLAN-091 WP-B6).
				const details = await openRowDetails(row)
				await expect(details).toContainText('Rinse everything, then load the racks.')

				// Checklist progress is usable and persists per cycle; the
				// checkbox input is visually hidden — click its label.
				const checklistItem = details.locator('[data-testid^="checklist-item-"]').first()
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
				await holdToConfirm(kidExecutor.page.getByTestId(`today-complete-button-${flexRoomTaskId}`))
				const receipt = await (await completeResponsePromise).json()
				expect(receipt.success).toBe(true)
				const rewards = receipt.data.rewards

				// Exact credited deltas against the independently captured
				// before-state (never a value compared to itself). WP3 §3.1:
				// work evidence + own wallet in exact minor units (2 energy →
				// +200 contribution minor, +2000 booked coin minor).
				expect(rewards.energyCost).toBe(2)
				expect(rewards.completion.totalEnergy).toBe(2)
				expect(rewards.cleanlinessChange).toBe(20)
				expect(rewards.contributionMinor).toBe(baselineContribution + 200)
				expect(rewards.wallet.bookedMinor).toBe(baselineCoins + 2000)
				expect(rewards.completion.undoneAt).toBeNull()
				expect(receipt.data.room.cleanliness).toBe(Math.min(100, baselineCleanliness + 20))
				completionIdForUndo = receipt.data.completionId
				// ADR-021: the receipt names the NEXT cycle, not the one closed.
				expect(receipt.data.cycle).not.toBeNull()
				expect(receipt.data.cycle.cycleKey).not.toBe(cycleKeyBefore)
				expect(receipt.data.occurrence).toBeUndefined()
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

				// Persisted projections right after the completion (fast read —
				// the kid's OWN wallet through the game state).
				const stateAfter = await (await kid.page.request.get('/apps/chorestrike/api/v1/game/state')).json()
				expect(stateAfter.data.wallet.bookedMinor).toBe(baselineCoins + 2000)

				const undoResponsePromise = kid.page.waitForResponse((response) => {
					const pathname = new URL(response.url()).pathname
					return response.request().method() === 'POST'
						&& pathname === `/apps/chorestrike/api/v1/completions/${completionIdForUndo}/undo`
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
			// wallet, contribution, streak, cleanliness and the cycle.
			const kidAgain = await createUserContext(browser, 'kid')
			try {
				await kidAgain.page.goto('/apps/chorestrike/')
				await expect(kidAgain.page.getByTestId('today-view')).toBeVisible({ timeout: 30000 })
				const current = await (await kidAgain.page.request.get('/apps/chorestrike/api/v1/users/current')).json()
				expect(current.data.contributionMinor).toBe(baselineContribution)
				expect(current.data.currentStreak).toBe(0)
				expect(await walletOf(browser)).toBe(baselineCoins)
				expect(await kitchenCleanliness(browser)).toBe(baselineCleanliness)
				const roomTasksResponse = await kidAgain.page.request.get(`/apps/chorestrike/api/v1/room-tasks?roomId=${kitchenRoomId}`)
				const restored = ((await roomTasksResponse.json()).data as Array<{ id: number, lastCompleted: string | null, isActive: boolean, idealDueDate: string, cycle: { cycleKey: string, state: string } }>)
					.find((roomTask) => roomTask.id === flexRoomTaskId)
				expect(restored!.isActive).toBe(true)
				expect(restored!.lastCompleted).toBeNull()
				// The undone completion reopens the very same cycle.
				expect(restored!.cycle).toMatchObject({ cycleKey: cycleKeyBefore, state: 'open' })
				expect(restored!.idealDueDate.startsWith(houseLocalDate(0))).toBeTruthy()
				const againRow = kidAgain.page.locator('[data-testid="today-task-item"]').filter({ hasText: CUSTOM_TASK }).first()
				const againDetails = await openRowDetails(againRow)
				const progress = againDetails.locator('[data-testid^="checklist-item-"] input[type="checkbox"]')
				// D-091-60: an undo keeps the ticks the completion set — the
				// reopened cycle shows every sub-task done.
				await expect(progress).toHaveCount(2)
				await expect(progress.nth(0)).toBeChecked()
				await expect(progress.nth(1)).toBeChecked()
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
			// completion restored — never recalculated — its cycle).
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

/**
 * The kid's OWN wallet booked total (WP3 §3.6: own wallet via /game/state —
 * the retired /minigames/coins economy read is gone).
 */
async function walletOf(browser: Browser): Promise<number> {
	const session = await createUserContext(browser, 'kid')
	try {
		const response = await session.page.request.get('/apps/chorestrike/api/v1/game/state')
		const body = await response.json()
		return body.data.wallet?.bookedMinor ?? 0
	} finally {
		await session.context.close()
	}
}

async function userSnapshot(browser: Browser): Promise<{ contribution: number, streak: number }> {
	const session = await createUserContext(browser, 'kid')
	try {
		const current = await (await session.page.request.get('/apps/chorestrike/api/v1/users/current')).json()
		return {
			contribution: current.data.contributionMinor,
			streak: current.data.currentStreak,
		}
	} finally {
		await session.context.close()
	}
}
