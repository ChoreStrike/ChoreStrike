const crypto = require('node:crypto')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawnSync } = require('node:child_process')
const { main } = require('../../../scripts/export-package.js')
const { qualificationSummary } = require('../../../scripts/run-package-smoke.js')

const sha256 = data => crypto.createHash('sha256').update(data).digest('hex')
const script = path.resolve(__dirname, '../../../scripts/export-package.js')
const commit = 'a'.repeat(40)
const notes = '## Qualification\nLocal package smoke; full release gates separate.\n\n## Limitations\nProduction acceptance pending.\n\n## Requested Action\nVerify archive; do not deploy.\n'
let root, exchange, run, pkg, handoff, manifest, qualification

function json(file, value) {
	fs.writeFileSync(file, JSON.stringify(value, null, 2) + '\n')
}

function writeRecords() {
	json(path.join(pkg, 'package-manifest.json'), manifest)
	json(path.join(pkg, 'identity.json'), { status: 'package-identity-record', gate: 'CS-PLAN-083 WP2', ...manifest })
	json(path.join(run, 'qualification.json'), qualification)
}

function args() {
	return ['--run', run, '--handoff', handoff, '--reviewed']
}

function execute() {
	return main(args(), { CHORESTRIKE_EXCHANGE_DIR: exchange })
}

function expectUnpublished() {
	expect(fs.readdirSync(path.join(exchange, 'von-chorestrike'))).toEqual([])
	expect(fs.readdirSync(exchange).sort()).toEqual(['README.md', 'an-chorestrike', 'von-chorestrike'])
}

function makeQualification() {
	return {
		status: 'package-qualified',
		gate: 'CS-PLAN-083 WP3',
		candidate: { ...manifest.candidate },
		archive: { ...manifest.archive },
		extractedDriftCheck: 'post-qualification-rehash-ok',
		qualifiedAt: '2026-10-10T12:00:00.000Z',
		packageManifest: path.join(pkg, 'package-manifest.json'),
		rows: ['primary', 'aio'].map((row, i) => ({
			row,
			project: `private-fixture-${row}`,
			candidate: commit,
			nextcloud: i === 0 ? '35.0.1' : '34.0.4',
			extractedDriftCheck: 'post-run-rehash-ok',
			teardown: 'owned-down-volumes',
			setup: { project: `private-fixture-${row}`, action: 'setup', results: [{ normalOwnerBootstrap: true }] },
			verify: { project: `private-fixture-${row}`, action: 'verify', results: Array.from({ length: 6 }, () => ({ width: 390 })) },
		})),
	}
}

beforeEach(() => {
	root = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-exchange-test-'))
	exchange = path.join(root, 'exchange')
	run = path.join(root, 'selected-run')
	pkg = path.join(run, 'package')
	handoff = path.join(root, 'notes.md')
	for (const dir of [pkg, path.join(exchange, 'von-chorestrike'), path.join(exchange, 'an-chorestrike')]) fs.mkdirSync(dir, { recursive: true })
	fs.writeFileSync(path.join(exchange, 'README.md'), 'Private test exchange rules; do not import automatically.')
	fs.writeFileSync(handoff, notes)
	const archive = Buffer.from('qualified archive bytes')
	fs.writeFileSync(path.join(pkg, 'chorestrike.tar.gz'), archive)
	manifest = {
		archive: { name: 'chorestrike.tar.gz', bytes: archive.length, sha256: sha256(archive) },
		candidate: { commit, branch: 'master', sourceDateEpoch: 1700000000 },
		files: [{ path: 'app.js', sha256: sha256('app') }],
		source: [{ path: 'src/main.js', sha256: sha256('source') }],
		assetContract: { violations: [] },
	}
	qualification = makeQualification()
	writeRecords()
})

afterEach(() => {
	jest.restoreAllMocks()
	fs.rmSync(root, { recursive: true, force: true })
})

