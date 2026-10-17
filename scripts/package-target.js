/** Package-only target policy. The ordinary true-marker test guard is unchanged. */
'use strict'

const path = require('node:path')
const { spawnSync } = require('node:child_process')

// Owner-smoke case matrix (TECH-PKG-001): the single source for the smoke
// itself (tests/package/owner-smoke.cjs) and both evidence validators
// (run-package-smoke.js, export-package.js). One cold setup case; verify runs
// every language at every width.
const OWNER_SMOKE_LANGUAGES = Object.freeze(['de-DE', 'de', 'en'])
const OWNER_SMOKE_WIDTHS = Object.freeze([390, 1440])
const OWNER_SMOKE_CASES = Object.freeze({ setup: 1, verify: OWNER_SMOKE_LANGUAGES.length * OWNER_SMOKE_WIDTHS.length })

function validatePackageTarget(container, target, extractDir) {
	const labels = container?.Config?.Labels ?? {}
	if (labels['org.chorestrike.package-smoke'] !== 'true') throw new Error('Package target is missing the package-smoke label')
	if (!/^cs082-[a-z0-9-]+$/.test(target.project) || labels['com.docker.compose.project'] !== target.project) throw new Error('Package target project ownership mismatch')
	if (target.container !== `${target.project}-nextcloud-pgsql-1` || labels['com.docker.compose.service'] !== 'nextcloud-pgsql') throw new Error('Package target service mismatch')
	if (container?.State?.Health?.Status !== 'healthy') throw new Error('Package target is not healthy')
	if (container?.Image !== target.image) throw new Error('Package target frozen image mismatch')
	const environment = container?.Config?.Env ?? []
	for (const key of ['NEXTCLOUD_TEST_MODE', 'NEXTCLOUD_TEST_ENV']) {
		if (!environment.includes(`${key}=false`) || environment.some(value => value.startsWith(`${key}=`) && value !== `${key}=false`)) throw new Error('Package target must run with both test markers false')
	}
	const url = new URL(target.url)
	if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || url.pathname !== '/' || url.username || url.password || url.search || url.hash) throw new Error('Package target URL must be a credential-free numeric loopback origin')
	const ports = container?.NetworkSettings?.Ports ?? {}
	const bindings = Object.values(ports).flat().filter(Boolean)
	if (!bindings.length || bindings.some(binding => binding.HostIp !== '127.0.0.1')) throw new Error('Package target must publish exclusively on numeric IPv4 loopback')
	if (!(ports['80/tcp'] ?? []).some(binding => binding.HostIp === '127.0.0.1' && binding.HostPort === url.port)) throw new Error('Package target HTTP port mismatch')
	const normalize = value => {
		const normalized = value.replaceAll('\\', '/').replace(/^\/run\/desktop\/mnt\/host\/([a-z])\//i, '$1:/')
		return /^[a-z]:\//i.test(normalized) ? normalized.toLowerCase() : normalized
	}
	const mount = (container?.Mounts ?? []).find(entry => entry.Destination === '/var/www/html/custom_apps/chorestrike')
	if (!mount || mount.RW !== false || normalize(mount.Source) !== normalize(path.resolve(extractDir))) throw new Error('Package target must mount the exact extracted archive read-only')
	return { url: url.origin, container: target.container, project: target.project }
}

function validatePackageStatus(cli, http, internalVersion) {
	if (!/^\d+\.\d+\.\d+\.\d+$/.test(internalVersion)) throw new Error('Package target requires a frozen internal version')
	for (const status of [cli, http]) {
		if (status.installed !== true || status.maintenance !== false || status.needsDbUpgrade !== false || status.version !== internalVersion) throw new Error('Package smoke HTTP/OCC runtime coherence mismatch')
	}
}

function assertPackageCoherence(target, internalVersion, invoke = (command, args) => spawnSync(command, args, { encoding: 'utf8', timeout: 15000 })) {
	const read = (command, args) => {
		const result = invoke(command, args)
		if (result.status !== 0 || result.signal || result.error) throw new Error('Package smoke HTTP/OCC preflight failed')
		return JSON.parse(result.stdout)
	}
	const cli = read('docker', ['exec', '--user', 'www-data', target.container, 'php', '/var/www/html/occ', 'status', '--output=json'])
	const http = read('curl', ['--fail', '--silent', '--show-error', '--max-time', '10', '--noproxy', '*', `${target.url}/status.php`])
	validatePackageStatus(cli, http, internalVersion)
}

module.exports = { OWNER_SMOKE_LANGUAGES, OWNER_SMOKE_WIDTHS, OWNER_SMOKE_CASES, validatePackageTarget, validatePackageStatus, assertPackageCoherence }
