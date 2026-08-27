const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

// CS-PLAN-051: the shared RULE_ASSETS regex (png/jpe?g/gif/svg/…) matches the
// Fluent icon SVGs too, so the inline-asset type base64-encoded the
// svg-inline-loader's CJS output instead of letting it export the SVG string
// — every fluent icon rendered its data-URI source as text. Exclude the
// Fluent directory from the generic asset rule; the dedicated svg-inline
// rule below owns those files.
const assetRule = webpackConfig.module?.rules?.find((rule) => rule.type === 'asset/inline')
if (assetRule) {
	assetRule.exclude = [path.resolve(__dirname, 'node_modules', '@fluentui', 'svg-icons')]
}

// Force cache bust
const buildTime = Date.now()

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

webpackConfig.output.path = path.resolve(__dirname, 'js')
webpackConfig.output.filename = `chorestrike-[name].js?v=${buildTime}`
webpackConfig.output.chunkFilename = `chorestrike-[name].js?v=${buildTime}`

// Add CSS extraction
const MiniCssExtractPlugin = require('mini-css-extract-plugin')
webpackConfig.plugins.push(new MiniCssExtractPlugin({
	filename: '../css/chorestrike-main.css',
}))

// Fix webpack 5 + axios compatibility
webpackConfig.plugins.push(new NodePolyfillPlugin())

webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.fallback = {
	...webpackConfig.resolve.fallback,
	path: require.resolve('path-browserify'),
}
webpackConfig.resolve.extensions = ['.js', '.vue', '.mjs', '.json']

// Fix ESM module resolution for axios and @nextcloud/axios
webpackConfig.module = webpackConfig.module || { rules: [] }
webpackConfig.module.rules.push({
	test: /\.m?js$/,
	resolve: { fullySpecified: false },
})

webpackConfig.module.rules.push({
	test: /\.svg$/,
	include: [path.resolve(__dirname, 'node_modules', '@fluentui', 'svg-icons')],
	use: [{
		loader: 'svg-inline-loader',
		options: {
			removeSVGTagAttrs: false,
		},
	}],
})

webpackConfig.performance = {
	maxAssetSize: 4 * 1024 * 1024,
	maxEntrypointSize: 4 * 1024 * 1024,
}

module.exports = webpackConfig
