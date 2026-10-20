/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Transparency & Insights UI (CS-PLAN-040).
 *
 * Covers the FEAT-UX-016/017/022 acceptance rows end to end:
 * - /insights renders house stats cards, the capacity-normalized fairness
 *   table, and an activity feed listing a real completion.
 * - SchedulingView surfaces the overcapacity warning when planned energy
 *   exceeds the available capacity.
 *
 * The completion and the overcapacity tasks are created via the API (the
 * deterministic data path); the assertions are UI-driven because these are
 * user-facing render checks.
 *
 * Seed-mutating: registered in `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import { completeRoomTaskViaApi, resolveOccurrenceReceipt, postCompletionWithReceipt, newCommandId } from './fixtures/completion-api'
import type { Page } from '@playwright/test'

import { houseDateOffset } from './fixtures/house-clock.js'

interface RoomTaskJson {
	id: number
	isActive: boolean
	nextDue: string | null
}

/** Fetch an active, due-dated room task via floors/with-rooms → index. */
async function pickActiveRoomTask(page: Page): Promise<RoomTaskJson | null> {
	const floorsResponse = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	if (floorsResponse.status() !== 200) {
		return null
	}
	const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
	const roomIds = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)

	for (const roomId of roomIds) {
		const response = await page.request.get(
			`/apps/chorestrike/api/v1/room-tasks?roomId=${roomId}`,
			{ headers: { Accept: 'application/json' } },
		)
		if (response.status() !== 200) {
			continue
		}
		const tasks = ((await response.json()).data || []) as RoomTaskJson[]
		const found = tasks.find((rt) => rt.isActive && rt.nextDue)
		if (found) {
			return found
		}
	}
	return null
}
const offsetDate = (days: number) => houseDateOffset(days, 'UTC')

test.describe('Release: Insights Flow', () => {
	test.use({ databaseProfile: 'family' })

	test('insights renders stats cards, fairness table, and activity feed', async ({ browser, page, seededData }) => {
		expect(seededData).not.toBeNull()

		// Complete one seeded task as mom so the feed has real data.
		const mom = await createUserContext(browser, 'mom')
		try {
			const roomTask = await pickActiveRoomTask(mom.page)
			expect(roomTask).not.toBeNull()
			const complete = await completeRoomTaskViaApi(mom.page, roomTask!.id)
			expect(complete.status()).toBe(200)
		} finally {
			await mom.context.close()
		}

		await page.goto('/apps/chorestrike/#/insights')
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })

		// CS-PLAN-051: under load a fetch can transiently fail; the retryable
		// error state is the recovery path — retry bounded times before
		// asserting the healthy render.
		const errorRetry = page.getByTestId('error-retry')
		for (let attempt = 0; attempt < 3; attempt++) {
			if (await errorRetry.isVisible().catch(() => false)) {
				await errorRetry.click()
			} else {
				break
			}
		}

		await expect(page.getByTestId('house-stats-cards')).toBeVisible({ timeout: 20000 })
		await expect(page.getByTestId('member-fairness-table')).toBeVisible()
		await expect(page.getByTestId('fairness-row').first()).toBeVisible()

		// CS-PLAN-051: a successful load must not surface the shared
		// retryable error state anywhere on the view.
		await expect(page.getByTestId('error-state')).toHaveCount(0)

		// WP3 cutover: contribution is the fairness currency — the retired
		// aggregate coins column stays gone.
		await expect(page.getByTestId('header-contribution')).toBeVisible()
		await expect(page.getByTestId('header-coins')).toHaveCount(0)

		// The feed lists mom's completion with actor + task.
		const feedItem = page.getByTestId('activity-item').first()
		await expect(feedItem).toBeVisible({ timeout: 15000 })
	})

	test('overcapacity warning appears when a day exceeds its capacity', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		// BUG-E2E-005 (CS-PLAN-068 §3.4): the probe day and the capacity are
		// CONTROLLED, never the weekday-dependent family profile. All seven
		// members (six family + the invited admin) get an explicit full-week
		// budget (7 × 4 = 28/day) and the four probes (4 × 5 = 20 energy)
		// are due TODAY — which is always inside the displayed Monday-start
		// week and the today+0..6 strip. Planned/capacity per day: today
		// 10 + 20 = 30 > 28 (over), tomorrow 16 < 28, later days 0 — exactly
		// ONE overloaded day on any run day.
		const owner = await createUserContext(browser, 'mom')
		try {
			const api = owner.page.request
			const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
			for (const member of ['mom', 'dad', 'teen', 'kid', 'grandma', 'friend', 'admin']) {
				const availabilityResponse = await api.put(`/apps/chorestrike/api/v1/scheduling/members/${member}`, {
					data: {
						weekdayAvailability: Object.fromEntries(days.map((day) => [day, true])),
						energyBudget: Object.fromEntries(days.map((day) => [day, 4])),
					},
					headers: { Accept: 'application/json' },
				})
				expect(availabilityResponse.status(), await availabilityResponse.text()).toBe(200)
			}

			const floorsResponse = await api.get('/apps/chorestrike/api/v1/floors/with-rooms', {
				headers: { Accept: 'application/json' },
			})
			expect(floorsResponse.status()).toBe(200)
			const floors = ((await floorsResponse.json()).data ?? []) as Array<{ rooms?: Array<{ id: number }> }>
			const roomId = floors.flatMap((f) => f.rooms ?? []).map((r) => r.id)[0]
			expect(roomId).toBeDefined()

			const today = offsetDate(0)
			for (let i = 0; i < 4; i++) {
				// CS-PLAN-063: one atomic aggregate create per room task —
				// the mount-duplicate guard still rejects duplicate pairs.
				const created = await api.post('/apps/chorestrike/api/v1/room-tasks', {
					data: {
						roomId,
						nextDue: today,
						task: { name: `Overcapacity Probe ${i}`, description: '', energyCost: 5, priority: 2, frequencyType: 'daily', frequencyValue: 1 },
					},
					headers: { Accept: 'application/json' },
				})
				const createdBody = await created.json()
				expect(created.status(), JSON.stringify(createdBody)).toBe(200)
			}
		} finally {
			await owner.context.close()
		}

		// The view is read as the OWNER so the member-availability grid (the
		// controlled capacity) is actually loaded.
		const ownerView = await createUserContext(browser, 'mom')
		try {
			const view = ownerView.page
			await view.goto('/apps/chorestrike/#/scheduling')
			await expect(view.getByTestId('scheduling-view')).toBeVisible({ timeout: 20000 })

			await expect(view.getByTestId('overcapacity-warning')).toBeVisible({ timeout: 15000 })
			// Exactly the probe day crosses the controlled capacity.
			await expect(view.getByTestId('overcapacity-day')).toHaveCount(1)
			// The calendar marks the overloaded day INSIDE the displayed week
			// (Saturday/Sunday/Monday runs all pin today, never "tomorrow").
			await expect(view.getByTestId('overcapacity-marker').first()).toBeVisible()
		} finally {
			await ownerView.context.close()
		}
	})

	test('navigation exposes the insights entry between leaderboard and scheduling', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await page.waitForLoadState('domcontentloaded')

		await expect(page.getByTestId('nav-insights')).toBeVisible()
		await page.getByTestId('nav-insights').click()
		await expect(page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
	})

	test('owner corrects a recorded work split from the activity history (WP3 §3.6)', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const owner = await createUserContext(browser, 'mom')
		try {
			// Record a two-person completion through the real API (the
			// explicit actual-work split the participation dialog sends).
			const roomTask = await pickActiveRoomTask(owner.page)
			expect(roomTask).not.toBeNull()
			const receipt = await resolveOccurrenceReceipt(owner.page, roomTask!.id)
			expect(receipt).not.toBeNull()
			const complete = await postCompletionWithReceipt(owner.page, roomTask!.id, receipt!, [
				{ userId: 'mom', sharePct: 50 },
				{ userId: 'dad', sharePct: 50 },
			])
			expect(complete.status(), await complete.text()).toBe(200)
			const completionId = (await complete.json()).data.completionId as number

			// The history exposes the authorized correction; the reused
			// participation form pre-fills the recorded split.
			await owner.page.goto('/apps/chorestrike/#/insights')
			await expect(owner.page.getByTestId('insights-view')).toBeVisible({ timeout: 20000 })
			const correctButton = owner.page.getByTestId(`activity-correct-${completionId}`)
			await expect(correctButton).toBeVisible({ timeout: 15000 })
			await correctButton.click()

			const dialog = owner.page.getByTestId('participation-dialog')
			await expect(dialog).toBeVisible()
			await expect(dialog.getByTestId('participant-share-mom')).toHaveValue('50')
			await expect(dialog.getByTestId('participant-share-dad')).toHaveValue('50')

			// Correct to the truthful sole split: dad did not take part.
			await dialog.getByTestId('participant-active-dad').uncheck()
			await dialog.getByTestId('participant-share-mom').fill('100')
			await dialog.getByTestId('participation-confirm').click()
			await expect(dialog).toBeHidden({ timeout: 15000 })

			// The read model shows the corrected selected revision — the
			// exact union deltas committed once, the audit chain extended.
			const read = await owner.page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`, {
				headers: { Accept: 'application/json' },
			})
			expect(read.status()).toBe(200)
			const body = await read.json()
			expect(body.data.version).toBe(1)
			expect(body.data.allocationRevision).toBe(2)
			expect(body.data.allocation).toHaveLength(1)
			expect(body.data.allocation[0].userId).toBe('mom')
			expect(body.data.allocation[0].sharePct).toBe(100)
		} finally {
			await owner.context.close()
		}
	})

	test('a concurrent correction requires explicit review before resubmission and history can undo it', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		try {
			const page = owner.page
			const roomTask = await pickActiveRoomTask(page)
			expect(roomTask).not.toBeNull()
			const receipt = await resolveOccurrenceReceipt(page, roomTask!.id)
			expect(receipt).not.toBeNull()
			const complete = await postCompletionWithReceipt(page, roomTask!.id, receipt!, [
				{ userId: 'mom', sharePct: 50 }, { userId: 'dad', sharePct: 50 },
			])
			expect(complete.status(), await complete.text()).toBe(200)
			const completionId = (await complete.json()).data.completionId as number

			await page.goto('/apps/chorestrike/#/insights')
			await page.getByTestId(`activity-correct-${completionId}`).click()
			const dialog = page.getByTestId('participation-dialog')
			await expect(dialog).toBeVisible()
			await dialog.getByTestId('participant-share-mom').fill('60')
			await dialog.getByTestId('participant-share-dad').fill('40')

			// A second authorized write advances the version while the first
			// dialog is mounted; the draft must not silently retarget it.
			const concurrent = await page.request.put(`/apps/chorestrike/api/v1/completions/${completionId}/attribution`, {
				data: {
					commandId: newCommandId(),
					expectedVersion: 0,
					participants: [{ userId: 'dad', sharePct: 100 }],
					allocationReviewed: true,
				},
			})
			expect(concurrent.status(), await concurrent.text()).toBe(200)
			await dialog.getByTestId('participation-confirm').click()
			const review = page.getByTestId('correction-review')
			await expect(review).toBeVisible()
			await expect(review.getByTestId('correction-draft')).toContainText('Mom: 60%')
			await expect(review.getByTestId('correction-current')).toContainText('Dad: 100%')
			const beforeReview = await page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`)
			expect((await beforeReview.json()).data.version).toBe(1)

			await review.getByTestId('correction-review-button').click()
			await expect(dialog.getByTestId('participant-share-mom')).toHaveValue('60')
			await expect(dialog.getByTestId('participant-share-dad')).toHaveValue('40')
			const activityRefreshed = page.waitForResponse((response) => response.url().includes('/api/v1/house/activity') && response.status() === 200)
			await dialog.getByTestId('participation-confirm').click()
			await activityRefreshed
			await expect(dialog).toBeHidden()
			const corrected = await page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`)
			expect((await corrected.json()).data.version).toBe(2)

			// Unlike the short snackbar, history remains actionable after a
			// correction and sends the completion's current version, not a task ID.
			const undo = page.getByTestId(`activity-undo-${completionId}`)
			await expect(undo).toBeVisible()
			await expect(undo).toHaveAttribute('data-version', '2')
			await undo.click()
			await expect(undo).toHaveCount(0)
			const undone = await page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`)
			expect((await undone.json()).data.undoneAt).not.toBeNull()
		} finally {
			await owner.context.close()
		}
	})

	test('the caller wallet reads its exact minor-unit balance after a dashboard reload', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		try {
			const roomTask = await pickActiveRoomTask(owner.page)
			expect(roomTask).not.toBeNull()
			const complete = await completeRoomTaskViaApi(owner.page, roomTask!.id)
			expect(complete.status(), await complete.text()).toBe(200)
			const state = await owner.page.request.get('/apps/chorestrike/api/v1/game/state')
			expect(state.status()).toBe(200)
			const minor = (await state.json()).data.wallet.availableMinor as number
			const whole = Math.trunc(Math.abs(minor) / 100)
			const fraction = String(Math.abs(minor) % 100).padStart(2, '0').replace(/0+$/, '')
			const expected = `${minor < 0 ? '-' : ''}${whole}${fraction ? `.${fraction}` : ''}`

			await owner.page.goto('/apps/chorestrike/#/dashboard')
			await expect(owner.page.getByTestId('own-wallet-available')).toHaveText(expected)
			await owner.page.reload()
			await expect(owner.page.getByTestId('own-wallet-available')).toHaveText(expected)
		} finally {
			await owner.context.close()
		}
	})

	test('a lost attribution response replays the same correction after a completion refetch', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()
		const owner = await createUserContext(browser, 'mom')
		try {
			const page = owner.page
			const roomTask = await pickActiveRoomTask(page)
			expect(roomTask).not.toBeNull()
			const receipt = await resolveOccurrenceReceipt(page, roomTask!.id)
			expect(receipt).not.toBeNull()
			const complete = await postCompletionWithReceipt(page, roomTask!.id, receipt!, [
				{ userId: 'mom', sharePct: 50 }, { userId: 'dad', sharePct: 50 },
			])
			expect(complete.status(), await complete.text()).toBe(200)
			const completionId = (await complete.json()).data.completionId as number

			await page.goto('/apps/chorestrike/#/insights')
			await page.getByTestId(`activity-correct-${completionId}`).click()
			const dialog = page.getByTestId('participation-dialog')
			await expect(dialog).toBeVisible()
			await dialog.getByTestId('participant-active-dad').uncheck()
			await dialog.getByTestId('participant-share-mom').fill('100')

			// Commit the request but deliver a synthetic 503 to the browser.
			// Its next save MUST replay this command, not create another audit.
			await page.route(`**/completions/${completionId}/attribution`, async (route) => {
				const response = await route.fetch()
				expect(response.status()).toBe(200)
				await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'TEMPORARY', message: 'Request failed' } }) })
			}, { times: 1 })
			await dialog.getByTestId('participation-confirm').click()
			await expect(page.getByTestId('correction-retry')).toBeVisible()
			const read = await page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`)
			expect((await read.json()).data.version).toBe(1)

			await page.getByTestId('correction-retry').click()
			await expect(page.getByTestId('correction-review')).toBeHidden()
			const replayed = await page.request.get(`/apps/chorestrike/api/v1/completions/${completionId}`)
			const body = await replayed.json()
			expect(body.data.version).toBe(1)
			expect(body.data.history).toHaveLength(2)
		} finally {
			await owner.context.close()
		}
	})
})
