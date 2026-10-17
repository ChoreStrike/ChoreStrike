/** @jest-environment node */
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { install, worktree, HOOKS_PATH } = require('../../../scripts/git-guard')

const HOOKS_DIR = path.resolve(__dirname, '../../../.githooks')
// A synthetic identity for throwaway repositories only.
const IDENTITY = ['-c', 'user.name=Branch Guard Test', '-c', 'user.email=guard@example.invalid']

let repo

function git(args, env = {}) {
	return spawnSync('git', [...IDENTITY, ...args], { cwd: repo, encoding: 'utf8', env: { ...process.env, CS_ALLOW_MASTER: '', ...env } })
}

function commit(message, env) {
	fs.writeFileSync(path.join(repo, `${message}.txt`), message)
	git(['add', '.'])
	return git(['commit', '-q', '-m', message], env)
}

beforeEach(() => {
	repo = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-git-guard-'))
	git(['init', '-q', '-b', 'master'])
	git(['config', 'core.hooksPath', HOOKS_DIR.replace(/\\/g, '/')])
	expect(git(['commit', '-q', '--allow-empty', '-m', 'root'], { CS_ALLOW_MASTER: '1' }).status).toBe(0)
})

afterEach(() => {
	fs.rmSync(repo, { recursive: true, force: true })
})

describe('pre-commit branch guard', () => {
	it('refuses a direct commit on master and explains the way out', () => {
		const result = commit('direct')

		expect(result.status).not.toBe(0)
		expect(result.stderr).toMatch(/Refusing a direct commit on 'master'/)
		expect(result.stderr).toMatch(/CS_ALLOW_MASTER=1/)
		expect(git(['rev-list', '--count', 'HEAD']).stdout.trim()).toBe('1')
	})

	it('accepts a master commit the user explicitly allowed for that command', () => {
		expect(commit('allowed', { CS_ALLOW_MASTER: '1' }).status).toBe(0)
	})

	it('accepts commits on a feature branch and a fast-forward into master', () => {
		git(['switch', '-q', '-c', 'feat/x'])
		expect(commit('feature').status).toBe(0)
		git(['switch', '-q', 'master'])

		expect(git(['merge', '-q', '--ff-only', 'feat/x']).status).toBe(0)
		expect(git(['rev-list', '--count', 'HEAD']).stdout.trim()).toBe('2')
	})

	it('leaves a detached HEAD alone', () => {
		git(['switch', '-q', '--detach'])

		expect(commit('detached').status).toBe(0)
	})

	it('refuses a commit on another branch than the worktree pin, accepts the pinned one', () => {
		git(['switch', '-q', '-c', 'feat/pinned'])
		expect(worktree('pin', repo)).toMatch(/pinned .* to 'feat\/pinned'/)
		expect(commit('on-pin').status).toBe(0)

		git(['switch', '-q', '-c', 'feat/other'])
		const result = commit('off-pin')
		expect(result.status).not.toBe(0)
		expect(result.stderr).toMatch(/pinned to 'feat\/pinned', but HEAD is on 'feat\/other'/)

		worktree('unpin', repo)
		expect(commit('unpinned').status).toBe(0)
	})

	it('reports worktree, branch, pin and hook state', () => {
		expect(worktree('status', repo)).toMatch(/branch master · pin \(none\) · hooks /)
	})
})

describe('git-guard install', () => {
	it('activates the tracked hooks once and is idempotent', () => {
		git(['config', '--unset', 'core.hooksPath'])

		expect(install(repo)).toBe('activated')
		expect(git(['config', '--get', 'core.hooksPath']).stdout.trim()).toBe(HOOKS_PATH)
		expect(install(repo)).toBe('already active')
	})

	it('never replaces a deliberately configured hooks path', () => {
		git(['config', 'core.hooksPath', 'custom-hooks'])

		expect(install(repo)).toMatch(/left unchanged/)
		expect(git(['config', '--get', 'core.hooksPath']).stdout.trim()).toBe('custom-hooks')
	})

	it('is a no-op outside a git work tree (package builder, archive)', () => {
		const plain = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-git-guard-plain-'))
		try {
			expect(install(plain)).toMatch(/skipped/)
		} finally {
			fs.rmSync(plain, { recursive: true, force: true })
		}
	})
})
