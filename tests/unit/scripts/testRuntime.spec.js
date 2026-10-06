/** @jest-environment node */
const fs = require('node:fs')
const path = require('node:path')
const { validateInventory, selectRuntime, stableRelease, tagRevision, registryIdentity, checkStable, inventoryHash, buildArguments, assertInstalled, verifyImage, execute } = require('../../../scripts/test-runtime')

const frozen = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../docker/test-runtime.json'), 'utf8'))
const copy = () => JSON.parse(JSON.stringify(frozen))
const release = version => ({ tag_name: `v${version}`, draft: false, prerelease: false })

function officialFixtures(inventory = frozen) {
	return {
		fetchMetadata: jest.fn(async url => {
			if (url.includes('/server/releases/')) return JSON.stringify(release(inventory.nextcloud.version))
			if (url.includes('/all-in-one/releases/')) return JSON.stringify(release(inventory.aio.version))
			return `ENV NEXTCLOUD_VERSION=${inventory.aio.nextcloudVersion}\n`
		}),
		execute: jest.fn((command, args) => {
			if (command === 'git') {
				const rows = [inventory, ...Object.values(inventory.compatibility || {})]
				const sources = rows.flatMap(row => args[1].includes('notifications') ? [row.notifications] : [row.nextcloud, row.previous])
				const source = sources.find(item => args[2] === `refs/tags/${item.ref}`)
				return `${source.revision}\trefs/tags/${source.ref}\n`
			}
			const image = [inventory.php, inventory.composer, inventory.databases.mysql, inventory.databases.pgsql].find(item => item.image === args[3])
			return JSON.stringify({ digest: image.digest, manifests: [{ digest: image.platformDigest, platform: { os: 'linux', architecture: 'amd64' } }] })
		}),
	}
}

