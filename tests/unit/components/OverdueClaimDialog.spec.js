import { mount } from '@vue/test-utils'
import OverdueClaimDialog from '@/components/Tasks/OverdueClaimDialog.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => {
		if (vars) {
			return text.replace(/{(\w+)}/g, (m, key) => vars[key] ?? m)
		}
		return text
	}),
}))

describe('OverdueClaimDialog', () => {
	it('renders nothing when not visible', () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: false },
		})
		expect(wrapper.find('.overdue-claim-dialog').exists()).toBe(false)
	})

	it('renders on the shared modal when visible', () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true, assigneeName: 'Alice' },
		})
		expect(wrapper.find('[data-testid="modal"]').exists()).toBe(true)
		expect(wrapper.find('.overdue-claim-dialog').exists()).toBe(true)
		expect(wrapper.text()).toContain('Alice')
	})

	it('emits confirm on claim button click', async () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true, assigneeName: 'Alice' },
		})
		await wrapper.find('[data-testid="confirm-claim-button"]').trigger('click')
		expect(wrapper.emitted('confirm')).toBeTruthy()
	})

	it('emits cancel on cancel button click', async () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true, assigneeName: 'Alice' },
		})
		await wrapper.find('.secondary-button').trigger('click')
		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	it('shows dialog with default props', () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true },
		})
		expect(wrapper.find('.overdue-claim-dialog').exists()).toBe(true)
	})

	it('emits cancel when the shared modal close button is clicked (CS-PLAN-044 review)', async () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true, assigneeName: 'Alice' },
		})
		await wrapper.find('[data-testid="modal-close"]').trigger('click')
		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	it('emits cancel when Escape closes the shared modal (CS-PLAN-044 review)', async () => {
		const wrapper = mount(OverdueClaimDialog, {
			props: { visible: true, assigneeName: 'Alice' },
		})
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('cancel')).toBeTruthy()
	})
})
