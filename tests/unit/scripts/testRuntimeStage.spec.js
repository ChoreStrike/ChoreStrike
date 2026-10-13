/** @jest-environment node */
const { readInventory, inventoryHash, selectionHash, selectRuntimeSource } = require('../../../scripts/test-runtime')
const { stageSettings, composeArguments, assertFreshProject, assertOwnedProject, assertProviderImage, verifyInstallation, stage, preserve } = require('../../../scripts/test-runtime-stage')

const inventory = readInventory('primary')
const imageId = `sha256:${'1'.repeat(64)}`
const settings = (provider = 'pgsql') => stageSettings(inventory, 'cs082-unit-pgsql', provider, imageId, 8182)

function databaseFixture(options = settings()) {
	const expected = inventory.databases[options.provider]
	return {
		container: {
			Image: imageId,
			Config: { Image: `${expected.image}@${expected.digest}`,
				Labels: { 'com.docker.compose.project': options.project, 'com.docker.compose.service': `db-${options.provider}` },
				Env: [`PGDATA=${options.environment.WP9_PGDATA}`] },
			Mounts: [{ Type: 'volume', Name: `${options.project}_pgsql-db`, Destination: '/var/lib/postgresql' }],
			NetworkSettings: { Networks: { [`${options.project}_wp9`]: { IPAddress: '172.20.0.2' } } },
		},
		image: { Id: imageId, Os: 'linux', Architecture: 'amd64', RepoDigests: [`${expected.image.split(':')[0]}@${expected.digest}`] },
	}
}

function installationFixture(options = settings()) {
	const db = options.provider === 'sqlite' ? {} : databaseFixture(options)
	const actual = {
		nextcloud: inventory.nextcloud.version, internalVersion: inventory.nextcloud.internalVersion,
		revision: inventory.nextcloud.revision, notificationsRevision: inventory.notifications.revision,
		viewerRevision: inventory.viewer.revision,
		php: inventory.php.version, sqlite: inventory.databases.sqlite.version, pcov: inventory.tools.pcov, apcu: inventory.tools.apcu, phpredis: inventory.tools.phpredis,
		configuredVersion: inventory.nextcloud.internalVersion, provider: options.provider === 'sqlite' ? 'sqlite3' : options.provider,
		databaseVersion: inventory.databases[options.provider].version, databaseTargetMatches: true, databasePeer: options.provider === 'sqlite' ? null : '172.20.0.2', enabled: true,
	}
	const container = { Image: imageId,
		Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(inventory), 'com.docker.compose.project': options.project } },
		State: { Health: { Status: 'healthy' } },
		NetworkSettings: { Networks: { [`${options.project}_wp9`]: { IPAddress: '172.20.0.3' } } },
		Mounts: ['config', 'data'].map(directory => ({ Type: 'volume', Name: `${options.project}_${options.provider}-${directory}`, Destination: `/var/www/html/${directory}` })) }
	const run = jest.fn((command, args) => {
		if (args[0] === 'exec') return JSON.stringify(actual)
		if (args[0] === 'image') return JSON.stringify([db.image])
		return JSON.stringify([args[1] === options.container ? container : db.container])
	})
	return { actual, container, db, run, preflight: jest.fn() }
}

