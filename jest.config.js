module.exports = {
	testEnvironment: 'jsdom',
	moduleFileExtensions: ['js', 'json', 'vue'],
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
		'^@nextcloud/(.*)$': '<rootDir>/tests/unit/__mocks__/nextcloud-$1.js',
	},
	transform: {
		'^.+\\.vue$': '@vue/vue3-jest',
		'^.+\\.js$': 'babel-jest',
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(@nextcloud|vue-router)/)',
	],
	setupFilesAfterEnv: ['<rootDir>/tests/unit/setup.js'],
}
