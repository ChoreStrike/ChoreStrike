const fs = require('node:fs')
const path = require('node:path')
const {
	readGitState, buildTar, readTar, gzipDeterministic, assertSafeArchiveName,
	selectRuntimeFiles, classifyLineEndings, compareToBlob, checkAssetContract,
	validatePackageConfig, assemblePackage, sha256Hex, sha1Hex,
} = require('../../../scripts/build-package')

const root = path.resolve(__dirname, '../../..')
const fixtures = path.join(root, 'tests/package/fixtures')
const config = JSON.parse(fs.readFileSync(path.join(root, 'docker/package-build.json'), 'utf8'))

describe('build-package clean-HEAD gate', () => {
	const probeOf = stdout => () => ({ stdout })

	it('refuses a dirty tree', () => {
		expect(() => readGitState(probeOf('a'.repeat(40)))).toThrow(/dirty tree/)
	})

	it('refuses a detached HEAD', () => {
		expect(() => readGitState((command, args) => ({ stdout: args[0] === 'rev-parse' ? 'b'.repeat(40) : '' })))
			.toThrow(/detached HEAD/)
	})

	it('accepts a clean branch tip and derives the commit epoch', () => {
		const state = readGitState((command, args) => ({
			stdout: args[0] === 'rev-parse' ? 'c'.repeat(40)
				: args[0] === 'symbolic-ref' ? 'refs/heads/master'
					: args[0] === 'status' ? '' : '1700000000',
		}))
		expect(state).toEqual({ head: 'c'.repeat(40), branch: 'refs/heads/master', epoch: 1700000000 })
	})
})

describe('build-package deterministic archive', () => {
	const entries = [
		{ name: 'js', type: 'dir', mode: 0o755, mtime: 1700000000, data: Buffer.alloc(0) },
		{ name: 'js/chorestrike-main.js', type: 'file', mode: 0o644, mtime: 1700000000, data: Buffer.from('main') },
		{ name: 'lib/AppInfo/Application.php', type: 'file', mode: 0o644, mtime: 1700000000, data: Buffer.from('php') },
	]

	it('produces identical bytes regardless of input order', () => {
		expect(buildTar(entries).equals(buildTar([...entries].reverse()))).toBe(true)
	})

	it('round-trips names and bytes', () => {
		const parsed = readTar(buildTar(entries))
		expect(parsed.map(entry => entry.name)).toEqual(['js', 'js/chorestrike-main.js', 'lib/AppInfo/Application.php'])
		expect(parsed[1].data.toString()).toBe('main')
	})

	it('normalizes metadata: 0:0 ownership, 0644/0755, packaging epoch mtime', () => {
		const tar = buildTar(entries)
		expect(tar.subarray(108, 124).toString()).toBe('0000000\0'.repeat(2))
		expect(tar.subarray(100, 108).toString().startsWith('0000755')).toBe(true)
		expect(tar.subarray(512 + 100, 512 + 108).toString().startsWith('0000644')).toBe(true)
		expect(tar.subarray(512 + 136, 512 + 148).toString().startsWith((1700000000).toString(8).padStart(11, '0'))).toBe(true)
	})

	it('has a stable package manifest hash across assemblies', () => {
		const manifestOf = tar => sha256Hex(gzipDeterministic(tar))
		expect(manifestOf(buildTar(entries))).toBe(manifestOf(buildTar([...entries].reverse())))
	})

	it('skips pax global/extended metadata members (git archive commit header)', () => {
		const record = Buffer.from('52 comment=commit ' + 'e'.repeat(40) + '\n')
		const paxHeader = Buffer.alloc(512)
		paxHeader.write('pax_global_header', 0, 'utf8')
		paxHeader.write((0o644).toString(8).padStart(7, '0') + '\0', 100, 8, 'utf8')
		paxHeader.write(record.length.toString(8).padStart(11, '0') + '\0', 124, 12, 'utf8')
		paxHeader.fill(0x20, 148, 156)
		paxHeader.write('g', 156, 1, 'utf8')
		let sum = 0
		for (const byte of paxHeader) sum += byte
		paxHeader.write(sum.toString(8).padStart(6, '0') + '\0 ', 148, 8, 'utf8')
		const stream = Buffer.concat([paxHeader, record, Buffer.alloc(512 - record.length), buildTar(entries)])
		expect(readTar(stream).map(entry => entry.name)).toEqual(['js', 'js/chorestrike-main.js', 'lib/AppInfo/Application.php'])
	})

	it('rejects traversal, absolute paths and host separators', () => {
		expect(() => assertSafeArchiveName('a/../b')).toThrow(/traversal/)
		expect(() => assertSafeArchiveName('/etc/passwd')).toThrow(/Unsafe/)
		expect(() => assertSafeArchiveName('a\\b')).toThrow(/separator/)
		expect(() => assertSafeArchiveName('a//b')).toThrow(/traversal/)
	})
})

