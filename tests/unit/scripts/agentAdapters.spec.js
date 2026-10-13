/** @jest-environment node */
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { MARKER, buildAdapters, checkAdapters, syncAdapters } = require('../../../scripts/sync-agent-adapters.js')

// Canonical AI standards live in docs/standards/ai/; the tool folders only hold
// generated adapters. This suite keeps the checked-in adapters in sync (so
// `npm test` fails on drift) and pins the generator's behavior on a fixture.
const ROOT = path.resolve(__dirname, '../../..')

const write = (root, rel, content) => {
	fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
	fs.writeFileSync(path.join(root, rel), content)
}

describe('agent adapters', () => {
	let fixture

	beforeEach(() => {
		fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-adapters-'))
		write(fixture, 'docs/standards/ai/skills/demo/SKILL.md', '---\nname: demo\ndescription: Demo skill\n---\n\n# Demo\n')
		write(fixture, 'docs/standards/ai/commands/do-it.md', '---\ndescription: Do it\n---\n\nRun $ARGUMENTS.\n')
		write(fixture, 'docs/standards/ai/agents/checker.md', '---\nname: checker\ndescription: Read-only checker\nread-only: true\n---\n\nCheck only.\n')
	})

	afterEach(() => {
		fs.rmSync(fixture, { recursive: true, force: true })
	})

	it('checked-in adapters match the canonical sources', () => {
		expect(checkAdapters(ROOT)).toEqual([])
	})

	it('generates a skill pointer, command copies for both tools and a read-only Claude agent', () => {
		const adapters = buildAdapters(fixture)

		expect(Object.keys(adapters).sort()).toEqual([
			'.claude/agents/checker.md',
			'.claude/commands/do-it.md',
			'.claude/skills/demo/SKILL.md',
			'.kilo/command/do-it.md',
		])
		expect(adapters['.claude/skills/demo/SKILL.md']).toContain('name: demo\ndescription: Demo skill\n')
		expect(adapters['.claude/skills/demo/SKILL.md']).toContain('(../../../docs/standards/ai/skills/demo/SKILL.md)')
		expect(adapters['.kilo/command/do-it.md']).toBe(adapters['.claude/commands/do-it.md'])
		expect(adapters['.kilo/command/do-it.md']).toContain('Run $ARGUMENTS.')
		expect(adapters['.claude/agents/checker.md']).toContain('tools: Read, Grep, Glob\n')
		for (const content of Object.values(adapters)) expect(content).toContain(MARKER)
	})

	it('reports missing, edited and stale adapters until a sync repairs them', () => {
		expect(checkAdapters(fixture)).toHaveLength(4)

		syncAdapters(fixture)
		expect(checkAdapters(fixture)).toEqual([])

		write(fixture, '.kilo/command/do-it.md', 'hand edited')
		write(fixture, '.claude/commands/retired.md', `---\ndescription: Old\n---\n\n${MARKER}\n`)
		write(fixture, '.claude/commands/personal.md', 'user-owned, never generated')
		expect(checkAdapters(fixture)).toEqual([
			'.kilo/command/do-it.md: out of date',
			'.claude/commands/retired.md: stale generated adapter',
		])

		expect(syncAdapters(fixture).removed).toEqual(['.claude/commands/retired.md'])
		expect(checkAdapters(fixture)).toEqual([])
		expect(fs.existsSync(path.join(fixture, '.claude/commands/personal.md'))).toBe(true)
	})

	it('rejects a skill whose frontmatter name differs from its directory', () => {
		write(fixture, 'docs/standards/ai/skills/other/SKILL.md', '---\nname: renamed\ndescription: X\n---\n')

		expect(() => buildAdapters(fixture)).toThrow('must equal directory "other"')
	})
})
