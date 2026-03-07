const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

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

module.exports = webpackConfig
