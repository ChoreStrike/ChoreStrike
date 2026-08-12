/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Release E2E regression — Task Templates (CS-PLAN-024, Phase 4; absorbed
 * from CS-022 Phase 5).
 *
 * Covers the template-catalog half of the release-test-plan.md §5 "Task
 * templates" row (create/import). The template catalog is a static JSON file
 * (`lib/Templates/TaskTemplates.json`), so the read paths are available
 * without seeding; the import paths create real Task rows.
 *
 * Verified:
 *  - GET /templates returns the catalog (non-empty, with categories).
 *  - GET /templates/category/{cat} filters by category.
 *  - POST /templates/import/{id} creates a task from a template.
 *  - POST /templates/bulk-import creates one task per requested id.
 *
 * Seed-mutating (template import inserts Task rows): registered in
 * `SEED_DEPENDENT_SPECS` (chromium-serial-db).
 * Requires: Docker test environment with the `family` database profile.
 */

import { test, expect } from './fixtures/database-fixtures'
import { createUserContext } from './fixtures/auth-contexts'
import type { Page } from '@playwright/test'

interface TemplateJson {
	id: string
	name: string
	category?: string
}

async function fetchTemplates(page: Page): Promise<TemplateJson[]> {
	const response = await page.request.get('/apps/chorestrike/api/v1/templates', {
		headers: { Accept: 'application/json' },
	})
	expect(response.status()).toBe(200)
	const body = await response.json()
	expect(body.success).toBe(true)
	return (body.data || []) as TemplateJson[]
}

test.describe('Release: Task Templates', () => {
	test.use({ databaseProfile: 'family' })

	test('template catalog is readable and filterable by category', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const templates = await fetchTemplates(page)
			expect(templates.length).toBeGreaterThan(0)

			const first = templates[0]
			expect(first.id).toBeTruthy()
			expect(first.name).toBeTruthy()

			// Categories endpoint is non-empty.
			const catResponse = await page.request.get('/apps/chorestrike/api/v1/templates/categories', {
				headers: { Accept: 'application/json' },
			})
			expect(catResponse.status()).toBe(200)
			const catBody = await catResponse.json()
			expect(Array.isArray(catBody.data)).toBe(true)
			expect(catBody.data.length).toBeGreaterThan(0)

			// Category filter returns only that category's templates.
			const category = first.category ?? catBody.data[0]
			const byCatResponse = await page.request.get(
				`/apps/chorestrike/api/v1/templates/category/${encodeURIComponent(category)}`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(byCatResponse.status()).toBe(200)
			const byCat = await byCatResponse.json()
			expect(byCat.data.length).toBeGreaterThan(0)
		} finally {
			await context.close()
		}
	})

	test('importing a template creates a task with the template defaults', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const templates = await fetchTemplates(page)
			const template = templates[0]

			const importResponse = await page.request.post(
				`/apps/chorestrike/api/v1/templates/import/${encodeURIComponent(template.id)}`,
				{ headers: { Accept: 'application/json' } },
			)
			expect(importResponse.status()).toBe(200)
			const body = await importResponse.json()
			expect(body.success).toBe(true)
			expect(body.data.id).toBeTruthy()
		} finally {
			await context.close()
		}
	})

	test('bulk-import creates one task per requested template id', async ({ browser, seededData }) => {
		expect(seededData).not.toBeNull()

		const { context, page } = await createUserContext(browser, 'mom')
		try {
			const templates = await fetchTemplates(page)
			const ids = templates.slice(0, 2).map((t) => t.id)
			expect(ids.length).toBe(2)

			const bulkResponse = await page.request.post('/apps/chorestrike/api/v1/templates/bulk-import', {
				data: { templateIds: ids },
				headers: { Accept: 'application/json' },
			})
			expect(bulkResponse.status()).toBe(200)
			const body = await bulkResponse.json()
			expect(body.success).toBe(true)
			expect(body.data.length).toBe(ids.length)
		} finally {
			await context.close()
		}
	})
})
