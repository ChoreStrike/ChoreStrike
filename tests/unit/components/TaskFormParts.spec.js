/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals'
import { mount } from '@vue/test-utils'
import TaskFormMore from '../../../src/components/Tasks/TaskFormMore.vue'
import TaskScopeDialog from '../../../src/components/Tasks/TaskScopeDialog.vue'
import TaskWindowNote from '../../../src/components/Tasks/TaskWindowNote.vue'
import { formatDayKey } from '../../../src/utils/dateKeys.js'
import { detailsOf, rhythmOf } from '../../../src/utils/taskFormModel.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, params) => Object.entries(params ?? {})
		.reduce((label, [key, value]) => label.replaceAll(`{${key}}`, String(value)), text)),
}))
jest.mock('../../../src/store/house.js', () => ({ useHouseStore: () => ({ timezone: 'UTC' }) }))

/** The smaller parts of the one form (CS-PLAN-091 WP-B6). */
describe('TaskFormMore (D-091-85)', () => {
	const categories = [{ value: 'kitchen', label: 'Kitchen' }, { value: 'other', label: 'Other' }]
	const mountMore = (details = {}, isOwner = false) => {
		const wrapper = mount(TaskFormMore, {
			props: {
				modelValue: { ...detailsOf(null, () => 'other'), ...details },
				categories,
				isOwner,
				'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
			},
		})
		return wrapper
	}
	const last = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0]
	const visibilities = (wrapper) => wrapper.get('[data-testid="task-visibility-select"]').findAll('option')

	it('folds the extra fields under a closed "More"', () => {
		const wrapper = mountMore()

		expect(wrapper.get('[data-testid="task-form-more"]').element.tagName).toBe('DETAILS')
		expect(wrapper.get('[data-testid="task-form-more"]').attributes('open')).toBeUndefined()
		expect(wrapper.get('summary').text()).toBe('More')
	})

	it('edits description, instructions, priority and category', async () => {
		const wrapper = mountMore()

		await wrapper.get('[data-testid="task-description-input"]').setValue('Short')
		await wrapper.get('[data-testid="task-instructions-input"]').setValue('Step by step')
		await wrapper.get('[data-testid="task-priority-select"]').setValue('3')
		await wrapper.get('[data-testid="task-category"]').setValue('kitchen')

		expect(last(wrapper)).toMatchObject({ description: 'Short', instructions: 'Step by step', priority: 3, category: 'kitchen' })
		expect(wrapper.get('[data-testid="task-instructions-input"]').attributes('maxlength')).toBe('2000')
	})

	it('states the real visibility matrix (CS-PLAN-061 §3.9, D2)', async () => {
		const wrapper = mountMore({}, true)
		const hint = () => wrapper.get('[data-testid="task-visibility-hint"]').text()
		expect(hint()).toBe('All house members can see and complete this task')

		await wrapper.get('[data-testid="task-visibility-select"]').setValue('private')
		expect(hint()).toBe('Only you and the assigned members can see and complete this task. Assigned members can also edit it.')
		await wrapper.get('[data-testid="task-visibility-select"]').setValue('admin-only')
		expect(hint()).toBe('Only house owners can see and complete this task')
	})

	it('offers admin-only to the owner only, and keeps a member from lifting it', () => {
		expect(visibilities(mountMore({}, false)).map((option) => option.element.value)).toEqual(['public', 'private'])
		expect(visibilities(mountMore({}, true)).map((option) => option.element.value)).toEqual(['public', 'private', 'admin-only'])

		const kept = visibilities(mountMore({ visibilityType: 'admin-only' }, false))
		expect(kept.map((option) => option.element.value)).toEqual(['public', 'private', 'admin-only'])
		expect(kept[2].element.disabled).toBe(true)
	})
})

