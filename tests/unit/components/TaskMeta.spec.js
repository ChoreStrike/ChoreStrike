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
})
