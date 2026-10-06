#!/usr/bin/env node
/** Frozen candidate execution is deliberately separate from read-only discovery. */
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const { spawnSync } = require('node:child_process')

const ROOT = path.resolve(__dirname, '..')
const INVENTORY_PATH = path.join(ROOT, 'docker/test-runtime.json')
const VERSION = /^\d+\.\d+\.\d+$/
const REVISION = /^[a-f0-9]{40}$/
const DIGEST = /^sha256:[a-f0-9]{64}$/
const RUNTIME_FIELDS = "'nextcloud' => $OC_VersionString, 'internalVersion' => implode('.', $OC_Version),"
	+ "'revision' => trim(shell_exec('git -C /var/www/html rev-parse HEAD')),"
	+ "'notificationsRevision' => trim(shell_exec('git -C /var/www/html/apps/notifications rev-parse HEAD')),"
	+ "'php' => PHP_VERSION, 'sqlite' => (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn(),"
	+ "'pcov' => phpversion('pcov'), 'apcu' => phpversion('apcu')"

function requireValue(condition, field) {
	if (!condition) throw new Error(`Invalid runtime inventory: ${field}`)
}

function validateInventory(value) {
	requireValue(value?.schemaVersion === 1, 'schemaVersion')
	requireValue(['candidate', 'qualified'].includes(value.status), 'status')
	requireValue(value.platform === 'linux/amd64', 'platform')
	requireValue(/^\d{4}-\d{2}-\d{2}$/.test(value.verifiedAt)
		&& new Date(value.verifiedAt).toISOString().slice(0, 10) === value.verifiedAt, 'verifiedAt')
	for (const key of ['nextcloud', 'previous']) {
		const release = value[key]
		requireValue(VERSION.test(release?.version), `${key}.version`)
		requireValue(release?.ref === `v${release.version}`, `${key}.ref`)
		requireValue(REVISION.test(release?.revision), `${key}.revision`)
		requireValue(new RegExp(`^${release.version.replaceAll('.', '\\.')}\\.\\d+$`).test(release.internalVersion), `${key}.internalVersion`)
	}
	requireValue(value.previous.version.split('.').slice(0, 2).join('.') === value.nextcloud.version.split('.').slice(0, 2).join('.')
		&& compareVersions(value.previous.version, value.nextcloud.version) < 0, 'previous must be a real older same-major patch')
	requireValue(value.notifications?.ref === value.nextcloud.ref && REVISION.test(value.notifications?.revision), 'notifications')
	for (const [key, prefix, versionPattern] of [
		['php', 'php', VERSION], ['composer', 'composer', VERSION],
		['databases.mysql', 'mariadb', VERSION], ['databases.pgsql', 'postgres', /^\d+\.\d+$/],
	]) {
		const image = key.split('.').reduce((item, part) => item?.[part], value)
		requireValue(versionPattern.test(image?.version), `${key}.version`)
		requireValue(typeof image?.image === 'string'
			&& new RegExp(`^${prefix}:${image.version.replaceAll('.', '\\.')}(-[a-z0-9.-]+)?$`).test(image.image), `${key}.image`)
		requireValue(DIGEST.test(image?.digest) && DIGEST.test(image?.platformDigest), `${key}.digest`)
	}
	requireValue(VERSION.test(value.databases?.sqlite?.version), 'databases.sqlite.version')
	for (const key of ['pcov', 'apcu', 'phpunit', 'codingStandard']) requireValue(VERSION.test(value.tools?.[key]), `tools.${key}`)
	requireValue(VERSION.test(value.aio?.version) && VERSION.test(value.aio?.nextcloudVersion), 'aio')
	if (value.compatibility !== undefined) {
		requireValue(Object.keys(value.compatibility).join() === 'aio', 'compatibility rows')
		const row = value.compatibility.aio
		requireValue(row?.nextcloud?.version === value.aio.nextcloudVersion, 'compatibility.aio bundle')
		requireValue(Object.keys(row).sort().join() === 'nextcloud,notifications,previous', 'compatibility.aio fields')
		validateInventory({ ...value, ...row, compatibility: undefined })
	}
	return value
}

function readInventory(row) {
	const inventory = validateInventory(JSON.parse(fs.readFileSync(INVENTORY_PATH, 'utf8')))
	return row ? selectRuntime(inventory, row) : inventory
}

function selectRuntime(inventory, row = 'primary') {
	validateInventory(inventory)
	requireValue(['primary', 'aio'].includes(row), 'runtime selector')
	if (row !== 'primary') requireValue(inventory.compatibility?.[row], 'missing compatibility row')
	const { compatibility, ...runtime } = inventory
	return validateInventory({ ...runtime, ...(row === 'primary' ? {} : compatibility[row]) })
}

function compareVersions(left, right) {
	const a = left.split('.').map(Number)
	const b = right.split('.').map(Number)
	return Math.sign(a[0] - b[0] || a[1] - b[1] || a[2] - b[2])
}

