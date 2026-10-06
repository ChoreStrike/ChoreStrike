/** @jest-environment node */
const fs = require('node:fs')
const path = require('node:path')
const yaml = require('js-yaml')
const { main } = require('../../../scripts/run-tests')
const SummaryReporter = require('../../../scripts/playwright-summary-reporter')
const root = path.resolve(__dirname, '../../..')
const workflow = name => yaml.load(fs.readFileSync(path.join(root, `.forgejo/workflows/${name}.yml`), 'utf8'))

afterEach(() => jest.restoreAllMocks())

describe('runtime workflow execution prerequisites', () => {
	it('retains canonical configuration and data alongside the database across recreation', () => {
		const compose = yaml.load(fs.readFileSync(path.join(root, 'docker/compose.test.yml'), 'utf8'))
		expect(compose.services['nextcloud-test'].volumes).toEqual(expect.arrayContaining([
			'canonical-config:/var/www/html/config', 'canonical-data:/var/www/html/data',
		]))
		expect(compose.services['nextcloud-test'].ports).toEqual(['127.0.0.1:8080:80', '[::1]:8080:80'])
		expect(compose.services.db.volumes).toEqual(['canonical-db:/var/lib/postgresql'])
	})
	it('runs both complete PHP suites after readiness and frozen identity, without tearing down shared resources', () => {
		const run = jest.fn(() => ({ status: 0 }))
		expect(main([], run)).toBe(0)
		expect(run.mock.calls.map(([command, args]) => [command, args])).toEqual([
			['docker', ['compose', '-f', 'docker/compose.test.yml', 'up', '-d', '--build', '--wait', '--wait-timeout', '900']],
			[process.execPath, ['scripts/test-runtime.js', 'verify-canonical']],
			...['unit', 'integration'].map(suite => ['docker', ['exec', 'chorestrike-test', 'vendor/bin/phpunit', '--configuration',
				`phpunit.${suite}.xml`, '--fail-on-skipped', '--do-not-cache-result', '--order-by=default']]),
		])
	})
	it.each([0, 1, 2, 3])('stops and preserves the failing exit code at command %s', index => {
		let call = 0
		const run = jest.fn(() => ({ status: call++ === index ? 7 : 0 }))
		expect(main([], run)).toBe(7)
		expect(run).toHaveBeenCalledTimes(index + 1)
	})
	it('rejects interrupted or unavailable tools and unexpected arguments', () => {
		expect(main([], () => ({ signal: 'SIGTERM', status: null }))).toBe(1)
		expect(main([], () => ({ error: new Error('unavailable'), status: null }))).toBe(1)
		const run = jest.fn()
		expect(() => main(['--reset'], run)).toThrow('Usage')
		expect(run).not.toHaveBeenCalled()
	})
	it.each(['release-gate', 'full-matrix', 'test'])('%s builds assets before its browser jobs execute', name => {
		const jobs = Object.values(workflow(name).jobs).filter(job => job.steps?.some(step => /(?:npx playwright test|npm run test:e2e:gate)/.test(step.run)))
		expect(jobs.length).toBeGreaterThan(0)
		for (const job of jobs) {
			const build = job.steps.findIndex(step => step.run === 'npm run build')
			const browser = job.steps.findIndex(step => /(?:npx playwright test|npm run test:e2e:gate)/.test(step.run))
			expect(build).toBeGreaterThan(-1)
			expect(build).toBeLessThan(browser)
		}
	})
	it.each(['release-gate', 'full-matrix'])('%s awaits full app health and runtime verification before seeding', name => {
		const job = Object.values(workflow(name).jobs).find(job => job.steps?.some(step => step.name?.startsWith('Bring up Docker test env')))
		const setup = job.steps.find(step => step.name?.startsWith('Bring up Docker test env')).run
		expect(setup).toContain('--wait --wait-timeout 900')
		expect(setup.indexOf('npm run test:runtime:canonical')).toBeLessThan(setup.indexOf('php occ chorestrike:test-data:seed'))
	})
	it('keeps the profile/browser smoke matrix on the fully provisioned frozen runtime', () => {
		const job = workflow('test').jobs.e2e
		expect(job.strategy.matrix.profile).toEqual(['empty', 'minimal', 'family'])
		expect(job.strategy.matrix.browser).toEqual(['chromium', 'firefox'])
		expect(job.services).toBeUndefined()
		const setup = job.steps.find(step => step.name === 'Provision frozen test runtime').run
		expect(setup).toContain('--wait --wait-timeout 900')
		expect(setup).toContain('npm run test:runtime:canonical')
		expect(job.env.NEXTCLOUD_CONTAINER).toBe('chorestrike-test')
	})
	it.each(['full-matrix', 'test'])('%s publishes only sanitized reporter outputs', name => {
		const job = Object.values(workflow(name).jobs).find(job => job.steps?.some(step => step.run?.includes('npx playwright test')))
		const upload = job.steps.find(step => step.uses?.startsWith('actions/upload-artifact'))
		expect(upload.with.path.trim()).toBe('build/e2e-summary/*.json')
		for (const step of job.steps.filter(step => step.run?.includes('npx playwright test'))) {
			expect(step.run).toContain('--reporter=./scripts/playwright-summary-reporter.js')
		}
	})
	it('keeps session data, errors and attachments out of summary output', () => {
		jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {})
		const write = jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {})
		const reporter = new SummaryReporter()
		reporter.onTestEnd({ title: 'sensitive-title' }, { status: 'failed', retry: 1, error: 'secret', attachments: ['cookie'] })
		reporter.onEnd({ status: 'failed', error: 'secret' })
		expect(JSON.parse(write.mock.calls[0][1])).toEqual({ status: 'failed', passed: 0, failed: 1, timedOut: 0, skipped: 0, interrupted: 0, retries: 1 })
		expect(write.mock.calls[0][1]).not.toMatch(/secret|cookie|sensitive/)
	})
})
