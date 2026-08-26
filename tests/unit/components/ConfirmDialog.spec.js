/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import ConfirmDialog from '@/components/Common/ConfirmDialog.vue'

jest.mock('@/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-stub">icon</span>',
}))

jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: ['show', 'ariaLabel'],
	emits: ['close'],
	template: '<div v-if="show !== false" class="modal-stub" :aria-label="ariaLabel"><slot /></div>',
}))

describe('ConfirmDialog', () => {
	it('renders title, description and action labels', () => {
		const wrapper = mount(ConfirmDialog, {
			props: {
				title: 'Delete floor?',
				description: 'All rooms and tasks will be gone.',
				confirmLabel: 'Remove',
			},
		})

		expect(wrapper.find('[data-testid="confirm-dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Delete floor?')
		expect(wrapper.text()).toContain('All rooms and tasks will be gone.')
		expect(wrapper.find('[data-testid="confirm-dialog-confirm"]').text()).toBe('Remove')
		expect(wrapper.find('[data-testid="confirm-dialog-cancel"]').exists()).toBe(true)
	})

	it('forwards the title as the modal accessible name (UX-016)', () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete floor?' },
		})

		expect(wrapper.find('.modal-stub').attributes('aria-label')).toBe('Delete floor?')
	})

	it('renders without a description', () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete this sub-task?' },
		})

		expect(wrapper.find('.confirm-dialog p').exists()).toBe(false)
		expect(wrapper.text()).toContain('Delete this sub-task?')
	})

	it('emits confirm when the confirm button is clicked', async () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete?' },
		})

		await wrapper.find('[data-testid="confirm-dialog-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('emits cancel from the cancel button', async () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete?' },
		})

		await wrapper.find('[data-testid="confirm-dialog-cancel"]').trigger('click')

		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	it('emits cancel when the modal backdrop closes', async () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete?' },
		})

		wrapper.findComponent({ name: 'Modal' }).vm.$emit('close')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})

	it('disables the confirm button while loading', () => {
		const wrapper = mount(ConfirmDialog, {
			props: { title: 'Delete?', loading: true },
		})

		expect(wrapper.find('[data-testid="confirm-dialog-confirm"]').attributes('disabled')).toBeDefined()
	})
})
