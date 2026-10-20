import { type Page, type Response, expect, test } from '@playwright/test'

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
	test('Protected views wait for membership bootstrap before issuing reads', async ({ page }) => {
		let release!: () => void
		const bootstrapBarrier = new Promise<void>((resolve) => {
			release = resolve
		})
		const protectedReads: string[] = []
		page.on('request', (request) => {
			const pathname = new URL(request.url()).pathname
			if (pathname.includes('/chorestrike/api/v1/') && !pathname.endsWith('/users/current')) {
				protectedReads.push(pathname)
			}
		})
		await page.route('**/api/v1/users/current', async (route) => {
			await bootstrapBarrier
			await route.continue()
		})
		try {
			await page.goto('/apps/chorestrike/')
			await expect(page.getByTestId('membership-loading')).toBeVisible()
			await expect(page.getByTestId('today-view')).toHaveCount(0)
			expect(protectedReads).toEqual([])
			const daily = page.waitForResponse('**/api/v1/room-tasks/filtered-daily')
			const overdue = page.waitForResponse('**/api/v1/room-tasks/overdue')
			release()
			expect((await daily).status()).toBe(200)
			expect((await overdue).status()).toBe(200)
			await expect(page.getByTestId('today-view')).toBeVisible()
			await expect(page.getByTestId('error-state')).toHaveCount(0)
		} finally {
			release()
		}
	})

	test('App loads with title and visible shell', async ({ page }) => {
		// CS-PLAN-087 WP-A: the header requests the app glyph — an <img> on
		// NC34, a CSS mask (--app-icon-url) on NC35.
		const headerIcon = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/chorestrike/img/app.svg'))
		await page.goto('/apps/chorestrike/#/dashboard')
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })

		const title = await page.title()
		expect(title.length).toBeGreaterThan(0)

		// CS-PLAN-087 WP-A (CR-NAV-01): every navigation entry shows a glyph.
		const entries = page.locator('[data-testid^="nav-"]:not([data-testid="nav-help"])')
		await expect.poll(() => entries.count()).toBeGreaterThanOrEqual(10)
		for (const entry of await entries.all()) {
			await expect(entry.locator('.app-navigation-entry-icon svg')).toBeVisible()
		}
		await expectBrandAssets(page, await headerIcon)
	})

	test('No critical console errors on load', async ({ page }) => {
		const errors: string[] = []

		// The listener must be attached before navigation, otherwise
		// load-time errors are never captured.
		page.on('console', (msg) => {
			if (msg.type() === 'error') {
				const text = msg.text()
				// Filter out common non-critical errors
				if (!text.includes('favicon') && !text.includes('404') && !text.includes('net::ERR')) {
					errors.push(text)
				}
			}
		})

		await page.goto('/apps/chorestrike/#/dashboard')
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

	for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
		test(`Injected styles and lazy assets work at ${viewport.width}px without resource or CSP errors`, async ({ page }) => {
			const errors: string[] = []
			const scripts: string[] = []
			await page.setViewportSize(viewport)
			page.on('console', (message) => {
				if (message.type() === 'error') {
					errors.push(message.text())
				}
			})
			page.on('pageerror', (error) => errors.push(error.message))
			page.on('requestfailed', (request) => errors.push(`Failed request: ${new URL(request.url()).pathname}`))
			page.on('response', (response) => {
				if (response.status() >= 400) {
					errors.push(`HTTP ${response.status()}: ${new URL(response.url()).pathname}`)
				}
				if (response.request().resourceType() === 'script') {
					const url = new URL(response.url())
					scripts.push(url.pathname)
					if (url.pathname.includes('/chorestrike/')) {
						// CS-PLAN-083 WP1: every fetched app script must come from the
						// exact physical custom_apps base — the legacy /apps alias
						// masked the production ChunkLoadError defect class.
						expect(url.pathname.startsWith('/custom_apps/chorestrike/js/')).toBe(true)
						expect(response.status()).toBe(200)
						expect(response.headers()['content-type']).toMatch(/^(?:application|text)\/javascript\b/)
					}
				}
			})
			await page.addInitScript(() => {
				const violations: string[] = []
				Object.assign(window, { assetCspViolations: violations })
				document.addEventListener('securitypolicyviolation', (event) => violations.push(event.effectiveDirective))
			})

			await page.goto('/apps/chorestrike/')
			const today = page.getByTestId('today-view')
			await expect(today).toBeVisible({ timeout: 30000 })
			await expect(page.locator('html')).toHaveCSS('--cs-radius-md', '10px')
			await expect(page.locator('body')).toHaveCSS('overflow-x', 'hidden')
			await expect(today).toHaveCSS('max-width', '900px')
			for (const container of [page.locator('#app-content-vue'), today]) {
				await expect.poll(() => container.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
			}
			expect(await today.evaluate((element) => element.getAttributeNames().some((name) => name.startsWith('data-v-')))).toBe(true)
			expect(scripts.some((url) => url.endsWith('/chorestrike-today.js'))).toBe(true)
			expect(scripts.some((url) => url.endsWith('/chorestrike-settings.js'))).toBe(false)

			if (viewport.width < 768) {
				await page.getByRole('button', { name: /Open navigation|Navigation \u00f6ffnen/i }).click()
			}
			await page.getByTestId('nav-settings').click()
			const settings = page.getByTestId('settings-view')
			await expect(settings).toBeVisible()
			if (viewport.width < 768) {
				await page.getByRole('button', { name: /Close navigation|Navigation schlie\u00dfen/i }).click()
			}
			await expect(settings).toHaveCSS('max-width', '1000px')
			expect(await settings.evaluate((element) => element.getAttributeNames().some((name) => name.startsWith('data-v-')))).toBe(true)
			expect(scripts.some((url) => url.endsWith('/chorestrike-settings.js'))).toBe(true)
			await expect(page.locator('link[rel="stylesheet"][href*="chorestrike-main.css"]')).toHaveCount(0)
			expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
			for (const container of [page.locator('#app-content-vue'), settings]) {
				await expect.poll(() => container.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true)
			}
			for (const control of [page.getByTestId('settings-title'), page.getByTestId('task-digest-switch'), page.getByTestId('digest-time-input'), page.getByTestId('pause-reason-select')]) {
				await expect(control).toBeVisible()
				await expect.poll(() => control.evaluate((element) => {
					const bounds = element.getBoundingClientRect()
					const content = element.closest('#app-content-vue')!.getBoundingClientRect()
					return bounds.width > 0 && bounds.left >= Math.max(0, content.left)
						&& bounds.right <= Math.min(window.innerWidth, content.right)
				})).toBe(true)
			}
			expect(await page.evaluate(() => (window as unknown as { assetCspViolations: string[] }).assetCspViolations)).toEqual([])
			expect(errors).toEqual([])
		})
	}
})

