/** @jest-environment node */
const { configuredTarget, assertTargetIdentity, assertTestTarget, authScope, executeReadOnly } = require('../../../scripts/test-target')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const ts = require('typescript')
const { readInventory, inventoryHash, selectRuntimeSource, selectionHash } = require('../../../scripts/test-runtime')
const runtime = readInventory('primary')

const environment = { NEXTCLOUD_URL: 'http://127.0.0.1:8082', NEXTCLOUD_CONTAINER: 'cs082-fixture-nextcloud-pgsql-1' }
const target = { url: environment.NEXTCLOUD_URL, container: environment.NEXTCLOUD_CONTAINER }
const state = { installed: true, maintenance: false, needsDbUpgrade: false, version: runtime.nextcloud.internalVersion }

function container() {
	return {
		Name: `/${target.container}`, State: { Running: true },
		Config: { Env: ['NEXTCLOUD_TEST_MODE=true', 'NEXTCLOUD_TEST_ENV=true', `TEST_RUNTIME_INTERNAL_VERSION=${runtime.nextcloud.internalVersion}`, `TEST_RUNTIME_PHP_VERSION=${runtime.php.version}`],
			Labels: { 'com.docker.compose.project': 'cs082-fixture', 'com.docker.compose.service': 'nextcloud-pgsql', 'org.chorestrike.test-runtime.inventory': inventoryHash(runtime) } },
		NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '127.0.0.1', HostPort: '8082' }] } },
	}
}