describe('isolated frozen runtime staging', () => {
	it('uses the shared matrix with immutable images, numeric loopback and the PG18 owned volume layout', () => {
		const value = settings()
		expect(value.environment.WP9_TEST_IMAGE).toBe(imageId)
		expect(value.environment.WP9_PGSQL_IMAGE).toBe(`${inventory.databases.pgsql.image}@${inventory.databases.pgsql.digest}`)
		expect(value.environment.WP9_MYSQL_IMAGE).toBe(`${inventory.databases.mysql.image}@${inventory.databases.mysql.digest}`)
		expect(value.environment.WP9_PGDATA).toBe('/var/lib/postgresql/18/docker')
		expect(value.environment.WP9_PGSQL_VOLUME_TARGET).toBe('/var/lib/postgresql')
		expect(JSON.parse(value.input).services['nextcloud-pgsql']).toMatchObject({
			ports: ['127.0.0.1:8182:80'], environment: { TEST_RUNTIME_INTERNAL_VERSION: '35.0.1.1', TEST_RUNTIME_PHP_VERSION: '8.4.25' },
		})
		expect(composeArguments(value, ['up'])).toEqual(expect.arrayContaining(['cs082-unit-pgsql', 'docker/compose.test-matrix.yml', '-', 'pgsql']))
	})
	it.each([
		['other-project', 'pgsql', imageId, 8182], ['cs082-unit', 'postgres', imageId, 8182],
		['cs082-unit', 'pgsql', 'mutable:latest', 8182], ['cs082-unit', 'pgsql', imageId, 80],
		['cs082-unit', 'pgsql', imageId, 65536], ['cs082-unit', 'pgsql', imageId, NaN],
	])('refuses invalid settings before any Docker mutation: %j', (...args) => {
		expect(() => stageSettings(inventory, ...args)).toThrow('Invalid owned staging')
	})
	it.each([0, 1, 2, 3, 4, 5])('refuses existing labelled or unlabelled resources at probe %i before pulling or provisioning', probe => {
		const invoke = jest.fn()
		const run = jest.fn().mockReturnValue('')
		run.mockImplementationOnce(() => probe === 0 ? 'old' : '')
		for (let index = 1; index <= probe; index++) run.mockImplementationOnce(() => index === probe ? 'cs082-unit-pgsql_old' : '')
		expect(() => stage(inventory, settings(), invoke, run)).toThrow(/already exists/)
		expect(invoke).not.toHaveBeenCalled()
	})
	it('provisions only a fresh project, with no build/pull alias or cleanup fallback', () => {
		const invoke = jest.fn(() => ({ status: 0 }))
		stage(inventory, settings(), invoke, jest.fn(() => ''))
		expect(invoke.mock.calls[0][1]).toEqual(['pull', '--platform', inventory.platform, `${inventory.databases.pgsql.image}@${inventory.databases.pgsql.digest}`])
		expect(invoke.mock.calls[1][1]).toEqual(expect.arrayContaining(['--no-build', '--pull', 'never', '--wait']))
		expect(invoke.mock.calls[1][2]).toMatchObject({ input: settings().input })
		// Check the Docker argument lists only: the inherited process env can
		// legitimately contain these substrings (e.g. a value of "markdown").
		const dockerArgs = invoke.mock.calls.flatMap(call => call[1])
		expect(dockerArgs).not.toContain('down')
		expect(dockerArgs).not.toContain('--force-recreate')
	})
	it.each(['pull', 'provision'])('propagates %s failure without destroying diagnostic resources', failure => {
		const invoke = jest.fn().mockReturnValueOnce({ status: failure === 'pull' ? 1 : 0 }).mockReturnValue({ status: 1 })
		expect(() => stage(inventory, settings(), invoke, jest.fn(() => ''))).toThrow(/failed/)
		expect(invoke).toHaveBeenCalledTimes(failure === 'pull' ? 1 : 2)
	})
})

