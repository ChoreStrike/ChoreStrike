/** @jest-environment node */
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * `npm run docs:screenshots` guards (CS-PLAN-087 WP-B): build prerequisites,
 * owned-project teardown, the scene list and the loopback-only target.
 */

const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { assertBuildPrerequisites, captureSettings, capture } = require('../../../scripts/capture-screenshots')
const { SCENES, SEEDED_ROLES, PREPARES, ACTIONS, assertCaptureTarget } = require('../../docs-screenshots/scenes')

const ROOT = path.resolve(__dirname, '../../..')

describe('build prerequisites', () => {
	let root
	const write = (relative, mtime) => {
		const file = path.join(root, relative)
		fs.mkdirSync(path.dirname(file), { recursive: true })
		fs.writeFileSync(file, '')
		fs.utimesSync(file, mtime, mtime)
	}

	beforeEach(() => {
		root = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-capture-'))
		write('src/views/TodayView.vue', 1000)
	})
	afterEach(() => fs.rmSync(root, { recursive: true, force: true }))

	it('refuses a missing production build', () => {
		write('vendor/autoload.php', 1000)
		expect(() => assertBuildPrerequisites(root)).toThrow('npm run build')
	})

	it('refuses a missing vendor tree', () => {
		write('js/chorestrike-main.js', 2000)
		expect(() => assertBuildPrerequisites(root)).toThrow('composer install')
	})

	it('refuses a build older than a source file', () => {
		write('js/chorestrike-main.js', 2000)
		write('vendor/autoload.php', 1000)
		write('src/components/Common/Icon.vue', 3000)
		expect(() => assertBuildPrerequisites(root)).toThrow('older than src/')
	})

	it('accepts a current build', () => {
		write('js/chorestrike-main.js', 2000)
		write('vendor/autoload.php', 1000)
		expect(() => assertBuildPrerequisites(root)).not.toThrow()
	})
})

describe('capture run', () => {
	const settings = captureSettings('35.0.2', 8199, 1760000000000)
	const ok = { status: 0 }

	it('stages an owned SQLite row of the given runtime on loopback', () => {
		expect(settings.project).toMatch(/^cs082-docs-[a-z0-9]+$/)
		expect(settings.image).toBe('cs082-runtime-35.0.2-local')
		expect(settings.url).toBe('http://127.0.0.1:8199')
		expect(settings.container).toBe(`${settings.project}-nextcloud-sqlite-1`)
	})

	it('stages, captures against the row, then removes only its own project', () => {
		const run = jest.fn(() => ok)
		capture(settings, { run, assertFresh: jest.fn() })

		const [stage, playwright, teardown] = run.mock.calls
		expect(stage[1]).toEqual([path.join('scripts', 'test-runtime-stage.js'), 'up', settings.project, 'sqlite', settings.image, '8199'])
		expect(playwright[1].slice(1)).toEqual(['test', '--config', 'playwright.docs.config.ts'])
		expect(playwright[2].env).toMatchObject({ NEXTCLOUD_URL: settings.url, NEXTCLOUD_CONTAINER: settings.container })
		expect(teardown.slice(0, 2)).toEqual(['docker', ['compose', '--project-name', settings.project, 'down', '--volumes']])
	})

	it('tears its project down after a failed capture', () => {
		const run = jest.fn((command, args) => (args.includes('playwright.docs.config.ts') ? { status: 1 } : ok))
		expect(() => capture(settings, { run, assertFresh: jest.fn() })).toThrow('Screenshot capture failed')
		expect(run).toHaveBeenLastCalledWith('docker', ['compose', '--project-name', settings.project, 'down', '--volumes'], expect.any(Object))
	})

	it('creates and removes nothing when the project name is already taken', () => {
		const run = jest.fn(() => ok)
		const assertFresh = jest.fn(() => { throw new Error('Staging project already exists') })
		expect(() => capture(settings, { run, assertFresh })).toThrow('already exists')
		expect(run).not.toHaveBeenCalled()
	})

	it('names the manual cleanup when its own teardown fails', () => {
		const run = jest.fn((command) => (command === 'docker' ? { status: 1 } : ok))
		const stderr = jest.spyOn(process.stderr, 'write').mockImplementation(() => true)
		capture(settings, { run, assertFresh: jest.fn() })
		expect(stderr).toHaveBeenCalledWith(expect.stringContaining(`docker compose --project-name ${settings.project} down --volumes`))
		stderr.mockRestore()
	})

	it('keeps the row on request', () => {
		const run = jest.fn(() => ok)
		jest.spyOn(process.stdout, 'write').mockImplementation(() => true)
		capture(settings, { run, assertFresh: jest.fn(), keep: true })
		expect(run).toHaveBeenCalledTimes(2)
		process.stdout.write.mockRestore()
	})
})

describe('scene list', () => {
	it('writes unique PNG outputs', () => {
		const outputs = SCENES.map(scene => scene.output)
		expect(new Set(outputs).size).toBe(outputs.length)
		outputs.forEach(output => expect(output).toMatch(/^[a-z0-9-]+\.png$/))
	})

	it('uses seeded roles and known steps only', () => {
		for (const scene of SCENES) {
			expect(SEEDED_ROLES).toContain(scene.role)
			expect(scene.route).toMatch(/^\/[a-z-]+$/)
			expect(scene.ready).toMatch(/^[a-z-]+$/)
			if (scene.prepare) expect(PREPARES).toContain(scene.prepare)
			if (scene.action) expect(ACTIONS).toContain(scene.action)
		}
	})

	it('implements every action step and empties the household only last', () => {
		const spec = fs.readFileSync(path.join(ROOT, 'tests/docs-screenshots/capture.spec.ts'), 'utf8')
		ACTIONS.forEach(action => expect(spec).toMatch(new RegExp(`async ${action}\\(page\\)`)))
		expect(SCENES.findIndex(scene => scene.prepare === 'emptyHousehold')).toBe(SCENES.length - 1)
	})

	it('stays outside the canonical browser gate', () => {
		expect(fs.readFileSync(path.join(ROOT, 'playwright.config.ts'), 'utf8')).toContain("testDir: './tests/e2e'")
		expect(fs.existsSync(path.join(ROOT, 'tests/e2e/capture.spec.ts'))).toBe(false)
	})
})

describe('capture target', () => {
	it.each(['http://127.0.0.1:8191', 'http://localhost:8080', 'http://[::1]:8191/'])('accepts the loopback origin %s', url => {
		expect(assertCaptureTarget(url)).toBe(new URL(url).origin)
	})

	it.each([
		'https://cloud.example.org',
		'http://192.168.1.20:8080',
		'http://cloud.example.org',
		'https://127.0.0.1:8443',
		'http://admin:secret@127.0.0.1:8191',
		'not a url',
		undefined,
	])('refuses %s', url => {
		expect(() => assertCaptureTarget(url)).toThrow(/loopback/)
	})
})
