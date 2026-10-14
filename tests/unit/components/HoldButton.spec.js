/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach, afterEach } from '@jest/globals'
import { mount } from '@vue/test-utils'
import HoldButton, { HOLD_MS } from '@/components/Common/HoldButton.vue'

jest.mock('@/components/Common/Icon.vue', () => ({
	name: 'Icon',
	props: ['name', 'size'],
	template: '<span class="icon-stub" />',
}))

const mountButton = (props = {}, attrs = {}) => mount(HoldButton, {
	props: { label: 'Done', ...props },
	attrs: { 'data-testid': 'hold', ...attrs },
})

const control = wrapper => wrapper.get('[data-testid="hold"]')
const hint = wrapper => wrapper.get('[data-testid="hold-hint"]')

describe('HoldButton (CS-PLAN-084 WP-E)', () => {
	beforeEach(() => {
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('confirms after a pointer hold of 600 ms', async () => {
		const wrapper = mountButton()
		expect(HOLD_MS).toBe(600)

		await control(wrapper).trigger('pointerdown', { button: 0, clientX: 5, clientY: 5 })
		expect(control(wrapper).attributes('data-hold-state')).toBe('holding')
		jest.advanceTimersByTime(HOLD_MS - 1)
		expect(wrapper.emitted('confirm')).toBeUndefined()

		jest.advanceTimersByTime(1)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(control(wrapper).attributes('data-hold-state')).toBe('confirmed')

		await control(wrapper).trigger('pointerup')
		await control(wrapper).trigger('click')
		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(hint(wrapper).text()).toBe('')
	})

	it('cancels an early release without confirming and shows the hold hint', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(200)
		await control(wrapper).trigger('pointerup')
		await control(wrapper).trigger('click')
		jest.advanceTimersByTime(HOLD_MS)

		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect(hint(wrapper).text()).toBe('Press and hold to confirm')
		expect(hint(wrapper).attributes('role')).toBe('status')

		jest.advanceTimersByTime(3000)
		await wrapper.vm.$nextTick()
		expect(hint(wrapper).text()).toBe('')
	})

	it('cancels silently when the pointer leaves, is cancelled or scrolls away', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('pointerdown', { button: 0 })
		await control(wrapper).trigger('pointerleave')
		await control(wrapper).trigger('pointerdown', { button: 0 })
		await control(wrapper).trigger('pointercancel')
		await control(wrapper).trigger('pointerdown', { button: 0, clientX: 0, clientY: 0 })
		await control(wrapper).trigger('pointermove', { clientX: 0, clientY: 8 })
		expect(control(wrapper).attributes('data-hold-state')).toBe('holding')
		await control(wrapper).trigger('pointermove', { clientX: 0, clientY: 15 })
		jest.advanceTimersByTime(HOLD_MS * 2)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
		expect(hint(wrapper).text()).toBe('')
	})

	it('ignores secondary mouse buttons', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('pointerdown', { button: 2 })
		jest.advanceTimersByTime(HOLD_MS)

		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it.each(['Enter', ' '])('confirms a keyboard hold of %j and ignores auto-repeat', async (key) => {
		const wrapper = mountButton()

		await control(wrapper).trigger('keydown', { key })
		jest.advanceTimersByTime(300)
		await control(wrapper).trigger('keydown', { key, repeat: true })
		jest.advanceTimersByTime(300)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		await control(wrapper).trigger('keyup', { key })
		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
	})

	it('cancels an early key release and shows the hint', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('keydown', { key: 'Enter' })
		jest.advanceTimersByTime(100)
		await control(wrapper).trigger('keyup', { key: 'Enter' })
		jest.advanceTimersByTime(HOLD_MS)

		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect(hint(wrapper).text()).toBe('Press and hold to confirm')
	})

	it('cancels a keyboard hold when focus leaves', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('keydown', { key: 'Enter' })
		await control(wrapper).trigger('blur')
		jest.advanceTimersByTime(HOLD_MS)

		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('explains an untracked click (assistive activation) instead of confirming', async () => {
		const wrapper = mountButton()

		await control(wrapper).trigger('click')

		expect(wrapper.emitted('confirm')).toBeUndefined()
		expect(hint(wrapper).text()).toBe('Press and hold to confirm')
	})

	it('describes the gesture to assistive technology', () => {
		const wrapper = mountButton()
		const describedBy = control(wrapper).attributes('aria-describedby')

		expect(describedBy).toBeTruthy()
		expect(wrapper.get(`#${describedBy}`).text()).toBe('Press and hold to confirm')
	})

	it('does not start while disabled or busy, and marks busy', async () => {
		const disabled = mountButton({ disabled: true })
		await control(disabled).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(HOLD_MS)
		expect(disabled.emitted('confirm')).toBeUndefined()

		const busy = mountButton({ busy: true })
		expect(control(busy).attributes('disabled')).toBeDefined()
		expect(control(busy).attributes('aria-busy')).toBe('true')
		await control(busy).trigger('keydown', { key: 'Enter' })
		jest.advanceTimersByTime(HOLD_MS)
		expect(busy.emitted('confirm')).toBeUndefined()
	})

	it('returns to idle when the confirmed action stops being in flight', async () => {
		const wrapper = mountButton()
		await control(wrapper).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(HOLD_MS)
		await wrapper.setProps({ busy: true })
		// A disabled button receives no pointerup.
		await wrapper.setProps({ busy: false })

		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
		await control(wrapper).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(HOLD_MS)
		expect(wrapper.emitted('confirm')).toHaveLength(2)
	})

	it('keeps the confirmed state when disabling the busy button blurs it', async () => {
		const wrapper = mountButton()
		await control(wrapper).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(HOLD_MS)
		await wrapper.setProps({ busy: true })
		await control(wrapper).trigger('blur')
		await control(wrapper).trigger('pointerleave')

		expect(control(wrapper).attributes('data-hold-state')).toBe('confirmed')
		await wrapper.setProps({ busy: false })
		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
	})

	it('settles a confirmed press whose release is swallowed (a dialog opened over it)', async () => {
		const wrapper = mountButton()
		await control(wrapper).trigger('pointerdown', { button: 0 })
		jest.advanceTimersByTime(HOLD_MS)
		await control(wrapper).trigger('pointerleave')
		expect(control(wrapper).attributes('data-hold-state')).toBe('confirmed')

		jest.advanceTimersByTime(1000)
		await wrapper.vm.$nextTick()
		expect(control(wrapper).attributes('data-hold-state')).toBe('idle')
		expect(hint(wrapper).text()).toBe('')
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('uses a static fill under prefers-reduced-motion', async () => {
		const originalMatchMedia = window.matchMedia
		window.matchMedia = jest.fn(query => ({ matches: query.includes('reduce'), media: query }))
		try {
			const wrapper = mountButton()
			await control(wrapper).trigger('pointerdown', { button: 0 })
			expect(control(wrapper).classes()).toEqual(expect.arrayContaining(['is-holding', 'is-static']))
		} finally {
			window.matchMedia = originalMatchMedia
		}
	})

	it('animates the ring without reduced motion', async () => {
		const wrapper = mountButton()
		await control(wrapper).trigger('pointerdown', { button: 0 })

		expect(control(wrapper).classes()).toContain('is-holding')
		expect(control(wrapper).classes()).not.toContain('is-static')
		expect(control(wrapper).attributes('style')).toContain('--hold-ms: 600ms')
	})

	it('puts test ids and labels on the button and classes on the wrapper', () => {
		const wrapper = mountButton({ label: '', variant: 'icon' }, { class: 'extra', 'aria-label': 'Quick clean' })

		expect(wrapper.classes()).toEqual(expect.arrayContaining(['hold-button', 'hold-button--icon', 'extra']))
		expect(control(wrapper).attributes('aria-label')).toBe('Quick clean')
		expect(control(wrapper).classes()).not.toContain('extra')
		expect(control(wrapper).attributes('type')).toBe('button')
	})
})
