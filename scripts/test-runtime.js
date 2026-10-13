#!/usr/bin/env node
/** Frozen candidate execution is deliberately separate from read-only discovery. */
const fs = require('node:fs')
const path = require('node:path')
const crypto = require('node:crypto')
const { spawnSync } = require('node:child_process')

const ROOT = path.resolve(__dirname, '..')
const INVENTORY_PATH = path.join(ROOT, 'docker/test-runtime.json')
const CANONICAL_CONTAINER = 'chorestrike-test'
const CANONICAL_DB_CONTAINER = 'chorestrike-db'
const VERSION = /^\d+\.\d+\.\d+$/
const REVISION = /^[a-f0-9]{40}$/
const DIGEST = /^sha256:[a-f0-9]{64}$/
const RUNTIME_FIELDS = "'nextcloud' => $OC_VersionString, 'internalVersion' => implode('.', $OC_Version),"
	+ "'revision' => trim(shell_exec('git -C /var/www/html rev-parse HEAD')),"
	+ "'notificationsRevision' => trim(shell_exec('git -C /var/www/html/apps/notifications rev-parse HEAD')),"
	+ "'viewerRevision' => trim(shell_exec('git -C /var/www/html/apps/viewer rev-parse HEAD')),"
	+ "'php' => PHP_VERSION, 'sqlite' => (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn(),"
	+ "'pcov' => phpversion('pcov'), 'apcu' => phpversion('apcu'), 'phpredis' => phpversion('redis')"

function requireValue(condition, field) {
	if (!condition) throw new Error(`Invalid runtime inventory: ${field}`)
}

function validateRuntimeShape(value) {
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
	requireValue(value.notifications?.ref === value.nextcloud.ref && REVISION.test(value.notifications?.revision), 'notifications')
	requireValue(value.viewer?.ref === value.nextcloud.ref && REVISION.test(value.viewer?.revision), 'viewer')
	if (value.patches !== undefined) {
		requireValue(Object.keys(value.patches).sort().join() === 'previousNotifications,previousViewer', 'patches')
		requireValue(value.patches.previousNotifications?.ref === `v${value.previous.version}`
			&& REVISION.test(value.patches.previousNotifications?.revision), 'patches.previousNotifications')
		requireValue(value.patches.previousViewer?.ref === `v${value.previous.version}`
			&& REVISION.test(value.patches.previousViewer?.revision), 'patches.previousViewer')
	}
	for (const [key, prefix, versionPattern] of [
		['php', 'php', VERSION], ['composer', 'composer', VERSION],
		['databases.mysql', 'mariadb', VERSION], ['databases.pgsql', 'postgres', /^\d+\.\d+$/],
		['cache.redis', 'redis', VERSION],
	]) {
		const image = key.split('.').reduce((item, part) => item?.[part], value)
		requireValue(versionPattern.test(image?.version), `${key}.version`)
		requireValue(typeof image?.image === 'string'
			&& new RegExp(`^${prefix}:${image.version.replaceAll('.', '\\.')}(-[a-z0-9.-]+)?$`).test(image.image), `${key}.image`)
		requireValue(DIGEST.test(image?.digest) && DIGEST.test(image?.platformDigest), `${key}.digest`)
	}
	requireValue(VERSION.test(value.databases?.sqlite?.version), 'databases.sqlite.version')
	for (const key of ['pcov', 'apcu', 'phpredis', 'phpunit', 'codingStandard']) requireValue(VERSION.test(value.tools?.[key]), `tools.${key}`)
	requireValue(VERSION.test(value.aio?.version) && VERSION.test(value.aio?.nextcloudVersion), 'aio')
	return value
}

