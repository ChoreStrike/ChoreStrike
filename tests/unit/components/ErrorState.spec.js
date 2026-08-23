import { mount } from '@vue/test-utils'
import ErrorState from '../../../src/components/Common/ErrorState.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

// The global test setup stubs Icon as `<span class="icon">{name}</span>`.

const setOnline = (online) => {
	Object.defineProperty(window, 'navigator', {
		value: { ...window.navigator, onLine: online },
		writable: true,
		configurable: true,
	})
}

describe('ErrorState (CS-PLAN-044, FEAT-UX-031)', () => {
	const originalNavigator = window.navigator

	afterEach(() => {
		Object.defineProperty(window, 'navigator', {
			value: originalNavigator,
			writable: true,
			configurable: true,
		})
	})

	it('renders the error message and emits retry when the button is clicked', async () => {
		setOnline(true)
		const wrapper = mount(ErrorState, {
			props: { message: 'Could not load your tasks' },
		})

		expect(wrapper.find('[data-testid="error-state"]').exists()).toBe(true)
		expect(wrapper.find('.error-message').text()).toBe('Could not load your tasks')

		await wrapper.find('[data-testid="error-retry"]').trigger('click')
		expect(wrapper.emitted('retry')).toHaveLength(1)
	})

	it('can render without a retry action', () => {
		setOnline(true)
		const wrapper = mount(ErrorState, {
			props: { showRetry: false },
		})

		expect(wrapper.find('[data-testid="error-retry"]').exists()).toBe(false)
	})

	it('shows an offline variant while the browser reports no connection', async () => {
		setOnline(false)
		const wrapper = mount(ErrorState)

		expect(wrapper.find('.error-message').text()).toBe('You appear to be offline')
		expect(wrapper.find('.error-hint').text()).toBe('Reconnect and try again')
		expect(wrapper.find('[data-testid="error-retry"]').exists()).toBe(true)
	})

	it('switches between offline and online text on window events', async () => {
		setOnline(true)
		const wrapper = mount(ErrorState)
		expect(wrapper.find('.error-message').text()).not.toBe('You appear to be offline')

		window.dispatchEvent(new Event('offline'))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.error-message').text()).toBe('You appear to be offline')

		window.dispatchEvent(new Event('online'))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.error-message').text()).not.toBe('You appear to be offline')
	})
})