/**
 * CS-PLAN-087 WP-A: the header icon, the notifier's dark icon, the PWA
 * manifest and its icons resolve under the physical app root (/custom_apps
 * in AIO and canonical installs), where the former hard-coded
 * /apps/chorestrike/img/ URLs 404, and the manifest's start URL opens the app.
 */
async function expectBrandAssets(page: Page, headerIcon: Response): Promise<void> {
	// Core links the app's own img/manifest.json, not the theming fallback.
	const manifestLink = page.locator('link[rel="manifest"]')
	await expect(manifestLink).toHaveCount(1)
	const manifestUrl = new URL((await manifestLink.getAttribute('href'))!, page.url())
	const manifest = await (await page.request.get(manifestUrl.href)).json()
	expect((await page.request.get(new URL(manifest.start_url, manifestUrl).href)).status()).toBe(200)
	const assets: Array<[URL, RegExp]> = [
		[new URL(headerIcon.url()), /^image\/svg\+xml/],
		[new URL('app-dark.svg', manifestUrl), /^image\/svg\+xml/],
		[manifestUrl, /^application\/(?:manifest\+)?json/],
		...manifest.icons.map((icon: { src: string }): [URL, RegExp] => [new URL(icon.src, manifestUrl), /^image\/png/]),
	]
	expect(assets).toHaveLength(6)
	for (const [url, mime] of assets) {
		expect(url.pathname).toMatch(/^\/custom_apps\/chorestrike\/img\//)
		const response = await page.request.get(url.href)
		expect(response.status()).toBe(200)
		expect(response.headers()['content-type']).toMatch(mime)
	}
}
