import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import ExpandChevron from '../../../src/components/Common/ExpandChevron.vue'

const SRC = resolve(__dirname, '../../../src')

describe('ExpandChevron', () => {
	it('turns only when open', async () => {
		const wrapper = mount(ExpandChevron)
		expect(wrapper.classes()).toContain('expand-chevron')
		expect(wrapper.classes()).not.toContain('expand-chevron--open')

		await wrapper.setProps({ open: true })
		expect(wrapper.classes()).toContain('expand-chevron--open')
	})

	it('turns 90 degrees and respects reduced motion', () => {
		const source = readFileSync(resolve(SRC, 'components/Common/ExpandChevron.vue'), 'utf8')

		expect(source).toMatch(/\.expand-chevron--open\s*\{\s*transform:\s*rotate\(90deg\)/)
		// The token drops to 0ms under prefers-reduced-motion.
		expect(source).toContain('transition: transform var(--cs-transition-fast)')
	})

	it.each([
		'views/Settings.vue',
		'views/TodayView.vue',
		'components/Dashboard/FloorList.vue',
		'components/Tasks/TaskFilterBar.vue',
		'components/Tasks/TaskListItem.vue',
	])('is the expand mark of %s', (file) => {
		const source = readFileSync(resolve(SRC, file), 'utf8')

		expect(source).toContain('<ExpandChevron')
		expect(source).not.toMatch(/\? 'Chevron(Up|Down|Right)' : 'Chevron(Up|Down|Right)'/)
	})

	it('leaves the arrows to the reorder controls', () => {
		const source = readFileSync(resolve(SRC, 'views/Settings.vue'), 'utf8')

		expect(source).toMatch(/move-floor-up-[\s\S]{0,200}name="ArrowUp"/)
		expect(source).toMatch(/move-floor-down-[\s\S]{0,240}name="ArrowDown"/)
	})
})
