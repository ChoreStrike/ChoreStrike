/** @jest-environment node */
const { readInventory, inventoryHash } = require('../../../scripts/test-runtime')
const { stageSettings, composeArguments, assertFreshProject, assertProviderImage, verifyInstallation, stage } = require('../../../scripts/test-runtime-stage')

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
		php: inventory.php.version, sqlite: inventory.databases.sqlite.version, pcov: inventory.tools.pcov, apcu: inventory.tools.apcu,
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
		expect(JSON.stringify(invoke.mock.calls)).not.toMatch(/down|force-recreate/)
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
