/** Provision the canonical disposable stack, then execute both full PHP suites. */
const { spawnSync } = require('node:child_process')
const path = require('node:path')

function main(args = process.argv.slice(2), run = spawnSync) {
	if (args.length) throw new Error('Usage: scripts/run-tests.sh (no arguments)')
	const commands = [
		['docker', ['compose', '-f', 'docker/compose.test.yml', 'up', '-d', '--build', '--wait', '--wait-timeout', '900']],
		[process.execPath, ['scripts/test-runtime.js', 'verify-canonical']],
		...['unit', 'integration'].map(suite => ['docker', ['exec', 'chorestrike-test', 'vendor/bin/phpunit',
			'--configuration', `phpunit.${suite}.xml`, '--fail-on-skipped', '--do-not-cache-result', '--order-by=default']]),
	]
	for (const [command, arguments_] of commands) {
		const result = run(command, arguments_, { cwd: path.resolve(__dirname, '..'), stdio: 'inherit' })
		if (result.error || result.signal || result.status !== 0) return result.status || 1
	}
	return 0
}

module.exports = { main }
if (require.main === module) {
	try { process.exitCode = main() } catch (error) {
		process.stderr.write(`${error.message}\n`)
		process.exitCode = 1
	}
}