describe('coherent owned browser/CLI target', () => {
	it('does not hide runtime diagnostics behind valid-looking identity output', () => {
		expect(() => executeReadOnly(process.execPath, ['-e', "process.stdout.write('{}');process.stderr.write('diagnostic')"], { encoding: 'utf8', timeout: 10000 })).toThrow('emitted diagnostics')
	})
	it('requires an explicit OCC target for custom HTTP endpoints', () => {
		const execute = jest.fn()
		expect(() => configuredTarget({ NEXTCLOUD_URL: environment.NEXTCLOUD_URL }, execute)).toThrow('requires NEXTCLOUD_CONTAINER')
		expect(execute).not.toHaveBeenCalled()
		expect(configuredTarget(environment, execute)).toEqual(target)
		expect(() => configuredTarget({ ...environment, NEXTCLOUD_URL: 'http://localhost:8082' }, execute)).toThrow('numeric loopback')
	})
	it.each(['', 'chorestrike-test\nnextcloud'])('refuses missing or ambiguous canonical targets: %s', names => {
		expect(() => configuredTarget({}, jest.fn(() => names))).toThrow('missing or ambiguous')
	})
	it('retains unambiguous canonical CI/local selection without falling back to unrelated containers', () => {
		expect(configuredTarget({}, jest.fn(() => 'unrelated\nchorestrike-test'))).toEqual({ url: 'http://localhost:8080', container: 'chorestrike-test' })
		expect(configuredTarget({}, jest.fn(() => 'nextcloud'))).toEqual({ url: 'http://localhost:8080', container: 'nextcloud' })
	})
	it.each(['https://localhost:8082', 'http://example.org:8082', 'http://user:secret@localhost:8082', 'http://localhost:8082/subpath', 'http://localhost:8082/?token=secret'])('rejects unsupported or credential-bearing target %s', url => {
		expect(() => configuredTarget({ ...environment, NEXTCLOUD_URL: url })).toThrow('loopback HTTP origin')
	})
	it('validates the target selector before passing it to Docker', () => {
		expect(() => configuredTarget({ ...environment, NEXTCLOUD_CONTAINER: 'test; unsafe' })).toThrow('Invalid test container selector')
	})
	it('requires both HTTP and CLI readiness on the actual published container endpoint', () => {
		expect(() => assertTargetIdentity(target, container(), state, state)).not.toThrow()
	})
	it('refuses matching older HTTP/CLI versions or a moved inventory before browser mutation', () => {
		expect(() => assertTargetIdentity(target, container(), { ...state, version: '31.0.0.1' }, { ...state, version: '31.0.0.1' })).toThrow('frozen runtime inventory')
		const value = container()
		value.Config.Labels['org.chorestrike.test-runtime.inventory'] = 'old'
		expect(() => assertTargetIdentity(target, value, state, state)).toThrow('frozen runtime inventory')
		const aio = readInventory('aio')
		value.Config.Labels['org.chorestrike.test-runtime.inventory'] = inventoryHash(aio)
		value.Config.Env = ['NEXTCLOUD_TEST_MODE=true', 'NEXTCLOUD_TEST_ENV=true', `TEST_RUNTIME_INTERNAL_VERSION=${aio.nextcloud.internalVersion}`, `TEST_RUNTIME_PHP_VERSION=${aio.php.version}`]
		expect(() => assertTargetIdentity(target, value, { ...state, version: aio.nextcloud.internalVersion }, { ...state, version: aio.nextcloud.internalVersion })).not.toThrow()
		value.Config.Env.pop()
		expect(() => assertTargetIdentity(target, value, { ...state, version: aio.nextcloud.internalVersion }, { ...state, version: aio.nextcloud.internalVersion })).toThrow('frozen runtime inventory')
	})
	it('accepts a pinned previous-patch update source, but never with the current hash or label drift', () => {
		const full = readInventory()
		const previous = selectRuntimeSource(full, 'primary', 'previous')
		const value = container()
		value.Config.Labels['org.chorestrike.test-runtime.inventory'] = selectionHash(previous)
		value.Config.Env = ['NEXTCLOUD_TEST_MODE=true', 'NEXTCLOUD_TEST_ENV=true', `TEST_RUNTIME_INTERNAL_VERSION=${previous.nextcloud.internalVersion}`, `TEST_RUNTIME_PHP_VERSION=${previous.php.version}`]
		const sourceState = { ...state, version: previous.nextcloud.internalVersion }
		expect(() => assertTargetIdentity(target, value, sourceState, sourceState)).not.toThrow()
		value.Config.Labels['org.chorestrike.test-runtime.inventory'] = inventoryHash(runtime)
		expect(() => assertTargetIdentity(target, value, sourceState, sourceState)).toThrow('frozen runtime inventory')
		value.Config.Labels['org.chorestrike.test-runtime.inventory'] = selectionHash(previous)
		expect(() => assertTargetIdentity(target, value, { ...sourceState, version: '35.0.9.9' }, sourceState)).toThrow('HTTP/OCC test target mismatch')
	})
	it.each([
		['wrong port', value => { value.NetworkSettings.Ports['80/tcp'][0].HostPort = '8080' }],
		['missing published port', value => { value.NetworkSettings.Ports = {} }],
		['wrong binding', value => { value.NetworkSettings.Ports['80/tcp'][0].HostIp = '10.0.0.1' }],
		['wrong container', value => { value.Name = '/chorestrike-test' }],
		['test mode off', value => { value.Config.Env = ['NEXTCLOUD_TEST_ENV=true'] }],
		['not a test environment', value => { value.Config.Env = ['NEXTCLOUD_TEST_MODE=true'] }],
		['stopped container', value => { value.State.Running = false }],
		['unowned project', value => { value.Config.Labels['com.docker.compose.project'] = 'production' }],
		['unowned service', value => { value.Config.Labels['com.docker.compose.service'] = 'unrelated' }],
		['IPv4 wildcard publication', value => { value.NetworkSettings.Ports['80/tcp'][0].HostIp = '0.0.0.0' }],
		['IPv6 wildcard publication', value => { value.NetworkSettings.Ports['80/tcp'][0].HostIp = '::' }],
		['mixed loopback/public publication', value => { value.NetworkSettings.Ports['80/tcp'].push({ HostIp: '0.0.0.0', HostPort: '8083' }) }],
	])('refuses %s before an HTTP request or OCC execution', (_, mutate) => {
		const value = container()
		mutate(value)
		const execute = jest.fn(() => JSON.stringify([value]))
		expect(() => assertTestTarget(target.url, environment, execute)).toThrow('no seed/reset/job was executed')
		expect(execute.mock.calls).toHaveLength(1)
		expect(execute.mock.calls[0][1][0]).toBe('inspect')
	})
	it.each([
		['different installation', { version: '31.0.0.1' }],
		['not installed', { installed: false }],
		['maintenance', { maintenance: true }],
		['upgrade required', { needsDbUpgrade: true }],
		['malformed readiness', { needsDbUpgrade: undefined }],
	])('refuses %s after read-only probes', (_, changes) => {
		expect(() => assertTargetIdentity(target, container(), state, { ...state, ...changes })).toThrow('installed runtime/readiness')
		expect(() => assertTargetIdentity(target, container(), { ...state, ...changes }, state)).toThrow('installed runtime/readiness')
	})
	it('runs only read-only commands and refuses unavailable HTTP without leaking command output', () => {
		const execute = jest.fn((command, args) => {
			if (args[0] === 'inspect') return JSON.stringify([container()])
			return JSON.stringify(state)
		})
		expect(assertTestTarget(target.url, environment, execute)).toEqual(target)
		expect(execute.mock.calls.map(([command, args]) => [command, args[0]])).toEqual([['docker', 'inspect'], ['docker', 'exec'], ['curl', '--fail']])
		expect(execute.mock.calls[1][1].at(-1)).toContain('OC_Util::needUpgrade($config)')
		execute.mockImplementation(() => { throw new Error('sensitive command output') })
		expect(() => assertTestTarget(target.url, environment, execute)).toThrow(/^Test target preflight failed; no seed\/reset\/job was executed$/)
	})
	it('refuses a fixture URL that disagrees with the configured target before invoking Docker', () => {
		const execute = jest.fn()
		expect(() => assertTestTarget('http://localhost:8080', environment, execute)).toThrow('preflight failed')
		expect(execute).not.toHaveBeenCalled()
	})
	it.each(['/other-instance', '/?token=synthetic', '/#fragment', '/?', '/#'])('refuses same-origin non-root caller route %s before any probe', suffix => {
		const execute = jest.fn()
		expect(() => assertTestTarget(`${target.url}${suffix}`, environment, execute)).toThrow('preflight failed')
		expect(execute).not.toHaveBeenCalled()
	})
	it('refuses same-origin caller credentials before any probe', () => {
		const execute = jest.fn()
		expect(() => assertTestTarget('http://user:synthetic@127.0.0.1:8082', environment, execute)).toThrow('preflight failed')
		expect(execute).not.toHaveBeenCalled()
	})
	it('requires both localhost address families to route to the inspected canonical container', () => {
		const value = container()
		value.Name = '/chorestrike-test'
		value.NetworkSettings.Ports['80/tcp'] = [{ HostIp: '127.0.0.1', HostPort: '8080' }]
		const canonical = { url: 'http://localhost:8080', container: 'chorestrike-test' }
		expect(() => assertTargetIdentity(canonical, value, state, state)).toThrow('published endpoint')
		value.NetworkSettings.Ports['80/tcp'].push({ HostIp: '::1', HostPort: '8080' })
		expect(() => assertTargetIdentity(canonical, value, state, state)).not.toThrow()
	})
	it.each(['0.0.0.0', '::', '10.0.0.1'])('refuses canonical non-loopback publication %s before probes', address => {
		const value = container()
		value.Name = '/chorestrike-test'
		value.NetworkSettings.Ports['80/tcp'] = [{ HostIp: address, HostPort: '8080' }]
		const execute = jest.fn(() => JSON.stringify([value]))
		expect(() => assertTestTarget('http://localhost:8080', { NEXTCLOUD_CONTAINER: 'chorestrike-test' }, execute)).toThrow('preflight failed')
		expect(execute).toHaveBeenCalledTimes(1)
	})
	it.each(['31.0.0.1', '35.0.0.10', '34.0.4.1'])('refuses canonical stale or compatibility runtime %s', version => {
		const value = container()
		value.Name = '/chorestrike-test'
		value.NetworkSettings.Ports['80/tcp'] = [{ HostIp: '127.0.0.1', HostPort: '8080' }, { HostIp: '::1', HostPort: '8080' }]
		expect(() => assertTargetIdentity({ url: 'http://localhost:8080', container: 'chorestrike-test' }, value,
			{ ...state, version }, { ...state, version })).toThrow('frozen runtime inventory')
	})
	it('refuses canonical identity without the frozen inventory label', () => {
		const value = container()
		value.Name = '/chorestrike-test'
		value.NetworkSettings.Ports['80/tcp'] = [{ HostIp: '127.0.0.1', HostPort: '8080' }, { HostIp: '::1', HostPort: '8080' }]
		delete value.Config.Labels['org.chorestrike.test-runtime.inventory']
		expect(() => assertTargetIdentity({ url: 'http://localhost:8080', container: 'chorestrike-test' }, value, state, state)).toThrow('frozen runtime inventory')
	})
	it('preserves only the explicitly owned CI service route and proves its actual network endpoint', () => {
		const ci = { CI: 'true', NEXTCLOUD_URL: 'http://nextcloud:80', NEXTCLOUD_CONTAINER: 'nextcloud' }
		const value = container()
		value.Name = '/nextcloud'
		value.Config.Labels['org.chorestrike.test-ci'] = 'chorestrike-e2e'
		value.NetworkSettings.Ports = {}
		value.NetworkSettings.Networks = { ci: { Aliases: ['nextcloud'], IPAddress: '172.19.0.4' } }
		const execute = jest.fn((command, args) => args[0] === 'inspect' ? JSON.stringify([value])
			: command === 'curl' ? `${JSON.stringify(state)}\n172.19.0.4` : JSON.stringify(state))
		expect(assertTestTarget(ci.NEXTCLOUD_URL, ci, execute)).toEqual({ url: 'http://nextcloud', container: 'nextcloud' })
		expect(() => configuredTarget({ ...ci, CI: 'false' })).toThrow('loopback HTTP origin')
		const original = execute.getMockImplementation()
		execute.mockImplementation((command, args) => command === 'curl' ? `${JSON.stringify(state)}\n172.19.0.5` : original(command, args))
		expect(() => assertTestTarget(ci.NEXTCLOUD_URL, ci, execute)).toThrow('preflight failed')
		delete value.Config.Labels['org.chorestrike.test-ci']
		execute.mockClear()
		expect(() => assertTestTarget(ci.NEXTCLOUD_URL, ci, execute)).toThrow('preflight failed')
		expect(execute).toHaveBeenCalledTimes(1)
	})
	it('scopes saved authentication to both the HTTP origin and OCC target', () => {
		expect(authScope(environment)).toMatch(/^[a-f0-9]{24}$/)
		expect(authScope(environment)).toBe(authScope({ ...environment, NEXTCLOUD_URL: `${target.url}/` }))
		expect(authScope(environment)).not.toBe(authScope({ ...environment, NEXTCLOUD_URL: 'http://localhost:8083' }))
		expect(authScope(environment)).not.toBe(authScope({ ...environment, NEXTCLOUD_CONTAINER: 'cs082-other' }))
		expect(() => authScope({ NEXTCLOUD_URL: 'http://user:secret@localhost:8080' })).toThrow('Credentials')
	})
	it('routes every configured browser pool through the scoped authentication path helper', () => {
		const code = fs.readFileSync(path.resolve(__dirname, '../../../playwright.config.ts'), 'utf8')
		const exports = {}
		vm.runInNewContext(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, {
			exports, process,
			require: name => name === '@playwright/test' ? { defineConfig: value => value, devices: {} }
				: { userStatePath: user => `build/.playwright-auth/target-fixture/${user}.json` },
		})
		const pools = exports.default.projects.filter(project => project.name !== 'setup')
		// Five desktop pools plus the two CS-PLAN-084 phone projects.
		expect(pools.map(pool => pool.name)).toEqual(['chromium', 'firefox', 'webkit', 'chromium-serial-db', 'chromium-scheduling-owner', 'mobile-chromium', 'mobile-webkit'])
		for (const pool of pools) expect(pool.use.storageState).toBe(`build/.playwright-auth/target-fixture/${pool.name === 'chromium-scheduling-owner' ? 'dad' : 'admin'}.json`)
	})
})
