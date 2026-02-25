// Jest setup file for Vue 3 testing

// Global mocks
global.OC = {
	translate: jest.fn((app, text) => text),
}

// Mock Vue Router
jest.mock('vue-router', () => ({
	useRouter: jest.fn(() => ({
		push: jest.fn(),
		resolve: jest.fn(),
	})),
	useRoute: jest.fn(() => ({
		params: {},
		query: {},
	})),
}))

// Configure Vue Test Utils
const { config } = require('@vue/test-utils')
config.global.stubs = {
	'transition': false,
	'transition-group': false,
	'Icon': {
		name: 'Icon',
		props: ['name', 'size'],
		template: '<span class="icon">{{ name }}</span>',
	},
	'Emoji': {
		name: 'Emoji',
		props: ['emoji', 'size'],
		template: '<span class="emoji">{{ emoji }}</span>',
	},
}
