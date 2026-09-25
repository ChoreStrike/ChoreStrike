/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import SchedulePlanner from '../../../src/components/Scheduling/SchedulePlanner.vue'
import { useSchedulingStore } from '../../../src/store/scheduling.js'
import { useRoomTasksStore } from '../../../src/store/roomTasks.js'
import { useTasksStore } from '../../../src/store/tasks.js'
import { useRoomsStore } from '../../../src/store/rooms.js'
import { api } from '../../../src/services/api.js'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { todayKeyInZone } from '../../../src/utils/dateKeys.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, text, vars) => {
		if (!vars) return text
		return text.replace(/\{(\w+)\}/g, (match, key) => Object.prototype.hasOwnProperty.call(vars, key) ? String(vars[key]) : match)
	}),
}))

jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: jest.fn(),
	showError: jest.fn(),
}))

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))

const previewPayload = (overrides = {}) => ({
	previewVersion: 'v1',
	startDate: '2026-09-10',
	endDateExclusive: '2026-09-17',
	timezone: 'UTC',
	changes: [{
		roomTaskId: 11,
		taskId: 1,
		taskName: 'Dishes',
		roomName: 'Kitchen',
		energyCost: 2,
		priority: 2,
		flexDays: 3,
		legalDates: ['2026-09-10', '2026-09-11'],
		before: { assignees: [], dueDate: '2026-09-08' },
		proposed: { assignees: ['dad'], dueDate: '2026-09-10' },
		warnings: ['DATE_MOVED', 'ASSIGNEES_CHANGED'],
	}],
	unchanged: [],
	unplaced: [],
	capacity: [
		{ date: '2026-09-10', members: { dad: { houseUserId: 1, available: true, budget: 10, planned: 2, remaining: 8, taskCount: 1 } } },
	],
	warnings: [],
	...overrides,
})