function stableRelease(value, name) {
	requireValue(value?.draft === false && value?.prerelease === false
		&& /^v\d+\.\d+\.\d+$/.test(value?.tag_name), `${name} stable release metadata`)
	return value.tag_name.slice(1)
}

function tagRevision(output, ref) {
	const lines = output.trim().split(/\r?\n/).map(line => line.split(/\s+/))
	const revision = lines.find(([, name]) => name === `refs/tags/${ref}^{}`)?.[0]
		|| lines.find(([, name]) => name === `refs/tags/${ref}`)?.[0]
	requireValue(REVISION.test(revision), 'source tag response')
	return revision
}

function registryIdentity(manifest, platform) {
	const [os, architecture] = platform.split('/')
	const row = manifest?.manifests?.find(item => item.platform?.os === os && item.platform?.architecture === architecture)
	requireValue(DIGEST.test(manifest?.digest) && DIGEST.test(row?.digest), 'registry manifest/platform')
	return { digest: manifest.digest, platformDigest: row.digest }
}

function execute(command, args, expectedMetadata = '') {
	const result = spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', timeout: 60000, maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] })
	if (result.error || result.status !== 0 || (result.stderr.trim() && result.stderr.trim() !== expectedMetadata)) throw new Error('Read-only runtime probe failed or emitted diagnostics')
	if (result.stderr.trim()) process.stderr.write(result.stderr)
	return result.stdout
}

async function fetchMetadata(url) {
	const response = await fetch(url, { signal: AbortSignal.timeout(30000), headers: { 'User-Agent': 'ChoreStrike-runtime-check' } })
	if (!response.ok) throw new Error('Official runtime metadata unavailable')
	return response.text()
}

async function checkStable(inventory, io = { fetchMetadata, execute }) {
	validateInventory(inventory)
	const upstream = stableRelease(JSON.parse(await io.fetchMetadata('https://api.github.com/repos/nextcloud/server/releases/latest')), 'Nextcloud')
	const aio = stableRelease(JSON.parse(await io.fetchMetadata('https://api.github.com/repos/nextcloud/all-in-one/releases/latest')), 'AIO')
	const aioFile = await io.fetchMetadata(`https://raw.githubusercontent.com/nextcloud/all-in-one/v${aio}/Containers/nextcloud/Dockerfile`)
	const aioNextcloud = aioFile.match(/^ENV NEXTCLOUD_VERSION=(\d+\.\d+\.\d+)\s*$/m)?.[1]
	requireValue(VERSION.test(aioNextcloud), 'AIO bundled Nextcloud metadata')
	const drift = []
	if (upstream !== inventory.nextcloud.version) drift.push('upstream-nextcloud')
	if (aio !== inventory.aio.version || aioNextcloud !== inventory.aio.nextcloudVersion) drift.push('aio-bundle')
	for (const [repository, release] of [
		['server', inventory.nextcloud], ['server', inventory.previous], ['notifications', inventory.notifications],
		...Object.values(inventory.compatibility || {}).flatMap(row => [
			['server', row.nextcloud], ['server', row.previous], ['notifications', row.notifications],
		]),
	]) {
		const output = io.execute('git', ['ls-remote', `https://github.com/nextcloud/${repository}.git`, `refs/tags/${release.ref}`, `refs/tags/${release.ref}^{}`])
		if (tagRevision(output, release.ref) !== release.revision) drift.push(`moved-${repository}-${release.ref}`)
	}
	for (const [name, image] of Object.entries({ php: inventory.php, composer: inventory.composer, mysql: inventory.databases.mysql, pgsql: inventory.databases.pgsql })) {
		const current = registryIdentity(JSON.parse(io.execute('docker', ['buildx', 'imagetools', 'inspect', image.image, '--format', '{{json .Manifest}}'])), inventory.platform)
		if (current.digest !== image.digest || current.platformDigest !== image.platformDigest) drift.push(`moved-${name}-image`)
	}
	return { status: drift.length ? 'update-needed' : 'current', upstream, aio, aioNextcloud, drift, qualification: inventory.status }
}

function inventoryHash(inventory) {
	return crypto.createHash('sha256').update(JSON.stringify(selectRuntime(inventory))).digest('hex')
}

function buildArguments(inventory, imageName) {
	validateInventory(inventory)
	requireValue(/^cs082-runtime-[a-z0-9.-]+$/.test(imageName), 'owned candidate image name')
	const values = {
		PHP_IMAGE: `${inventory.php.image}@${inventory.php.digest}`,
		COMPOSER_IMAGE: `${inventory.composer.image}@${inventory.composer.digest}`,
		NEXTCLOUD_REF: inventory.nextcloud.ref,
		NEXTCLOUD_REVISION: inventory.nextcloud.revision,
		NOTIFICATIONS_REF: inventory.notifications.ref,
		NOTIFICATIONS_REVISION: inventory.notifications.revision,
		PCOV_VERSION: inventory.tools.pcov,
		APCU_VERSION: inventory.tools.apcu,
		RUNTIME_INVENTORY_SHA256: inventoryHash(inventory),
	}
	return ['build', '--platform', inventory.platform, '--file', 'docker/Dockerfile.test', '--tag', imageName,
		...Object.entries(values).flatMap(([key, value]) => ['--build-arg', `${key}=${value}`]), '.']
}

