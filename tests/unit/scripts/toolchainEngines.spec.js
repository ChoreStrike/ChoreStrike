/** @jest-environment node */
// CS-PLAN-088 WP-C: one Node/npm engine contract for the checkout, CI and the
// isolated package builder. A drifting pin fails here instead of in a release.
const fs = require('node:fs')
const path = require('node:path')
const yaml = require('js-yaml')
const { CONTAINER_BUILD_SCRIPT } = require('../../../scripts/build-package')

const root = path.resolve(__dirname, '../../..')
const read = file => fs.readFileSync(path.join(root, file), 'utf8')
const engines = JSON.parse(read('package.json')).engines
const builder = JSON.parse(read('docker/package-build.json'))
const workflowFiles = fs.readdirSync(path.join(root, '.github/workflows')).filter(name => name.endsWith('.yml'))

/** `^MAJOR[.MINOR[.PATCH]]` against an exact `MAJOR.MINOR.PATCH` version. */
function satisfiesCaret(version, range) {
	const bound = range.replace(/^\^/, '').split('.').map(Number)
	const actual = version.split('.').map(Number)
	if (actual[0] !== bound[0]) {
		return false
	}
	for (let index = 1; index < bound.length; index++) {
		if (actual[index] !== bound[index]) {
			return actual[index] > bound[index]
		}
	}
	return true
}

describe('Node and npm engine contract', () => {
	it('declares caret ranges that the checker understands', () => {
		expect(engines.node).toMatch(/^\^\d+(\.\d+){0,2}$/)
		expect(engines.npm).toMatch(/^\^\d+(\.\d+){0,2}$/)
	})

	it('pins a package-builder Node image inside the declared Node range', () => {
		expect(satisfiesCaret(builder.node.version, engines.node)).toBe(true)
	})

	it('keeps the Dockerfile default image identical to the builder inventory', () => {
		const dockerfile = read('docker/Dockerfile.package')
		expect(dockerfile).toContain(`ARG NODE_IMAGE=${builder.node.image}@${builder.node.platformDigest}`)
	})

	it('installs dependencies in the package builder with strict engine checks', () => {
		expect(CONTAINER_BUILD_SCRIPT).toMatch(/npm ci --engine-strict /)
	})

	it.each(workflowFiles)('runs %s on the same Node as the builder, inside the declared ranges', file => {
		const workflow = yaml.load(read(`.github/workflows/${file}`))
		const setups = Object.values(workflow.jobs)
			.flatMap(job => job.steps ?? [])
			.filter(step => typeof step.uses === 'string' && step.uses.startsWith('actions/setup-node@'))
		for (const step of setups) {
			expect(step.with['node-version']).toBe(builder.node.version)
			expect(satisfiesCaret(step.with['node-version'], engines.node)).toBe(true)
		}
		const text = read(`.github/workflows/${file}`)
		for (const [, npmVersion] of text.matchAll(/test "\$\(npm --version\)" = "([\d.]+)"/g)) {
			expect(satisfiesCaret(npmVersion, engines.npm)).toBe(true)
		}
		for (const [, nodeVersion] of text.matchAll(/test "\$\(node --version\)" = "v([\d.]+)"/g)) {
			expect(nodeVersion).toBe(builder.node.version)
		}
	})

	it('installs in CI only with strict engine and peer validation', () => {
		for (const file of workflowFiles) {
			for (const [command] of read(`.github/workflows/${file}`).matchAll(/npm (?:ci|install)\b[^\n]*/g)) {
				expect(command).toContain('--engine-strict')
				expect(command).not.toMatch(/--force|--legacy-peer-deps(?!=false)/)
			}
		}
	})
})
