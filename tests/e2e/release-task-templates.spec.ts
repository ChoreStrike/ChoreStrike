/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Templates (rewritten for CS-PLAN-027 v2).
 *
 * The v2 catalog is a static JSON file materialized via TemplateService and
 * exposed through the setup catalog endpoint. The legacy DB-backed
 * `/api/v1/templates/*` endpoints were removed (decision D7); their
 * room-type filtering + import behaviour is now covered by the catalog and
 * the per-room from-template endpoint.
 *
 * Verified:
 *  - GET /setup/catalog returns levels, room types, and templates.
 *  - Room-type filtering: a template lists the room types it applies to.
 *  - POST /room-tasks/from-template creates a room task from a template.
 *
 * Seed-mutating (from-template inserts Task + RoomTask rows): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'

interface CatalogTemplate {
	id: string
	name: string
	roomTypes: string[]
	levels: Record<string, unknown>
}

interface Catalog {
	levels: { id: string }[]
	roomTypes: { id: string; defaultName: string }[]
	templates: CatalogTemplate[]
}

async function fetchCatalog(page: import('@playwright/test').Page): Promise<Catalog> {
	const response = await page.request.get('/apps/chorestrike/api/v1/setup/catalog', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	expect(body.success).toBe(true)
	return body.data as Catalog
}

interface FloorRoom {
	id: number
	roomType?: string
}

/**
 * The first seeded room with its catalog-relevant fields, resolved via
 * `floors/with-rooms` (the `room#show` route was pruned in CS-PLAN-055 —
 * the with-rooms payload carries the same `Room::jsonSerialize` shape).
 */
async function firstRoom(page: import('@playwright/test').Page): Promise<FloorRoom> {
	const response = await page.request.get('/apps/chorestrike/api/v1/floors/with-rooms', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	const floors = body.data || []
	for (const floor of floors) {
		for (const room of floor.rooms || []) {
			if (room.id) {
				return room as FloorRoom
			}
		}
	}
	throw new Error('No room found in seeded data')
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

	test('creating a room task from a template succeeds', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const catalog = await fetchCatalog(page)
			const room = await firstRoom(page)
			const roomId = room.id
			const roomType = room.roomType ?? 'generic'
			const template = catalog.templates.find((t) => t.roomTypes.includes(roomType))
				?? catalog.templates.find((t) => t.roomTypes.includes('household'))
				?? catalog.templates[0]

			const createResponse = await page.request.post(
				'/apps/chorestrike/api/v1/room-tasks/from-template',
				{
					data: { roomId, templateId: template.id, level: 'putzteufel' },
					headers: { Accept: 'application/json' },
				},
			)
			expect(createResponse.status()).toBe(200)
			const body = await createResponse.json()
			expect(body.success).toBe(true)
			// CS-PLAN-063 re-pinned: from-template answers with the creation
			// aggregate `{task, roomTask, assignees}` — the room task row id
			// confirms the materialization.
			expect(body.data.roomTask.id).toBeTruthy()
		} finally {
			await context.close()
		}
	})
})
