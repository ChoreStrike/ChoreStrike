/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { HOLD_MS, useHoldPress } from '../../../src/composables/useHoldPress.js'

/**
 * Mount the composable inside a host component (it registers an unmount
 * hook and watchers) and return its state, the controls and the confirm spy.
 *
 * @param {object} initial Initial `disabled`, `busy` and `holdMs` values.
 */
function mountHold(initial = {}) {
	const disabled = ref(initial.disabled ?? false)
	const busy = ref(initial.busy ?? false)
	const holdMs = ref(initial.holdMs ?? HOLD_MS)
	const onConfirm = jest.fn()
	let hold
	const wrapper = mount(defineComponent({
		setup() {
			hold = useHoldPress({ holdMs, disabled, busy, onConfirm })
			return {}
		},
		template: '<div />',
	}))
	return { wrapper, hold, disabled, busy, holdMs, onConfirm }
}

const pointerDown = (hold, extra = {}) => hold.onPointerDown({ button: 0, clientX: 0, clientY: 0, ...extra })
const key = (name, extra = {}) => ({ key: name, repeat: false, preventDefault: jest.fn(), ...extra })

describe('useHoldPress (CR-084-04)', () => {
	beforeEach(() => {
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('confirms exactly once after the configured hold and settles back to idle', () => {
		const { hold, onConfirm } = mountHold()

		pointerDown(hold)
		expect(hold.phase.value).toBe('holding')
		jest.advanceTimersByTime(HOLD_MS - 1)
		expect(onConfirm).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(onConfirm).toHaveBeenCalledTimes(1)
		expect(hold.phase.value).toBe('confirmed')

		// Nothing releases the press (a dialog swallowed it): it settles on its own.
		jest.advanceTimersByTime(999)
		expect(hold.phase.value).toBe('confirmed')
		jest.advanceTimersByTime(1)
		expect(hold.phase.value).toBe('idle')
		expect(onConfirm).toHaveBeenCalledTimes(1)
	})

	it('reads the hold duration when the press starts', () => {
		const { hold, holdMs, onConfirm } = mountHold({ holdMs: 100 })
		holdMs.value = 250

		pointerDown(hold)
		jest.advanceTimersByTime(249)
		expect(onConfirm).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(onConfirm).toHaveBeenCalledTimes(1)
	})

	it('cancels an early release without confirming, shows the hint and hides it later', () => {
		const { hold, onConfirm } = mountHold()

		pointerDown(hold)
		jest.advanceTimersByTime(200)
		hold.onPointerUp()
		jest.advanceTimersByTime(HOLD_MS)

		expect(onConfirm).not.toHaveBeenCalled()
		expect(hold.phase.value).toBe('idle')
		expect(hold.hintVisible.value).toBe(true)

		jest.advanceTimersByTime(2500)
		expect(hold.hintVisible.value).toBe(false)
	})

	it('treats the click after a handled release as already explained', () => {
		const { hold } = mountHold()

		pointerDown(hold)
		hold.onPointerUp()
		hold.onClick()
		jest.advanceTimersByTime(3000)
		expect(hold.hintVisible.value).toBe(false)

		// An untracked click (assistive activation) explains the gesture.
		hold.onClick()
		expect(hold.hintVisible.value).toBe(true)
	})

	it('does not explain a click while blocked', () => {
		const { hold } = mountHold({ disabled: true })

		hold.onClick()

		expect(hold.hintVisible.value).toBe(false)
	})

	it('ignores secondary buttons and moves a scroll or drag away', () => {
		const { hold, onConfirm } = mountHold()

		pointerDown(hold, { button: 2 })
		expect(hold.phase.value).toBe('idle')

		pointerDown(hold, { clientX: 0, clientY: 0 })
		hold.onPointerMove({ clientX: 0, clientY: 10 })
		expect(hold.phase.value).toBe('holding')
		hold.onPointerMove({ clientX: 0, clientY: 11 })
		expect(hold.phase.value).toBe('idle')
		jest.advanceTimersByTime(HOLD_MS * 2)
		expect(onConfirm).not.toHaveBeenCalled()
	})

	it.each(['Enter', ' ', 'Spacebar'])('confirms a keyboard hold of %j and ignores auto-repeat', (name) => {
		const { hold, onConfirm } = mountHold()
		const down = key(name)

		hold.onKeyDown(down)
		expect(down.preventDefault).toHaveBeenCalled()
		jest.advanceTimersByTime(300)
		hold.onKeyDown(key(name, { repeat: true }))
		jest.advanceTimersByTime(300)

		expect(onConfirm).toHaveBeenCalledTimes(1)
		hold.onKeyUp(key(name))
		expect(hold.phase.value).toBe('idle')
	})

	it('cancels an early key release, ignores other keys and cancels on blur', () => {
		const { hold, onConfirm } = mountHold()
		const other = key('a')

		hold.onKeyDown(other)
		hold.onKeyUp(other)
		expect(other.preventDefault).not.toHaveBeenCalled()
		expect(hold.phase.value).toBe('idle')

		hold.onKeyDown(key('Enter'))
		jest.advanceTimersByTime(100)
		hold.onKeyUp(key('Enter'))
		expect(hold.hintVisible.value).toBe(true)

		hold.onKeyDown(key('Enter'))
		hold.abort()
		jest.advanceTimersByTime(HOLD_MS)
		expect(onConfirm).not.toHaveBeenCalled()
	})

	it.each(['disabled', 'busy'])('stops a running hold without confirming when it becomes %s', async (flag) => {
		const state = mountHold()
		pointerDown(state.hold)
		jest.advanceTimersByTime(HOLD_MS / 2)

		state[flag].value = true
		await state.wrapper.vm.$nextTick()
		expect(state.hold.phase.value).toBe('idle')
		jest.advanceTimersByTime(HOLD_MS)
		expect(state.onConfirm).not.toHaveBeenCalled()
	})

	it('does not start a hold while disabled or busy', () => {
		const { hold, onConfirm, busy } = mountHold({ disabled: true })
		pointerDown(hold)
		jest.advanceTimersByTime(HOLD_MS)
		expect(onConfirm).not.toHaveBeenCalled()
		expect(hold.phase.value).toBe('idle')

		busy.value = true
		pointerDown(hold)
		expect(hold.phase.value).toBe('idle')
	})

	it('keeps a confirmed press while the action is in flight and frees it afterwards', async () => {
		const { wrapper, hold, busy } = mountHold()
		pointerDown(hold)
		jest.advanceTimersByTime(HOLD_MS)
		busy.value = true
		await wrapper.vm.$nextTick()
		expect(hold.phase.value).toBe('confirmed')

		hold.abort()
		jest.advanceTimersByTime(5000)
		expect(hold.phase.value).toBe('confirmed')

		busy.value = false
		await wrapper.vm.$nextTick()
		expect(hold.phase.value).toBe('idle')
	})

	it('uses a static fill only under prefers-reduced-motion', () => {
		const original = window.matchMedia
		try {
			const { hold } = mountHold()
			window.matchMedia = jest.fn(() => ({ matches: false }))
			pointerDown(hold)
			expect(hold.staticFill.value).toBe(false)
			hold.abort()

			window.matchMedia = jest.fn((query) => ({ matches: query.includes('reduce') }))
			pointerDown(hold)
			expect(hold.staticFill.value).toBe(true)
		} finally {
			window.matchMedia = original
		}
	})

	it('clears its timers on unmount so a late confirm never fires', () => {
		const { wrapper, hold, onConfirm } = mountHold()
		pointerDown(hold)

		wrapper.unmount()
		jest.advanceTimersByTime(HOLD_MS * 3)

		expect(onConfirm).not.toHaveBeenCalled()
	})
})