describe('frozen runtime inventory', () => {
	it('validates the checked-in candidate without calling upstream services', () => {
		expect(validateInventory(frozen)).toBe(frozen)
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
		['distribution', value => { value.aio.version = 'latest' }],
		['AIO compatibility identity', value => { value.compatibility.aio.nextcloud.version = '34.0.5' }],
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
		expect(primary.nextcloud.version).toBe('35.0.1')
		expect(aio.nextcloud.version).toBe('34.0.4')
		expect(aio.previous.version).toBe('34.0.3')
		expect(inventoryHash(aio)).toBe('9a3e384c673473610ade6ca774dfd31d9b60c132dee4d05611e987fc729fb3c9')
		expect(inventoryHash(primary)).not.toBe(inventoryHash(aio))
		expect(value).toEqual(frozen)
		expect(() => selectRuntime(value, 'unknown')).toThrow('runtime selector')
		expect(() => selectRuntime(primary, 'aio')).toThrow('missing compatibility row')
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
	it('requires the frozen architecture rather than taking the first registry row', () => {
		const manifest = { digest: frozen.php.digest, manifests: [{ digest: frozen.php.platformDigest, platform: { os: 'linux', architecture: 'arm64' } }] }
		expect(() => registryIdentity(manifest, frozen.platform)).toThrow('registry manifest/platform')
	})
})

describe('read-only stable discovery', () => {
	it('fails on diagnostic output even if a native probe exits successfully', () => {
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('diagnostic')"])).toThrow('emitted diagnostics')
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('warning')"], 'expected metadata')).toThrow('emitted diagnostics')
	})
	it('reports matching artifacts without upgrading a candidate to qualified', async () => {
		const io = officialFixtures()
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'current', qualification: 'candidate', drift: [] })
		expect(io.execute.mock.calls.every(([command, args]) => command === 'git' ? args[0] === 'ls-remote' : args.slice(0, 3).join(' ') === 'buildx imagetools inspect')).toBe(true)
	})
	it('reports newer upstream separately from the unchanged AIO bundle without rewriting the lock', async () => {
		const value = copy()
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases/') ? JSON.stringify(release('35.0.2'))
			: url.includes('/releases/') ? JSON.stringify(release(frozen.aio.version)) : `ENV NEXTCLOUD_VERSION=${frozen.aio.nextcloudVersion}`)
		await expect(checkStable(value, io)).resolves.toMatchObject({ status: 'update-needed', upstream: '35.0.2', aioNextcloud: '34.0.4', drift: ['upstream-nextcloud'] })
		expect(value).toEqual(frozen)
	})
	it('reports a different AIO bundle even when its Nextcloud version stays unchanged', async () => {
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases/') ? JSON.stringify(release(frozen.nextcloud.version))
			: url.includes('/releases/') ? JSON.stringify(release('14.3.0')) : `ENV NEXTCLOUD_VERSION=${frozen.aio.nextcloudVersion}`)
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'update-needed', drift: ['aio-bundle'] })
	})
	it('detects moved source tags and image digests', async () => {
		const io = officialFixtures()
		const execute = io.execute.getMockImplementation()
		io.execute.mockImplementation((command, args) => command === 'git' ? `${'a'.repeat(40)}\t${args[2]}\n`
			: JSON.stringify({ ...JSON.parse(execute(command, args)), digest: `sha256:${'f'.repeat(64)}` }))
		const result = await checkStable(frozen, io)
		expect(result.status).toBe('update-needed')
		expect(result.drift).toHaveLength(10)
	})
	it.each(['release network failure', 'registry failure', 'malformed release', 'malformed AIO bundle', 'prerelease'])('fails closed on %s', async failure => {
		const io = officialFixtures()
		if (failure === 'release network failure') io.fetchMetadata.mockRejectedValue(new Error('unavailable'))
		if (failure === 'registry failure') io.execute.mockImplementation(() => { throw new Error('registry unavailable') })
		if (failure === 'malformed release') io.fetchMetadata.mockResolvedValue('{}')
		if (failure === 'malformed AIO bundle') io.fetchMetadata.mockImplementation(async url => url.includes('/releases/') ? JSON.stringify(release('34.0.4')) : '')
		if (failure === 'prerelease') io.fetchMetadata.mockResolvedValue(JSON.stringify({ ...release('35.0.2'), prerelease: true }))
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
		const workflow = fs.readFileSync(path.resolve(__dirname, '../../../.forgejo/workflows/runtime-check.yml'), 'utf8')
		expect(workflow).toContain("cron: '0 8 * * 1'")
		expect(workflow).toContain('workflow_dispatch:')
		expect(workflow).toContain('run: npm run test:runtime:validate')
		expect(workflow).toContain('run: npm run test:runtime:check')
		expect(workflow).not.toMatch(/docker (compose|build|run)|git (commit|push)/)
	})
	it('uses only locked identities and refuses non-owned image tags', () => {
		const args = buildArguments(frozen, 'cs082-runtime-34.0.4-local')
		expect(args).toEqual(expect.arrayContaining([
		`PHP_IMAGE=${frozen.php.image}@${frozen.php.digest}`,
		`COMPOSER_IMAGE=${frozen.composer.image}@${frozen.composer.digest}`,
		`NEXTCLOUD_REVISION=${frozen.nextcloud.revision}`, `NOTIFICATIONS_REVISION=${frozen.notifications.revision}`,
	]))
		expect(args.join(' ')).not.toMatch(/latest|stable34/)
		expect(() => buildArguments(frozen, 'docker-nextcloud-test')).toThrow('owned candidate image name')
	})
	it('asserts actual runtime identity instead of trusting healthy older containers', () => {
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version,
			pcov: frozen.tools.pcov, apcu: frozen.tools.apcu, inventoryHash: inventoryHash(frozen),
		}
		expect(assertInstalled(frozen, actual)).toEqual(actual)
		for (const key of Object.keys(actual)) expect(() => assertInstalled(frozen, { ...actual, [key]: 'older' })).toThrow(`Installed runtime mismatch: ${key}`)
	})
	it('rejects old or mismatched images before creating any ephemeral probe container', () => {
		const run = jest.fn(() => JSON.stringify([{ Os: 'linux', Architecture: 'amd64', Config: { Labels: {} } }]))
		expect(() => verifyImage(frozen, 'cs082-runtime-34.0.4-local', run)).toThrow('inventory/platform mismatch')
		expect(run).toHaveBeenCalledTimes(1)
	})
	it('verifies the frozen image and tools without claiming an installed/provider gate', () => {
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version, pcov: frozen.tools.pcov, apcu: frozen.tools.apcu,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: `sha256:${'1'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(frozen) } } }])
			if (args.includes('composer')) return `Composer version ${frozen.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		expect(verifyImage(frozen, 'cs082-runtime-34.0.4-local', run)).toMatchObject({ status: 'frozen-image-identity-match', qualification: 'candidate', composer: frozen.composer.version })
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes('--rm') && args.includes('none'))).toBe(true)
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes(`sha256:${'1'.repeat(64)}`) && !args.includes('cs082-runtime-34.0.4-local'))).toBe(true)
		const original = run.getMockImplementation()
		run.mockImplementation((command, args) => args.at(-1).includes('notifications-ready') ? '' : original(command, args))
		expect(() => verifyImage(frozen, 'cs082-runtime-34.0.4-local', run)).toThrow('notifications runtime autoload mismatch')
		run.mockImplementation((command, args) => args.includes('composer') ? 'Composer version 2.0.0' : original(command, args))
		expect(() => verifyImage(frozen, 'cs082-runtime-34.0.4-local', run)).toThrow('mismatch: composer')
	})
})
