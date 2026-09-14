#!/usr/bin/env node

/**
 * Zero-noise gate for the Jest suite (CS-PLAN-034).
 *
 * Runs the full frontend test suite and fails when any
 * `[ChoreStrike] [error]` line leaks into the combined
 * stdout/stderr. Deliberate error-path tests silence the logger via
 * the shared mock (see tests/unit/store/checklist.spec.js), so any
 * remaining noise indicates an unexpected failure path.
 */

const { spawn } = require('child_process')

const NOISE_PATTERN = '[ChoreStrike] [error]'
const MAX_REPORTED_LINES = 20

/**
 * Strip ANSI escape sequences so colored Jest output matches too.
 *
 * @param {string} text Raw output chunk.
 * @return {string}
 */
function stripAnsi(text) {
	// eslint-disable-next-line no-control-regex
	return text.replace(/\u001B\[[0-9;]*[A-Za-z]/g, '')
}

// npm supplies its CLI path to lifecycle scripts; avoid Windows .cmd shells
// and Node's DEP0190 warning while keeping the actual npm test lifecycle.
if (!process.env.npm_execpath) {
	console.error('[check-test-noise] run through npm run test:noise')
	process.exit(1)
}
const child = spawn(process.execPath, [process.env.npm_execpath, 'test'], {
	env: { ...process.env, FORCE_COLOR: '0' },
})

let output = ''

child.stdout.on('data', (chunk) => {
	output += chunk
	process.stdout.write(chunk)
})

child.stderr.on('data', (chunk) => {
	output += chunk
	process.stderr.write(chunk)
})

child.on('error', (error) => {
	console.error(`[check-test-noise] failed to spawn npm test: ${error.message}`)
	process.exit(1)
})

child.on('close', (code) => {
	const offending = stripAnsi(output)
		.split(/\r?\n/)
		.filter((line) => line.includes(NOISE_PATTERN))

	if (offending.length > 0) {
		console.error(`\n[check-test-noise] ${offending.length} noisy line(s) containing "${NOISE_PATTERN}" in Jest output:`)
		offending.slice(0, MAX_REPORTED_LINES).forEach((line) => {
			console.error(`  ${line.trim()}`)
		})
		if (offending.length > MAX_REPORTED_LINES) {
			console.error(`  … and ${offending.length - MAX_REPORTED_LINES} more`)
		}
		process.exit(1)
	}

	if (code !== 0) {
		console.error(`\n[check-test-noise] Jest exited with code ${code}`)
		process.exit(code === null ? 1 : code)
	}

	console.log('[check-test-noise] Jest output is free of error-level log noise')
})
