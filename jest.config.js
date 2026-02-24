module.exports = {
	testEnvironment: 'jsdom',
	moduleFileExtensions: ['js', 'json', 'vue'],
	moduleNameMapper: {
		'^.+\\.vue$': '<rootDir>/tests/unit/vueMock.js',
	},
}
