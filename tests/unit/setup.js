// Jest setup file for Vue 3 testing

// Global mocks
global.OC = {
	translate: jest.fn((app, text) => text),
}

// jsdom does not implement matchMedia; the celebration + sound utilities
// rely on it to honour prefers-reduced-motion. Install a minimal stub
// that defaults to "no preference" so unit tests can mount the components
// without exploding.
if (typeof window !== 'undefined' && typeof window.matchMedia !== 'function') {
	window.matchMedia = (query) => ({
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	})
}

// Mock Vue Router
jest.mock('vue-router', () => ({
	useRouter: jest.fn(() => ({
		push: jest.fn(),
		replace: jest.fn(),
		resolve: jest.fn(),
	})),
	useRoute: jest.fn(() => ({
		params: {},
		query: {},
	})),
	// Factory-level exports for modules that build the router at import
	// time (src/router.js — imported by App.vue for the CS-PLAN-066 D4
	// landing helper); tests only ever exercise the pure helpers.
	createRouter: jest.fn(() => ({
		push: jest.fn(),
		replace: jest.fn(),
		resolve: jest.fn(() => ({ href: '' })),
	})),
	createWebHashHistory: jest.fn(() => ({})),
}))

// Configure Vue Test Utils
const { config } = require('@vue/test-utils')
config.global.stubs = {
	'transition': false,
	'transition-group': false,
	// Dialogs teleport to <body> (CS-PLAN-087 RF-087-09); component specs
	// keep their content inside the wrapper.
	'teleport': true,
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
