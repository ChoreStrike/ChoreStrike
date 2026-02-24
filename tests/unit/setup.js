// Jest setup file for Vue 3 testing
import { config } from '@vue/test-utils'

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
config.global.stubs = {
	'transition': false,
	'transition-group': false,
}