function validateInventory(value) {
	validateRuntimeShape(value)
	requireValue(value.previous.version.split('.').slice(0, 2).join('.') === value.nextcloud.version.split('.').slice(0, 2).join('.')
		&& compareVersions(value.previous.version, value.nextcloud.version) < 0, 'previous must be a real older same-major patch')
	if (value.compatibility !== undefined) {
		requireValue(Object.keys(value.compatibility).join() === 'aio', 'compatibility rows')
		const row = value.compatibility.aio
		requireValue(row?.nextcloud?.version === value.aio.nextcloudVersion, 'compatibility.aio bundle')
		requireValue(Object.keys(row).sort().join() === 'nextcloud,notifications,patches,previous,viewer', 'compatibility.aio fields')
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
	const { compatibility, patches, ...runtime } = inventory
	if (row === 'primary') return validateRuntimeShape(runtime)
	const { patches: rowPatches, ...rowRuntime } = compatibility[row]
	return validateRuntimeShape({ ...runtime, ...rowRuntime })
}

/** Exact P2 update-source selection: the row's real older patch with its own app pins. */
function selectRuntimeSource(inventory, row = 'primary', patch = 'current') {
	const runtime = selectRuntime(inventory, row)
	requireValue(['current', 'previous'].includes(patch), 'patch selector')
	if (patch === 'current') return runtime
	const patches = row === 'primary' ? inventory.patches : inventory.compatibility?.[row]?.patches
	requireValue(patches?.previousNotifications?.ref === `v${runtime.previous.version}`
		&& REVISION.test(patches.previousNotifications?.revision)
		&& patches?.previousViewer?.ref === `v${runtime.previous.version}`
		&& REVISION.test(patches.previousViewer?.revision), 'previous app pins')
	return validateRuntimeShape({ ...runtime, nextcloud: runtime.previous,
		notifications: patches.previousNotifications, viewer: patches.previousViewer })
}

/** Stable identity hash of an exact (row/patch-)selected runtime. */
function selectionHash(selection) {
	requireValue(selection?.compatibility === undefined && selection?.patches === undefined, 'exact selected runtime')
	return crypto.createHash('sha256').update(JSON.stringify(validateRuntimeShape(selection))).digest('hex')
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

/** Fail-tolerant probe for assertions that inspect non-zero exits themselves. */
function probe(command, args) {
	const result = spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', timeout: 120000, maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] })
	if (result.error) throw new Error('Runtime probe execution failed')
	return { status: result.status, stdout: result.stdout, stderr: result.stderr }
}

const PLATFORM_PROBE_NOTES = {
	core: 'No vendor dir present, checking non-dev platform requirements from the lock file',
	'3rdparty': 'Checking non-dev platform requirements for packages in the vendor dir',
}

/**
 * Exact recorded upstream artifact conditions (RF-082-02/RF-082-04 dispositions),
 * keyed by row and patch. The current entries are the operator-approved candidate
 * dispositions; the previous entries are the recorded P2 update-source artifact
 * states of the same named upstream shapes. A failure listed here is a named,
 * visible upstream packaging limitation, never a passed dependency gate; any
 * deviation (extra, different or missing entries) fails closed so the disposition
 * is re-reviewed instead of drifting silently.
 */
const UPSTREAM_BUILD_CONDITIONS = {
	primary: {
		current: {
			'3rdpartyFailures': [],
			notificationsWarnings: ['Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.'],
		},
		previous: {
			'3rdpartyFailures': [],
			notificationsWarnings: ['Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.'],
		},
	},
	aio: {
		current: {
			'3rdpartyFailures': [{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }],
			notificationsWarnings: [],
		},
		previous: {
			'3rdpartyFailures': [{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }],
			notificationsWarnings: [],
		},
	},
}

function rowConditions(row, patch = 'current') {
	const conditions = UPSTREAM_BUILD_CONDITIONS[row]?.[patch]
	if (!conditions) throw new Error(`Unknown runtime row/patch for upstream conditions: ${row}/${patch}`)
	return conditions
}

async function fetchMetadata(url) {
	const response = await fetch(url, { signal: AbortSignal.timeout(30000), headers: { 'User-Agent': 'ChoreStrike-runtime-check' } })
	if (!response.ok) throw new Error('Official runtime metadata unavailable')
	return response.text()
}

/**
 * Versions of the AIO subcomponents the app runs against, parsed from the
 * official AIO container sources of one release tag. Every value must parse;
 * unknown/malformed metadata fails closed instead of reporting "current".
 */
async function aioComponents(io, aio, nextcloudFile) {
	const source = file => io.fetchMetadata(`https://raw.githubusercontent.com/nextcloud/all-in-one/v${aio}/Containers/${file}/Dockerfile`)
	const [redisFile, postgresFile] = [await source('redis'), await source('postgresql')]
	const components = {
		php: nextcloudFile.match(/^FROM php:(\d+\.\d+\.\d+)-fpm\S*\s*$/m)?.[1],
		apcu: nextcloudFile.match(/pecl install (?:-\S+ )*APCu-(\d+\.\d+\.\d+);/)?.[1],
		phpredis: nextcloudFile.match(/pecl install (?:-\S+ )*(?:'[^']*' )?redis-(\d+\.\d+\.\d+);/)?.[1],
		redis: redisFile.match(/^FROM redis:(\d+\.\d+\.\d+)-alpine\S*\s*$/m)?.[1],
		postgres: postgresFile.match(/^FROM postgres:(\d+\.\d+)-alpine\S*\s*$/m)?.[1],
	}
	for (const [name, version] of Object.entries(components)) requireValue(/^\d+\.\d+(\.\d+)?$/.test(version ?? ''), `AIO ${name} metadata`)
	return components
}

/** Drift labels for AIO subcomponents that differ from the frozen inventory. */
function aioComponentDrift(inventory, components) {
	const expected = {
		php: inventory.php.version, apcu: inventory.tools.apcu, phpredis: inventory.tools.phpredis,
		redis: inventory.cache.redis.version, postgres: inventory.databases.pgsql.version,
	}
	return Object.keys(expected).filter(name => components[name] !== expected[name]).map(name => `aio-${name}`)
}

async function checkStable(inventory, io = { fetchMetadata, execute }) {
	validateInventory(inventory)
	const upstream = stableRelease(JSON.parse(await io.fetchMetadata('https://api.github.com/repos/nextcloud/server/releases/latest')), 'Nextcloud')
	const aio = stableRelease(JSON.parse(await io.fetchMetadata('https://api.github.com/repos/nextcloud/all-in-one/releases/latest')), 'AIO')
	const aioFile = await io.fetchMetadata(`https://raw.githubusercontent.com/nextcloud/all-in-one/v${aio}/Containers/nextcloud/Dockerfile`)
	const aioNextcloud = aioFile.match(/^ENV NEXTCLOUD_VERSION=(\d+\.\d+\.\d+)\s*$/m)?.[1]
	requireValue(VERSION.test(aioNextcloud), 'AIO bundled Nextcloud metadata')
	const components = await aioComponents(io, aio, aioFile)
	const drift = []
	if (upstream !== inventory.nextcloud.version) drift.push('upstream-nextcloud')
	if (aio !== inventory.aio.version || aioNextcloud !== inventory.aio.nextcloudVersion) drift.push('aio-bundle')
	drift.push(...aioComponentDrift(inventory, components))
	for (const [repository, release] of [
		['server', inventory.nextcloud], ['server', inventory.previous], ['notifications', inventory.notifications],
		['notifications', inventory.patches?.previousNotifications], ['viewer', inventory.viewer],
		['viewer', inventory.patches?.previousViewer],
		...Object.values(inventory.compatibility || {}).flatMap(row => [
			['server', row.nextcloud], ['server', row.previous], ['notifications', row.notifications],
			['notifications', row.patches?.previousNotifications], ['viewer', row.viewer],
			['viewer', row.patches?.previousViewer],
		]),
	]) {
		if (!release) continue
		const output = io.execute('git', ['ls-remote', `https://github.com/nextcloud/${repository}.git`, `refs/tags/${release.ref}`, `refs/tags/${release.ref}^{}`])
		if (tagRevision(output, release.ref) !== release.revision) drift.push(`moved-${repository}-${release.ref}`)
	}
	for (const [name, image] of Object.entries({ php: inventory.php, composer: inventory.composer, mysql: inventory.databases.mysql, pgsql: inventory.databases.pgsql, redis: inventory.cache.redis })) {
		const current = registryIdentity(JSON.parse(io.execute('docker', ['buildx', 'imagetools', 'inspect', image.image, '--format', '{{json .Manifest}}'])), inventory.platform)
		if (current.digest !== image.digest || current.platformDigest !== image.platformDigest) drift.push(`moved-${name}-image`)
	}
	return { status: drift.length ? 'update-needed' : 'current', upstream, aio, aioNextcloud, aioComponents: components, drift, qualification: inventory.status }
}

function inventoryHash(inventory) {
	return selectionHash(selectRuntime(inventory))
}

function buildArguments(selection, imageName) {
	validateRuntimeShape(selection)
	requireValue(/^cs082-runtime-[a-z0-9.-]+$/.test(imageName), 'owned candidate image name')
	const values = {
		PHP_IMAGE: `${selection.php.image}@${selection.php.digest}`,
		COMPOSER_IMAGE: `${selection.composer.image}@${selection.composer.digest}`,
		NEXTCLOUD_REF: selection.nextcloud.ref,
		NEXTCLOUD_REVISION: selection.nextcloud.revision,
		NOTIFICATIONS_REF: selection.notifications.ref,
		NOTIFICATIONS_REVISION: selection.notifications.revision,
		VIEWER_REF: selection.viewer.ref,
		VIEWER_REVISION: selection.viewer.revision,
		PCOV_VERSION: selection.tools.pcov,
		APCU_VERSION: selection.tools.apcu,
		PHPREDIS_VERSION: selection.tools.phpredis,
		RUNTIME_INVENTORY_SHA256: selectionHash(selection),
	}
	return ['build', '--platform', selection.platform, '--file', 'docker/Dockerfile.test', '--tag', imageName,
		...Object.entries(values).flatMap(([key, value]) => ['--build-arg', `${key}=${value}`]), '.']
}

function assertInstalled(selection, actual) {
	validateRuntimeShape(selection)
	const expected = {
		nextcloud: selection.nextcloud.version, internalVersion: selection.nextcloud.internalVersion,
		revision: selection.nextcloud.revision, notificationsRevision: selection.notifications.revision,
		viewerRevision: selection.viewer.revision,
		php: selection.php.version, sqlite: selection.databases.sqlite.version,
		pcov: selection.tools.pcov, apcu: selection.tools.apcu, phpredis: selection.tools.phpredis,
		inventoryHash: selectionHash(selection),
	}
	for (const [key, value] of Object.entries(expected)) {
		if (actual?.[key] !== value) throw new Error(`Installed runtime mismatch: ${key}`)
	}
	return expected
}

const NOTIFICATIONS_LOCK_COMPARE = "$l = json_decode(file_get_contents('/var/www/html/apps/notifications/composer.lock'), true);"
	+ "$i = json_decode(file_get_contents('/var/www/html/apps/notifications/vendor/composer/installed.json'), true);"
	+ "$f = function ($p) { return $p['name'] . ' ' . $p['version']; };"
	+ "$a = array_map($f, $l['packages']); $b = array_map($f, $i['packages']); sort($a); sort($b);"
	+ "echo json_encode(['matches' => $a === $b, 'locked' => count($a), 'installed' => count($b)]);"

function assertPlatformGate(row, imageId, probeFn = probe, patch = 'current') {
	const conditions = rowConditions(row, patch)
	for (const [name, directory] of [['core', '/var/www/html'], ['3rdparty', '/var/www/html/3rdparty']]) {
		const result = probeFn('docker', ['run', '--rm', '--network', 'none', imageId, 'sh', '-c',
			`cd ${directory} && composer check-platform-reqs --no-dev --format=json`])
		if (result.stderr.trim() !== PLATFORM_PROBE_NOTES[name]) throw new Error(`Candidate ${name} platform probe emitted unexpected diagnostics`)
		let rows
		try { rows = JSON.parse(result.stdout) } catch { throw new Error(`Candidate ${name} platform probe malformed`) }
		if (!Array.isArray(rows) || rows.length === 0) throw new Error(`Candidate ${name} platform probe malformed`)
		const failures = rows.filter(entry => entry?.status !== 'success').map(entry => ({
			name: entry?.name, source: entry?.failed_requirement?.source, constraint: entry?.failed_requirement?.constraint,
		}))
		const expected = name === '3rdparty' ? conditions['3rdpartyFailures'] : []
		if (result.status !== (expected.length ? 1 : 0) || JSON.stringify(failures) !== JSON.stringify(expected)) {
			throw new Error(`Candidate ${name} platform gate mismatch: ${JSON.stringify(failures)}`)
		}
	}
	return conditions['3rdpartyFailures']
}

function assertNotificationsBuild(row, imageId, probeFn = probe, patch = 'current') {
	const conditions = rowConditions(row, patch)
	const locked = probeFn('docker', ['run', '--rm', '--network', 'none', imageId, 'php', '-r', NOTIFICATIONS_LOCK_COMPARE])
	if (locked.status !== 0 || locked.stderr.trim()) throw new Error('Candidate notifications lock probe failed or emitted diagnostics')
	let sets
	try { sets = JSON.parse(locked.stdout) } catch { throw new Error('Candidate notifications lock probe malformed') }
	if (sets?.matches !== true || !Number.isInteger(sets.locked) || sets.locked === 0) {
		throw new Error('Candidate notifications installed vendor diverges from the upstream lock')
	}
	const warnings = probeFn('docker', ['run', '--rm', '--network', 'none', imageId, 'bash', '-o', 'pipefail', '-c',
		"cd /var/www/html/apps/notifications && composer install --no-dev --no-interaction --prefer-dist --dry-run 2>&1 | { grep '^Warning:' || true; }"])
	if (warnings.status !== 0 || warnings.stderr.trim()) throw new Error('Candidate notifications build probe failed or emitted diagnostics')
	const lines = warnings.stdout.split(/\r?\n/).filter(Boolean)
	if (JSON.stringify(lines) !== JSON.stringify(conditions.notificationsWarnings)) {
		throw new Error(`Candidate notifications build warnings mismatch: ${JSON.stringify(lines)}`)
	}
	for (const line of lines) process.stderr.write(`${line}\n`)
	return { lockedPackages: sets.locked, warnings: lines }
}

function verifyImage(selection, imageName, run = execute, row = 'primary', probeFn = probe, patch = 'current') {
	buildArguments(selection, imageName)
	const image = JSON.parse(run('docker', ['image', 'inspect', imageName]))[0]
	if (`${image?.Os}/${image?.Architecture}` !== selection.platform
		|| !DIGEST.test(image?.Id)
		|| image?.Config?.Labels?.['org.chorestrike.test-runtime.inventory'] !== selectionHash(selection)) {
		throw new Error('Candidate image inventory/platform mismatch')
	}
	const code = `require '/var/www/html/version.php'; echo json_encode([${RUNTIME_FIELDS}]);`
	const actual = JSON.parse(run('docker', ['run', '--rm', '--network', 'none', '--platform', selection.platform, image.Id, 'php', '-r', code]))
	actual.inventoryHash = image.Config.Labels['org.chorestrike.test-runtime.inventory']
	assertInstalled(selection, actual)
	const autoload = "require '/var/www/html/lib/composer/autoload.php'; require '/var/www/html/3rdparty/autoload.php';"
		+ "require '/var/www/html/apps/notifications/composer/autoload.php'; require '/var/www/html/apps/viewer/composer/autoload.php';"
		+ "if (!class_exists('OCA\\\\Notifications\\\\AppInfo\\\\Application') || !class_exists('OCA\\\\Viewer\\\\AppInfo\\\\Application')) { exit(1); } echo 'notifications-ready';"
	if (run('docker', ['run', '--rm', '--network', 'none', image.Id, 'php', '-r', autoload]) !== 'notifications-ready') {
		throw new Error('Candidate notifications runtime autoload mismatch')
	}
	// Composer's version command reports these two informational lines on stderr.
	const composer = run('docker', ['run', '--rm', '--network', 'none', image.Id, 'composer', '--version', '--no-ansi'],
		`PHP version ${selection.php.version} (/usr/local/bin/php)\nRun the "diagnose" command to get more detailed diagnostics output.`)
	if (composer.match(/Composer version (\d+\.\d+\.\d+)/)?.[1] !== selection.composer.version) throw new Error('Installed runtime mismatch: composer')
	const platformFailures = assertPlatformGate(row, image.Id, probeFn, patch)
	const notifications = assertNotificationsBuild(row, image.Id, probeFn, patch)
	const upstreamLimitations = [
		...platformFailures.map(failure => `${selection.nextcloud.ref}-3rdparty-${failure.source}-${failure.constraint}-php-${selection.php.version}`),
		...notifications.warnings.map(() => `${selection.notifications.ref}-notifications-stale-root-lock`),
	]
	return { status: 'frozen-image-identity-match', qualification: selection.status, ...actual, notificationsAutoload: true,
		notificationsLockedPackages: notifications.lockedPackages, composer: selection.composer.version, imageId: image.Id, upstreamLimitations }
}

/**
 * R7 canonical check: the documented default stack (docker/compose.test.yml,
 * chorestrike-test/chorestrike-db on the PostgreSQL reference provider) must be
 * running the frozen primary selection — image label, frozen runtime
 * environment, owned PostgreSQL layout and installed app/provider/database
 * identity all asserted read-only before any seed/reset/job mutation.
 */
function verifyCanonical(selection, run = execute, preflight) {
	validateRuntimeShape(selection)
	// Lazy require: test-target.js already depends on this module.
	const assertTestTarget = preflight ?? require('./test-target').assertTestTarget
	const url = 'http://localhost:8080'
	assertTestTarget(url, { NEXTCLOUD_URL: url, NEXTCLOUD_CONTAINER: CANONICAL_CONTAINER })
	const container = JSON.parse(run('docker', ['inspect', CANONICAL_CONTAINER]))[0]
	if (container?.Config?.Labels?.['org.chorestrike.test-runtime.inventory'] !== selectionHash(selection)
		|| container?.Config?.Env?.includes(`TEST_RUNTIME_INTERNAL_VERSION=${selection.nextcloud.internalVersion}`) !== true
		|| container?.Config?.Env?.includes(`TEST_RUNTIME_PHP_VERSION=${selection.php.version}`) !== true
		|| container?.State?.Running !== true) {
		throw new Error('Canonical container frozen-runtime mismatch')
	}
	if (!['config', 'data'].every(directory => container.Mounts?.some(mount => mount.Type === 'volume'
		&& mount.Destination === `/var/www/html/${directory}`))) {
		throw new Error('Canonical config/data persistence mismatch')
	}
	const database = selection.databases.pgsql
	const db = JSON.parse(run('docker', ['inspect', CANONICAL_DB_CONTAINER]))[0]
	if (db?.Config?.Image !== `${database.image}@${database.digest}`
		|| db?.Config?.Env?.includes(`PGDATA=/var/lib/postgresql/${database.version.split('.')[0]}/docker`) !== true
		|| db?.State?.Running !== true
		|| !db.Mounts?.some(mount => mount.Type === 'volume' && mount.Destination === '/var/lib/postgresql')
		|| db.Mounts?.some(mount => mount.Destination === '/var/lib/postgresql/data')) {
		throw new Error('Canonical PostgreSQL image/PGDATA/volume mismatch')
	}
	const code = "require '/var/www/html/version.php'; require '/var/www/html/lib/base.php';"
		+ "$cfg = \\OC::$server->get(\\OC\\SystemConfig::class);"
		+ "$dbtype = $cfg->getValue('dbtype'); $db = \\OC::$server->get(\\OCP\\IDBConnection::class); $params = $db->getInner()->getParams();"
		+ "$matches = ($dbtype === 'pgsql' && ($params['host'] ?? null) === '" + CANONICAL_DB_CONTAINER + "'"
		+ " && (string) ($params['port'] ?? '') === '5432' && ($params['dbname'] ?? null) === 'nextcloud');"
		+ "if (!$matches) { fwrite(STDERR, 'Canonical database target mismatch.'); exit(1); }"
		+ "$qb = $db->getQueryBuilder(); $result = $qb->select($qb->createFunction(\"current_setting('server_version')\"))->executeQuery();"
		+ "$version = $result->fetchOne(); $result->closeCursor();"
		+ `echo json_encode([${RUNTIME_FIELDS}, 'configuredVersion' => $cfg->getValue('version'), 'provider' => $dbtype,`
		+ "'databaseVersion' => $version, 'databaseTargetMatches' => $matches,"
		+ "'enabled' => \\OC::$server->get(\\OCP\\App\\IAppManager::class)->isEnabledForUser('chorestrike')]);"
	const actual = JSON.parse(run('docker', ['exec', '--user', 'www-data', CANONICAL_CONTAINER, 'php', '-r', code]))
	actual.inventoryHash = container.Config.Labels['org.chorestrike.test-runtime.inventory']
	assertInstalled(selection, actual)
	if (actual.configuredVersion !== selection.nextcloud.internalVersion || actual.enabled !== true || actual.provider !== 'pgsql'
		|| actual.databaseVersion.match(/^\d+\.\d+(?:\.\d+)?/)?.[0] !== database.version) {
		throw new Error('Canonical installed app/provider/database version mismatch')
	}
	return { status: 'canonical-runtime-match', qualification: selection.status, url, ...actual }
}

async function main(args) {
	let selector = 'primary'
	let patch = 'current'
	args = args.filter(arg => {
		if (arg.startsWith('--runtime=')) { selector = arg.slice('--runtime='.length); return false }
		if (arg.startsWith('--patch=')) { patch = arg.slice('--patch='.length); return false }
		return true
	})
	if (!['current', 'previous'].includes(patch)) throw new Error('Patch selector must be current or previous')
	if (args[0] === 'check' && (selector !== 'primary' || patch !== 'current')) {
		throw new Error('Discovery always checks the primary and all compatibility rows')
	}
	if (args.length === 1 && args[0] === 'validate') {
		if (patch !== 'current') throw new Error('Inventory validation covers the recorded current candidate')
		const inventory = readInventory(selector)
		process.stdout.write(`${JSON.stringify({ status: 'valid', qualification: inventory.status, inventoryHash: inventoryHash(inventory) })}\n`)
	} else if (args.length === 1 && args[0] === 'check') {
		const result = await checkStable(readInventory())
		process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
		process.exitCode = result.status === 'current' ? 0 : 2
	} else if (args.length === 1 && args[0] === 'selection') {
		const selection = selectRuntimeSource(readInventory(), selector, patch)
		process.stdout.write(`${JSON.stringify({ status: 'selection', row: selector, patch, inventoryHash: selectionHash(selection),
			nextcloud: selection.nextcloud.version, internalVersion: selection.nextcloud.internalVersion,
			revision: selection.nextcloud.revision, notifications: selection.notifications, php: selection.php.version,
			qualification: selection.status }, null, 2)}\n`)
	} else if (args.length === 1 && args[0] === 'verify-canonical') {
		if (selector !== 'primary' || patch !== 'current') throw new Error('Canonical verification covers the primary current selection')
		process.stdout.write(`${JSON.stringify(verifyCanonical(selectRuntimeSource(readInventory(), 'primary', 'current')), null, 2)}\n`)
	} else if (args.length === 2 && ['build', 'verify-image'].includes(args[0])) {
		const selection = selectRuntimeSource(readInventory(), selector, patch)
		if (args[0] === 'build') {
			const result = spawnSync('docker', buildArguments(selection, args[1]), { cwd: ROOT, stdio: 'inherit' })
			if (result.error || result.status !== 0) throw new Error('Candidate runtime build failed')
		} else {
			process.stdout.write(`${JSON.stringify(verifyImage(selection, args[1], execute, selector, probe, patch), null, 2)}\n`)
		}
	} else {
		throw new Error('Usage: node scripts/test-runtime.js validate|check|selection|verify-canonical|build|verify-image [cs082-runtime-<name>] [--runtime=primary|aio] [--patch=current|previous]')
	}
}

// The CLI entry point runs AFTER module.exports below: verify-canonical
// lazily requires ./test-target, which requires this module back — invoking
// main() before the exports are replaced would re-enter a circular require.
module.exports = { validateInventory, validateRuntimeShape, readInventory, selectRuntime, selectRuntimeSource, selectionHash, stableRelease, tagRevision, registryIdentity, aioComponents, aioComponentDrift, checkStable, inventoryHash, buildArguments, assertInstalled, assertPlatformGate, assertNotificationsBuild, verifyImage, verifyCanonical, execute, probe, UPSTREAM_BUILD_CONDITIONS, RUNTIME_FIELDS, CANONICAL_CONTAINER, CANONICAL_DB_CONTAINER }

if (require.main === module) main(process.argv.slice(2)).catch((error) => {
	process.stderr.write(`Runtime check/execution failed: ${error?.message ?? 'unknown'}; inventory and environments were not updated.\n`)
	process.exitCode = 3
})