describe('SchedulePlanner (owner plan: preview → adjust → apply, CS-PLAN-065)', () => {
	let schedulingStore
	let roomTasksStore
	let tasksStore
	let roomsStore

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()

		schedulingStore = useSchedulingStore()
		roomTasksStore = useRoomTasksStore()
		tasksStore = useTasksStore()
		roomsStore = useRoomsStore()

		schedulingStore.memberAvailability = [
			{ userId: 'dad', userName: 'Dad', weekdayAvailability: {}, energyBudget: {} },
			{ userId: 'mom', userName: 'Mom', weekdayAvailability: {}, energyBudget: {} },
		]
		tasksStore.tasks.push({ id: 1, name: 'Dishes', energyCost: 2 })
		roomsStore.rooms.push({ id: 5, name: 'Kitchen' })
		roomTasksStore.roomTasks.push({
			id: 11,
			taskId: 1,
			roomId: 5,
			isActive: true,
			nextDue: `${todayKeyInZone('UTC')}T08:00:00Z`,
			assignees: [],
		})
		// Default: scheduling-owned reads succeed (post-apply refresh).
		api.get.mockResolvedValue([])
	})

	const mountComponent = () => mount(SchedulePlanner)

	const selectTask = async (wrapper) => {
		await wrapper.find('[data-testid="planner-task-checkbox-11"]').setValue(true)
	}

	const previewAndApplyReady = async (wrapper, payload = previewPayload()) => {
		api.post.mockResolvedValueOnce(payload)
		await selectTask(wrapper)
		await wrapper.find('[data-testid="preview-plan-button"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	// WP2 §3.3.2 (RF-073-WP2-06/13): the owner's explicit consent
	// attestation gates the apply control.
	const grantConsent = async (wrapper) => {
		await wrapper.find('[data-testid="planner-consent"]').setValue(true)
	}

	it('renders the planner with selection UI and a disabled preview button', () => {
		const wrapper = mountComponent()
		expect(wrapper.find('[data-testid="schedule-planner"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="planner-task-checkbox-11"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="preview-plan-button"]').attributes('disabled')).toBeDefined()
	})

	it('previews the selected real tasks with the exact request shape', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())

		expect(api.post).toHaveBeenCalledWith('/api/v1/scheduling/suggestions', {
			startDate: todayKeyInZone('UTC'),
			roomTaskIds: [11],
			overrides: {},
		})
		expect(wrapper.find('[data-testid="plan-change-11"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Dishes')
		// Apply stays disabled until the explicit consent attestation.
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="planner-consent-hint"]').exists()).toBe(true)
		await grantConsent(wrapper)
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeUndefined()
	})

	it('shows the no-changes state and keeps Apply disabled', async () => {
		const wrapper = await previewAndApplyReady(mountComponent(), previewPayload({
			changes: [],
			unchanged: [{ roomTaskId: 11, reason: 'UP_TO_DATE', warnings: [] }],
		}))

		expect(wrapper.find('[data-testid="plan-no-changes"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="plan-unchanged"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeDefined()
	})

	it('reports unplaced rows with their reason', async () => {
		const wrapper = await previewAndApplyReady(mountComponent(), previewPayload({
			changes: [],
			unplaced: [{ roomTaskId: 11, reason: 'NO_CAPACITY', warnings: [] }],
		}))

		expect(wrapper.find('[data-testid="plan-unplaced-11"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('no free capacity on any legal day')
	})

	it('renders exact share labels and neutral/overload capacity clarifications (RF-073-WP2-13)', async () => {
		const wrapper = await previewAndApplyReady(mountComponent(), previewPayload({
			changes: [{
				roomTaskId: 11,
				taskId: 1,
				taskName: 'Dishes',
				roomName: 'Kitchen',
				energyCost: 2,
				priority: 2,
				flexDays: 3,
				legalDates: ['2026-09-10'],
				before: { assignees: ['dad'], dueDate: '2026-09-08' },
				proposed: { assignees: ['dad', 'mom'], dueDate: '2026-09-10', shares: { dad: 34, mom: 66 } },
				warnings: [],
			}],
			capacity: [{
				date: '2026-09-10',
				members: {
					dad: { houseUserId: 1, available: true, paused: true, budget: 10, planned: 0, remaining: 10, taskCount: 0 },
					mom: { houseUserId: 2, available: true, overloaded: true, budget: 10, planned: 12, remaining: 0, taskCount: 2 },
				},
			}],
		}))

		// The exact agreed shares ride the change row (whole percents).
		const shares = wrapper.find('[data-testid="plan-change-shares-11"]')
		expect(shares.exists()).toBe(true)
		expect(shares.text()).toContain('Dad 34%')
		expect(shares.text()).toContain('Mom 66%')

		// Neutral pause and existing overload stay visible with amounts.
		expect(wrapper.text()).toContain('Paused')
		expect(wrapper.text()).toContain('12/10 — over capacity')
	})

	it('marks the preview stale when an override edit happens', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())

		await wrapper.find('[data-testid="planner-date-11"]').setValue('2026-09-11')

		expect(schedulingStore.planStatus).toBe('stale')
		expect(wrapper.find('[data-testid="plan-stale"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeDefined()
	})

	it('marks the preview stale when the task selection changes (§3.1 rule 7)', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())

		await wrapper.find('[data-testid="planner-task-checkbox-11"]').setValue(false)

		expect(schedulingStore.planStatus).toBe('stale')
		expect(wrapper.find('[data-testid="plan-stale"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeDefined()
	})

	it('marks the preview stale when the start date changes (§3.1 rule 7)', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())
		const tomorrow = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

		await wrapper.find('[data-testid="planner-start-date"]').setValue(tomorrow)

		expect(schedulingStore.planStatus).toBe('stale')
		expect(wrapper.find('[data-testid="plan-stale"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="apply-plan-button"]').attributes('disabled')).toBeDefined()
	})

	it('applies the confirmed preview with input + previewVersion + commandId + consent and reports success', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())
		await grantConsent(wrapper)
		api.post.mockResolvedValueOnce({
			appliedCount: 1,
			windowStart: '2026-09-10',
			windowEndExclusive: '2026-09-17',
			roomTasks: [],
		})

		await wrapper.find('[data-testid="apply-plan-button"]').trigger('click')
		await flushPromises()

		const [, applyPayload] = api.post.mock.calls.find(([path]) => path === '/api/v1/scheduling/apply')
		expect(applyPayload).toEqual({
			startDate: todayKeyInZone('UTC'),
			roomTaskIds: [11],
			overrides: {},
			previewVersion: 'v1',
			commandId: schedulingStore.planCommandId,
			consent: true,
		})
		expect(schedulingStore.planCommandId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/)
		expect(schedulingStore.planStatus).toBe('applied')
		expect(wrapper.find('[data-testid="plan-applied"]').exists()).toBe(true)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('maps a 409 apply to the stale state with an error toast', async () => {
		const wrapper = await previewAndApplyReady(mountComponent())
		await grantConsent(wrapper)
		const conflict = new Error('Conflict')
		conflict.status = 409
		api.post.mockRejectedValueOnce(conflict)

		await wrapper.find('[data-testid="apply-plan-button"]').trigger('click')
		await flushPromises()

		expect(schedulingStore.planStatus).toBe('stale')
		expect(wrapper.find('[data-testid="plan-stale"]').exists()).toBe(true)
		expect(showError).toHaveBeenCalled()
		// The draft keeps its consent attestation across the 409 retry.
		expect(schedulingStore.planConsent).toBe(true)
	})

	it('shows an error toast and banner when the preview fails', async () => {
		api.post.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mountComponent()
		await selectTask(wrapper)
		await wrapper.find('[data-testid="preview-plan-button"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="plan-error"]').exists()).toBe(true)
	})
})