describe('build-package runtime selection', () => {
	const allowlist = config.runtimeAllowlist
	const forbidden = config.forbidden

	it('selects only allowlisted runtime files from mixed candidates', () => {
		const selected = selectRuntimeFiles([
			{ path: 'lib/AppInfo/Application.php', mode: '100644' },
			{ path: 'js/chorestrike-main.js', mode: '100644' },
			{ path: 'docs/engineering/testing.md', mode: '100644' },
			{ path: 'tests/unit/a.spec.js', mode: '100644' },
			{ path: 'LICENSE', mode: '100644' },
			{ path: 'composer.lock', mode: '100644' },
		], allowlist, forbidden).map(entry => entry.path)
		expect(selected).toEqual(['lib/AppInfo/Application.php', 'js/chorestrike-main.js', 'LICENSE', 'composer.lock'])
	})

	it.each([
		['source maps', 'js/chorestrike-main.js.map'],
		['node_modules', 'js/node_modules/x.js'],
		['tests', 'lib/tests/x.php'],
		['private evidence', 'lib/private/evidence.json'],
		['docker tooling', 'lib/docker/Dockerfile'],
	])('rejects forbidden archive members: %s', (label, name) => {
		expect(() => selectRuntimeFiles([{ path: name, mode: '100644' }], allowlist, forbidden)).toThrow(/Forbidden/)
	})

	it('rejects symlinks', () => {
		expect(() => selectRuntimeFiles([{ path: 'lib/link.php', mode: '120000' }], allowlist, forbidden)).toThrow(/Symlinks/)
	})
})

describe('build-package source-vs-blob identity', () => {
	it('matches exact blobs and records CRLF-only provenance', () => {
		const blob = sha1Hex(Buffer.from('line\n'))
		expect(compareToBlob(Buffer.from('line\n'), blob)).toBe('match')
		expect(compareToBlob(Buffer.from('line\r\n'), blob)).toBe('crlf')
		expect(compareToBlob(Buffer.from('tampered\n'), blob)).toBe('drift')
	})

	it('classifies line-ending provenance', () => {
		expect(classifyLineEndings(Buffer.from('a\nb\n'))).toBe('lf')
		expect(classifyLineEndings(Buffer.from('a\r\nb\r\n'))).toBe('crlf')
		expect(classifyLineEndings(Buffer.from('a\nb\r\n'))).toBe('mixed')
	})
})

describe('build-package asset contract', () => {
	const contract = { entry: 'main', ...config.assetContract }
	const load = name => JSON.parse(fs.readFileSync(path.join(fixtures, name), 'utf8'))

	it('passes the valid shared/vendor chunk graph fixture', () => {
		expect(checkAssetContract(load('contract-valid-stats.json'), contract)).toEqual([])
	})

	it('rejects the dangling referenced asset fixture', () => {
		const dangling = { ...contract, referenced: [...contract.referenced, 'chorestrike-missing.js'] }
		expect(checkAssetContract(load('contract-dangling-stats.json'), dangling).join('\n')).toMatch(/dangling/)
	})

	it('rejects the orphaned chunk fixture', () => {
		expect(checkAssetContract(load('contract-orphan-stats.json'), contract).join('\n')).toMatch(/orphaned/)
	})

	it('accepts a named, reviewed exception for the orphan', () => {
		const exceptions = { ...contract, exceptions: ['chorestrike-orphan.js'] }
		expect(checkAssetContract(load('contract-orphan-stats.json'), exceptions)).toEqual([])
	})

	it('rejects a stale exception', () => {
		const exceptions = { ...contract, exceptions: ['chorestrike-gone.js'] }
		expect(checkAssetContract(load('contract-valid-stats.json'), exceptions).join('\n')).toMatch(/stale exception/)
	})

	it('rejects missing entrypoints', () => {
		expect(checkAssetContract({ assets: [], chunks: [] }, contract).join('\n')).toMatch(/entrypoint/)
	})
})

describe('build-package configuration', () => {
	it('accepts the tracked builder configuration', () => {
		expect(validatePackageConfig(config)).toBe(config)
	})

	it.each([
		['schemaVersion', { schemaVersion: 2 }],
		['platform', { platform: 'windows/amd64' }],
		['node digest', { node: { ...config.node, digest: 'sha256:xyz' } }],
		['composer image', { composer: { ...config.composer, image: 'composer:latest' } }],
		['empty allowlist', { runtimeAllowlist: [] }],
		['missing referenced assets', { assetContract: { ...config.assetContract, referenced: [] } }],
	])('rejects invalid configuration: %s', (label, override) => {
		expect(() => validatePackageConfig({ ...config, ...override })).toThrow(/package-build\.json/)
	})
})

describe('build-package assembly', () => {
	it('assembles tracked + built files with normalized metadata', () => {
		const assembled = assemblePackage({
			trackedFiles: [
				{ path: 'appinfo/info.xml', mode: '100644', data: Buffer.from('<xml>') },
				{ path: 'docs/secret.md', mode: '100644', data: Buffer.from('secret') },
			],
			builtFiles: [{ name: 'js/chorestrike-main.js', type: 'file', data: Buffer.from('js') }],
			allowlist: config.runtimeAllowlist,
			forbidden: config.forbidden,
			epoch: 1700000000,
		})
		expect(assembled.files.map(file => file.path)).toEqual(['appinfo/info.xml', 'js/chorestrike-main.js'])
		const parsed = readTar(assembled.tar)
		expect(parsed.filter(entry => entry.type === 'file').map(entry => entry.name)).toEqual(['appinfo/info.xml', 'js/chorestrike-main.js'])
	})
})
