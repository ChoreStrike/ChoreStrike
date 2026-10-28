/** @jest-environment node */
const fs = require('node:fs')
const path = require('node:path')
const { validateInventory, validateRuntimeShape, selectRuntime, selectRuntimeSource, selectionHash, stableRelease, highestStableRelease, tagRevision, registryIdentity, checkStable, inventoryHash, buildArguments, assertInstalled, assertPlatformGate, assertNotificationsBuild, verifyImage, verifyCanonical, execute, UPSTREAM_BUILD_CONDITIONS } = require('../../../scripts/test-runtime')

const frozen = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../docker/test-runtime.json'), 'utf8'))
const copy = () => JSON.parse(JSON.stringify(frozen))
const release = version => ({ tag_name: `v${version}`, draft: false, prerelease: false })
// The release-list endpoints return arrays (highest stable version wins).
const releases = (...versions) => JSON.stringify(versions.map(release))

const STALE_LOCK_WARNING = 'Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.'
const LCOBUCCI_FAILURE = { name: 'php', version: '8.4.25', status: 'failed', failed_requirement: { source: 'lcobucci/clock', type: 'requires', target: 'php', constraint: '~8.1.0 || ~8.2.0' }, provider: null }

function probeFixtures(row = 'primary', overrides = {}) {
	return jest.fn((command, args) => {
		const line = args.join(' ')
		if (line.includes('check-platform-reqs')) {
			const thirdparty = line.includes('/var/www/html/3rdparty')
			const failures = row === 'aio' && thirdparty ? [LCOBUCCI_FAILURE] : []
			const response = {
				status: failures.length ? 1 : 0,
				stdout: JSON.stringify([{ name: 'php', version: '8.4.25', status: 'success', failed_requirement: null, provider: null }, ...failures]),
				stderr: thirdparty ? 'Checking non-dev platform requirements for packages in the vendor dir' : 'No vendor dir present, checking non-dev platform requirements from the lock file',
			}
			return { ...response, ...(overrides[thirdparty ? 'thirdparty' : 'core'] || {}) }
		}
		if (args.includes('-r')) return { status: 0, stdout: JSON.stringify({ matches: true, locked: 15, installed: 15 }), stderr: '', ...overrides.lock }
		if (args.includes('pipefail')) return { status: 0, stdout: row === 'primary' ? `${STALE_LOCK_WARNING}\n` : '', stderr: '', ...overrides.warnings }
		throw new Error(`unexpected probe: ${line}`)
	})
}

/** Official AIO container sources (shape of the v14.4.2 Dockerfiles) for one inventory. */
function aioDockerfile(url, inventory = frozen, overrides = {}) {
	const value = { nextcloud: inventory.aio.nextcloudVersion, php: inventory.php.version, apcu: inventory.tools.apcu,
		phpredis: inventory.tools.phpredis, redis: inventory.cache.redis.version, postgres: inventory.databases.pgsql.version, ...overrides }
	if (url.endsWith('/Containers/redis/Dockerfile')) return `# syntax=docker/dockerfile:latest\nFROM redis:${value.redis}-alpine\n`
	if (url.endsWith('/Containers/postgresql/Dockerfile')) return `# syntax=docker/dockerfile:latest\nFROM postgres:${value.postgres}-alpine\n`
	return `FROM php:${value.php}-fpm-alpine3.24\n\nARG IGBINARY_VERSION=3.2.16\nARG APCU_VERSION=${value.apcu}\n`
		+ `ARG MEMCACHED_VERSION=3.4.0\nARG REDIS_VERSION=${value.phpredis}\n\nENV NEXTCLOUD_VERSION=${value.nextcloud}\n`
		+ `    curl -fsSL --retry 5 "https://github.com/igbinary/igbinary/archive/refs/tags/$IGBINARY_VERSION.tar.gz" \\\n`
		+ `    curl -fsSL --retry 5 "https://github.com/krakjoe/apcu/archive/refs/tags/v$APCU_VERSION.tar.gz" \\\n`
		+ `    curl -fsSL --retry 5 "https://github.com/php-memcached-dev/php-memcached/archive/refs/tags/v$MEMCACHED_VERSION.tar.gz" \\\n`
		+ `    curl -fsSL --retry 5 "https://github.com/phpredis/phpredis/archive/refs/tags/$REDIS_VERSION.tar.gz" \\\n`
}

function officialFixtures(inventory = frozen) {
	return {
		fetchMetadata: jest.fn(async url => {
			if (url.includes('/server/releases')) return releases(inventory.nextcloud.version)
			if (url.includes('/all-in-one/releases')) return releases(inventory.aio.version)
			return aioDockerfile(url, inventory)
		}),
		execute: jest.fn((command, args) => {
			if (command === 'git') {
				const rows = [inventory, ...Object.values(inventory.compatibility || {})]
				const sources = rows.flatMap(row => {
					if (args[1].includes('notifications')) return [row.notifications, row.patches?.previousNotifications].filter(Boolean)
					if (args[1].includes('viewer')) return [row.viewer, row.patches?.previousViewer].filter(Boolean)
					return [row.nextcloud, row.previous]
				})
				const source = sources.find(item => args[2] === `refs/tags/${item.ref}`)
				return `${source.revision}\trefs/tags/${source.ref}\n`
			}
			const image = [inventory.php, inventory.composer, inventory.databases.mysql, inventory.databases.pgsql, inventory.cache.redis].find(item => item.image === args[3])
			return JSON.stringify({ digest: image.digest, manifests: [{ digest: image.platformDigest, platform: { os: 'linux', architecture: 'amd64' } }] })
		}),
	}
}

