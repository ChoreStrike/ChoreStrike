module.exports = {
	testEnvironment: 'jsdom',
	moduleFileExtensions: ['js', 'json', 'vue'],
	testPathIgnorePatterns: ['/node_modules/', '/archive/'],
	collectCoverageFrom: [
		'src/**/*.{js,vue}',
		'!src/**/*.d.ts',
	],
	coverageDirectory: 'build/coverage/jest',
	coverageProvider: 'v8',
	coverageThreshold: {
		global: {
			statements: 80,
			branches: 75,
			functions: 62,
			lines: 80,
		},
	},
	moduleNameMapper: {
		'^@/(.*)$': '<rootDir>/src/$1',
		'^@nextcloud/(.*)$': '<rootDir>/tests/unit/__mocks__/nextcloud-$1.js',
		'\\.svg$': '<rootDir>/tests/unit/__mocks__/svg.js',
		'../utils/icons.js': '<rootDir>/tests/unit/__mocks__/icons.js',
		'../../utils/icons.js': '<rootDir>/tests/unit/__mocks__/icons.js',
	},
	transform: {
		'^.+\\.vue$': '@vue/vue3-jest',
		'^.+\\.js$': 'babel-jest',
	},
	transformIgnorePatterns: [
		'/node_modules/(?!(@nextcloud|vue-router)/)',
	],
	setupFilesAfterEnv: ['<rootDir>/tests/unit/setup.js'],
	testEnvironmentOptions: {
		customExportConditions: ['node', 'node-addons'],
	},
}
