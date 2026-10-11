module.exports = {
	testEnvironment: 'jsdom',
	moduleFileExtensions: ['js', 'json', 'vue'],
	testPathIgnorePatterns: ['/node_modules/', '/archive/', '/\\.kilo/'],
	// testPathIgnorePatterns keeps worktree tests out of the run list, but
	// haste/module discovery still indexes manual mocks inside them
	// (duplicate-mock warnings, TECH-JEST-001). modulePathIgnorePatterns is
	// the discovery-level exclusion: those paths leave the haste map entirely.
	// Separator-agnostic dots: jest's Windows pattern normalization mangles
	// explicit [/\\] character classes.
	modulePathIgnorePatterns: [
		'<rootDir>.archive.',
		'<rootDir>.[.]kilo.worktrees.',
		// Extracted packages contain dependency license package.json copies.
		// The boundary dots are any-char matches for path separators; unlike the
		// kilo entry, the directory name itself contains no literal dot.
		'<rootDir>.build.',
	],
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
	// Runs before the test framework loads — early enough to override
	// process.emitWarning for the DEP0040 punycode noise (see file header).
	setupFiles: ['<rootDir>/tests/jest.setup.deprecations.js'],
	testEnvironmentOptions: {
		customExportConditions: ['node', 'node-addons'],
	},
}
