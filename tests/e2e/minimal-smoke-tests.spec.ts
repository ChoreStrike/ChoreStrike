import { test, expect } from './fixtures/test-fixtures'

/**
 * Minimal Smoke Tests
 *
 * CS-PLAN-050: the single lean smoke layer. It verifies boot, shell, title,
 * and hash deep-links with auto-waiting assertions. The click-through
 * navigation and console-error sweep live in `fast-core-tests.spec.ts`
 * (which loads the app the same way); the former `smoke-tests.spec.ts`
 * duplicate was deleted. The console listener is attached *before*
 * `goto` so load-time errors are actually captured.
 */
test.describe('Minimal Smoke Tests', () => {
	test('App loads with title and visible shell', async ({ page }) => {
		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		const title = await page.title()
		expect(title.length).toBeGreaterThan(0)
	})

	test('No critical console errors on load', async ({ page }) => {
		const errors: string[] = []

		// The listener must be attached before navigation, otherwise
		// load-time errors are never captured.
		page.on('console', msg => {
			if (msg.type() === 'error') {
				const text = msg.text()
				// Filter out common non-critical errors
				if (!text.includes('favicon') && !text.includes('404') && !text.includes('net::ERR')) {
					errors.push(text)
				}
			}
		})

		await page.goto('/apps/chorestrike/')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		expect(errors).toHaveLength(0)
	})

	test('Hash deep-links reach the tasks and settings views', async ({ page }) => {
		const routes = [
			{ hash: '#/tasks', viewTestid: 'task-manager-view' },
			{ hash: '#/settings', viewTestid: 'settings-view' },
		]

		for (const route of routes) {
			await page.goto(`/apps/chorestrike/${route.hash}`)
			await expect(page.getByTestId(route.viewTestid)).toBeVisible({ timeout: 30000 })
			const title = await page.title()
			expect(title).not.toContain('Error')
			expect(title).not.toContain('Not Found')
		}
	})
})
