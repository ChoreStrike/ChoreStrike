/** Alias-free, no-seed package smoke. Only explicitly labelled local disposable containers. */
const fs = require('node:fs')
const path = require('node:path')
const assert = require('node:assert/strict')
const { execFileSync } = require('node:child_process')
const { createHash } = require('node:crypto')
const { chromium, expect } = require('@playwright/test')
const { validatePackageTarget, assertPackageCoherence } = require('../../scripts/package-target')

const [targetFile, packageDirectory, reportFile, action] = process.argv.slice(2)
assert(['setup', 'verify'].includes(action), 'Explicit setup or verify action required')
const target = JSON.parse(fs.readFileSync(targetFile))
assert(!fs.existsSync(reportFile), 'Refusing to replace evidence')
const docker = args => execFileSync('docker', args, { encoding: 'utf8' }).trim()
const container = JSON.parse(docker(['inspect', target.container]))[0]
validatePackageTarget(container, target, packageDirectory)
assertPackageCoherence(target, target.internalVersion)
docker(['exec', target.container, 'bash', '-c', 'test ! -e /var/www/html/apps/chorestrike && test ! -L /var/www/html/apps/chorestrike'])
const occ = args => docker(['exec', '--user', 'www-data', target.container, 'php', '/var/www/html/occ', ...args])
const sha256 = body => createHash('sha256').update(body).digest('hex')
const appUrl = `${target.url}/apps/chorestrike/`
const scopes = []
function counts() {
	const php = 'require "/var/www/html/lib/base.php"; $db=\\OC::$server->get(\\OCP\\IDBConnection::class); $counts=[];'
		+ 'foreach (["chorestrike_houses","chorestrike_house_users","chorestrike_floors","chorestrike_rooms"] as $table) {'
		+ '$q=$db->getQueryBuilder();$r=$q->select($q->func()->count("*"))->from($table)->executeQuery();$counts[]=(int)$r->fetchOne();$r->closeCursor();} echo json_encode($counts);'
	return JSON.parse(docker(['exec', '--user', 'www-data', target.container, 'php', '-r', php]))
}
async function login(page) {
	await page.goto(`${target.url}/login`)
	await page.locator('input[name="user"]').fill('package-owner')
	await page.locator('input[name="password"]').fill('PackageOwner123!')
	await page.locator('button[type="submit"]').click()
	await page.waitForURL('**/apps/**')
}
async function instrument(page) {
	const errors = [], assets = [], pending = [], jsonRequests = [], cspViolations = []
	const apiEvents = []
	const scope = { errors, assets, jsonRequests, cspViolations, apiEvents }
	scopes.push(scope)
	const started = Date.now()
	const requests = new Set()
	page.on('console', message => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()) })
	page.on('pageerror', error => errors.push(error.message))
	// Navigation can cancel an AJAX cron started on the preceding login page.
	// Attribute failures/responses only to requests actually begun in this scope.
	page.on('requestfailed', req => { if (requests.has(req)) errors.push(`Failed ${req.method()} request: ${req.url()} (${req.failure()?.errorText})`) })
	page.on('request', req => {
		requests.add(req)
		const pathname = new URL(req.url()).pathname
		if (pathname.includes('/chorestrike/api/v1/')) apiEvents.push({ phase: 'request', path: pathname, method: req.method(), elapsedMs: Date.now() - started })
		if (/\/chorestrike\/l10n\/.+\.json/.test(req.url())) jsonRequests.push(req.url())
	})
	page.on('response', response => {
		if (!requests.has(response.request())) return
		const url = new URL(response.url())
		if (url.pathname.includes('/chorestrike/api/v1/')) {
			const event = { phase: 'response', path: url.pathname, method: response.request().method(), status: response.status(), elapsedMs: Date.now() - started }
			apiEvents.push(event)
			if (response.status() >= 400) pending.push(response.json().then(body => { event.code = body.error?.code }).catch(() => {}))
		}
		if (response.status() >= 400) errors.push(`HTTP ${response.status()}: ${url.pathname}`)
		if (/\/chorestrike\/js\/.+\.js$/.test(url.pathname)) {
			pending.push((async () => {
				expect(url.origin).toBe(target.url)
				expect(url.pathname).toBe(`/custom_apps/chorestrike/js/${path.basename(url.pathname)}`)
				expect(response.status()).toBe(200)
				expect(response.headers()['content-type']).toMatch(/^(?:application|text)\/javascript\b/)
				const hash = sha256(await response.body())
				expect(hash).toBe(sha256(fs.readFileSync(path.join(packageDirectory, 'js', path.basename(url.pathname)))))
				assets.push({ url: response.url(), status: response.status(), mime: response.headers()['content-type'], sha256: hash })
			})().catch(error => errors.push(error.message)))
		}
	})
	await page.exposeFunction('recordPackageCsp', directive => cspViolations.push(directive))
	await page.addInitScript(() => document.addEventListener('securitypolicyviolation', event => window.recordPackageCsp(event.effectiveDirective)))
	return async required => {
		await page.waitForLoadState('networkidle')
		await Promise.all(pending)
		for (const name of required) expect(assets.some(asset => new URL(asset.url).pathname.endsWith(`/chorestrike-${name}.js`))).toBe(true)
		expect(jsonRequests).toEqual([])
		expect(cspViolations).toEqual([])
		expect(errors).toEqual([])
		return scope
	}
}
async function setup(browser) {
	const before = counts()
	fs.writeFileSync(reportFile.replace(/\.json$/, '-before.json'), JSON.stringify(before), { flag: 'wx' })
	expect(before).toEqual([0, 0, 0, 0])
	occ(['user:setting', 'package-owner', 'core', 'lang', 'en'])
	const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
	try {
		const page = await context.newPage()
		await login(page)
		const finish = await instrument(page)
		// The real first entry is My Day, not a bootstrap-capable Dashboard shortcut.
		const daily = page.waitForResponse('**/api/v1/room-tasks/filtered-daily')
		const overdue = page.waitForResponse('**/api/v1/room-tasks/overdue')
		await page.goto(appUrl)
		expect((await daily).status()).toBe(200)
		expect((await overdue).status()).toBe(200)
		await expect(page.getByTestId('today-view')).toBeVisible()
		await expect(page.getByTestId('error-state')).toHaveCount(0)
		await page.getByRole('button', { name: /Open navigation/i }).click()
		await page.getByTestId('nav-dashboard').click()
		await page.getByRole('button', { name: /Close navigation/i }).click()
		await expect(page.getByTestId('setup-wizard')).toBeVisible({ timeout: 30000 })
		const membersResponse = await page.request.get(`${appUrl}api/v1/users`, {
			headers: { requesttoken: await page.evaluate(() => window.OC.requestToken) },
		})
		expect(membersResponse.status()).toBe(200)
		const members = await membersResponse.json()
		expect(members.data).toHaveLength(1)
		expect(members.data[0]).toMatchObject({ nextcloudUserId: 'package-owner', role: 'owner' })
		await page.getByTestId('wizard-house-name').fill('Package Home')
		await page.getByTestId('wizard-floor-count').fill('1')
		await page.getByTestId('wizard-rooms-per-floor').fill('1')
		await page.getByTestId('wizard-next').click()
		await page.getByTestId('wizard-room-name-0-0').fill('Package Kitchen')
		await page.getByTestId('wizard-room-type-0-0').selectOption('kitchen')
		await page.getByTestId('wizard-household').uncheck()
		await page.getByTestId('wizard-hallways').uncheck()
		await page.getByTestId('wizard-next').click()
		await page.getByTestId('wizard-apply').click()
		await expect(page.getByTestId('wizard-apply-result')).toBeVisible()
		await page.getByTestId('wizard-finish').click()
		await expect(page.getByTestId('setup-wizard')).toHaveCount(0)
		expect(counts()).toEqual([1, 1, 1, 1])
		return { normalOwnerBootstrap: true, counts: counts(), ...await finish(['main', 'today', 'dashboard']) }
	} finally { await context.close() }
}
async function verify(browser, language, width) {
	// Native Nextcloud catalogs use "de"; regional browser locale remains
	// "de-DE". Exact regional getLanguage() inputs are covered by the unit test.
	occ(['config:system:delete', 'force_language'])
	const userLanguage = language.split('-')[0]
	occ(['user:setting', 'package-owner', 'core', 'lang', userLanguage])
	const context = await browser.newContext({ locale: language, viewport: { width, height: width === 390 ? 844 : 900 } })
	try {
		const page = await context.newPage()
		await login(page)
		const finish = await instrument(page)
		await page.goto(`${appUrl}#/dashboard`)
		await expect(page.getByTestId('dashboard-view')).toBeVisible({ timeout: 30000 })
		await expect(page.getByTestId('setup-wizard')).toHaveCount(0)
		expect(await page.evaluate(() => document.documentElement.lang)).toBe(userLanguage)
		expect(await page.evaluate(() => window._nc_l10n_language)).toBe(userLanguage)
		expect(await page.evaluate(() => navigator.language)).toBe(language)
		await expect(page.locator('html')).toHaveCSS('--cs-radius-md', '10px')
		await page.evaluate(() => window.chorestrike.router.push('/today'))
		await expect(page.getByTestId('today-view')).toHaveCSS('max-width', '900px')
		if (width < 768) await page.getByRole('button', { name: /Open navigation|Navigation.*ffnen/i }).click()
		await page.getByTestId('nav-settings').click()
		await expect(page.getByTestId('settings-title')).toHaveText(language.startsWith('de') ? 'Einstellungen' : 'Settings')
		if (width < 768) await page.getByRole('button', { name: /Close navigation|Navigation.*schlie/i }).click()
		await expect(page.getByTestId('settings-view')).toHaveCSS('max-width', '1000px')
		await expect(page.getByTestId('house-name-input')).toHaveValue('Package Home')
		await expect(page.getByTestId('house-section')).toBeVisible()
		const floor = page.getByTestId('floors-list').locator('.floor-item').first()
		await expect(floor.locator('.room-count')).toHaveText(language.startsWith('de') ? '1 R\u00e4ume' : '1 rooms')
		const actions = floor.locator('.floor-actions button')
		await expect(actions).toHaveCount(5)
		const bounds = []
		for (const control of [page.getByTestId('house-timezone-select'), page.getByTestId('save-house-timezone'), ...await actions.all()]) {
			await expect(control).toBeVisible()
			await control.scrollIntoViewIfNeeded()
			await expect.poll(() => control.evaluate(element => {
				const box = element.getBoundingClientRect(), card = element.closest('.setting-card, #app-content-vue').getBoundingClientRect()
				return box.width > 0 && box.left >= Math.max(0, card.left) && box.right <= Math.min(innerWidth, card.right)
			})).toBe(true)
			bounds.push(await control.boundingBox())
		}
		const containers = []
		for (const selector of ['#app-content-vue', '[data-testid="settings-view"]']) {
			await expect.poll(() => page.locator(selector).evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true)
			containers.push(await page.locator(selector).evaluate(element => ({ clientWidth: element.clientWidth, scrollWidth: element.scrollWidth })))
		}
		await floor.getByTitle(language.startsWith('de') ? 'Etage l\u00f6schen' : 'Delete floor', { exact: true }).click()
		await expect(page.getByTestId('confirm-dialog')).toBeVisible()
		await page.getByTestId('confirm-dialog').getByRole('button', { name: language.startsWith('de') ? 'Abbrechen' : 'Cancel', exact: true }).click()
		await expect(floor).toBeVisible()
		const timezone = page.getByTestId('house-timezone-select')
		const original = await timezone.inputValue()
		await expect(page.getByTestId('save-house-timezone')).toBeDisabled()
		for (const value of [original === 'UTC' ? 'Europe/Berlin' : 'UTC', original]) {
			await timezone.selectOption(value)
			const saved = page.waitForResponse(response => response.request().method() === 'PUT' && new URL(response.url()).pathname.endsWith('/api/v1/house'))
			await page.getByTestId('save-house-timezone').click()
			const response = await saved
			expect(response.status()).toBe(200)
			expect((await response.json()).data.timezone).toBe(value)
			await expect(page.getByTestId('save-house-timezone')).toBeDisabled()
			await page.waitForLoadState('networkidle')
			await page.reload()
			await expect(page.getByTestId('house-name-input')).toHaveValue('Package Home')
			await expect(timezone).toHaveValue(value)
			await expect(page.getByTestId('save-house-timezone')).toBeDisabled()
		}
		const result = { browserLocale: language, userLanguage, width, bounds, containers, ...await finish(['main', 'dashboard', 'today', 'settings']) }
		await page.screenshot({ path: reportFile.replace(/\.json$/, `-${language}-${width}.png`), fullPage: true })
		return result
	} finally { await context.close() }
}
async function main() {
	const browser = await chromium.launch()
	try {
		const results = []
		if (action === 'setup') results.push(await setup(browser))
		else {
			expect(counts()).toEqual([1, 1, 1, 1])
			for (const language of ['de-DE', 'de', 'en']) for (const width of [390, 1440]) results.push(await verify(browser, language, width))
			expect(counts()).toEqual([1, 1, 1, 1])
		}
		fs.writeFileSync(reportFile, JSON.stringify({ project: target.project, action, browser: browser.version(), results }, null, 2), { flag: 'wx' })
		console.log(JSON.stringify({ project: target.project, action, cases: results.length, passed: true }))
	} catch (error) {
		fs.writeFileSync(reportFile.replace(/\.json$/, '-failed.json'), JSON.stringify({ project: target.project, action, passed: false, error: error.message, scopes }, null, 2), { flag: 'wx' })
		throw error
	} finally { await browser.close() }
}
main().catch(error => { console.error(error); process.exitCode = 1 })