describe('frozen runtime inventory', () => {
	it('validates the checked-in qualified inventory without calling upstream services', () => {
		expect(validateInventory(frozen)).toBe(frozen)
		expect(frozen.status).toBe('qualified')
		expect(inventoryHash(frozen)).toMatch(/^[a-f0-9]{64}$/)
	})
	it.each([
		['schema', value => { value.schemaVersion = 2 }],
		['qualification', value => { value.status = 'green' }],
		['platform', value => { value.platform = 'linux/arm64' }],
		['date', value => { value.verifiedAt = '2026-02-31' }],
		['prerelease', value => { value.nextcloud.version += 'rc1' }],
		['internal version', value => { value.nextcloud.internalVersion = '31.0.0.1' }],
		['mutable ref', value => { value.nextcloud.ref = 'stable34' }],
		['source revision', value => { value.nextcloud.revision = 'HEAD' }],
		['fake previous patch', value => { value.previous = { ...value.nextcloud } }],
		['previous major', value => { value.previous.version = '33.0.4'; value.previous.ref = 'v33.0.4'; value.previous.internalVersion = '33.0.4.1' }],
		['notifications', value => { value.notifications.ref = 'stable34' }],
		['mutable image', value => { value.php.image = 'php:latest' }],
		['wrong image version', value => { value.php.image = 'php:8.2.0-apache' }],
		['image digest', value => { delete value.php.digest }],
		['database digest', value => { value.databases.pgsql.platformDigest = 'unknown' }],
		['unobserved SQLite', value => { value.databases.sqlite.version = null }],
		['tool', value => { delete value.tools.apcu }],
		['phpredis tool', value => { delete value.tools.phpredis }],
		['missing Redis cache', value => { delete value.cache }],
		['mutable Redis image', value => { value.cache.redis.image = 'redis:latest' }],
		['Redis digest', value => { value.cache.redis.platformDigest = 'unknown' }],
		['distribution', value => { value.aio.version = 'latest' }],
		['AIO compatibility identity', value => { value.compatibility.aio.nextcloud.version = '34.0.6' }],
		['AIO compatibility revision', value => { value.compatibility.aio.notifications.revision = 'HEAD' }],
		['unknown compatibility row', value => { value.compatibility.unknown = value.compatibility.aio }],
	])('rejects malformed %s before execution', (_, mutate) => {
		const value = copy()
		mutate(value)
		expect(() => validateInventory(value)).toThrow()
	})
	it('selects the explicit AIO runtime without changing the primary or its historical image identity', () => {
		const value = copy()
		const primary = selectRuntime(value)
		const aio = selectRuntime(value, 'aio')
		expect(primary.nextcloud.version).toBe('35.0.2')
		expect(aio.nextcloud.version).toBe('34.0.5')
		expect(aio.previous.version).toBe('34.0.4')
		expect(inventoryHash(aio)).toBe('704381b9a49544aea322d661f61af18284267a370bb4f44ed4fdfe60093fad82')
		expect(inventoryHash(primary)).not.toBe(inventoryHash(aio))
		expect(value).toEqual(frozen)
		expect(() => selectRuntime(value, 'unknown')).toThrow('runtime selector')
		expect(() => selectRuntime(primary, 'aio')).toThrow('missing compatibility row')
	})
	it.each([
		['forged previous notifications ref', value => { value.patches.previousNotifications.ref = 'v35.0.2' }],
		['forged previous notifications revision', value => { value.patches.previousNotifications.revision = 'HEAD' }],
		['forged previous viewer pin', value => { value.patches.previousViewer.ref = 'v35.0.2' }],
		['unknown patches key', value => { value.patches.future = {} }],
		['missing previous viewer pin', value => { delete value.patches.previousViewer }],
		['AIO previous notifications pin', value => { value.compatibility.aio.patches.previousNotifications.ref = 'v34.0.5' }],
		['viewer ref', value => { value.viewer.ref = 'v34.0.4' }],
		['viewer revision', value => { value.viewer.revision = 'HEAD' }],
		['AIO viewer pin', value => { value.compatibility.aio.viewer.revision = 'HEAD' }],
	])('rejects malformed %s before execution', (_, mutate) => {
		const value = copy()
		mutate(value)
		expect(() => validateInventory(value)).toThrow()
	})
	it('resolves peeled annotated tags rather than mistaking tag objects for source revisions', () => {
		expect(tagRevision(`${'a'.repeat(40)} refs/tags/v34.0.4\n${'b'.repeat(40)} refs/tags/v34.0.4^{}\n`, 'v34.0.4')).toBe('b'.repeat(40))
		expect(() => tagRevision('', 'v34.0.4')).toThrow('source tag response')
	})
	it.each([
		{ tag_name: 'v35.0.2rc1', draft: false, prerelease: true },
		{ tag_name: 'v35.0.2', draft: true, prerelease: false },
		{ tag_name: 'v35.0.2', draft: false },
		{ tag_name: 'latest', draft: false, prerelease: false },
	])('refuses prerelease or malformed stable metadata %j', value => {
		expect(() => stableRelease(value, 'Nextcloud')).toThrow('stable release metadata')
	})
	// CS-PLAN-084: publish order is not version order (2026-10-15 published
	// 35.0.2, 34.0.5, then 33.0.10; AIO also publishes helm-chart releases).
	it('takes the highest stable release, not the most recently published one', () => {
		const server = [release('33.0.10'), release('34.0.5'), release('35.0.2'), { ...release('35.0.3'), prerelease: true },
			{ ...release('36.0.0'), draft: true }, { tag_name: 'v35.0.10rc1', draft: false, prerelease: false }]
		expect(highestStableRelease(server, 'Nextcloud')).toBe('35.0.2')
		expect(highestStableRelease([release('35.0.9'), release('35.0.10')], 'Nextcloud')).toBe('35.0.10')
		expect(highestStableRelease([{ tag_name: 'helm-chart-14.4.0', draft: false, prerelease: false }, release('14.3.0')], 'AIO')).toBe('14.3.0')
		expect(() => highestStableRelease([{ ...release('35.0.2'), prerelease: true }], 'Nextcloud')).toThrow('stable release metadata')
		expect(() => highestStableRelease(release('35.0.2'), 'Nextcloud')).toThrow('release list')
	})
	it('requires the frozen architecture rather than taking the first registry row', () => {
		const manifest = { digest: frozen.php.digest, manifests: [{ digest: frozen.php.platformDigest, platform: { os: 'linux', architecture: 'arm64' } }] }
		expect(() => registryIdentity(manifest, frozen.platform)).toThrow('registry manifest/platform')
	})
})

