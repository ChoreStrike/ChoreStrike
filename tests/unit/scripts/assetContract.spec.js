const fs = require('node:fs')
const path = require('node:path')
const { execFileSync } = require('node:child_process')

const root = path.resolve(__dirname, '../../..')

describe('JavaScript-injected app styles', () => {
	it('registers the entry script without requesting a nonexistent stylesheet', () => {
		const template = fs.readFileSync(path.join(root, 'templates/main.php'), 'utf8')
		expect(template).toMatch(/script\('chorestrike', 'chorestrike-main'\)/)
		expect(template).not.toMatch(/\bstyle\s*\(/)
		const controller = fs.readFileSync(path.join(root, 'lib/Controller/PageController.php'), 'utf8')
		expect(controller).not.toMatch(/\bUtil::addStyle\s*\(/)
	})

	it('uses the real shared CSS and SCSS style loaders without an extraction plugin', () => {
		// A native subprocess avoids Jest substituting its Nextcloud module mocks.
		const result = execFileSync(process.execPath, ['-e', `
			const config = require('./webpack.config.js')
			process.stdout.write('ASSET_CONTRACT=' + JSON.stringify({
				rules: config.module.rules.filter(rule => rule.test?.test('app.css') || rule.test?.test('app.scss')),
				plugins: config.plugins.map(plugin => plugin.constructor.name),
			}))
		`], { cwd: root, encoding: 'utf8' })
		const { rules, plugins } = JSON.parse(result.split('ASSET_CONTRACT=')[1])
		expect(rules.length).toBeGreaterThan(0)
		for (const rule of rules) {
			expect(JSON.stringify(rule.use)).toContain('style-loader')
			expect(JSON.stringify(rule.use)).not.toContain('mini-css-extract-plugin')
		}
		expect(plugins).not.toContain('MiniCssExtractPlugin')
	})
})
