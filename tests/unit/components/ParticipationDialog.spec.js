/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { mount } from '@vue/test-utils'
import ParticipationDialog from '@/components/Common/ParticipationDialog.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => {
		let out = text
		Object.entries(vars).forEach(([key, value]) => {
			out = out.replace(`{${key}}`, String(value))
		})
		return out
	},
}))

jest.mock('@/components/Common/Modal.vue', () => ({
	name: 'Modal',
	props: ['ariaLabel'],
	template: '<div class="modal-stub"><slot /></div>',
}))

const members = [
	{ userId: 'dad', displayName: 'Dad' },
	{ userId: 'mom', displayName: 'Mom' },
	{ userId: 'kid', displayName: 'Kid' },
]

const mountDialog = (props = {}) => mount(ParticipationDialog, {
	props: { members, ...props },
})

describe('ParticipationDialog (WP3 §3.6 actual-work split)', () => {
	it('defaults to the equal wallet-order split without a proposal', () => {
		const wrapper = mountDialog()

		const shares = wrapper.vm.participants.map(p => p.sharePct)
		expect(shares).toEqual([34, 33, 33])
		expect(wrapper.vm.totalPct).toBe(100)
		expect(wrapper.find('[data-testid="participation-confirm"]').attributes('disabled')).toBeUndefined()
	})

	it('pre-fills the server proposal (the effective concrete agreement)', () => {
		const wrapper = mountDialog({
			proposal: {
				occurrenceId: '3:7',
				basis: 'agreement',
				participants: [
					{ userId: 'mom', sharePct: 70 },
					{ userId: 'dad', sharePct: 30 },
				],
			},
		})

		expect(wrapper.vm.participants.map(p => [p.userId, p.sharePct])).toEqual([
			['dad', 30],
			['mom', 70],
		])
		// Display names resolve through the member list.
		expect(wrapper.vm.participants[0].displayName).toBe('Dad')
	})

	it('falls back to raw user ids for proposal participants outside the member list', () => {
		const wrapper = mountDialog({
			proposal: {
				occurrenceId: '3:7',
				basis: 'agreement',
				participants: [{ userId: 'grandma', sharePct: 100 }],
			},
		})

		expect(wrapper.vm.participants).toHaveLength(1)
		expect(wrapper.vm.participants[0].displayName).toBe('grandma')
		expect(wrapper.vm.participants[0].sharePct).toBe(100)
	})

	it('disables confirmation while the shares do not total exactly 100', async () => {
		const wrapper = mountDialog()

		// 34 + 50 + 33 = 117 — over 100, the confirm stays disabled.
		await wrapper.find('[data-testid="participant-share-mom"]').setValue('50')

		expect(wrapper.vm.totalPct).toBe(117)
		expect(wrapper.vm.isValid).toBe(false)
		expect(wrapper.find('[data-testid="participation-confirm"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="share-total"]').classes()).toContain('invalid')
	})

	it('excludes unchecked participants from the confirmed split', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="participant-active-kid"]').setValue(false)
		await wrapper.find('[data-testid="participant-share-dad"]').setValue('50')
		await wrapper.find('[data-testid="participant-share-mom"]').setValue('50')
		await wrapper.find('[data-testid="participation-confirm"]').trigger('click')

		const emitted = wrapper.emitted('confirm')
		expect(emitted).toHaveLength(1)
		expect(emitted[0][0]).toEqual({
			participants: [
				{ userId: 'dad', sharePct: 50 },
				{ userId: 'mom', sharePct: 50 },
			],
			allocationReviewed: true,
		})
	})

	it('close never emits a confirmation', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="participation-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	describe('attribution correction mode (WP3 §3.7 reuse)', () => {
		it('pre-fills the recorded split over the current member list', () => {
			const wrapper = mountDialog({
				initialParticipants: [
					{ userId: 'mom', sharePct: 60 },
					{ userId: 'kid', sharePct: 40 },
				],
			})

			// Recorded participants stay active with their exact percents;
			// the unrecorded member starts inactive (share redistributable).
			expect(wrapper.vm.participants.map(p => [p.userId, p.active, p.sharePct])).toEqual([
				['dad', false, 0],
				['kid', true, 40],
				['mom', true, 60],
			])
			expect(wrapper.vm.totalPct).toBe(100)
		})

		it('does NOT auto-join the correter (Q-074-02: completer or owner)', () => {
			const wrapper = mountDialog({
				actorUserId: 'dad',
				initialParticipants: [{ userId: 'mom', sharePct: 100 }],
			})

			expect(wrapper.vm.participants.find(p => p.userId === 'dad').active).toBe(false)
			expect(wrapper.vm.totalPct).toBe(100)
		})

		it('renders the correction copy overrides', () => {
			const wrapper = mountDialog({
				initialParticipants: [{ userId: 'mom', sharePct: 100 }],
				heading: 'Correct the work split',
				hint: 'Adjust who actually did the work. Shares must total exactly 100%.',
				confirmLabel: 'Save correction',
			})

			expect(wrapper.find('h2').text()).toBe('Correct the work split')
			expect(wrapper.find('.dialog-hint').text()).toContain('Adjust who actually did the work')
			expect(wrapper.find('[data-testid="participation-confirm"]').text()).toBe('Save correction')
		})

		it('emits the corrected split with the review confirmation', async () => {
			const wrapper = mountDialog({
				initialParticipants: [{ userId: 'mom', sharePct: 100 }],
			})

			await wrapper.find('[data-testid="participant-active-dad"]').setValue(true)
			await wrapper.find('[data-testid="participant-share-mom"]').setValue('50')
			await wrapper.find('[data-testid="participant-share-dad"]').setValue('50')
			await wrapper.find('[data-testid="participation-confirm"]').trigger('click')

			const emitted = wrapper.emitted('confirm')
			expect(emitted).toHaveLength(1)
			expect(emitted[0][0]).toEqual({
				participants: [
					{ userId: 'dad', sharePct: 50 },
					{ userId: 'mom', sharePct: 50 },
				],
				allocationReviewed: true,
			})
		})
	})
})
