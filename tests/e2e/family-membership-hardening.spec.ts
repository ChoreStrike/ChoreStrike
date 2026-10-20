import type { APIResponse, Browser, BrowserContext, Page } from '@playwright/test'

import { randomUUID } from 'node:crypto'
import { createUserContext } from './fixtures/auth-contexts'
import { completeAsSoleParticipantViaApi } from './fixtures/completion-api'
import { expect, test } from './fixtures/database-fixtures'
import { runOcc } from './fixtures/occ-runner'

const API = '/apps/chorestrike/api/v1'

async function dataOf(response: APIResponse, status = 200) {
	expect(response.status(), await response.text()).toBe(status)
	return (await response.json()).data
}

async function loginSyntheticOwner(browser: Browser, uid: string, password: string) {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	try {
		const page = await context.newPage()
		await page.goto('/login')
		await page.locator('#user').fill(uid)
		await page.locator('#password').fill(password)
		await page.locator('button[type="submit"], #submit-form').first().click()
		await page.waitForURL('**/apps/**')
		const token = await page.evaluate(() => (window as unknown as { OC: { requestToken: string } }).OC.requestToken)
		expect(token).toBeTruthy()
		await context.setExtraHTTPHeaders({ requesttoken: token })
		return { context, page }
	} catch (error) {
		await context.close()
		throw error
	}
}

test.describe('family membership hardening (CS-PLAN-081 H3)', () => {
	test.use({ databaseProfile: 'family' })
	test.setTimeout(300000)

	for (const endpoint of ['invite', 'remove', 'updateRole'] as const) {
		test(`${endpoint} enforces the approved 60/300 boundary with no rejected mutation and isolated counters`, async ({ browser, seededData }) => {
			expect(seededData).not.toBeNull()
			// Fresh, exclusively owned NC identities isolate native cache keys on
			// every run, without clearing shared caches or shortening the window.
			const suffix = randomUUID().slice(0, 8)
			const uids = ['owner_a', 'owner_b', 'target_a', 'target_b'].map((name) => `cs81_${name}_${suffix}`)
			const created: string[] = []
			const contexts: BrowserContext[] = []
			const password = 'Synthetic-CS81-test-only!'
			try {
				for (const uid of uids) {
					runOcc(`user:add --password-from-env --display-name=${uid} ${uid}`, { OC_PASS: password })
					created.push(uid)
					runOcc(`user:setting ${uid} settings email ${uid}@example.invalid`)
				}
				const broker = await createUserContext(browser, 'mom')
				contexts.push(broker.context)
				for (const uid of uids.slice(0, 2)) {
					await dataOf(await broker.page.request.post(`${API}/users/invite`, { data: { email: `${uid}@example.invalid`, role: 'owner' } }))
				}
				const a = await loginSyntheticOwner(browser, uids[0], password)
				contexts.push(a.context)
				const b = await loginSyntheticOwner(browser, uids[1], password)
				contexts.push(b.context)
				const invite = (page: Page, uid: string) => page.request.post(`${API}/users/invite`, { data: { email: `${uid}@example.invalid`, role: 'member' } })
				let targetA: { id: number }
				let targetB: { id: number }
				if (endpoint === 'invite') {
					const started = Date.now()
					for (let i = 0; i < 60; i++) {
						await dataOf(await invite(a.page, uids[2]))
					}
					const before = await dataOf(await a.page.request.get(`${API}/users`))
					expect((await invite(a.page, uids[3])).status()).toBe(429)
					expect(await dataOf(await a.page.request.get(`${API}/users`))).toEqual(before)
					expect(Date.now() - started).toBeLessThan(300000)
					// A second user has an independent allowance; another endpoint
					// for the exhausted first user must still perform real mutations.
					targetB = await dataOf(await invite(b.page, uids[3]))
					targetA = before.find((entry: { nextcloudUserId: string }) => entry.nextcloudUserId === uids[2])
					await dataOf(await a.page.request.put(`${API}/users/${targetA.id}/role`, { data: { role: 'child' } }))
					await dataOf(await a.page.request.delete(`${API}/users/${targetA.id}`))
				} else {
					targetA = await dataOf(await invite(a.page, uids[2]))
					targetB = await dataOf(await invite(a.page, uids[3]))
					const started = Date.now()
					for (let i = 0; i < 60; i++) {
						if (endpoint === 'updateRole') {
							await dataOf(await a.page.request.put(`${API}/users/${targetA.id}/role`, { data: { role: i % 2 === 0 ? 'child' : 'member' } }))
						} else {
							const response = await a.page.request.delete(`${API}/users/${targetA.id}`)
							expect(response.status(), await response.text()).toBe(i === 0 ? 200 : 404)
							if (i > 0) {
								expect((await response.json()).error.code).toBe('USER_NOT_FOUND')
							}
						}
					}
					const before = await dataOf(await a.page.request.get(`${API}/users`))
					const reject = endpoint === 'updateRole'
						? await a.page.request.put(`${API}/users/${targetA.id}/role`, { data: { role: 'child' } })
						: await a.page.request.delete(`${API}/users/${targetB.id}`)
					expect(reject.status()).toBe(429)
					expect(await dataOf(await a.page.request.get(`${API}/users`))).toEqual(before)
					expect(Date.now() - started).toBeLessThan(300000)
					if (endpoint === 'updateRole') {
						await dataOf(await b.page.request.put(`${API}/users/${targetA.id}/role`, { data: { role: 'child' } }))
						await dataOf(await a.page.request.delete(`${API}/users/${targetB.id}`))
					} else {
						await dataOf(await b.page.request.delete(`${API}/users/${targetB.id}`))
						await dataOf(await a.page.request.put(`${API}/users/${(await dataOf(await a.page.request.get(`${API}/users/current`))).id}/role`, { data: { role: 'owner' } }))
					}
				}
				const rows = await dataOf(await a.page.request.get(`${API}/room-tasks`))
				const completable = rows.find((row: { id: number, occurrence?: { occurrenceId?: string } }) => row.occurrence?.occurrenceId)
				expect(completable).toBeTruthy()
				await dataOf(await completeAsSoleParticipantViaApi(a.page, completable.id, uids[0]))
			} finally {
				for (const context of contexts.reverse()) {
					await context.close()
				}
				for (const uid of created.reverse()) {
					runOcc(`user:delete ${uid}`)
				}
			}
		})
	}
})