describe('installed runtime and provider identity', () => {
	it.each(['mysql', 'pgsql', 'sqlite'])('proves a real %s installation without claiming qualification', provider => {
		const options = settings(provider)
		const fixture = installationFixture(options)
		expect(verifyInstallation(inventory, options, fixture.run, fixture.preflight)).toMatchObject({
			status: 'installed-runtime-provider-match', qualification: 'candidate', nextcloud: '35.0.1', provider: provider === 'sqlite' ? 'sqlite3' : provider,
		})
		expect(fixture.preflight).toHaveBeenCalledWith(options.url, { NEXTCLOUD_URL: options.url, NEXTCLOUD_CONTAINER: options.container })
		expect(fixture.run.mock.calls.every(([, args]) => ['inspect', 'image', 'exec'].includes(args[0]))).toBe(true)
		const code = fixture.run.mock.calls.find(([, args]) => args[0] === 'exec')[1].at(-1)
		expect(code).toContain('$db->getInner()->getParams()')
		expect(code).toContain(`($params['host'] ?? null) === 'db-${provider}'`)
		expect(code).toContain("($params['dbname'] ?? null) === 'nextcloud'")
		expect(code).toContain("$cfg->getValue('datadirectory') === '/var/www/html/data'")
		expect(code).toContain("($params['path'] ?? null) === '/var/www/html/data/owncloud.db'")
		expect(code).toContain('$qb->select($qb->createFunction($function))->executeQuery()')
		expect(code).not.toContain('getenv(')
	})
	it.each(['coherent foreign endpoint', 'foreign database peer', 'foreign network', 'unused companion'])('refuses %s rather than certifying an unused owned DB', scenario => {
		const fixture = installationFixture()
		if (scenario === 'coherent foreign endpoint') fixture.actual.databaseTargetMatches = false
		if (scenario === 'foreign database peer') fixture.actual.databasePeer = '172.21.0.2'
		if (scenario === 'foreign network') fixture.container.NetworkSettings.Networks = { foreign: { IPAddress: '172.21.0.3' } }
		if (scenario === 'unused companion') fixture.db.container.NetworkSettings.Networks = { foreign: { IPAddress: '172.21.0.2' } }
		expect(() => verifyInstallation(inventory, settings(), fixture.run, fixture.preflight)).toThrow(/mismatch/)
	})
	it('refuses a mismatched HTTP/OCC target before an installed-runtime probe', () => {
		const run = jest.fn()
		expect(() => verifyInstallation(inventory, settings(), run, () => { throw new Error('wrong target') })).toThrow('wrong target')
		expect(run).not.toHaveBeenCalled()
	})
	it.each(['Image', 'inventory', 'project', 'health'])('rejects a healthy-looking older or foreign app container: %s', field => {
		const fixture = installationFixture()
		if (field === 'Image') fixture.container.Image = `sha256:${'2'.repeat(64)}`
		if (field === 'inventory') fixture.container.Config.Labels['org.chorestrike.test-runtime.inventory'] = 'old'
		if (field === 'project') fixture.container.Config.Labels['com.docker.compose.project'] = 'cs-wp9-existing'
		if (field === 'health') fixture.container.State.Health.Status = 'starting'
		expect(() => verifyInstallation(inventory, settings(), fixture.run, fixture.preflight)).toThrow('image/readiness mismatch')
		expect(fixture.run).toHaveBeenCalledTimes(1)
	})
	it.each(['config', 'data'])('refuses an unrelated %s volume before the installed-runtime probe', directory => {
		const fixture = installationFixture()
		fixture.container.Mounts.find(mount => mount.Destination.endsWith(directory)).Name = 'other-instance'
		expect(() => verifyInstallation(inventory, settings(), fixture.run, fixture.preflight)).toThrow('owned volume mismatch')
		expect(fixture.run).toHaveBeenCalledTimes(1)
	})
	it.each(['nextcloud', 'configuredVersion', 'databaseVersion', 'provider', 'enabled', 'databaseTargetMatches'])('rejects actual installed %s drift even when HTTP and CLI agree', field => {
		const fixture = installationFixture()
		fixture.actual[field] = ['enabled', 'databaseTargetMatches'].includes(field) ? false : 'older'
		expect(() => verifyInstallation(inventory, settings(), fixture.run, fixture.preflight)).toThrow(/mismatch/)
	})
	it.each(['image', 'digest', 'platform', 'project', 'service', 'PGDATA', 'volume', 'old mount'])('refuses wrong database identity/layout: %s', field => {
		const fixture = databaseFixture()
		if (field === 'image') fixture.container.Image = 'older'
		if (field === 'digest') fixture.image.RepoDigests = ['postgres@unknown']
		if (field === 'platform') fixture.image.Architecture = 'arm64'
		if (field === 'project') fixture.container.Config.Labels['com.docker.compose.project'] = 'other'
		if (field === 'service') fixture.container.Config.Labels['com.docker.compose.service'] = 'db-mysql'
		if (field === 'PGDATA') fixture.container.Config.Env = ['PGDATA=/var/lib/postgresql/data']
		if (field === 'volume') fixture.container.Mounts[0].Name = 'old-pg16-data'
		if (field === 'old mount') fixture.container.Mounts[0].Destination = '/var/lib/postgresql/data'
		expect(() => assertProviderImage(inventory, settings(), fixture.container, fixture.image)).toThrow(/mismatch/)
	})
})

