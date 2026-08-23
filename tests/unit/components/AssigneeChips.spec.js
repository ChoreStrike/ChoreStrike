import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import AssigneeChips from '../../../src/components/Tasks/AssigneeChips.vue'
import { useUserStore } from '../../../src/store/user.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

describe('AssigneeChips', () => {
	const seedMembers = (userStore) => {
		userStore.houseUsers = [
			{ nextcloudUserId: 'u1', displayName: 'Alice' },
			{ nextcloudUserId: 'u2', displayName: 'Bob' },
		]
	}

	const mountChips = (modelValue = []) => {
		const pinia = createPinia()
		setActivePinia(pinia)
		const userStore = useUserStore()
		seedMembers(userStore)

		return mount(AssigneeChips, {
			props: { modelValue },
			global: { plugins: [pinia] },
		})
	}

	it('renders one chip per house member', () => {
		const wrapper = mountChips(['u1'])
		expect(wrapper.findAll('.assignee-chip')).toHaveLength(2)
		expect(wrapper.find('[data-testid="assignee-chip-u1"]').classes()).toContain('selected')
		expect(wrapper.find('[data-testid="assignee-chip-u2"]').classes()).not.toContain('selected')
	})

	it('emits update:modelValue when a chip is toggled', async () => {
		const wrapper = mountChips([])
		await wrapper.find('[data-testid="assignee-chip-u1"] input').setValue(true)
		expect(wrapper.emitted('update:modelValue')[0]).toEqual([['u1']])
	})

	it('keeps the checkbox keyboard-reachable instead of display:none (UX-007)', async () => {
		const wrapper = mountChips([])

		const checkboxes = wrapper.findAll('.assignee-chip input[type="checkbox"]')
		expect(checkboxes).toHaveLength(2)

		for (const checkbox of checkboxes) {
			// The visually-hidden clip pattern keeps the element rendered and
			// tabbable; it must not be removed via display:none.
			expect(checkbox.classes()).toContain('cs-visually-hidden')
			expect(checkbox.attributes('style') || '').not.toContain('display: none')
			expect(checkbox.element.type).toBe('checkbox')
		}

		// Keyboard operation: triggering the change handler (Space on a
		// focused native checkbox) toggles the selection.
		await checkboxes[0].setValue(true)
		expect(wrapper.emitted('update:modelValue')[0]).toEqual([['u1']])
	})
})