describe('explicit private package export', () => {
	it('accepts the qualification root advertised by the smoke producer', () => {
		const summary = qualificationSummary(qualification)
		expect(summary.runDir).toBe(run)
		expect(main(['--run', summary.runDir, '--handoff', handoff, '--reviewed'], { CHORESTRIKE_EXCHANGE_DIR: exchange }).status).toBe('package-exported')
	})

	it('checks local configuration without changing or disclosing it', () => {
		expect(main(['--check'], { CHORESTRIKE_EXCHANGE_DIR: exchange })).toEqual({ status: 'exchange-configured' })
		expectUnpublished()
	})

	it('publishes only four files, preserving the original package bytes and incoming feedback', () => {
		fs.writeFileSync(path.join(pkg, 'webpack.log'), 'private build output')
		fs.writeFileSync(path.join(run, 'screenshot.png'), 'private image')
		fs.writeFileSync(path.join(exchange, 'an-chorestrike', 'feedback.md'), 'Do not import')
		const result = execute()
		const out = path.join(exchange, 'von-chorestrike', result.handoff)
		expect(result).toMatchObject({ status: 'package-exported', commit, archiveSha256: manifest.archive.sha256 })
		expect(fs.readdirSync(out).sort()).toEqual(['chorestrike.tar.gz', 'handoff.md', 'identity.json', 'package-manifest.json'])
		for (const name of ['chorestrike.tar.gz', 'package-manifest.json', 'identity.json']) {
			expect(fs.readFileSync(path.join(out, name)).equals(fs.readFileSync(path.join(pkg, name)))).toBe(true)
		}
		const document = fs.readFileSync(path.join(out, 'handoff.md'), 'utf8')
		expect(document).toContain(sha256(fs.readFileSync(path.join(pkg, 'identity.json'))))
		expect(document).toContain(sha256(fs.readFileSync(path.join(pkg, 'package-manifest.json'))))
		expect(document).toContain(sha256(fs.readFileSync(path.join(run, 'qualification.json'))))
		expect(document).toContain(notes)
		for (const privateText of [root, 'private-fixture-', 'private build output']) expect(document + JSON.stringify(result)).not.toContain(privateText)
		expect(fs.readFileSync(path.join(exchange, 'an-chorestrike', 'feedback.md'), 'utf8')).toBe('Do not import')
		expect(execute().handoff).not.toBe(result.handoff)
	})

	it.each([undefined, '', 'relative/exchange'])('rejects missing or relative configuration: %s', value => {
		expect(() => main(['--check'], { CHORESTRIKE_EXCHANGE_DIR: value })).toThrow(/CHORESTRIKE_EXCHANGE_DIR/)
		expectUnpublished()
	})

	it('rejects exchange destinations in or above the source tree', () => {
		const repo = path.resolve(__dirname, '../../..')
		for (const destination of [repo, path.dirname(repo), path.join(repo, 'scripts')]) {
			expect(() => main(['--check'], { CHORESTRIKE_EXCHANGE_DIR: destination })).toThrow(/separate directory trees/)
		}
	})

	it.each(['README.md', 'an-chorestrike', 'von-chorestrike'])('rejects missing exchange structure: %s', name => {
		fs.rmSync(path.join(exchange, name), { recursive: true })
		expect(() => execute()).toThrow(/Export failed/)
	})

	it('requires nonempty exchange rules and explicit human review', () => {
		expect(() => main(args().slice(0, -1), { CHORESTRIKE_EXCHANGE_DIR: exchange })).toThrow(/--reviewed/)
		fs.writeFileSync(path.join(exchange, 'README.md'), ' \n')
		expect(() => execute()).toThrow(/README/)
		expectUnpublished()
	})

	it.each(['Qualification', 'Limitations', 'Requested Action'])('rejects empty handoff section: %s', section => {
		fs.writeFileSync(handoff, notes.replace(new RegExp(`(## ${section}\\n)[^\\n]+`), '$1'))
		expect(() => execute()).toThrow(/nonempty/)
		expectUnpublished()
	})

	it.each(['exchange', 'outgoing', 'package'])('rejects directory links: %s', kind => {
		const target = kind === 'exchange' ? exchange : kind === 'outgoing' ? path.join(exchange, 'von-chorestrike') : pkg
		const original = path.join(root, 'original')
		fs.renameSync(target, original)
		fs.symlinkSync(original, target, 'junction')
		expect(() => execute()).toThrow(/without links/)
	})
})

describe('package identity and qualification refusals', () => {
	it('rejects archive drift without publishing partial output', () => {
		fs.appendFileSync(path.join(pkg, 'chorestrike.tar.gz'), 'tampered')
		expect(() => execute()).toThrow(/Archive size or SHA-256/)
		expectUnpublished()
	})

	it('rejects mixed build manifests', () => {
		json(path.join(pkg, 'identity.json'), { status: 'package-identity-record', gate: 'CS-PLAN-083 WP2', ...manifest, source: [] })
		expect(() => execute()).toThrow(/same build run/)
		expectUnpublished()
	})

	it.each([
		['archive filename', m => { m.archive.name = '../outside.tar.gz' }],
		['archive size', m => { m.archive.bytes++ }],
		['archive hash', m => { m.archive.sha256 = 'b'.repeat(64) }],
		['source commit', m => { m.candidate.commit = 'invalid' }],
		['source evidence', m => { delete m.source }],
		['file inventory', m => { m.files = [] }],
		['asset contract', m => { m.assetContract.violations = ['failed'] }],
	])('rejects invalid %s', (label, change) => {
		change(manifest)
		writeRecords()
		expect(() => execute()).toThrow()
		expectUnpublished()
	})

	it.each([
		['status', q => { q.status = 'failed' }],
		['commit', q => { q.candidate.commit = 'b'.repeat(40) }],
		['archive', q => { q.archive.sha256 = 'b'.repeat(64) }],
		['post-run drift', q => { delete q.extractedDriftCheck }],
		['date', q => { q.qualifiedAt = 'invalid' }],
		['missing row', q => { q.rows.pop() }],
		['duplicate row', q => { q.rows[1] = q.rows[0] }],
		['row candidate', q => { q.rows[0].candidate = 'b'.repeat(40) }],
		['row failure', q => { q.rows[0].failure = { retained: true } }],
		['case count', q => { q.rows[0].verify.results.pop() }],
		['empty case', q => { q.rows[0].verify.results[0] = null }],
		['report identity', q => { q.rows[0].setup.project = 'different-run' }],
		['failed case', q => { q.rows[0].verify.results[0].passed = false }],
	])('rejects mismatched or incomplete qualification: %s', (label, change) => {
		change(qualification)
		writeRecords()
		expect(() => execute()).toThrow()
		expectUnpublished()
	})

	it('requires a qualification record, not merely a successful build', () => {
		fs.unlinkSync(path.join(run, 'qualification.json'))
		expect(() => execute()).toThrow(/Export failed/)
		expectUnpublished()
	})
})