function assertInstalled(inventory, actual) {
	validateInventory(inventory)
	const expected = {
		nextcloud: inventory.nextcloud.version, internalVersion: inventory.nextcloud.internalVersion,
		revision: inventory.nextcloud.revision, notificationsRevision: inventory.notifications.revision,
		php: inventory.php.version, sqlite: inventory.databases.sqlite.version,
		pcov: inventory.tools.pcov, apcu: inventory.tools.apcu,
		inventoryHash: inventoryHash(inventory),
	}
	for (const [key, value] of Object.entries(expected)) {
		if (actual?.[key] !== value) throw new Error(`Installed runtime mismatch: ${key}`)
	}
	return expected
}

function verifyImage(inventory, imageName, run = execute) {
	buildArguments(inventory, imageName)
	const image = JSON.parse(run('docker', ['image', 'inspect', imageName]))[0]
	if (`${image?.Os}/${image?.Architecture}` !== inventory.platform
		|| !DIGEST.test(image?.Id)
		|| image?.Config?.Labels?.['org.chorestrike.test-runtime.inventory'] !== inventoryHash(inventory)) {
		throw new Error('Candidate image inventory/platform mismatch')
	}
	const code = `require '/var/www/html/version.php'; echo json_encode([${RUNTIME_FIELDS}]);`
	const actual = JSON.parse(run('docker', ['run', '--rm', '--network', 'none', '--platform', inventory.platform, image.Id, 'php', '-r', code]))
	actual.inventoryHash = image.Config.Labels['org.chorestrike.test-runtime.inventory']
	assertInstalled(inventory, actual)
	const autoload = "require '/var/www/html/lib/composer/autoload.php'; require '/var/www/html/3rdparty/autoload.php';"
		+ "require '/var/www/html/apps/notifications/composer/autoload.php';"
		+ "if (!class_exists('OCA\\\\Notifications\\\\AppInfo\\\\Application')) { exit(1); } echo 'notifications-ready';"
	if (run('docker', ['run', '--rm', '--network', 'none', image.Id, 'php', '-r', autoload]) !== 'notifications-ready') {
		throw new Error('Candidate notifications runtime autoload mismatch')
	}
	// Composer's version command reports these two informational lines on stderr.
	const composer = run('docker', ['run', '--rm', '--network', 'none', image.Id, 'composer', '--version', '--no-ansi'],
		`PHP version ${inventory.php.version} (/usr/local/bin/php)\nRun the "diagnose" command to get more detailed diagnostics output.`)
	if (composer.match(/Composer version (\d+\.\d+\.\d+)/)?.[1] !== inventory.composer.version) throw new Error('Installed runtime mismatch: composer')
	return { status: 'frozen-image-identity-match', qualification: inventory.status, ...actual, notificationsAutoload: true, composer: inventory.composer.version, imageId: image.Id }
}

async function main(args) {
	const selector = args.at(-1)?.startsWith('--runtime=') ? args.pop().slice('--runtime='.length) : 'primary'
	const inventory = args[0] === 'check' ? readInventory() : readInventory(selector)
	if (args[0] === 'check' && selector !== 'primary') throw new Error('Discovery always checks the primary and all compatibility rows')
	if (args.length === 1 && args[0] === 'validate') {
		process.stdout.write(`${JSON.stringify({ status: 'valid', qualification: inventory.status, inventoryHash: inventoryHash(inventory) })}\n`)
	} else if (args.length === 1 && args[0] === 'check') {
		const result = await checkStable(inventory)
		process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
		process.exitCode = result.status === 'current' ? 0 : 2
	} else if (args.length === 2 && args[0] === 'build') {
		const result = spawnSync('docker', buildArguments(inventory, args[1]), { cwd: ROOT, stdio: 'inherit' })
		if (result.error || result.status !== 0) throw new Error('Candidate runtime build failed')
	} else if (args.length === 2 && args[0] === 'verify-image') {
		process.stdout.write(`${JSON.stringify(verifyImage(inventory, args[1]), null, 2)}\n`)
	} else {
		throw new Error('Usage: node scripts/test-runtime.js validate|check|build|verify-image [cs082-runtime-<name>] [--runtime=primary|aio]')
	}
}

if (require.main === module) main(process.argv.slice(2)).catch((error) => {
	process.stderr.write(`Runtime check/execution failed: ${error?.message ?? 'unknown'}; inventory and environments were not updated.\n`)
	process.exitCode = 3
})

module.exports = { validateInventory, readInventory, selectRuntime, stableRelease, tagRevision, registryIdentity, checkStable, inventoryHash, buildArguments, assertInstalled, verifyImage, execute, RUNTIME_FIELDS }
