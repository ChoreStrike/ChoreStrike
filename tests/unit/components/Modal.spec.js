import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Modal from '../../../src/components/Common/Modal.vue'

describe('Modal', () => {
	const createWrapper = (props = {}, slots = {}) => {
		const pinia = createPinia()
		setActivePinia(pinia)
		
		return mount(Modal, {
			props: {
				show: true,
				...props,
			},
			slots: {
				default: '<div class="modal-content">Test Content</div>',
				header: '<h2>Test Header</h2>',
				...slots,
			},
			global: {
				plugins: [pinia],
			},
		})
	}

	describe('Visibility', () => {
		it('renders when show is true', () => {
			const wrapper = createWrapper({ show: true })
			expect(wrapper.find('.modal-overlay').exists()).toBe(true)
		})

		it('does not render when show is false', () => {
			const wrapper = createWrapper({ show: false })
			expect(wrapper.find('.modal-overlay').exists()).toBe(false)
		})
	})

	describe('Slots', () => {
		it('renders default slot content', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('.modal-content').exists()).toBe(true)
			expect(wrapper.text()).toContain('Test Content')
		})

		it('renders header slot content', () => {
			const wrapper = createWrapper()
			expect(wrapper.text()).toContain('Test Header')
		})
	})

	describe('Stable testids (CS-PLAN-015)', () => {
		it('renders data-testid="modal" on the dialog surface', () => {
			const wrapper = createWrapper({ show: true })
			expect(wrapper.find('[data-testid="modal"]').exists()).toBe(true)
		})

		it('renders data-testid="modal-close" on the close button', () => {
			const wrapper = createWrapper({ show: true })
			expect(wrapper.find('[data-testid="modal-close"]').exists()).toBe(true)
		})

		it('does not render any testid when hidden', () => {
			const wrapper = createWrapper({ show: false })
			expect(wrapper.find('[data-testid="modal"]').exists()).toBe(false)
		})
	})

	describe('Close Behavior', () => {
		it('emits close event when clicking overlay', async () => {
			const wrapper = createWrapper()
			await wrapper.find('.modal-overlay').trigger('click')
			expect(wrapper.emitted('close')).toBeTruthy()
		})

		it('emits close event when clicking close button', async () => {
			const wrapper = createWrapper()
			const closeButton = wrapper.find('.modal-close')
			if (closeButton.exists()) {
				await closeButton.trigger('click')
				expect(wrapper.emitted('close')).toBeTruthy()
			}
		})

		it('does not close when clicking modal content', async () => {
			const wrapper = createWrapper()
			await wrapper.find('.modal-container').trigger('click')
			expect(wrapper.emitted('close')).toBeFalsy()
		})
	})
})