describe('pinned update-source (previous patch) selection', () => {
	it('selects the real older patch with its own notifications pin, keeping the reviewed current hashes stable', () => {
		const value = copy()
		const currentPrimary = selectRuntimeSource(value, 'primary', 'current')
		const currentAio = selectRuntimeSource(value, 'aio', 'current')
		const previousPrimary = selectRuntimeSource(value, 'primary', 'previous')
		const previousAio = selectRuntimeSource(value, 'aio', 'previous')
		expect(previousPrimary.nextcloud.version).toBe('35.0.1')
		expect(previousPrimary.previous.version).toBe('35.0.1')
		expect(previousPrimary.notifications).toEqual({ ref: 'v35.0.1', revision: '0389f40a52d07663efa09de8750a2e26cb528144' })
		expect(previousPrimary.viewer).toEqual({ ref: 'v35.0.1', revision: '3509a539e695f21fd872434cbd3ee84d4bc2b605' })
		expect(previousAio.nextcloud.version).toBe('34.0.4')
		expect(previousAio.notifications.ref).toBe('v34.0.4')
		expect(previousAio.viewer).toEqual({ ref: 'v34.0.4', revision: '73ac591a6696b992e0009a71eef0f06c1c9e04a2' })
		expect(selectionHash(currentPrimary)).toBe('0ddc67606f6c8779078bcab667d55d3470f26339af91e818953a76b31e16a2f7')
		expect(selectionHash(currentAio)).toBe('704381b9a49544aea322d661f61af18284267a370bb4f44ed4fdfe60093fad82')
		expect(selectionHash(previousPrimary)).not.toBe(selectionHash(currentPrimary))
		expect(selectionHash(previousAio)).not.toBe(selectionHash(currentAio))
		expect(value).toEqual(frozen)
	})
	it('refuses unknown patch selectors and missing previous app pins', () => {
		expect(() => selectRuntimeSource(frozen, 'primary', 'future')).toThrow('patch selector')
		const value = copy()
		delete value.patches.previousNotifications
		expect(() => selectRuntimeSource(value, 'primary', 'previous')).toThrow(/previous app pins|inventory: patches/)
		expect(() => selectRuntimeSource(value, 'primary', 'current')).toThrow('inventory: patches')
		delete value.patches
		expect(() => selectRuntimeSource(value, 'primary', 'previous')).toThrow('previous app pins')
		expect(() => selectRuntimeSource(value, 'primary', 'current')).not.toThrow()
	})
	it('builds the pinned previous-patch source image with its own label hash', () => {
		const previous = selectRuntimeSource(copy(), 'primary', 'previous')
		const args = buildArguments(previous, 'cs082-runtime-35.0.1-local')
		expect(args).toEqual(expect.arrayContaining([
			`NEXTCLOUD_REF=v35.0.1`, `NEXTCLOUD_REVISION=${frozen.previous.revision}`,
			`NOTIFICATIONS_REF=v35.0.1`, `NOTIFICATIONS_REVISION=${previous.notifications.revision}`,
			`VIEWER_REF=v35.0.1`, `VIEWER_REVISION=${previous.viewer.revision}`,
		]))
		expect(args.join(' ')).toContain(`RUNTIME_INVENTORY_SHA256=${selectionHash(previous)}`)
		expect(args.join(' ')).not.toContain(frozen.nextcloud.revision)
	})
	it('threads the patch through verifyImage so a previous source image records its own named conditions', () => {
		const previous = selectRuntimeSource(copy(), 'primary', 'previous')
		const actual = {
			nextcloud: previous.nextcloud.version, internalVersion: previous.nextcloud.internalVersion,
			revision: previous.nextcloud.revision, notificationsRevision: previous.notifications.revision, viewerRevision: previous.viewer.revision,
			php: previous.php.version, sqlite: previous.databases.sqlite.version, pcov: previous.tools.pcov, apcu: previous.tools.apcu, phpredis: previous.tools.phpredis,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: `sha256:${'3'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': selectionHash(previous) } } }])
			if (args.includes('composer')) return `Composer version ${previous.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		expect(verifyImage(previous, 'cs082-runtime-35.0.1-local', run, 'primary', probeFixtures(), 'previous')).toMatchObject({
			status: 'frozen-image-identity-match', nextcloud: '35.0.1',
			upstreamLimitations: [`${previous.notifications.ref}-notifications-stale-root-lock`],
		})
		const mismatchedLabel = jest.fn((command, args) => args[0] === 'image'
			? JSON.stringify([{ Id: `sha256:${'3'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': selectionHash(selectRuntime(copy())) } } }])
			: run(command, args))
		expect(() => verifyImage(previous, 'cs082-runtime-35.0.1-local', mismatchedLabel, 'primary', probeFixtures(), 'previous'))
			.toThrow('inventory/platform mismatch')
	})
})

describe('read-only stable discovery', () => {
	it('fails on diagnostic output even if a native probe exits successfully', () => {
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('diagnostic')"])).toThrow('emitted diagnostics')
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('warning')"], 'expected metadata')).toThrow('emitted diagnostics')
	})
	it('reports matching artifacts without upgrading a candidate to qualified', async () => {
		const io = officialFixtures()
		await expect(checkStable({ ...frozen, status: 'candidate' }, io)).resolves.toMatchObject({ status: 'current', qualification: 'candidate', drift: [] })
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'current', qualification: 'qualified', drift: [] })
		expect(io.execute.mock.calls.every(([command, args]) => command === 'git' ? args[0] === 'ls-remote' : args.slice(0, 3).join(' ') === 'buildx imagetools inspect')).toBe(true)
	})
	it('reports newer upstream separately from the unchanged AIO bundle without rewriting the lock', async () => {
		const value = copy()
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases') ? releases('35.0.3')
			: url.includes('/releases') ? releases(frozen.aio.version) : aioDockerfile(url))
		await expect(checkStable(value, io)).resolves.toMatchObject({ status: 'update-needed', upstream: '35.0.3', aioNextcloud: '34.0.5', drift: ['upstream-nextcloud'] })
		expect(value).toEqual(frozen)
	})
	it('reports a different AIO bundle even when its Nextcloud version stays unchanged', async () => {
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases') ? releases(frozen.nextcloud.version)
			: url.includes('/releases') ? releases('14.4.0') : aioDockerfile(url))
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'update-needed', drift: ['aio-bundle'] })
	})
	it('reports matching AIO subcomponents read from the release tag of the AIO container sources', async () => {
		const io = officialFixtures()
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'current', drift: [], aioComponents: {
			php: frozen.php.version, apcu: frozen.tools.apcu, phpredis: frozen.tools.phpredis,
			redis: frozen.cache.redis.version, postgres: frozen.databases.pgsql.version,
		} })
		const sources = io.fetchMetadata.mock.calls.map(([url]) => url).filter(url => url.endsWith('/Dockerfile'))
		expect(sources).toEqual(['nextcloud', 'redis', 'postgresql'].map(name =>
			`https://raw.githubusercontent.com/nextcloud/all-in-one/v${frozen.aio.version}/Containers/${name}/Dockerfile`))
	})
	it.each([
		['php', '8.4.28', 'aio-php'], ['apcu', '5.1.29', 'aio-apcu'], ['phpredis', '6.3.1', 'aio-phpredis'],
		['redis', '8.10.3', 'aio-redis'], ['postgres', '18.7', 'aio-postgres'],
	])('reports an AIO %s update as drift even when the bundled Nextcloud stays unchanged', async (component, version, label) => {
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases') ? releases(frozen.nextcloud.version)
			: url.includes('/releases') ? releases(frozen.aio.version) : aioDockerfile(url, frozen, { [component]: version }))
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'update-needed', drift: [label] })
	})
	it.each([
		['nextcloud', 'ARG APCU_VERSION='], ['nextcloud', 'ARG REDIS_VERSION='], ['redis', 'FROM redis:'], ['postgresql', 'FROM postgres:'],
		// A pin the extension download no longer consumes says nothing about the build.
		['nextcloud', 'v$APCU_VERSION.tar.gz'], ['nextcloud', '/$REDIS_VERSION.tar.gz'],
	])('fails closed when the AIO %s source no longer exposes a parsable version (%s)', async (container, marker) => {
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases') ? releases(frozen.nextcloud.version)
			: url.includes('/releases') ? releases(frozen.aio.version)
				: url.endsWith(`/Containers/${container}/Dockerfile`) ? aioDockerfile(url).replace(marker, '') : aioDockerfile(url))
		await expect(checkStable(frozen, io)).rejects.toThrow(/AIO .* metadata/)
	})
	it('detects moved source tags and image digests', async () => {
		const io = officialFixtures()
		const execute = io.execute.getMockImplementation()
		io.execute.mockImplementation((command, args) => command === 'git' ? `${'a'.repeat(40)}\t${args[2]}\n`
			: JSON.stringify({ ...JSON.parse(execute(command, args)), digest: `sha256:${'f'.repeat(64)}` }))
		const result = await checkStable(frozen, io)
		expect(result.status).toBe('update-needed')
		expect(result.drift).toHaveLength(17)
	})
	it.each(['release network failure', 'registry failure', 'malformed release', 'malformed AIO bundle', 'prerelease'])('fails closed on %s', async failure => {
		const io = officialFixtures()
		if (failure === 'release network failure') io.fetchMetadata.mockRejectedValue(new Error('unavailable'))
		if (failure === 'registry failure') io.execute.mockImplementation(() => { throw new Error('registry unavailable') })
		if (failure === 'malformed release') io.fetchMetadata.mockResolvedValue('{}')
		if (failure === 'malformed AIO bundle') io.fetchMetadata.mockImplementation(async url => url.includes('/releases') ? releases('34.0.4') : '')
		if (failure === 'prerelease') io.fetchMetadata.mockResolvedValue(JSON.stringify([{ ...release('35.0.2'), prerelease: true }]))
		await expect(checkStable(frozen, io)).rejects.toThrow()
	})
})

describe('frozen candidate execution', () => {
	it('bounds candidate metadata to the approved primary/AIO majors and PHP minor, not untested old runtimes', () => {
		const metadata = fs.readFileSync(path.resolve(__dirname, '../../../appinfo/info.xml'), 'utf8')
		const composer = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../composer.json'), 'utf8'))
		expect(metadata).toContain('<nextcloud min-version="34" max-version="35"/>')
		expect(metadata).toContain('<php min-version="8.4" max-version="8.4"/>')
		expect(composer.require.php).toBe('~8.4.0')
	})
	it('wires weekly and manual pre-release checks to the same read-only checker', () => {
		const workflow = fs.readFileSync(path.resolve(__dirname, '../../../.github/workflows/runtime-check.yml'), 'utf8')
		expect(workflow).toContain("cron: '0 8 * * 1'")
		expect(workflow).toContain('workflow_dispatch:')
		expect(workflow).toContain('run: npm run test:runtime:validate')
		expect(workflow).toContain('run: npm run test:runtime:check')
		expect(workflow).not.toMatch(/docker (compose|build|run)|git (commit|push)/)
	})
	it('uses only locked identities and refuses non-owned image tags', () => {
		const args = buildArguments(selectRuntime(frozen), 'cs082-runtime-34.0.4-local')
		expect(args).toEqual(expect.arrayContaining([
		`PHP_IMAGE=${frozen.php.image}@${frozen.php.digest}`,
		`COMPOSER_IMAGE=${frozen.composer.image}@${frozen.composer.digest}`,
		`NEXTCLOUD_REVISION=${frozen.nextcloud.revision}`, `NOTIFICATIONS_REVISION=${frozen.notifications.revision}`,
		`VIEWER_REVISION=${frozen.viewer.revision}`,
	]))
		expect(args.join(' ')).not.toMatch(/latest|stable34/)
		expect(() => buildArguments(selectRuntime(frozen), 'docker-nextcloud-test')).toThrow('owned candidate image name')
		expect(() => buildArguments(frozen, 'cs082-runtime-34.0.4-local')).toThrow('exact selected runtime')
	})
	it('asserts actual runtime identity instead of trusting healthy older containers', () => {
		const selection = selectRuntime(frozen)
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision, viewerRevision: frozen.viewer.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version,
			pcov: frozen.tools.pcov, apcu: frozen.tools.apcu, phpredis: frozen.tools.phpredis, inventoryHash: inventoryHash(frozen),
		}
		expect(assertInstalled(selection, actual)).toEqual(actual)
		for (const key of Object.keys(actual)) expect(() => assertInstalled(selection, { ...actual, [key]: 'older' })).toThrow(`Installed runtime mismatch: ${key}`)
		expect(() => assertInstalled(frozen, actual)).toThrow('exact selected runtime')
	})
	it('rejects old or mismatched images before creating any ephemeral probe container', () => {
		const run = jest.fn(() => JSON.stringify([{ Os: 'linux', Architecture: 'amd64', Config: { Labels: {} } }]))
		expect(() => verifyImage(selectRuntime(frozen), 'cs082-runtime-34.0.4-local', run)).toThrow('inventory/platform mismatch')
		expect(run).toHaveBeenCalledTimes(1)
	})
	it('verifies the frozen image and tools without claiming an installed/provider gate', () => {
		const selection = selectRuntime(frozen)
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision, viewerRevision: frozen.viewer.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version, pcov: frozen.tools.pcov, apcu: frozen.tools.apcu, phpredis: frozen.tools.phpredis,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: `sha256:${'1'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(frozen) } } }])
			if (args.includes('composer')) return `Composer version ${frozen.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		const probe = probeFixtures()
		expect(verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probe)).toMatchObject({ status: 'frozen-image-identity-match', qualification: 'qualified', composer: frozen.composer.version,
			notificationsLockedPackages: 15, upstreamLimitations: [`${frozen.notifications.ref}-notifications-stale-root-lock`] })
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes('--rm') && args.includes('none'))).toBe(true)
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes(`sha256:${'1'.repeat(64)}`) && !args.includes('cs082-runtime-34.0.4-local'))).toBe(true)
		expect(probe.mock.calls.every(([, args]) => args.includes('--rm') && args.includes('none') && args.includes(`sha256:${'1'.repeat(64)}`))).toBe(true)
		const original = run.getMockImplementation()
		run.mockImplementation((command, args) => args.at(-1).includes('notifications-ready') ? '' : original(command, args))
		expect(() => verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probeFixtures())).toThrow('notifications runtime autoload mismatch')
		run.mockImplementation((command, args) => args.includes('composer') ? 'Composer version 2.0.0' : original(command, args))
		expect(() => verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probeFixtures())).toThrow('mismatch: composer')
	})
})

