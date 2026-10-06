/** Read-only HTTP/OCC coherence checks before any test mutation. */
const { spawnSync } = require('node:child_process')
const { createHash } = require('node:crypto')
const { readInventory, selectRuntime, inventoryHash } = require('./test-runtime')

const DEFAULT_URL = 'http://localhost:8080'
const CLI_IDENTITY = "require '/var/www/html/lib/base.php'; $config = \\OC::$server->get(\\OC\\SystemConfig::class);"
	+ " echo json_encode(['installed' => $config->getValue('installed', false),"
	+ " 'maintenance' => $config->getValue('maintenance', false),"
	+ " 'needsDbUpgrade' => \\OC_Util::needUpgrade($config), 'version' => $config->getValue('version')]);"

function executeReadOnly(command, args, options) {
	const result = spawnSync(command, args, { ...options, maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] })
	if (result.error || result.status !== 0 || result.stderr.trim()) throw new Error('Read-only target probe failed or emitted diagnostics')
	return result.stdout
}

function configuredTarget(environment = process.env, execute = executeReadOnly) {
	const source = environment.NEXTCLOUD_URL || DEFAULT_URL
	const url = new URL(source)
	const ciService = environment.CI === 'true' && environment.NEXTCLOUD_CONTAINER === 'nextcloud' && url.origin === 'http://nextcloud'
	if (url.protocol !== 'http:' || (!ciService && !['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))
		|| url.username || url.password || /[?#]/.test(source) || url.pathname !== '/') {
		throw new Error('Test target must be a credential-free loopback HTTP origin')
	}
	if (url.hostname === 'localhost' && url.origin !== DEFAULT_URL) throw new Error('Custom targets require a numeric loopback address')
	let container = environment.NEXTCLOUD_CONTAINER
	if (!container) {
		if (url.origin !== DEFAULT_URL) throw new Error('Custom HTTP test target requires NEXTCLOUD_CONTAINER')
		const names = execute('docker', ['ps', '--format', '{{.Names}}'], { encoding: 'utf8', timeout: 10000 }).trim().split(/\r?\n/)
		const candidates = ['chorestrike-test', 'nextcloud'].filter(name => names.includes(name))
		if (candidates.length !== 1) throw new Error('Test OCC target is missing or ambiguous; configure NEXTCLOUD_CONTAINER')
		container = candidates[0]
	}
	if (!/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(container)) throw new Error('Invalid test container selector')
	return { url: url.origin, container }
}

function assertOwnedTarget(target, container) {
	const url = new URL(target.url)
	const port = url.port || '80'
	const bindings = container?.NetworkSettings?.Ports?.['80/tcp'] || []
	const testMode = container?.Config?.Env?.includes('NEXTCLOUD_TEST_MODE=true')
	const testEnvironment = container?.Config?.Env?.includes('NEXTCLOUD_TEST_ENV=true')
	const addresses = url.hostname === '[::1]' ? ['::', '::1'] : ['0.0.0.0', '127.0.0.1']
	if (!container?.State?.Running || !testMode || !testEnvironment) throw new Error('Test target is not running in explicit test mode')
	const legacyCanonical = [DEFAULT_URL, 'http://127.0.0.1:8080'].includes(target.url) && ['chorestrike-test', 'nextcloud'].includes(target.container)
	const labels = container?.Config?.Labels || {}
	if (target.url === 'http://nextcloud' && target.container === 'nextcloud') {
		if (container.Name !== '/nextcloud' || labels['org.chorestrike.test-ci'] !== 'chorestrike-e2e') throw new Error('CI service target is not explicitly owned')
		return
	}
	if (!legacyCanonical && (!/^cs082-[a-z0-9][a-z0-9-]*$/.test(labels['com.docker.compose.project'])
		|| !/^nextcloud-(mysql|pgsql|sqlite)$/.test(labels['com.docker.compose.service']))) {
		throw new Error('Custom test target is not an owned CS082 installation')
	}
	if (!legacyCanonical && (url.hostname === 'localhost' || bindings.some(binding => !['127.0.0.1', '::1'].includes(binding.HostIp)))) {
		throw new Error('Custom test target must publish exclusively on numeric loopback addresses')
	}
	if (container.Name !== `/${target.container}`
		|| !bindings.some(binding => binding.HostPort === port && addresses.includes(binding.HostIp))
		|| (url.hostname === 'localhost' && !bindings.some(binding => binding.HostPort === port && ['::', '::1'].includes(binding.HostIp)))) {
		throw new Error('HTTP/OCC test target mismatch: published endpoint')
	}
}

function assertTargetIdentity(target, container, cli, http) {
	assertOwnedTarget(target, container)
	if (cli?.installed !== true || http?.installed !== true || cli?.maintenance !== false || http?.maintenance !== false
		|| cli?.needsDbUpgrade !== false || http?.needsDbUpgrade !== false
		|| !/^\d+\.\d+\.\d+\.\d+$/.test(cli?.version) || http?.version !== cli.version) {
		throw new Error('HTTP/OCC test target mismatch: installed runtime/readiness')
	}
	if (target.container.startsWith('cs082-')) {
		const inventory = readInventory()
		const runtime = [selectRuntime(inventory), selectRuntime(inventory, 'aio')]
			.find(row => row.nextcloud.internalVersion === cli.version)
		if (!runtime || container.Config.Labels['org.chorestrike.test-runtime.inventory'] !== inventoryHash(runtime)
			|| !container.Config.Env.includes(`TEST_RUNTIME_INTERNAL_VERSION=${runtime.nextcloud.internalVersion}`)
			|| !container.Config.Env.includes(`TEST_RUNTIME_PHP_VERSION=${runtime.php.version}`)) {
			throw new Error('HTTP/OCC test target mismatch: frozen runtime inventory')
		}
	}
}

function assertTestTarget(baseURL, environment = process.env, execute = executeReadOnly) {
	try {
		const target = configuredTarget(environment, execute)
		const caller = configuredTarget({ ...environment, NEXTCLOUD_URL: baseURL }, execute)
		if (caller.url !== target.url) throw new Error('Configured HTTP target mismatch')
		const options = { encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'] }
		const container = JSON.parse(execute('docker', ['inspect', target.container], options))[0]
		// Refuse wrong mappings/mode before even probing the HTTP installation.
		assertOwnedTarget(target, container)
		const cli = JSON.parse(execute('docker', ['exec', '--user', 'www-data', target.container,
			'php', '-r', CLI_IDENTITY], options))
		const service = target.url === 'http://nextcloud'
		const output = execute('curl', ['--fail', '--silent', '--show-error', '--max-time', '10', '--noproxy', '*',
			...(service ? ['--write-out', '\n%{remote_ip}'] : []), `${target.url}/status.php`], options)
		const split = output.lastIndexOf('\n')
		if (service) {
			const address = output.slice(split + 1).trim()
			const networks = Object.values(container.NetworkSettings.Networks || {})
			if (split < 0 || !networks.some(network => network.Aliases?.includes('nextcloud')
				&& address && [network.IPAddress, network.GlobalIPv6Address].includes(address))) {
				throw new Error('CI HTTP/OCC network endpoint mismatch')
			}
		}
		const http = JSON.parse(service ? output.slice(0, split) : output)
		assertTargetIdentity(target, container, cli, http)
		return target
	} catch {
		throw new Error('Test target preflight failed; no seed/reset/job was executed')
	}
}

function authScope(environment = process.env) {
	const url = new URL(environment.NEXTCLOUD_URL || DEFAULT_URL)
	if (url.username || url.password) throw new Error('Credentials are not allowed in a test target URL')
	return createHash('sha256').update(`${url.origin}\n${environment.NEXTCLOUD_CONTAINER || 'canonical'}`).digest('hex').slice(0, 24)
}

module.exports = { configuredTarget, assertTargetIdentity, assertTestTarget, authScope, executeReadOnly }
