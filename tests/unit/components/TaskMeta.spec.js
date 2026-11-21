import { describe, it, expect } from '@jest/globals'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import TaskMeta from '../../../src/components/Tasks/TaskMeta.vue'

const mountMeta = (props = {}) => mount(TaskMeta, {
	props: { energy: 3, recurrence: 'Every 3 days ±2', assignees: 'Assigned to: Mom', location: 'Upstairs · Bathroom', ...props },
	global: { stubs: { CsIcon: true } },
})

describe('TaskMeta', () => {
	it('shows energy, recurrence, assignees and the place of a task', () => {
		const wrapper = mountMeta()

		expect(wrapper.get('[data-testid="task-energy"]').text()).toBe('3')
		expect(wrapper.get('[data-testid="task-recurrence"]').text()).toBe('Every 3 days ±2')
		expect(wrapper.get('[data-testid="task-assignees"]').text()).toBe('Assigned to: Mom')
		expect(wrapper.get('[data-testid="task-location"]').text()).toBe('Upstairs · Bathroom')
	})

	it('leaves the place out while it is unknown', () => {
		expect(mountMeta({ location: '' }).find('[data-testid="task-location"]').exists()).toBe(false)
	})

	it('colours the energy through a token that both themes set', () => {
		const source = readFileSync(resolve(__dirname, '../../../src/components/Tasks/TaskMeta.vue'), 'utf8')
		const tokens = readFileSync(resolve(__dirname, '../../../src/styles/tokens.css'), 'utf8')

		expect(source).toMatch(/\.meta-item\.energy\s*\{[^}]*color:\s*var\(--cs-energy\)/)
		expect(tokens.match(/--cs-energy:/g)).toHaveLength(3)
	})

	it('keeps the energy on an ordinary background on an overdue task row (RF-090-40)', () => {
		// The energy hues reach 4.5:1 on the ordinary backgrounds only; on an
		// --color-error-hover tint they fall to about 3.9:1 (light theme). The
		// one task row (CS-PLAN-091 WP-B6) is a card on the main background
		// and marks overdue work by its border: a tint under it would need
		// the energy in the main text colour again.
		const row = readFileSync(resolve(__dirname, '../../../src/components/Tasks/TaskRow.vue'), 'utf8')
		const card = row.match(/\.task-row-group\s*\{([^}]*)\}/)
		const overdue = row.match(/\.task-row-group\.overdue\s*\{([^}]*)\}/)

		// The card's own background also keeps the row off the red block of Today.
		expect(card[1]).toMatch(/background:\s*var\(--color-main-background\)/)
		expect(overdue[1]).toMatch(/border-color:\s*var\(--color-element-error\)/)
		expect(overdue[1]).not.toMatch(/background/)
	})
})
