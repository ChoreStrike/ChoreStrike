/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import UndoSnackbar from '@/components/Common/UndoSnackbar.vue'
import { useRoomTasksStore } from '@/store/roomTasks.js'
import { subscribe } from '@nextcloud/event-bus'

jest.mock('@/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-stub">icon</span>',
}))

jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { showError } from '@nextcloud/dialogs'

const getCompletedHandler = () => {
	const call = subscribe.mock.calls.find(([event]) => event === 'chorestrike:task-completed')
	return call ? call[1] : null
}

describe('UndoSnackbar (queued list, UX-015)', () => {
	let pinia
	let store

	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		pinia = createPinia()
		setActivePinia(pinia)
		store = useRoomTasksStore()
		store.undoRoomTaskCompletion = jest.fn().mockResolvedValue({ roomTask: { id: 1 }, undone: true })
		showError.mockClear()
	})

	afterEach(() => {
		jest.runOnlyPendingTimers()
		jest.useRealTimers()
	})

	const mountSnackbar = () => mount(UndoSnackbar, { global: { plugins: [pinia] } })

	const completeTask = async (wrapper, roomTaskId) => {
		const handler = getCompletedHandler()
		expect(handler).not.toBeNull()
		handler({ roomTaskId, completionId: roomTaskId * 10, expectedVersion: 0 })
		await wrapper.vm.$nextTick()
	}

	const entryTestId = id => `[data-testid="undo-snackbar-entry-${id}"]`
	const undoTestId = id => `[data-testid="undo-snackbar-undo-button-${id}"]`
	const dismissTestId = id => `[data-testid="undo-snackbar-dismiss-button-${id}"]`

	it('is hidden by default and subscribes to completions on mount', () => {
		const wrapper = mountSnackbar()

		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
		expect(subscribe).toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
	})

	it('shows for ~5 s after a completion and auto-hides that entry only', async () => {
		const wrapper = mountSnackbar()

		await completeTask(wrapper, 42)
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(true)

		// Two seconds later a second completion arrives — each entry owns
		// its countdown.
		jest.advanceTimersByTime(2000)
		await wrapper.vm.$nextTick()
		await completeTask(wrapper, 43)

		jest.advanceTimersByTime(2999)
		await wrapper.vm.$nextTick()
		expect(wrapper.find(entryTestId(42)).exists()).toBe(true)

		jest.advanceTimersByTime(1)
		await wrapper.vm.$nextTick()
		// 42's 5 s window has passed; 43 still has ~3 s left.
		expect(wrapper.find(entryTestId(42)).exists()).toBe(false)
		expect(wrapper.find(entryTestId(43)).exists()).toBe(true)

		jest.advanceTimersByTime(3000)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('keeps BOTH undo entries after two completions — the first undo action is not destroyed (UX-015)', async () => {
		const wrapper = mountSnackbar()

		await completeTask(wrapper, 7)
		await completeTask(wrapper, 8)

		expect(wrapper.findAll('[data-testid^="undo-snackbar-entry-"]')).toHaveLength(2)

		await wrapper.find(undoTestId(7)).trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenCalledTimes(1)
		expect(store.undoRoomTaskCompletion).toHaveBeenCalledWith(70, 0)
		// The second entry survives and stays independently undoable.
		expect(wrapper.find(entryTestId(7)).exists()).toBe(false)
		expect(wrapper.find(entryTestId(8)).exists()).toBe(true)

		await wrapper.find(undoTestId(8)).trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenLastCalledWith(80, 0)
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('evicts the oldest entry beyond the small visible cap', async () => {
		const wrapper = mountSnackbar()

		await completeTask(wrapper, 1)
		await completeTask(wrapper, 2)
		await completeTask(wrapper, 3)
		await completeTask(wrapper, 4)

		expect(wrapper.findAll('[data-testid^="undo-snackbar-entry-"]')).toHaveLength(3)
		expect(wrapper.find(entryTestId(1)).exists()).toBe(false)
		expect(wrapper.find(entryTestId(4)).exists()).toBe(true)
	})

	it('undoes via the one-request store action and drops the entry instantly', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 7)

		await wrapper.find(undoTestId(7)).trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenCalledWith(70, 0)
		expect(showError).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('toasts an error when the undo API call fails', async () => {
		store.undoRoomTaskCompletion = jest.fn().mockRejectedValue(new Error('Offline'))
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 9)

		await wrapper.find(undoTestId(9)).trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenCalledWith(90, 0)
		expect(showError).toHaveBeenCalled()
		// The entry stays dismissed — server truth (completed) remains visible.
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('appends the API code to the undo failure toast (CS-PLAN-084 WP-F)', async () => {
		store.undoRoomTaskCompletion = jest.fn().mockRejectedValue(Object.assign(new Error('stale'), { status: 409, code: 'CONFLICT' }))
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 9)

		await wrapper.find(undoTestId(9)).trigger('click')
		await flushMicrotasks()

		expect(showError).toHaveBeenCalledWith('Could not undo the completion (CONFLICT)')
	})

	it('acknowledges every completion with a decorative check mark (D-084-03)', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 9)

		const check = wrapper.find('[data-testid="undo-snackbar-check-9"]')
		expect(check.exists()).toBe(true)
		expect(check.attributes('aria-hidden')).toBe('true')
		expect(wrapper.find('[data-testid="undo-snackbar-entry-9"]').text()).toContain('Task completed')
	})

	it('dismisses a single entry without side effects', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 11)
		await completeTask(wrapper, 12)

		await wrapper.find(dismissTestId(11)).trigger('click')

		expect(wrapper.find(entryTestId(11)).exists()).toBe(false)
		expect(wrapper.find(entryTestId(12)).exists()).toBe(true)
		expect(store.undoRoomTaskCompletion).not.toHaveBeenCalled()
	})

	it('exposes accessible entries: status role, localized labels, 44 px touch targets', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 5)

		const entry = wrapper.find(entryTestId(5))
		expect(entry.exists()).toBe(true)
		expect(entry.attributes('role')).toBe('status')
		expect(entry.attributes('aria-live')).toBe('polite')

		const undoButton = wrapper.find(undoTestId(5))
		expect(undoButton.text()).toBe('Undo')
		// 44 px minimum target via the shared snackbar-undo style class.
		expect(undoButton.classes()).toContain('snackbar-undo')

		const dismissButton = wrapper.find(dismissTestId(5))
		expect(dismissButton.attributes('aria-label')).toBe('Dismiss')
	})

	it('shows no reward internals — the copy stays "Task completed / Undo"', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 6)

		const text = wrapper.find(entryTestId(6)).text()
		expect(text).toContain('Task completed')
		expect(text).not.toMatch(/🪙|coins/i)
	})
})

async function flushMicrotasks() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
}
