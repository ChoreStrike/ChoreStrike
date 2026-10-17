#!/usr/bin/env node

/**
 * Branch guard setup and worktree pinning (`.githooks/pre-commit`).
 *
 * The hook refuses direct commits on master/main (override per command with
 * CS_ALLOW_MASTER=1 when the user explicitly asked for that commit) and
 * commits on any branch other than the one a worktree is pinned to. It is
 * tool-agnostic: Claude Code, Kilo and humans all commit through git.
 *
 * Usage:
 *   node scripts/git-guard.js install   point core.hooksPath at .githooks (npm `prepare`)
 *   node scripts/git-guard.js pin       pin this worktree to its current branch
 *   node scripts/git-guard.js unpin     remove this worktree's pin
 *   node scripts/git-guard.js status    print worktree, branch, pin and hook state
 *
 * `install` is a no-op outside a git work tree (package builders, archives)
 * and never replaces a different, deliberately configured hooks path.
 */

'use strict'

const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')

const SCRIPT_NAME = 'git-guard'
const HOOKS_PATH = '.githooks'
const PIN_FILE = 'chorestrike-branch'

/**
 * Run git in the given directory.
 *
 * @param {string[]} args Git arguments.
 * @param {string} cwd Working directory.
 * @return {{ok: boolean, out: string}}
 */
function git(args, cwd) {
	const result = spawnSync('git', args, { cwd, encoding: 'utf8' })
	return { ok: !result.error && result.status === 0, out: (result.stdout || '').trim() }
}

/**
 * Point core.hooksPath at the tracked hooks unless another path is configured.
 *
 * @param {string} cwd Working directory inside the repository.
 * @return {string} Outcome for logging.
 */
function install(cwd) {
	if (!git(['rev-parse', '--is-inside-work-tree'], cwd).ok) return 'skipped (not a git work tree)'
	const current = git(['config', '--get', 'core.hooksPath'], cwd).out
	if (current === HOOKS_PATH) return 'already active'
	if (current) return `left unchanged (core.hooksPath is '${current}')`
	return git(['config', 'core.hooksPath', HOOKS_PATH], cwd).ok ? 'activated' : 'failed to set core.hooksPath'
}

/**
 * Pin, unpin or describe the current worktree.
 *
 * @param {string} command pin | unpin | status
 * @param {string} cwd Working directory inside the worktree.
 * @return {string} Outcome for logging.
 */
function worktree(command, cwd) {
	const top = git(['rev-parse', '--show-toplevel'], cwd)
	if (!top.ok) throw new Error('not inside a git work tree')
	const branch = git(['symbolic-ref', '--short', '-q', 'HEAD'], cwd).out
	const pinPath = path.resolve(cwd, git(['rev-parse', '--git-path', PIN_FILE], cwd).out)
	const pinned = fs.existsSync(pinPath) ? fs.readFileSync(pinPath, 'utf8').trim() : ''
	if (command === 'pin') {
		if (!branch) throw new Error('detached HEAD: check out the task branch before pinning')
		fs.writeFileSync(pinPath, `${branch}\n`)
		return `pinned ${path.basename(top.out)} to '${branch}'`
	}
	if (command === 'unpin') {
		fs.rmSync(pinPath, { force: true })
		return `unpinned ${path.basename(top.out)}`
	}
	const hooks = git(['config', '--get', 'core.hooksPath'], cwd).out || '(git default)'
	return `worktree ${path.basename(top.out)} · branch ${branch || '(detached)'} · pin ${pinned || '(none)'} · hooks ${hooks}`
}

function main() {
	const command = process.argv[2] || 'status'
	if (command === 'install') {
		console.log(`[${SCRIPT_NAME}] branch guard ${install(process.cwd())}`)
		return
	}
	if (!['pin', 'unpin', 'status'].includes(command)) {
		console.error(`[${SCRIPT_NAME}] unknown command '${command}' (install | pin | unpin | status)`)
		process.exitCode = 1
		return
	}
	try {
		console.log(`[${SCRIPT_NAME}] ${worktree(command, process.cwd())}`)
	} catch (error) {
		console.error(`[${SCRIPT_NAME}] ${error.message}`)
		process.exitCode = 1
	}
}

module.exports = { install, worktree, HOOKS_PATH, PIN_FILE }

if (require.main === module) main()
