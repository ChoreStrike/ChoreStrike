/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Templates (rewritten for CS-PLAN-027 v2).
 *
 * The v2 catalog is a static JSON file materialized via TemplateService and
 * exposed through the setup catalog endpoint. The legacy DB-backed
 * `/api/v1/templates/*` endpoints were removed (decision D7); their
 * room-type filtering is now covered by the catalog. A template only fills
 * the one task form (CS-PLAN-091 WP-B6, which retired
 * `POST /room-tasks/from-template`); the save creates a task of its own.
 *
 * Verified:
 *  - GET /setup/catalog returns levels, room types, and templates.
 *  - Room-type filtering: a template lists the room types it applies to.
 *  - A room's form offers that room's templates; one fills the form and the
 *    save creates the room task through `POST /room-tasks`.
 *
 * Seed-mutating (the save inserts Task + RoomTask rows): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import type { Page } from '@playwright/test'

import { createUserContext } from './fixtures/auth-contexts.ts'
import { expect, test } from './fixtures/database-fixtures.ts'
import { openRoom, roomGroup } from './fixtures/rooms.ts'

interface CatalogTemplate {
	id: string
	name: string
	roomTypes: string[]
	levels: Record<string, unknown>
}

interface Catalog {
	levels: { id: string }[]
	roomTypes: { id: string, defaultName: string }[]
	templates: CatalogTemplate[]
}

async function fetchCatalog(page: Page): Promise<Catalog> {
	const response = await page.request.get('/apps/chorestrike/api/v1/setup/catalog', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	expect(body.success).toBe(true)
	return body.data as Catalog
}

test.describe('Release: Task Templates (v2 catalog)', () => {
	test.use({ databaseProfile: 'family' })

	test('setup catalog returns levels, room types, and templates', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const catalog = await fetchCatalog(page)

			expect(catalog.levels.length).toBe(2)
			expect(catalog.roomTypes.length).toBeGreaterThan(0)
			expect(catalog.templates.length).toBeGreaterThan(0)

			const first = catalog.templates[0]
			expect(first.id).toBeTruthy()
			expect(first.name).toBeTruthy()
			expect(Array.isArray(first.roomTypes)).toBe(true)
			expect(first.roomTypes.length).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('a room type exposes the templates that apply to it', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const catalog = await fetchCatalog(page)
			const roomType = catalog.roomTypes[0].id

			const applicable = catalog.templates.filter((t) => t.roomTypes.includes(roomType))
			expect(applicable.length).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('a template fills the form of a room, and the save creates a task of its own', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const catalog = await fetchCatalog(page)
			const room = await openRoom(page, 'Kitchen')
			const floors = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', { headers: { Accept: 'application/json' } })
			const roomType = ((await floors.json()).data as Array<{ rooms: Array<{ id: number, roomType?: string }> }>)
				.flatMap((floor) => floor.rooms).find((entry) => entry.id === room.id)?.roomType ?? 'generic'
			const template = catalog.templates.find((t) => t.roomTypes.includes(roomType))
			expect(template, `the catalog holds templates for the room type ${roomType}`).toBeTruthy()

			// New in the room heading: the form starts in the room, with the templates of its type.
			await roomGroup(page, room).getByTestId('room-add-task-button').click()
			await expect(page.getByTestId('task-form')).toBeVisible({ timeout: 15000 })
			await page.getByTestId('task-template-open').click()
			await expect(page.getByTestId('task-template-room-type')).toHaveValue(roomType)
			await page.getByTestId(`task-template-${template!.id}`).click()
			await expect(page.getByTestId('task-name-input')).toHaveValue(template!.name)

			const created = page.waitForResponse((response) => response.request().method() === 'POST'
				&& new URL(response.url()).pathname.endsWith('/api/v1/room-tasks'))
			await page.getByTestId('save-task-button').click()
			const response = await created
			expect(response.status(), await response.text()).toBe(200)
			const body = (await response.json()).data
			// CS-PLAN-063: the creation aggregate `{task, roomTask, assignees}`.
			expect(body.task.name).toBe(template!.name)
			expect(body.roomTask).toMatchObject({ roomId: room.id, taskId: body.task.id })
			await expect(page.getByTestId('task-form')).toHaveCount(0)
			await expect(roomGroup(page, room).getByTestId('task-item').filter({ hasText: template!.name }).first()).toBeVisible()

			// The materializing route is gone.
			const retired = await page.request.post('/apps/chorestrike/api/v1/room-tasks/from-template', {
				data: { roomId: room.id, templateId: template!.id, level: 'putzteufel' },
				headers: { Accept: 'application/json' },
			})
			expect(retired.status()).toBe(404)
		} finally {
			await context.close()
		}
	})
})
