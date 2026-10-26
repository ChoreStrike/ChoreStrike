import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Modal from '../../../src/components/Common/Modal.vue'

describe('Modal', () => {
	const mountedWrappers = []

	const createWrapper = (props = {}, slots = {}) => {
		const pinia = createPinia()
		setActivePinia(pinia)

		const wrapper = mount(Modal, {
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
			// Focus management (trap/restore) requires the dialog to be in
			// the real document tree.
			attachTo: document.body,
		})
		mountedWrappers.push(wrapper)
		return wrapper
	}

	afterEach(() => {
		while (mountedWrappers.length > 0) {
			mountedWrappers.pop().unmount()
		}
		document.body.style.overflow = ''
	})

	describe('Visibility', () => {
		it('renders when show is true', () => {
			const wrapper = createWrapper({ show: true })
			expect(wrapper.find('.modal-overlay').exists()).toBe(true)
		})

		it('does not render when show is false', () => {
			const wrapper = createWrapper({ show: false })
			expect(wrapper.find('.modal-overlay').exists()).toBe(false)
		})

		it('renders the overlay at <body>, above Nextcloud\'s chrome (CS-PLAN-087 RF-087-09)', () => {
			const teleport = createWrapper().find('teleport-stub')
			expect(teleport.attributes('to')).toBe('body')
			expect(teleport.find('.modal-overlay').exists()).toBe(true)
		})

		it('keeps the caller\'s attributes on the overlay despite the teleport root', () => {
			const wrapper = mount(Modal, {
				props: { show: true },
				attrs: { 'data-testid': 'request-confirm', class: 'caller-class' },
				global: { plugins: [createPinia()] },
			})
			mountedWrappers.push(wrapper)
			const overlay = wrapper.find('.modal-overlay')
			expect(overlay.attributes('data-testid')).toBe('request-confirm')
			expect(overlay.classes()).toContain('caller-class')
			expect(wrapper.find('teleport-stub').attributes('data-testid')).toBeUndefined()
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
			const closeButton = wrapper.find('[data-testid="modal-close"]')
			await closeButton.trigger('click')
			expect(wrapper.emitted('close')).toBeTruthy()
		})

		it('does not close when clicking modal content', async () => {
			const wrapper = createWrapper()
			await wrapper.find('.modal-container').trigger('click')
			expect(wrapper.emitted('close')).toBeFalsy()
		})
	})

	describe('Persistent dialogs (BUG-UX-001)', () => {
		it('ignores a backdrop click so the input survives', async () => {
			const wrapper = createWrapper({ persistent: true })
			await wrapper.find('.modal-overlay').trigger('click')
			expect(wrapper.emitted('close')).toBeFalsy()
		})

		it('still closes through the close button', async () => {
			const wrapper = createWrapper({ persistent: true })
			await wrapper.find('[data-testid="modal-close"]').trigger('click')
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('still closes on Escape', async () => {
			const wrapper = createWrapper({ persistent: true })
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
			await wrapper.vm.$nextTick()
			expect(wrapper.emitted('close')).toHaveLength(1)
		})

		it('is off by default, so confirmations keep the backdrop close', async () => {
			const wrapper = createWrapper()
			expect(wrapper.props('persistent')).toBe(false)
			await wrapper.find('.modal-overlay').trigger('click')
			expect(wrapper.emitted('close')).toHaveLength(1)
		})
	})

	describe('Accessibility (CS-PLAN-044, UX-012)', () => {
		it('renders role="dialog" with aria-modal="true"', () => {
			const wrapper = createWrapper()
			const dialog = wrapper.find('[role="dialog"]')
			expect(dialog.exists()).toBe(true)
			expect(dialog.attributes('aria-modal')).toBe('true')
		})

		it('labels the close button for screen readers', () => {
			const wrapper = createWrapper()
			expect(wrapper.find('[data-testid="modal-close"]').attributes('aria-label')).toBe('Close')
		})

		it('emits close when Escape is pressed', async () => {
			const wrapper = createWrapper()
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
			await wrapper.vm.$nextTick()
			expect(wrapper.emitted('close')).toBeTruthy()
		})

		it('ignores unrelated keys', async () => {
			const wrapper = createWrapper()
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
			await wrapper.vm.$nextTick()
			expect(wrapper.emitted('close')).toBeFalsy()
		})
	})

	describe('Focus management (CS-PLAN-044)', () => {
		it('moves focus into the dialog on open and restores it on close', async () => {
			const opener = document.createElement('button')
			document.body.appendChild(opener)
			opener.focus()
			expect(document.activeElement).toBe(opener)

			const wrapper = createWrapper({ show: true })
			await wrapper.vm.$nextTick()

			const container = wrapper.find('.modal-container')
			expect(document.activeElement).toBe(container.element)

			await wrapper.setProps({ show: false })
			expect(document.activeElement).toBe(opener)

			opener.remove()
		})

		it('wraps Tab at the last focusable element back to the first', async () => {
			const wrapper = createWrapper({}, {
				default: `
					<div class="modal-content">
						<button id="first">First</button>
						<button id="last">Last</button>
					</div>`,
			})
			await wrapper.vm.$nextTick()

			// First focusable in DOM order is the modal's own close button
			const closeButton = wrapper.find('[data-testid="modal-close"]').element
			document.getElementById('last').focus()
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }))

			expect(document.activeElement).toBe(closeButton)
		})

		it('wraps Shift+Tab backwards from the first focusable element', async () => {
			const wrapper = createWrapper({}, {
				default: `
					<div class="modal-content">
						<button id="middle">Middle</button>
						<button id="close-target">Close target</button>
					</div>`,
			})
			await wrapper.vm.$nextTick()

			// First focusable in DOM order is the modal's own close button
			const closeButton = wrapper.find('[data-testid="modal-close"]').element
			closeButton.focus()
			document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true }))

			expect(document.activeElement).toBe(wrapper.find('#close-target').element)
		})

		it('locks body scroll while open and restores it afterwards', async () => {
			expect(document.body.style.overflow).not.toBe('hidden')

			const wrapper = createWrapper()
			await wrapper.vm.$nextTick()
			expect(document.body.style.overflow).toBe('hidden')

			await wrapper.setProps({ show: false })
			expect(document.body.style.overflow).not.toBe('hidden')
		})
	})
})