// CS-PLAN-065 §3.4 (RF-065-02): keyboard/mobile control contract at the
// component level — every control is a native keyboard-operable element with
// an accessible name, and status changes are announced through the aria-live
// region. Real tab-order navigation, the focus ring and the 44px touch
// targets are layout behavior proven by the E2E keyboard/mobile journeys
// (tests/e2e/scheduling-apply-flow.spec.ts); jsdom computes no layout.
describe('SchedulePlanner — keyboard/mobile control contract', () => {
	let schedulingStore

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()

		schedulingStore = useSchedulingStore()
		const roomTasksStore = useRoomTasksStore()
		const tasksStore = useTasksStore()
		const roomsStore = useRoomsStore()

		schedulingStore.memberAvailability = [
			{ userId: 'dad', userName: 'Dad', weekdayAvailability: {}, energyBudget: {} },
		]
		tasksStore.tasks.push({ id: 1, name: 'Dishes', energyCost: 2 })
		roomsStore.rooms.push({ id: 5, name: 'Kitchen' })
		roomTasksStore.roomTasks.push({
			id: 11,
			taskId: 1,
			roomId: 5,
			isActive: true,
			nextDue: `${todayKeyInZone('UTC')}T08:00:00Z`,
			assignees: [],
		})
		api.get.mockResolvedValue([])
	})

	const mountComponent = () => mount(SchedulePlanner)

	const previewReady = async (wrapper) => {
		api.post.mockResolvedValueOnce(previewPayload())
		await wrapper.find('[data-testid="planner-task-checkbox-11"]').setValue(true)
		await wrapper.find('[data-testid="preview-plan-button"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	it('renders every interactive control as a native keyboard-operable element', async () => {
		const wrapper = await previewReady(mountComponent())

		const checkbox = wrapper.find('[data-testid="planner-task-checkbox-11"]')
		expect(checkbox.element.tagName).toBe('INPUT')
		expect(checkbox.attributes('type')).toBe('checkbox')

		expect(wrapper.find('[data-testid="preview-plan-button"]').element.tagName).toBe('BUTTON')
		expect(wrapper.find('[data-testid="apply-plan-button"]').element.tagName).toBe('BUTTON')

		// The WP2 consent attestation is a native checkbox (space-operable).
		const consent = wrapper.find('[data-testid="planner-consent"]')
		expect(consent.element.tagName).toBe('INPUT')
		expect(consent.attributes('type')).toBe('checkbox')

		expect(wrapper.find('[data-testid="planner-assignee-11"]').element.tagName).toBe('SELECT')
		expect(wrapper.find('[data-testid="planner-date-11"]').element.tagName).toBe('SELECT')

		const dateInput = wrapper.find('[data-testid="planner-start-date"]')
		expect(dateInput.element.tagName).toBe('INPUT')
		expect(dateInput.attributes('type')).toBe('date')
		// Past windows are invalid (§3.1 rule 1): the picker bounds at today.
		expect(dateInput.attributes('min')).toBe(dateInput.element.value)
	})

	it('associates an accessible name with every form control', async () => {
		const wrapper = await previewReady(mountComponent())

		expect(wrapper.find('label[for="planner-start-date"]').exists()).toBe(true)
		// The task checkbox is wrapped in its (clickable) label.
		const checkbox = wrapper.find('[data-testid="planner-task-checkbox-11"]')
		expect(checkbox.element.closest('label')).not.toBeNull()
		// The consent checkbox is wrapped in its label with visible text.
		const consent = wrapper.find('[data-testid="planner-consent"]')
		expect(consent.element.closest('label')).not.toBeNull()
		expect(consent.element.closest('label').textContent).toContain('Everyone affected has agreed to this plan')
		// Both override selects are named by an explicit label[for].
		expect(wrapper.find('label[for="assignee-11"]').exists()).toBe(true)
		expect(wrapper.find('label[for="date-11"]').exists()).toBe(true)
	})

	it('announces every plan status change through the aria-live region', async () => {
		const wrapper = mountComponent()
		const announcer = wrapper.find('[data-testid="plan-status-announcer"]')
		expect(announcer.attributes('aria-live')).toBe('polite')
		expect(announcer.text()).toBe('')

		const announcements = [
			['loading', 'Building the plan from your tasks…'],
			['applying', 'Applying the plan…'],
			['applied', 'The plan was applied.'],
			['stale', 'The preview is stale.'],
			['error', 'The plan failed.'],
		]
		for (const [status, expected] of announcements) {
			schedulingStore.planStatus = status
			await wrapper.vm.$nextTick()
			expect(announcer.text()).toBe(expected)
		}
	})
})