describe('upstream build condition dispositions', () => {
	const imageId = `sha256:${'2'.repeat(64)}`
	it('accepts the clean primary platform gate and records the exact notifications stale-lock warning', () => {
		expect(assertPlatformGate('primary', imageId, probeFixtures())).toEqual([])
		expect(assertNotificationsBuild('primary', imageId, probeFixtures())).toEqual({ lockedPackages: 15, warnings: [STALE_LOCK_WARNING] })
	})
	it('accepts the exact named NC34 lcobucci/clock limitation on the AIO row without relabeling it as passed', () => {
		expect(assertPlatformGate('aio', imageId, probeFixtures('aio'))).toEqual([{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }])
		expect(assertNotificationsBuild('aio', imageId, probeFixtures('aio'))).toEqual({ lockedPackages: 15, warnings: [] })
	})
	it.each([
		['unexpected core failure', 'primary', { core: { status: 1, stdout: JSON.stringify([LCOBUCCI_FAILURE]) } }, 'core platform gate mismatch'],
		['unexpected primary 3rdparty failure', 'primary', { thirdparty: { status: 1, stdout: JSON.stringify([LCOBUCCI_FAILURE]) } }, '3rdparty platform gate mismatch'],
		['different AIO failure', 'aio', { thirdparty: { stdout: JSON.stringify([{ ...LCOBUCCI_FAILURE, failed_requirement: { ...LCOBUCCI_FAILURE.failed_requirement, constraint: '~8.2.0' } }]) } }, '3rdparty platform gate mismatch'],
		['additional AIO failure', 'aio', { thirdparty: { stdout: JSON.stringify([LCOBUCCI_FAILURE, { ...LCOBUCCI_FAILURE, name: 'ext-gmp' }]) } }, '3rdparty platform gate mismatch'],
		['unexpectedly clean AIO gate', 'aio', { thirdparty: { status: 0, stdout: JSON.stringify([{ name: 'php', version: '8.4.25', status: 'success', failed_requirement: null, provider: null }]) } }, '3rdparty platform gate mismatch'],
		['probe diagnostics', 'primary', { core: { stderr: 'Deprecated: something' } }, 'core platform probe emitted unexpected diagnostics'],
		['malformed probe output', 'primary', { core: { stdout: 'not json' } }, 'core platform probe malformed'],
	])('fails closed on %s', (_, row, overrides, message) => {
		expect(() => assertPlatformGate(row, imageId, probeFixtures(row, overrides))).toThrow(message)
	})
	it.each([
		['vendor divergence', 'primary', { lock: { stdout: JSON.stringify({ matches: false, locked: 15, installed: 14 }) } }, 'diverges from the upstream lock'],
		['lock probe failure', 'primary', { lock: { status: 1 } }, 'lock probe failed'],
		['missing primary stale-lock warning', 'primary', { warnings: { stdout: '' } }, 'build warnings mismatch'],
		['unexpected AIO warning', 'aio', { warnings: { stdout: `${STALE_LOCK_WARNING}\n` } }, 'build warnings mismatch'],
		['warnings probe failure', 'primary', { warnings: { status: 1 } }, 'build probe failed'],
	])('fails closed on %s', (_, row, overrides, message) => {
		expect(() => assertNotificationsBuild(row, imageId, probeFixtures(row, overrides))).toThrow(message)
	})
	it('rejects unknown rows or patches instead of defaulting to permissive expectations', () => {
		expect(() => assertPlatformGate('unknown', imageId, probeFixtures())).toThrow('Unknown runtime row/patch')
		expect(() => assertPlatformGate('primary', imageId, probeFixtures(), 'unknown')).toThrow('Unknown runtime row/patch')
		expect(UPSTREAM_BUILD_CONDITIONS.primary.current.notificationsWarnings).toEqual([STALE_LOCK_WARNING])
		expect(UPSTREAM_BUILD_CONDITIONS.aio.current['3rdpartyFailures']).toEqual([{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }])
	})
	it('threads the row through verifyImage so the AIO image records its named limitation', () => {
		const aio = selectRuntime(copy(), 'aio')
		const actual = {
			nextcloud: aio.nextcloud.version, internalVersion: aio.nextcloud.internalVersion,
			revision: aio.nextcloud.revision, notificationsRevision: aio.notifications.revision, viewerRevision: aio.viewer.revision,
			php: aio.php.version, sqlite: aio.databases.sqlite.version, pcov: aio.tools.pcov, apcu: aio.tools.apcu, phpredis: aio.tools.phpredis,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: imageId, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(aio) } } }])
			if (args.includes('composer')) return `Composer version ${aio.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		expect(verifyImage(aio, 'cs082-runtime-34.0.4-local', run, 'aio', probeFixtures('aio'))).toMatchObject({
			status: 'frozen-image-identity-match',
			upstreamLimitations: [`${aio.nextcloud.ref}-3rdparty-lcobucci/clock-~8.1.0 || ~8.2.0-php-${aio.php.version}`],
		})
	})
})

describe('promoted canonical default stack (R7)', () => {
	const root = path.resolve(__dirname, '../../..')
	const selection = selectRuntime(frozen)

	it('pins Dockerfile.test defaults to the inventory primary selection so plain builds stay frozen', () => {
		const dockerfile = fs.readFileSync(path.join(root, 'docker/Dockerfile.test'), 'utf8')
		const defaults = Object.fromEntries([...dockerfile.matchAll(/^ARG ([A-Z0-9_]+)=([^\n]+)$/gm)].map(([, name, value]) => [name, value]))
		expect(defaults).toMatchObject({
			PHP_IMAGE: `${frozen.php.image}@${frozen.php.digest}`,
			COMPOSER_IMAGE: `${frozen.composer.image}@${frozen.composer.digest}`,
			NEXTCLOUD_REF: frozen.nextcloud.ref,
			NEXTCLOUD_REVISION: frozen.nextcloud.revision,
			NOTIFICATIONS_REF: frozen.notifications.ref,
			NOTIFICATIONS_REVISION: frozen.notifications.revision,
			VIEWER_REF: frozen.viewer.ref,
			VIEWER_REVISION: frozen.viewer.revision,
			PCOV_VERSION: frozen.tools.pcov,
			APCU_VERSION: frozen.tools.apcu,
			PHPREDIS_VERSION: frozen.tools.phpredis,
			RUNTIME_INVENTORY_SHA256: selectionHash(selection),
		})
		expect(defaults.PHP_IMAGE).not.toContain('latest')
	})

	it('points compose.test.yml at the pinned PostgreSQL reference with frozen runtime identity', () => {
		const compose = fs.readFileSync(path.join(root, 'docker/compose.test.yml'), 'utf8')
		expect(compose).toContain(`image: ${frozen.databases.pgsql.image}@${frozen.databases.pgsql.digest}`)
		expect(compose).toContain(`PGDATA=/var/lib/postgresql/${frozen.databases.pgsql.version.split('.')[0]}/docker`)
		expect(compose).toContain('- canonical-db:/var/lib/postgresql')
		expect(compose).not.toContain('mariadb:10.6')
		expect(compose).toContain('- TEST_DB_TYPE=pgsql')
		expect(compose).toContain(`- TEST_RUNTIME_INTERNAL_VERSION=${frozen.nextcloud.internalVersion}`)
		expect(compose).toContain(`- TEST_RUNTIME_PHP_VERSION=${frozen.php.version}`)
		// Promotion upgrades the kept volumes; empty by default (entrypoint ignores it).
		expect(compose).toContain('- TEST_RUNTIME_UPGRADE_FROM=${TEST_RUNTIME_UPGRADE_FROM:-}')
		expect(compose).toContain('- DB_PORT=5432')
	})

	it('mirrors the frozen pins in the standalone matrix defaults while keeping explicit overrides', () => {
		const compose = fs.readFileSync(path.join(root, 'docker/compose.test-matrix.yml'), 'utf8')
		expect(compose).toContain(`\${WP9_MYSQL_IMAGE:-${frozen.databases.mysql.image}@${frozen.databases.mysql.digest}}`)
		expect(compose).toContain(`\${WP9_PGSQL_IMAGE:-${frozen.databases.pgsql.image}@${frozen.databases.pgsql.digest}}`)
		expect(compose).toContain('WP9_PGDATA:-/var/lib/postgresql/18/docker')
		expect(compose).toContain('WP9_PGSQL_VOLUME_TARGET:-/var/lib/postgresql}')
		expect(compose).toContain('${WP9_TEST_IMAGE:-chorestrike-wp9-test:local}')
	})

	it('propagates the frozen build into the CI gates that consume the inventory', () => {
		const releaseGate = fs.readFileSync(path.join(root, '.github/workflows/release-gate.yml'), 'utf8')
		expect(releaseGate).toContain('node scripts/test-runtime.js build cs082-runtime-35.0.2-local')
		expect(releaseGate).toContain('node scripts/test-runtime.js verify-image cs082-runtime-35.0.2-local')
		expect(releaseGate).toContain('docker compose -f docker/compose.test.yml up -d --build --wait --wait-timeout 900')
		expect(releaseGate).toContain("php-version: '8.4'")
		const test = fs.readFileSync(path.join(root, '.github/workflows/test.yml'), 'utf8')
		expect(test).toContain("php: ['8.4']")
		expect(test).toContain('docker compose -f docker/compose.test.yml up -d --build --wait --wait-timeout 900')
		expect(test).toContain('npm run test:runtime:canonical')
		const fullMatrix = fs.readFileSync(path.join(root, '.github/workflows/full-matrix.yml'), 'utf8')
		expect(fullMatrix).toContain('docker compose -f docker/compose.test.yml up -d --build --wait --wait-timeout 900')
	})

	function canonicalFixtures(overrides = {}) {
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision, viewerRevision: frozen.viewer.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version,
			pcov: frozen.tools.pcov, apcu: frozen.tools.apcu, phpredis: frozen.tools.phpredis,
			configuredVersion: frozen.nextcloud.internalVersion, provider: 'pgsql',
			databaseVersion: `${frozen.databases.pgsql.version} (Debian ...)`,
			databaseTargetMatches: true, enabled: true,
		}
		const containers = {
			'chorestrike-test': {
				Config: {
					Labels: {
						'org.chorestrike.test-runtime.inventory': selectionHash(selection),
						'com.docker.compose.project.config_files': path.resolve(__dirname, '../../../docker/compose.test.yml'),
					},
					Env: [`TEST_RUNTIME_INTERNAL_VERSION=${frozen.nextcloud.internalVersion}`, `TEST_RUNTIME_PHP_VERSION=${frozen.php.version}`],
				},
				State: { Running: true },
				Mounts: ['config', 'data'].map(directory => ({ Type: 'volume', Destination: `/var/www/html/${directory}` })),
			},
			'chorestrike-db': {
				Config: {
					Image: `${frozen.databases.pgsql.image}@${frozen.databases.pgsql.digest}`,
					Env: [`PGDATA=/var/lib/postgresql/${frozen.databases.pgsql.version.split('.')[0]}/docker`],
				},
				State: { Running: true },
				Mounts: [{ Type: 'volume', Destination: '/var/lib/postgresql' }],
			},
		}
		for (const [name, mutate] of Object.entries(overrides.containers || {})) mutate(containers[name])
		const run = jest.fn((command, args) => {
			if (args[0] === 'inspect') return JSON.stringify([containers[args[1]]])
			if (args[0] === 'exec') return JSON.stringify({ ...actual, ...(overrides.actual || {}) })
			throw new Error(`unexpected probe: ${args.join(' ')}`)
		})
		return { run, preflight: jest.fn(), containers }
	}

	it('accepts the promoted canonical stack only after the read-only preflight', () => {
		const { run, preflight } = canonicalFixtures()
		expect(verifyCanonical(selection, run, preflight)).toMatchObject({ status: 'canonical-runtime-match', qualification: 'qualified',
			provider: 'pgsql', configuredVersion: frozen.nextcloud.internalVersion })
		expect(preflight).toHaveBeenCalledWith('http://localhost:8080', { NEXTCLOUD_URL: 'http://localhost:8080', NEXTCLOUD_CONTAINER: 'chorestrike-test' })
		expect(preflight.mock.invocationCallOrder[0]).toBeLessThan(run.mock.invocationCallOrder[0])
	})

	it.each([
		['stale image label', { containers: { 'chorestrike-test': c => { c.Config.Labels['org.chorestrike.test-runtime.inventory'] = `sha256:${'9'.repeat(64)}` } } }, 'Canonical container frozen-runtime mismatch'],
		['a stack created from another worktree', { containers: { 'chorestrike-test': c => { c.Config.Labels['com.docker.compose.project.config_files'] = path.resolve(__dirname, '../../../../other-worktree/docker/compose.test.yml') } } }, 'Canonical container was created from another checkout'],
		['a stack not created by compose', { containers: { 'chorestrike-test': c => { delete c.Config.Labels['com.docker.compose.project.config_files'] } } }, 'Canonical container was created from another checkout'],
		['missing config/data persistence', { containers: { 'chorestrike-test': c => { c.Mounts = [] } } }, 'Canonical config/data persistence mismatch'],
		['missing frozen runtime env', { containers: { 'chorestrike-test': c => { c.Config.Env = [] } } }, 'Canonical container frozen-runtime mismatch'],
		['stopped canonical container', { containers: { 'chorestrike-test': c => { c.State.Running = false } } }, 'Canonical container frozen-runtime mismatch'],
		['mutable database image', { containers: { 'chorestrike-db': c => { c.Config.Image = 'postgres:18-alpine' } } }, 'Canonical PostgreSQL image/PGDATA/volume mismatch'],
		['PG16-style data directory', { containers: { 'chorestrike-db': c => { c.Config.Env = ['PGDATA=/var/lib/postgresql/data']; c.Mounts = [{ Type: 'volume', Destination: '/var/lib/postgresql/data' }] } } }, 'Canonical PostgreSQL image/PGDATA/volume mismatch'],
		['database volume outside the owned root', { containers: { 'chorestrike-db': c => { c.Mounts = [{ Type: 'volume', Destination: '/var/lib/postgresql/data' }] } } }, 'Canonical PostgreSQL image/PGDATA/volume mismatch'],
		['stopped database container', { containers: { 'chorestrike-db': c => { c.State.Running = false } } }, 'Canonical PostgreSQL image/PGDATA/volume mismatch'],
		['wrong installed provider', { actual: { provider: 'mysql' } }, 'Canonical installed app/provider/database version mismatch'],
		['older installed provider version', { actual: { databaseVersion: '16.4' } }, 'Canonical installed app/provider/database version mismatch'],
		['app not enabled', { actual: { enabled: false } }, 'Canonical installed app/provider/database version mismatch'],
		['older installed runtime', { actual: { internalVersion: '35.0.0.10' } }, 'Installed runtime mismatch: internalVersion'],
	])('refuses the canonical stack on %s before any mutation', (_, overrides, message) => {
		const { run, preflight } = canonicalFixtures(overrides)
		expect(() => verifyCanonical(selection, run, preflight)).toThrow(message)
	})

	it('refuses before probing anything when the preflight fails', () => {
		const { run, preflight } = canonicalFixtures()
		preflight.mockImplementation(() => { throw new Error('Test target preflight failed; no seed/reset/job was executed') })
		expect(() => verifyCanonical(selection, run, preflight)).toThrow('Test target preflight failed')
		expect(run).not.toHaveBeenCalled()
	})
})
