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

const mountDialog = (props = {}, slots = {}) => mount(ParticipationDialog, {
	props: { members, ...props },
	slots,
})

describe('ParticipationDialog (WP3 §3.6 actual-work split)', () => {
	it('pre-fills the equal split of the candidates in wallet order — what the server credits by default', () => {
		const wrapper = mountDialog()

		const shares = wrapper.vm.participants.map(p => p.sharePct)
		expect(shares).toEqual([34, 33, 33])
		expect(wrapper.vm.totalPct).toBe(100)
		expect(wrapper.find('[data-testid="participation-confirm"]').attributes('disabled')).toBeUndefined()
	})

	it('ticks only the preselected members: who a hold would credit', () => {
		const wrapper = mountDialog({ preselected: ['mom'] })

		expect(wrapper.vm.participants.map(p => [p.userId, p.active, p.sharePct])).toEqual([
			['dad', false, 0],
			['kid', false, 0],
			['mom', true, 100],
		])
		expect(wrapper.find('[data-testid="participant-share-dad"]').attributes('disabled')).toBeDefined()
	})

	it('keeps the shares equal while members are ticked and unticked', async () => {
		const wrapper = mountDialog({ preselected: ['mom'] })

		await wrapper.find('[data-testid="participant-active-kid"]').setValue(true)
		expect(wrapper.vm.participants.map(p => p.sharePct)).toEqual([0, 50, 50])

		await wrapper.find('[data-testid="participant-active-dad"]').setValue(true)
		expect(wrapper.vm.participants.map(p => p.sharePct)).toEqual([34, 33, 33])

		await wrapper.find('[data-testid="participant-active-mom"]').setValue(false)
		expect(wrapper.vm.participants.map(p => p.sharePct)).toEqual([50, 50, 0])
		expect(wrapper.vm.isValid).toBe(true)
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

	it('records work for others: nobody has to tick themself (ADR-021 decision 10)', async () => {
		const wrapper = mountDialog({ preselected: ['dad'] })
		await wrapper.get('[data-testid="participant-active-dad"]').setValue(false)
		await wrapper.get('[data-testid="participant-active-mom"]').setValue(true)

		expect(wrapper.find('[data-testid="participation-actor-required"]').exists()).toBe(false)
		await wrapper.get('[data-testid="participation-confirm"]').trigger('click')

		expect(wrapper.emitted('confirm')[0][0]).toEqual({
			participants: [{ userId: 'mom', sharePct: 100 }],
			allocationReviewed: true,
		})
	})

	it('needs at least one ticked member', async () => {
		const wrapper = mountDialog({ preselected: [] })

		expect(wrapper.get('[data-testid="participation-confirm"]').element.disabled).toBe(true)
		wrapper.vm.confirm()
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('renders what else belongs to the decision between the people and the actions', () => {
		const wrapper = mountDialog({}, { default: '<p data-testid="extra">Sub-tasks</p>' })

		const html = wrapper.html()
		expect(html.indexOf('data-testid="share-total"')).toBeLessThan(html.indexOf('data-testid="extra"'))
		expect(html.indexOf('data-testid="extra"')).toBeLessThan(html.indexOf('data-testid="participation-cancel"'))
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

		it('keeps the shares as entered when a member is ticked: the owner sets exact percents', async () => {
			const wrapper = mountDialog({
				initialParticipants: [{ userId: 'mom', sharePct: 100 }],
			})

			await wrapper.find('[data-testid="participant-active-dad"]').setValue(true)

			expect(wrapper.vm.participants.map(p => [p.userId, p.sharePct])).toEqual([['dad', 0], ['kid', 0], ['mom', 100]])
			expect(wrapper.vm.isValid).toBe(false)
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
