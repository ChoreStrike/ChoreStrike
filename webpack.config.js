const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

webpackConfig.output.path = path.resolve(__dirname, 'js')

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

// Code splitting optimization
webpackConfig.optimization = webpackConfig.optimization || {}
webpackConfig.optimization.splitChunks = {
	chunks: 'all',
	minSize: 20000,
	maxSize: 244000,
	cacheGroups: {
		vendor: {
			test: /[\\/]node_modules[\\/]/,
			name: 'vendor',
			chunks: 'all',
			priority: 10,
		},
		vue: {
			test: /[\\/]node_modules[\\/](vue|vue-router|pinia)[\\/]/,
			name: 'vue-vendor',
			chunks: 'all',
			priority: 20,
		},
		nextcloud: {
			test: /[\\/]node_modules[\\/]@nextcloud[\\/]/,
			name: 'nextcloud-vendor',
			chunks: 'all',
			priority: 15,
		},
	},
}

module.exports = webpackConfig
