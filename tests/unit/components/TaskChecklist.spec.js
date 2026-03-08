import { mount } from '@vue/test-utils'
import TaskChecklist from '../../../src/components/Tasks/TaskChecklist.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text) => text),
}))

jest.mock('../../../src/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon">{{ name }}</span>',
}))

jest.mock('../../../src/components/Common/Modal.vue', () => ({
	name: 'Modal',
	emits: ['close'],
	template: '<div class="modal"><slot /></div>',
}))

describe('TaskChecklist', () => {
	const createItem = (overrides = {}) => ({
		id: 1,
		title: 'Wipe table',
		description: 'Use a damp cloth',
		order: 1,
		...overrides,
	})

	it('should render active checklist action icons in edit mode', () => {
		const wrapper = mount(TaskChecklist, {
			props: {
				items: [createItem()],
				progress: [],
				canEdit: true,
				readonly: false,
				roomTaskId: 10,
			},
		})

		const iconTexts = wrapper.findAll('.icon').map(node => node.text())

		expect(iconTexts).toContain('Edit2')
		expect(iconTexts).toContain('Trash2')
		expect(iconTexts).toContain('GripVertical')
		expect(iconTexts).toContain('Plus')
	})

	it('should hide checklist action icons when editing is disabled', () => {
		const wrapper = mount(TaskChecklist, {
			props: {
				items: [createItem()],
				progress: [],
				canEdit: false,
				readonly: false,
				roomTaskId: 10,
			},
		})

		const iconTexts = wrapper.findAll('.icon').map(node => node.text())

		expect(iconTexts).not.toContain('Edit2')
		expect(iconTexts).not.toContain('Trash2')
		expect(iconTexts).not.toContain('GripVertical')
		expect(iconTexts).not.toContain('Plus')
	})
})