describe('AIO-near Redis cache row', () => {
	const redisImage = `${inventory.cache.redis.image}@${inventory.cache.redis.digest}`
	const aioSettings = () => stageSettings(inventory, 'cs082-unit-aio', 'pgsql', imageId, 8183, {}, 'aio')

	function redisFixture(options = aioSettings()) {
		const fixture = installationFixture(options)
		const cache = { distributed: '\\OC\\Memcache\\Redis', locking: '\\OC\\Memcache\\Redis', host: 'redis',
			roundTrip: true, sessionHandler: 'redis', peer: '172.20.0.4' }
		const redis = { Config: { Image: redisImage, Labels: { 'com.docker.compose.project': options.project, 'com.docker.compose.service': 'redis' } },
			State: { Health: { Status: 'healthy' } }, NetworkSettings: { Networks: { [`${options.project}_wp9`]: { IPAddress: '172.20.0.4' } } } }
		const base = fixture.run.getMockImplementation()
		fixture.run = jest.fn((command, args) => {
			if (args[0] === 'exec' && args.at(-1).includes('cs084-probe')) return JSON.stringify(cache)
			if (args[0] === 'inspect' && args[1] === `${options.project}-redis-1`) return JSON.stringify([redis])
			return base(command, args)
		})
		return { ...fixture, cache, redis }
	}

	it('adds a pinned Redis companion and AIO session/cache environment only to the AIO row', () => {
		const services = JSON.parse(aioSettings().input).services
		expect(services.redis).toMatchObject({ image: redisImage, networks: ['wp9'], healthcheck: { test: ['CMD', 'redis-cli', 'ping'] } })
		expect(services['nextcloud-pgsql']).toMatchObject({
			depends_on: { redis: { condition: 'service_healthy' } },
			environment: { REDIS_HOST: 'redis', REDIS_PORT: '6379', TEST_RUNTIME_INTERNAL_VERSION: inventory.nextcloud.internalVersion },
			ports: ['127.0.0.1:8183:80'],
		})
		const primary = JSON.parse(settings().input).services
		expect(primary).not.toHaveProperty('redis')
		expect(primary['nextcloud-pgsql'].environment).not.toHaveProperty('REDIS_HOST')
		expect(() => stageSettings(inventory, 'cs082-unit-aio', 'pgsql', imageId, 8183, {}, 'staging')).toThrow('Invalid runtime row')
	})
	it('pulls the frozen database and Redis images before provisioning', () => {
		const invoke = jest.fn(() => ({ status: 0 }))
		stage(inventory, aioSettings(), invoke, jest.fn(() => ''))
		expect(invoke.mock.calls.slice(0, 2).map(([, args]) => args.at(-1))).toEqual([
			`${inventory.databases.pgsql.image}@${inventory.databases.pgsql.digest}`, redisImage,
		])
		expect(invoke.mock.calls[2][1]).toEqual(expect.arrayContaining(['up', '--no-build', '--pull', 'never']))
	})
	it('proves Nextcloud really uses the owned Redis for cache, locking and sessions', () => {
		const fixture = redisFixture()
		expect(verifyInstallation(inventory, aioSettings(), fixture.run, fixture.preflight)).toMatchObject({
			status: 'installed-runtime-provider-match', cache: 'redis', locking: 'redis', sessions: 'redis', redis: inventory.cache.redis.version,
		})
		expect(verifyInstallation(inventory, settings(), installationFixture().run, jest.fn())).toMatchObject({ cache: 'apcu' })
	})
	it.each([
		['distributed cache', fixture => { fixture.cache.distributed = '\\OC\\Memcache\\APCu' }],
		['file locking', fixture => { fixture.cache.locking = null }],
		['foreign host', fixture => { fixture.cache.host = 'other-redis' }],
		['failed round trip', fixture => { fixture.cache.roundTrip = false }],
		['file sessions', fixture => { fixture.cache.sessionHandler = 'files' }],
		['mutable image', fixture => { fixture.redis.Config.Image = 'redis:latest' }],
		['foreign project', fixture => { fixture.redis.Config.Labels['com.docker.compose.project'] = 'other' }],
		['unhealthy', fixture => { fixture.redis.State.Health.Status = 'starting' }],
		['foreign peer', fixture => { fixture.cache.peer = '172.99.0.4' }],
	])('fails closed on Redis %s drift', (_, mutate) => {
		const fixture = redisFixture()
		mutate(fixture)
		expect(() => verifyInstallation(inventory, aioSettings(), fixture.run, fixture.preflight)).toThrow(/Redis .*mismatch/)
	})
})

