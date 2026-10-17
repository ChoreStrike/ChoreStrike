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
 *   node scripts/git-guard.js status    print this worktree's branch, pin and hook state, then
 *                                       every worktree's drift against master
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
const BASE_BRANCH = 'master'

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
	const current = `worktree ${path.basename(top.out)} · branch ${branch || '(detached)'} · pin ${pinned || '(none)'} · hooks ${hooks}`
	// path.relative compares case-insensitively on Windows.
	const all = listWorktrees(cwd).map(entry => `${path.relative(entry.path, top.out) ? ' ' : '*'} ${describeWorktree(entry, cwd)}`)
	return [current, `all worktrees (drift against ${BASE_BRANCH}):`, ...all].join('\n')
}

/**
 * Parse `git worktree list --porcelain` into path/branch/head entries.
 *
 * @param {string} cwd Working directory inside the repository.
 * @return {{path: string, branch: string, head: string}[]}
 */
function listWorktrees(cwd) {
	return git(['worktree', 'list', '--porcelain'], cwd).out.split(/\r?\n\r?\n/).filter(Boolean).map(block => {
		const lines = block.split(/\r?\n/)
		const field = name => lines.find(line => line.startsWith(`${name} `))?.slice(name.length + 1) || ''
		return { path: field('worktree'), branch: field('branch').replace(/^refs\/heads\//, ''), head: field('HEAD').slice(0, 7) }
	})
}

/**
 * One overview line: branch, drift against master, pin and local changes.
 *
 * @param {{path: string, branch: string, head: string}} entry Worktree entry.
 * @param {string} cwd Working directory inside the repository.
 * @return {string}
 */
function describeWorktree(entry, cwd) {
	const name = path.basename(entry.path)
	if (!fs.existsSync(entry.path)) return `${name} · missing directory (git worktree prune)`
	const parts = [name, entry.branch || `(detached ${entry.head})`]
	if (entry.branch && entry.branch !== BASE_BRANCH) parts.push(...drift(entry.branch, cwd))
	const pinPath = path.resolve(entry.path, git(['rev-parse', '--git-path', PIN_FILE], entry.path).out)
	const dirty = git(['status', '--porcelain'], entry.path).out.split(/\r?\n/).filter(Boolean).length
	parts.push(`pin ${fs.existsSync(pinPath) ? fs.readFileSync(pinPath, 'utf8').trim() : '(none)'}`, dirty ? `${dirty} uncommitted` : 'clean')
	return parts.join(' · ')
}

/**
 * Commits ahead of / behind master, with the action each state calls for.
 *
 * @param {string} branch Branch name.
 * @param {string} cwd Working directory inside the repository.
 * @return {string[]}
 */
function drift(branch, cwd) {
	const counts = git(['rev-list', '--left-right', '--count', `${BASE_BRANCH}...${branch}`], cwd)
	if (!counts.ok) return [`no ${BASE_BRANCH} to compare`]
	const [behind, ahead] = counts.out.split(/\s+/).map(Number)
	const notes = [`${ahead} ahead / ${behind} behind`]
	if (behind) notes.push(`integrate ${BASE_BRANCH}`)
	if (!ahead) notes.push(`nothing beyond ${BASE_BRANCH} (merged or not started)`)
	return notes
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
