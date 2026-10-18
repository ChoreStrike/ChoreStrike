#!/usr/bin/env node
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * `npm run docs:screenshots` (CS-PLAN-087 WP-B, TECH-DOCS-007): recaptures the
 * README and user-guide images (tests/docs-screenshots/scenes.js) on an
 * isolated staged row. Never the canonical stack or a real household, and
 * not part of any gate.
 *
 * 1. Refuses a missing or stale production build (`js/` older than `src/`)
 *    or a missing `vendor/`: the staged row bind-mounts this checkout.
 * 2. Stages a fresh, owned SQLite row of the primary runtime on a free
 *    loopback port (scripts/test-runtime-stage.js, `family` seed).
 * 3. Runs the capture (playwright.docs.config.ts) against it.
 * 4. Removes only that project, also after a failure; `--keep` retains it.
 */

'use strict'

const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const net = require('node:net')
const path = require('node:path')
const { readInventory, selectRuntimeSource } = require('./test-runtime')
const { assertFreshProject } = require('./test-runtime-stage')

const ROOT = path.resolve(__dirname, '..')
const PLAYWRIGHT_CLI = path.join(ROOT, 'node_modules', '@playwright', 'test', 'cli.js')

/** Newest file modification time below `directory`, in ms. */
function newestMtime(directory) {
	let newest = 0
	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		const full = path.join(directory, entry.name)
		newest = Math.max(newest, entry.isDirectory() ? newestMtime(full) : fs.statSync(full).mtimeMs)
	}
	return newest
}

/** The staged row serves this checkout: it must hold a current build. */
function assertBuildPrerequisites(root = ROOT) {
	const bundle = path.join(root, 'js', 'chorestrike-main.js')
	if (!fs.existsSync(bundle)) throw new Error('No production build in js/: run `npm run build` first')
	if (!fs.existsSync(path.join(root, 'vendor', 'autoload.php'))) throw new Error('No vendor/: run `composer install` first')
	if (fs.statSync(bundle).mtimeMs < newestMtime(path.join(root, 'src'))) {
		throw new Error('js/ is older than src/: run `npm run build` first')
	}
}

/** Owned project, image and loopback target for one capture run. */
function captureSettings(version, port, now = Date.now()) {
	const project = `cs082-docs-${now.toString(36)}`
	return {
		project,
		image: `cs082-runtime-${version}-local`,
		port,
		url: `http://127.0.0.1:${port}`,
		container: `${project}-nextcloud-sqlite-1`,
	}
}

/** A loopback port the OS reports as free. */
function freePort() {
	return new Promise((resolve, reject) => {
		const server = net.createServer()
		server.on('error', reject)
		server.listen(0, '127.0.0.1', () => {
			const { port } = server.address()
			server.close(() => resolve(port))
		})
	})
}

/**
 * Stage, capture, tear down. `assertFresh` proves the project name is unused
 * before anything is created, so the teardown can only remove this run's
 * own resources.
 */
function capture(settings, { keep = false, run = spawnSync, assertFresh = assertFreshProject } = {}) {
	assertFresh(settings.project)
	const step = (command, args, failure, env = process.env) => {
		const result = run(command, args, { cwd: ROOT, env, stdio: 'inherit' })
		if (result.error || result.status !== 0) throw new Error(failure)
	}
	try {
		step(process.execPath, [path.join('scripts', 'test-runtime-stage.js'), 'up', settings.project, 'sqlite', settings.image, String(settings.port)],
			'Staging the capture row failed')
		step(process.execPath, [PLAYWRIGHT_CLI, 'test', '--config', 'playwright.docs.config.ts'], 'Screenshot capture failed',
			{ ...process.env, NEXTCLOUD_URL: settings.url, NEXTCLOUD_CONTAINER: settings.container })
	} finally {
		if (keep) {
			process.stdout.write(`Kept ${settings.project} at ${settings.url}; remove it with: docker compose --project-name ${settings.project} down --volumes\n`)
		} else {
			const down = run('docker', ['compose', '--project-name', settings.project, 'down', '--volumes'], { cwd: ROOT, stdio: 'inherit' })
			if (down.error || down.status !== 0) {
				process.stderr.write(`Teardown of ${settings.project} failed; remove it with: docker compose --project-name ${settings.project} down --volumes\n`)
			}
		}
	}
}

async function main(args) {
	assertBuildPrerequisites()
	const selection = selectRuntimeSource(readInventory(), 'primary', 'current')
	capture(captureSettings(selection.nextcloud.version, await freePort()), { keep: args.includes('--keep') })
	process.stdout.write('Screenshots written to docs/screenshots/; review the diff before committing.\n')
}

if (require.main === module) {
	main(process.argv.slice(2)).catch(error => {
		process.stderr.write(`docs:screenshots failed: ${error.message}\n`)
		process.exitCode = 1
	})
}

module.exports = { assertBuildPrerequisites, captureSettings, capture, newestMtime }
