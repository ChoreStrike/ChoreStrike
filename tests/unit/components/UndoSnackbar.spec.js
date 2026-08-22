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

describe('UndoSnackbar', () => {
	let pinia
	let store

	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		pinia = createPinia()
		setActivePinia(pinia)
		store = useRoomTasksStore()
		store.undoRoomTaskCompletion = jest.fn().mockResolvedValue({ id: 1, isActive: true })
		showError.mockClear()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	const mountSnackbar = () => mount(UndoSnackbar, { global: { plugins: [pinia] } })

	const completeTask = async (wrapper, roomTaskId) => {
		const handler = getCompletedHandler()
		expect(handler).not.toBeNull()
		handler({ roomTaskId })
		await wrapper.vm.$nextTick()
	}

	it('is hidden by default and subscribes to completions on mount', () => {
		const wrapper = mountSnackbar()

		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
		expect(subscribe).toHaveBeenCalledWith('chorestrike:task-completed', expect.any(Function))
	})

	it('shows for ~5 s after a completion and auto-hides', async () => {
		const wrapper = mountSnackbar()

		await completeTask(wrapper, 42)
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(true)

		wrapper.vm.show(43)
		await wrapper.vm.$nextTick()

		jest.advanceTimersByTime(4999)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(true)

		jest.advanceTimersByTime(1)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('undoes via the store action and hides instantly', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 7)

		await wrapper.find('[data-testid="undo-snackbar-undo-button"]').trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenCalledWith(7)
		expect(showError).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('toasts an error when the undo API call fails', async () => {
		store.undoRoomTaskCompletion = jest.fn().mockRejectedValue(new Error('Offline'))
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 9)

		await wrapper.find('[data-testid="undo-snackbar-undo-button"]').trigger('click')
		await flushMicrotasks()

		expect(store.undoRoomTaskCompletion).toHaveBeenCalledWith(9)
		expect(showError).toHaveBeenCalled()
		// The bar stays hidden — server truth (completed) remains visible.
		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
	})

	it('hides without side effects when dismissed', async () => {
		const wrapper = mountSnackbar()
		await completeTask(wrapper, 11)

		await wrapper.find('[data-testid="undo-snackbar-dismiss-button"]').trigger('click')

		expect(wrapper.find('[data-testid="undo-snackbar"]').exists()).toBe(false)
		expect(store.undoRoomTaskCompletion).not.toHaveBeenCalled()
	})
})

async function flushMicrotasks() {
	for (let i = 0; i < 10; i++) {
		await Promise.resolve()
	}
}