describe('TaskWindowNote (D-091-19)', () => {
	const rhythm = { ...rhythmOf(null), flexDays: 1 }
	const mountNote = (props = {}) => mount(TaskWindowNote, { props: { rhythm, ...props } })
	const window = (wrapper) => wrapper.get('[data-testid="task-next-window"]')

	beforeEach(() => {
		jest.useFakeTimers()
		jest.setSystemTime(new Date('2026-11-18T10:00:00Z'))
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('takes the first date of a new task and shows its first window, from tomorrow without one', async () => {
		const wrapper = mountNote()
		expect(wrapper.text()).toContain('Leave empty to start tomorrow')
		expect(window(wrapper).text()).toContain(`First window: ${formatDayKey('2026-11-18')} – ${formatDayKey('2026-11-20')}`)
		// Only the window itself is announced, not the explanation under it.
		expect(window(wrapper).get('strong').attributes('role')).toBe('status')
		expect(window(wrapper).attributes('role')).toBeUndefined()

		await wrapper.get('[data-testid="task-next-due-input"]').setValue('2026-12-01')
		expect(wrapper.emitted('update:firstDate')).toEqual([['2026-12-01']])

		await wrapper.setProps({ firstDate: '2026-12-01', rhythm: { ...rhythm, flexDays: 0 } })
		expect(window(wrapper).text()).toContain(`First window: ${formatDayKey('2026-12-01')}`)
		expect(window(wrapper).text()).not.toContain('–')
	})

	it('shows the next window of a task not yet done, whose first date still moves it', async () => {
		const roomTask = { id: 11, anchorOn: '2026-11-21', cycle: { state: 'upcoming', windowStartOn: '2026-11-21', windowEndOn: '2026-11-23' } }
		const wrapper = mountNote({ roomTask, firstDate: '2026-11-21', storedRhythm: rhythm })

		expect(wrapper.get('[data-testid="task-next-due-input"]').element.value).toBe('2026-11-21')
		expect(wrapper.text()).toContain('The first window is around this day until the task is done for the first time.')
		// The saved window, a postponement included.
		expect(window(wrapper).text()).toContain(`Next window: ${formatDayKey('2026-11-21')} – ${formatDayKey('2026-11-23')}`)

		// An edited first date shows the window it will give.
		await wrapper.setProps({ firstDate: '2026-11-25' })
		expect(window(wrapper).text()).toContain(`First window: ${formatDayKey('2026-11-24')} – ${formatDayKey('2026-11-26')}`)

		// So does edited flex.
		await wrapper.setProps({ firstDate: '2026-11-21', rhythm: { ...rhythm, flexDays: 0 } })
		expect(window(wrapper).text()).toContain(`First window: ${formatDayKey('2026-11-21')}`)
		expect(window(wrapper).text()).not.toContain('–')
	})

	it('names the last completion of a done task instead of a first date', () => {
		const roomTask = { id: 11, lastCompleted: '2026-11-15T09:00:00Z', cycle: { state: 'upcoming', windowStartOn: '2026-11-22', windowEndOn: '2026-11-22' } }
		const wrapper = mountNote({ roomTask, locked: true })

		expect(wrapper.find('[data-testid="task-next-due-input"]').exists()).toBe(false)
		expect(wrapper.get('[data-testid="task-last-done"]').text()).toContain(`Last done: ${formatDayKey('2026-11-15')}`)
		expect(window(wrapper).text()).toContain(`Next window: ${formatDayKey('2026-11-22')}`)
	})

	it('says that a sleeping task sleeps', () => {
		const wrapper = mountNote({ roomTask: { id: 11, cycle: { state: 'asleep', wakeOn: null } }, locked: true })

		expect(window(wrapper).text()).toContain('Asleep')
	})
})

describe('TaskScopeDialog (FEAT-UX-043)', () => {
	let wrapper

	afterEach(() => wrapper?.unmount())

	it('asks this room or all rooms, and can be left', async () => {
		wrapper = mount(TaskScopeDialog, { attachTo: document.body })
		const dialog = document.querySelector('[data-testid="modal"]')
		const press = async (testid) => {
			document.querySelector(`[data-testid="${testid}"]`).click()
			await wrapper.vm.$nextTick()
		}

		expect(dialog.getAttribute('aria-label')).toBe('Several rooms share this task')
		expect(document.querySelector('[data-testid="task-scope-dialog"]').textContent).toContain('Change it only in this room')
		await press('task-scope-this-room')
		await press('task-scope-all-rooms')
		await press('task-scope-cancel')

		expect(wrapper.emitted('choose')).toEqual([['thisRoom'], ['allRooms']])
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
