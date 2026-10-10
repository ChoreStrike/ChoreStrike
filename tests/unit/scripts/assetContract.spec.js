const fs = require('node:fs')
const path = require('node:path')
const { execFileSync } = require('node:child_process')

const root = path.resolve(__dirname, '../../..')

describe('Deployment-aware lazy assets', () => {
	it('initializes the runtime asset path before importing the app or router', () => {
		const main = fs.readFileSync(path.join(root, 'src/main.js'), 'utf8')
		expect(main.match(/^import .+$/m)[0]).toBe("import './public-path.js'")
	})

	it.each(['/apps/chorestrike', '/custom_apps/chorestrike', '/cloud/custom_apps/chorestrike', '/cloud/apps/chorestrike'])(
		'uses the real Nextcloud file resolver for %s without changing API routes', appRoot => {
			const result = execFileSync(process.execPath, ['-e', `
				const vm = require('node:vm')
				global.window = { _oc_appswebroots: { chorestrike: ${JSON.stringify(appRoot)} },
					_oc_webroot: ${JSON.stringify(appRoot.startsWith('/cloud/') ? '/cloud' : '')} }
				const code = require('@babel/core').transformFileSync('src/public-path.js', {
					configFile: false, presets: [['@babel/preset-env', { targets: { node: '20' } }]],
				}).code
				const context = { require, __webpack_public_path__: '\\\\apps\\\\chorestrike\\\\js\\\\' }
				vm.runInNewContext(code, context)
				process.stdout.write(JSON.stringify({ asset: context.__webpack_public_path__,
					api: require('@nextcloud/router').generateUrl('/apps/chorestrike/api/v1/user') }))
			`], { cwd: root, encoding: 'utf8' })
			const webroot = appRoot.startsWith('/cloud/') ? '/cloud' : ''
			expect(JSON.parse(result)).toEqual({ asset: `${appRoot}/js/`, api: `${webroot}/index.php/apps/chorestrike/api/v1/user` })
		},
	)
})

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

	it('omits source maps for qualified package builds only', () => {
		// Native subprocesses avoid Jest substituting its Nextcloud module mocks.
		const load = env => execFileSync(process.execPath, ['-e', `
			delete process.env.CHORESTRIKE_PACKAGE_BUILD
			${env ? `process.env.CHORESTRIKE_PACKAGE_BUILD=${JSON.stringify(env)};` : ''}
			const config = require('./webpack.config.js')
			process.stdout.write(String(config.devtool))
		`], { cwd: root, encoding: 'utf8' })
		expect(load('true')).toBe('false')
		expect(load(null)).not.toBe('false')
	})
})