describe('publication failure and privacy boundaries', () => {
	it('refuses an existing handoff even when empty', () => {
		jest.spyOn(crypto, 'randomUUID').mockReturnValue('fixed-id')
		const existing = path.join(exchange, 'von-chorestrike', `handoff-${commit.slice(0, 12)}-fixed-id`)
		fs.mkdirSync(existing)
		expect(() => execute()).toThrow(/refusing to overwrite/)
		expect(fs.readdirSync(existing)).toEqual([])
	})

	it.each(['write', 'corruption', 'rename'])('never publishes incomplete copies on %s failure', failure => {
		if (failure === 'rename') jest.spyOn(fs, 'renameSync').mockImplementation(() => { throw new Error(exchange) })
		else {
			const write = fs.writeFileSync
			jest.spyOn(fs, 'writeFileSync').mockImplementation((file, data, options) => {
				if (path.basename(file) === 'identity.json') {
					if (failure === 'write') throw new Error(exchange)
					return write(file, 'corrupt', options)
				}
				return write(file, data, options)
			})
		}
		expect(() => execute()).toThrow(/Export failed|Copied handoff bytes/)
		expectUnpublished()
	})

	it('refuses to publish when the outgoing direction becomes a link during export', () => {
		const outgoing = path.join(exchange, 'von-chorestrike')
		const moved = path.join(root, 'swapped-outgoing')
		const mkdtemp = fs.mkdtempSync
		jest.spyOn(fs, 'mkdtempSync').mockImplementation(prefix => {
			fs.renameSync(outgoing, moved)
			fs.symlinkSync(moved, outgoing, 'junction')
			return mkdtemp(prefix)
		})
		expect(() => execute()).toThrow(/without links/)
		expect(fs.readdirSync(moved)).toEqual([])
		expectUnpublished()
	})

	it('leaves hard-interrupted staging outside the published direction', () => {
		const code = 'const fs = require(\'node:fs\'); const write = fs.writeFileSync; fs.writeFileSync = (...args) => { if (String(args[0]).endsWith(\'identity.json\')) process.exit(71); return write(...args); }; require(process.argv[1]).main(process.argv.slice(2));'
		const result = spawnSync(process.execPath, ['-e', code, script, ...args()], { env: { ...process.env, CHORESTRIKE_EXCHANGE_DIR: exchange }, encoding: 'utf8' })
		expect(result.status).toBe(71)
		expect(fs.readdirSync(path.join(exchange, 'von-chorestrike'))).toEqual([])
		expect(fs.readdirSync(exchange).filter(name => name.startsWith('.chorestrike-export-'))).toHaveLength(1)
	})

	it('prints only the JSON result without private paths on success', () => {
		const result = spawnSync(process.execPath, [script, ...args()], { env: { ...process.env, CHORESTRIKE_EXCHANGE_DIR: exchange }, encoding: 'utf8' })
		expect(result.status).toBe(0)
		expect(result.stderr).toBe('')
		const parsed = JSON.parse(result.stdout)
		expect(parsed).toMatchObject({ status: 'package-exported', commit, archiveSha256: manifest.archive.sha256 })
		expect(typeof parsed.handoff).toBe('string')
		for (const privateText of [root, 'private-fixture-', 'notes.md']) expect(`${result.stdout}\n${result.stderr}`).not.toContain(privateText)
	})

	it.each(['json', 'filesystem', 'argument'])('does not print private paths or input fragments on %s errors', failure => {
		let input = args()
		if (failure === 'json') fs.writeFileSync(path.join(pkg, 'identity.json'), '{"private-marker": invalid}')
		if (failure === 'filesystem') fs.unlinkSync(handoff)
		if (failure === 'argument') input = ['--unknown', exchange, 'private-marker']
		const result = spawnSync(process.execPath, [script, ...input], { env: { ...process.env, CHORESTRIKE_EXCHANGE_DIR: exchange }, encoding: 'utf8' })
		expect(result.status).toBe(1)
		expect(result.stdout).toBe('')
		expect(result.stderr).toContain('[package-export]')
		expect(result.stderr).not.toContain(root)
		expect(result.stderr).not.toContain('private-marker')
		expectUnpublished()
	})
})
