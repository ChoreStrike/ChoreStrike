/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — the quiet-hours digest (CS-PLAN-070 WP5).
 *
 * Replaces the retired `release-notifications.spec.ts` (weekly-goal bell)
 * and the retired `time-travel-streak-notification.spec.ts`: the digest is
 * the app's ONLY notification path (C5e/D9/RF-070-10) — awards, titles and
 * in-app state stay in-app, and nothing external is sent by default.
 *
 * Verified:
 *  - The Settings notifications section renders for every member and the
 *    versioned save works (no bell while the digest is off — "silent by
 *    default").
 *  - An opted-in member with due planned work receives EXACTLY ONE
 *    `digest` bell through the real notification pipeline (the same pass
 *    the five-minute cron runs, triggered via the test-mode OCC seam).
 *    A replay of the pass delivers nothing further (at-most-once).
 *  - A member whose quiet hours cover the dispatch instant receives no
 *    bell — the delivery attempt is gated, not shifted (C5c).
 *
 * Seed-mutating (task creation + notification/job state): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import type { Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts'
import { expect, test } from './fixtures/database-fixtures'
import { containerDate, runOcc } from './fixtures/occ-runner'

interface OcsNotification {
	notification_id: number
	app: string
	object_type: string
	subject: string
}

/** ChoreStrike notifications currently in the acting user's Nextcloud bell. */
async function fetchChoreStrikeNotifications(page: Page): Promise<OcsNotification[]> {
	const response = await page.request.get(
		'/ocs/v2.php/apps/notifications/api/v2/notifications?format=json',
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(response.status()).toBe(200)
	const body = await response.json()
	const all: OcsNotification[] = body?.ocs?.data || []
	return all.filter((n) => n.app === 'chorestrike')
}

/**
 * Dismiss the user's existing digest bell rows so the delivery assertion
 * can only be satisfied by THIS run's dispatch.
 *
 * reset()/re-seed never purges `oc_notifications`, and the OCS bell
 * response is server-side-capped, so stale rows from earlier runs would
 * otherwise satisfy an "exists" check vacuously (CS-PLAN-058 gate-round
 * convention). Delete round after round until a fetched window shows no
 * stale rows.
 */
async function dismissDigestNotifications(page: Page): Promise<void> {
	for (let round = 0; round < 10; round++) {
		const stale = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest',)
		if (stale.length === 0) {
			return
		}
		for (const notification of stale) {
			const response = await page.request.delete(
				`/ocs/v2.php/apps/notifications/api/v2/notifications/${notification.notification_id}`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(response.status()).toBe(200)
		}
	}
	throw new Error('dismissDigestNotifications: bell did not converge to zero stale digest rows within 10 rounds')
}

const todayKey = (): string => containerDate(0)

/** Create a daily room task planned today and assigned to the given member. */
async function createDueWork(page: Page, name: string, assignee: string, plannedDay = todayKey()): Promise<void> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms')
	expect(floorsResponse.status()).toBe(200)
	const rooms = ((await floorsResponse.json()).data as Array<{ rooms: Array<{ id: number, name: string }> }>)
		.flatMap((floor) => floor.rooms)
	const kitchen = rooms.find((room) => room.name === 'Kitchen')
	expect(kitchen, 'the family seed always provides the Kitchen room').toBeTruthy()

	const createResponse = await page.request.post('/apps/chorestrike/api/v1/room-tasks', {
		data: {
			roomId: kitchen.id,
			assigneeUserIds: [assignee],
			nextDue: plannedDay,
			task: {
				name,
				energyCost: 1,
				frequencyType: 'daily',
				frequencyValue: 1,
			},
		},
	})
	expect(createResponse.status(), await createResponse.text()).toBe(200)
}

/** Opt one member into both digest categories through the self-only API. */
async function optInViaApi(page: Page, overrides: Record<string, unknown> = {}): Promise<void> {
	const showResponse = await page.request.get('/apps/chorestrike/api/v1/preferences/me')
	expect(showResponse.status()).toBe(200)
	const current = (await showResponse.json()).data

	const saveResponse = await page.request.put('/apps/chorestrike/api/v1/preferences/me', {
		data: {
			commandId: crypto.randomUUID(),
			expectedVersion: current.version,
			taskDigestEnabled: true,
			orgDigestEnabled: true,
			digestTime: '18:00',
			...overrides,
		},
	})
	expect(saveResponse.status(), await saveResponse.text()).toBe(200)
}

/**
 * Run the digest pass at a fixed instant. The wall-time form carries a
 * space — it is single-quoted so the quote survives the cmd.exe → bash
 * hop inside runOcc's docker exec.
 */
function runDigestPass(asOf: string): void {
	runOcc(`chorestrike:trigger-jobs daily-notification --as-of='${asOf}'`)
}

test.describe('Release: Notifications — quiet-hours digest', () => {
	test.use({ databaseProfile: 'family' })

	test('opted-in free and paused days suppress task reminders while due work still delivers', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const free = await createUserContext(browser, 'friend')
		const paused = await createUserContext(browser, 'mom')
		const working = await createUserContext(browser, 'dad')
		try {
			for (const session of [free, paused, working]) {
				await dismissDigestNotifications(session.page)
				await optInViaApi(session.page, { digestTime: '00:00', orgDigestEnabled: false })
			}
			const slotDay = containerDate(1)
			const weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
			const available = Object.fromEntries(weekdays.map((day) => [day, true]))
			const setAvailability = async (weekdayAvailability: Record<string, boolean>) => {
				const saved = await free.page.request.put('/apps/chorestrike/api/v1/scheduling/preferences', { data: { weekdayAvailability } })
				expect(saved.status(), await saved.text()).toBe(200)
			}
			await setAvailability(available)
			await createDueWork(paused.page, 'WP9 work on a subsequently free day', 'friend', slotDay)
			const slotWeekday = new Intl.DateTimeFormat('en', { weekday: 'long', timeZone: 'UTC' }).format(new Date(`${slotDay}T12:00:00Z`)).toLowerCase()
			await setAvailability({ ...available, [slotWeekday]: false })
			await createDueWork(paused.page, 'WP9 paused planned work', 'mom', slotDay)
			await createDueWork(working.page, 'WP9 reminder positive control', 'dad', slotDay)
			const pause = await paused.page.request.post('/apps/chorestrike/api/v1/pauses', {
				data: { scope: 'personal', commandId: crypto.randomUUID(), startDate: slotDay, endDate: slotDay, reasonCode: 'travel' },
			})
			expect(pause.status(), await pause.text()).toBe(200)
			runDigestPass(`${slotDay} 00:05`)
			for (const session of [free, paused]) {
				const bells = (await fetchChoreStrikeNotifications(session.page)).filter((n) => n.object_type === 'digest')
				expect(bells, 'free or recognized-paused work never generates task reminders').toHaveLength(0)
			}
			const delivered = (await fetchChoreStrikeNotifications(working.page)).filter((n) => n.object_type === 'digest')
			expect(delivered, 'the same pass must actually deliver an eligible task reminder').toHaveLength(1)
			expect(delivered[0].subject).toMatch(/planned task/)
		} finally {
			await free.context.close()
			await paused.context.close()
			await working.context.close()
		}
	})

	test('silent by default: an opted-out member gets no bell, and the settings save works', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			await dismissDigestNotifications(page)

			// Default state: nobody is opted in. The seed may assign mom due
			// planned work — but without an opt-in the materializer creates
			// no slots and the pass dispatches nothing (C5h: nothing
			// external is sent by default).
			runDigestPass(`${todayKey()} 18:05`)
			const before = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest')
			expect(before, 'an opted-out member receives no digest, however much work is due').toHaveLength(0)

			// The notifications section is on the member's own settings
			// surface — visible for every member, owner or not (C5h).
			await page.goto('/apps/chorestrike/#/settings')
			await expect(page.getByTestId('notifications-section')).toBeVisible({ timeout: 20000 })

			// NcCheckboxRadioSwitch renders the actual input visually hidden
			// behind its content span — click the visible switch label (the
			// realistic user interaction; mom is pinned to English).
			const taskSwitch = page.getByTestId('task-digest-switch')
			await expect(taskSwitch).not.toBeChecked()

			// Enabling pre-fills the quiet inputs with the 20:00/08:00
			// suggestion while they are empty (C5c).
			await page.getByText('Daily task digest', { exact: true }).click()
			await expect(taskSwitch).toBeChecked()
			await expect(page.getByTestId('quiet-start-input')).toHaveValue('20:00')
			await expect(page.getByTestId('quiet-end-input')).toHaveValue('08:00')

			await page.getByTestId('save-preferences').click()
			await expect(page.locator('.toast-success').first()).toBeVisible({ timeout: 10000 })

			// The persisted state reads back as enabled — via the self-only
			// API, the same surface a non-owner member would use.
			const showResponse = await page.request.get('/apps/chorestrike/api/v1/preferences/me')
			expect(showResponse.status()).toBe(200)
			const payload = (await showResponse.json()).data
			expect(payload.taskDigestEnabled).toBe(true)
			expect(payload.quietStart).toBe('20:00')
			expect(payload.quietEnd).toBe('08:00')
			expect(payload.version).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('an opted-in member with due work receives at most one daily summary bell', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		const { context: ownerContext, page: ownerPage } = await createUserContext(browser, 'dad')
		try {
			await dismissDigestNotifications(page)
			await optInViaApi(page, { digestTime: '00:00' })
			const slotDay = containerDate(1)
			await createDueWork(page, `Digest Work ${Date.now()}`, 'mom', slotDay)
			// A real queue source, chosen after today's midnight cutoff, belongs
			// to tomorrow. The pause itself starts later, so tomorrow's task is
			// still relevant. This avoids depending on the host's current hour.
			const pause = await ownerPage.request.post('/apps/chorestrike/api/v1/pauses', {
				data: {
					scope: 'house',
					commandId: crypto.randomUUID(),
					startDate: containerDate(2),
					endDate: containerDate(3),
					reasonCode: 'travel',
				},
			})
			expect(pause.status(), await pause.text()).toBe(200)

			// Trigger the SAME pass the five-minute cron job runs, inside
			// the slot's 60-minute window (digest time 00:00).
			const asOf = `${slotDay} 00:05`
			runDigestPass(asOf)

			const digestBells = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest')
			expect(digestBells, 'exactly one daily summary bell is delivered').toHaveLength(1)
			expect(digestBells[0].subject).toMatch(/planned task/)
			expect(digestBells[0].subject).toMatch(/household update/)

			// The replay run is consumed without a second delivery
			// (at-most-once, parent §3.6).
			runDigestPass(asOf)
			const replay = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest')
			expect(replay).toHaveLength(1)
		} finally {
			await ownerContext.close()
			await context.close()
		}
	})

	test('quiet hours gate the delivery attempt without shifting the slot', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'dad')
		try {
			await dismissDigestNotifications(page)
			// A quiet range covering the dispatch instant (18:05) while the
			// window is still open (18:00–19:00).
			await optInViaApi(page, { quietStart: '12:00', quietEnd: '18:30' })
			await createDueWork(page, `Quiet Work ${Date.now()}`, 'dad')

			runDigestPass(`${todayKey()} 18:05`)

			const digestBells = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest')
			expect(digestBells, 'the quiet member receives no bell during quiet hours').toHaveLength(0)

			// After the quiet range ends the slot is past its window — the
			// delivery expires permanently instead of a late burst (C5c).
			runDigestPass(`${todayKey()} 19:05`)
			const late = (await fetchChoreStrikeNotifications(page)).filter((n) => n.object_type === 'digest')
			expect(late, 'a missed slot is never compensated by a late catch-up').toHaveLength(0)
		} finally {
			await context.close()
		}
	})
})
