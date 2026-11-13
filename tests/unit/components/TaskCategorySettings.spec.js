/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import TaskCategorySettings from '../../../src/components/Settings/TaskCategorySettings.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match) }))
jest.mock('@nextcloud/dialogs', () => ({ showSuccess: jest.fn() }))
jest.mock('@/utils/logger', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))
jest.mock('../../../src/components/Common/Icon.vue', () => ({ name: 'Icon', props: ['name', 'size'], template: '<span />' }))

const DEFAULT_ITEMS = ['cleaning', 'kitchen', 'bathroom', 'organizing', 'maintenance', 'other'].map(key => ({ key, label: null }))
const mockHouse = reactive({ taskCategories: { revision: 0, items: DEFAULT_ITEMS }, saveTaskCategories: jest.fn() })
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => mockHouse }))

const names = (wrapper) => wrapper.findAll('.category-row').map(row => row.find('input').exists() ? row.find('input').element.value : row.find('.fixed-name').text())
const saveButton = (wrapper) => wrapper.get('[data-testid="task-category-save"]')

/** CS-PLAN-090 WP-I (D-090-65): the owner's editor of the task categories. */
describe('TaskCategorySettings', () => {
	beforeEach(() => {
		mockHouse.taskCategories = { revision: 0, items: DEFAULT_ITEMS }
		mockHouse.saveTaskCategories.mockReset()
	})

	it('lists the categories with Other last and fixed, and saves nothing unchanged', () => {
		const wrapper = mount(TaskCategorySettings)

		expect(names(wrapper)).toEqual(['Cleaning', 'Kitchen', 'Bathroom', 'Organizing', 'Maintenance', 'Other'])
		const otherRow = wrapper.get('[data-testid="task-category-row-5"]')
		expect(otherRow.find('input').exists()).toBe(false)
		expect(wrapper.find('[data-testid="task-category-delete-5"]').exists()).toBe(false)
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		// RF-090-36: each control names its row for a screen reader.
		expect(wrapper.get('[data-testid="task-category-row-1"]').findAll('button').map(button => button.attributes('aria-label')))
			.toEqual(['Move Kitchen up', 'Move Kitchen down', 'Delete Kitchen'])
	})

	it('adds before Other, renames, deletes, and keeps an untouched built-in name translated', async () => {
		mockHouse.saveTaskCategories.mockResolvedValue({})
		const wrapper = mount(TaskCategorySettings)

		await wrapper.get('[data-testid="task-category-new"]').setValue('  Garden ')
		await wrapper.get('[data-testid="task-category-add"]').trigger('click')
		await wrapper.get('[data-testid="task-category-name-1"]').setValue('Kitchen & dining')
		await wrapper.get('[data-testid="task-category-delete-2"]').trigger('click')
		expect(names(wrapper)).toEqual(['Cleaning', 'Kitchen & dining', 'Organizing', 'Maintenance', 'Garden', 'Other'])

		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(mockHouse.saveTaskCategories).toHaveBeenCalledWith([
			{ key: 'cleaning', label: null },
			{ key: 'kitchen', label: 'Kitchen & dining' },
			{ key: 'organizing', label: null },
			{ key: 'maintenance', label: null },
			{ key: null, label: 'Garden' },
			{ key: 'other', label: null },
		])
		expect(wrapper.find('[data-testid="task-category-error"]').exists()).toBe(false)
	})

	it('moves a row, never past Other', async () => {
		const wrapper = mount(TaskCategorySettings)
		const buttons = (index) => wrapper.get(`[data-testid="task-category-row-${index}"]`).findAll('button')

		await buttons(1)[0].trigger('click')
		expect(names(wrapper).slice(0, 2)).toEqual(['Kitchen', 'Cleaning'])
		expect(buttons(4)[1].attributes('disabled')).toBeDefined()
		expect(buttons(5)[0].attributes('disabled')).toBeDefined()
	})

	it('refuses to save a blank name', async () => {
		const wrapper = mount(TaskCategorySettings)

		await wrapper.get('[data-testid="task-category-name-0"]').setValue('   ')

		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('shows the current list again after a conflict, and the server message otherwise', async () => {
		const wrapper = mount(TaskCategorySettings)
		await wrapper.get('[data-testid="task-category-delete-0"]').trigger('click')

		mockHouse.saveTaskCategories.mockImplementationOnce(async () => {
			mockHouse.taskCategories = { revision: 1, items: [{ key: 'c0a1b2c3d', label: 'Garden' }, { key: 'other', label: null }] }
			throw Object.assign(new Error('changed'), { status: 409 })
		})
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.get('[data-testid="task-category-error"]').text()).toContain('Someone else saved the categories first')
		expect(names(wrapper)).toEqual(['Garden', 'Other'])

		await wrapper.get('[data-testid="task-category-name-0"]').setValue('garden')
		mockHouse.saveTaskCategories.mockRejectedValueOnce(Object.assign(new Error('Two categories cannot have the same name.'), { status: 400 }))
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.get('[data-testid="task-category-error"]').text()).toBe('Two categories cannot have the same name.')
	})
})