describe('owned-volume preservation recreation', () => {
	const targetImageId = `sha256:${'5'.repeat(64)}`
	const sourceImageId = `sha256:${'4'.repeat(64)}`
	const previous = selectRuntimeSource(readInventory(), 'primary', 'previous')

	function preservationSettings(preservation = { mode: true, upgradeFrom: '35.0.0.10' }) {
		const options = stageSettings(inventory, 'cs082-unit-pgsql', 'pgsql', targetImageId, 8182, preservation)
		options.sourceImageId = sourceImageId
		return options
	}

	function preservationFixture(options = preservationSettings()) {
		const fixture = installationFixture(options)
		fixture.container.Config.Labels['com.docker.compose.service'] = 'nextcloud-pgsql'
		const sourceContainer = JSON.parse(JSON.stringify(fixture.container))
		sourceContainer.Image = sourceImageId
		sourceContainer.Config.Labels['org.chorestrike.test-runtime.inventory'] = selectionHash(previous)
		const sourceActual = { ...fixture.actual, nextcloud: previous.nextcloud.version,
			internalVersion: previous.nextcloud.internalVersion, configuredVersion: previous.nextcloud.internalVersion,
			revision: previous.nextcloud.revision, notificationsRevision: previous.notifications.revision,
			viewerRevision: previous.viewer.revision }
		const targetContainer = JSON.parse(JSON.stringify(fixture.container))
		targetContainer.Image = targetImageId
		let inspections = 0
		const run = jest.fn((command, args) => {
			if (args[0] === 'ps') return 'owned-container-id\n'
			if (args[0] === 'exec') return JSON.stringify(inspections <= 2 ? sourceActual : fixture.actual)
			if (args[0] === 'image') return JSON.stringify([fixture.db.image])
			if (args[0] === 'inspect' && args[1] === options.container) {
				inspections++
				return JSON.stringify([inspections <= 2 ? sourceContainer : targetContainer])
			}
			return JSON.stringify([fixture.db.container])
		})
		return { ...fixture, run, sourceContainer, targetContainer, sourceActual }
	}

	it('recreates only the owned project containers on the pinned target, never its volumes', () => {
		const options = preservationSettings()
		const fixture = preservationFixture(options)
		const invoke = jest.fn(() => ({ status: 0 }))
		expect(preserve(previous, inventory, options, invoke, fixture.run, fixture.preflight)).toMatchObject({
			status: 'installed-runtime-provider-match', project: 'cs082-unit-pgsql', imageId: targetImageId,
		})
		const [downArgs, upArgs] = invoke.mock.calls.map(([, args]) => args)
		expect(downArgs).toContain('down')
		expect(downArgs.join(' ')).not.toMatch(/(^|\s)(-v|--volumes)(\s|$)/)
		expect(upArgs).toEqual(expect.arrayContaining(['up', '--no-build', '--pull', 'never', '--wait']))
		expect(invoke.mock.calls.every(([, args]) => args.includes('--project-name') && args.includes('cs082-unit-pgsql'))).toBe(true)
		const override = JSON.parse(options.input)
		expect(override.services['nextcloud-pgsql'].environment).toMatchObject({
			TEST_RUNTIME_PRESERVATION: 'true', TEST_RUNTIME_UPGRADE_FROM: '35.0.0.10', TEST_RUNTIME_INTERNAL_VERSION: '35.0.1.1',
		})
	})
	it('omits the update source for a same-build recreation', () => {
		const options = preservationSettings({ mode: true })
		expect(JSON.parse(options.input).services['nextcloud-pgsql'].environment).not.toHaveProperty('TEST_RUNTIME_UPGRADE_FROM')
	})
	it.each([
		[{ mode: false, upgradeFrom: '35.0.0.10' }], [{ mode: true, upgradeFrom: '35.0' }],
		[{ mode: true, upgradeFrom: 'stable35' }], [{ upgradeFrom: '35.0.0.10' }],
	])('refuses invalid preservation settings before any Docker call: %j', preservation => {
		expect(() => preservationSettings(preservation)).toThrow('Invalid preservation mode/update source')
	})
	it.each(['missing', 'wrong-image', 'unhealthy', 'foreign'])('refuses to preserve a %s source before teardown', state => {
		const options = preservationSettings()
		const fixture = preservationFixture(options)
		if (state === 'missing') fixture.run = jest.fn((command, args) => args[0] === 'ps' ? '' : JSON.stringify([{}]))
		if (state === 'wrong-image') fixture.sourceContainer.Image = `sha256:${'9'.repeat(64)}`
		if (state === 'unhealthy') fixture.sourceContainer.State.Health.Status = 'starting'
		if (state === 'foreign') fixture.sourceContainer.Config.Labels['com.docker.compose.project'] = 'cs-wp9-existing'
		if (state !== 'missing') {
			const source = fixture.sourceContainer
			const run = fixture.run.getMockImplementation()
			fixture.run = jest.fn((command, args) => args[0] === 'inspect' && args[1] === options.container ? JSON.stringify([source]) : run(command, args))
		}
		const invoke = jest.fn()
		expect(() => preserve(previous, inventory, options, invoke, fixture.run, fixture.preflight)).toThrow(/Preservation (project is missing|source container ownership)/)
		expect(invoke).not.toHaveBeenCalled()
	})
	it.each(['down', 'up'])('propagates a failed %s without claiming preservation', phase => {
		const options = preservationSettings()
		const fixture = preservationFixture(options)
		const invoke = jest.fn((command, args) => ({ status: args.includes(phase) ? 1 : 0 }))
		expect(() => preserve(previous, inventory, options, invoke, fixture.run, fixture.preflight)).toThrow(/preservation (teardown|re-creation) failed/i)
	})
	it.each(['endpoint', 'peer', 'volume', 'inventory', 'version', 'http', 'test-mode'])('refuses source %s drift before any mutation', field => {
		const options = preservationSettings()
		const fixture = preservationFixture(options)
		if (field === 'endpoint') fixture.sourceActual.databaseTargetMatches = false
		if (field === 'peer') fixture.sourceActual.databasePeer = '172.99.0.2'
		if (field === 'volume') fixture.sourceContainer.Mounts[0].Name = 'foreign-config'
		if (field === 'inventory') fixture.sourceContainer.Config.Labels['org.chorestrike.test-runtime.inventory'] = 'foreign'
		if (field === 'version') fixture.sourceActual.configuredVersion = '34.0.4.1'
		if (['http', 'test-mode'].includes(field)) fixture.preflight.mockImplementation(() => { throw new Error('source preflight refused') })
		const invoke = jest.fn()
		expect(() => preserve(previous, inventory, options, invoke, fixture.run, fixture.preflight)).toThrow()
		expect(invoke).not.toHaveBeenCalled()
	})
	it('verifies the previous-patch source selection with its own identity hash', () => {
		const previous = selectRuntimeSource(readInventory(), 'primary', 'previous')
		const previousSettings = stageSettings(previous, 'cs082-unit-pgsql', 'pgsql', sourceImageId, 8182)
		expect(JSON.parse(previousSettings.input).services['nextcloud-pgsql'].environment.TEST_RUNTIME_INTERNAL_VERSION).toBe('35.0.0.10')
		expect(selectionHash(previous)).not.toBe(inventoryHash(inventory))
		expect(previous.nextcloud.version).toBe('35.0.0')
	})
})
