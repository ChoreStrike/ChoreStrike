const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

webpackConfig.output.path = path.resolve(__dirname, 'js')

// Add bundle optimization
webpackConfig.optimization = {
	...webpackConfig.optimization,
	splitChunks: {
		chunks: 'all',
		cacheGroups: {
			vendor: {
				test: /[\\/]node_modules[\\/]/,
				name: 'vendors',
				chunks: 'all',
			},
		},
	},
}

module.exports = webpackConfig
