/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach, jest } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useSchedulingStore } from '../../../src/store/scheduling.js'

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

import { api } from '../../../src/services/api.js'

describe('Scheduling Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSchedulingStore()
		jest.clearAllMocks()
	})

	describe('Initial State', () => {
		it('has correct initial state', () => {
			expect(store.memberAvailability).toEqual([])
			expect(store.preferredWeekdays).toEqual([])
			expect(store.suggestion).toEqual({ days: {}, overdue: [], loads: {} })
			expect(store.energyBudget.monday).toBe(10)
			expect(store.weekdayAvailability.monday).toBe(true)
			// CS-PLAN-065: the reader-less energy counter is gone (D7). The
			// applied plan and the client-side load view went with ADR-022.
			expect(store).not.toHaveProperty('todayConsumedEnergy')
			expect(store).not.toHaveProperty('planStatus')
			expect(store).not.toHaveProperty('overcapacityDays')
			// The own child limit and the error state lost their last reader with the weekly plan's page.
			expect(store).not.toHaveProperty('maxDailyTasks')
			expect(store).not.toHaveProperty('error')
		})
	})

	describe('Getters', () => {
		it('getOwnCapacityForDay ignores the household read and honours an unavailable day', () => {
			store.memberAvailability = [
				{ userId: 'a', weekdayAvailability: { monday: true }, energyBudget: { monday: 9 } },
				{ userId: 'b', weekdayAvailability: { monday: true }, energyBudget: { monday: 4 } },
			]
			store.energyBudget = { ...store.energyBudget, monday: 6, tuesday: 7 }
			store.weekdayAvailability = { ...store.weekdayAvailability, tuesday: false }

			expect(store.getOwnCapacityForDay('Monday')).toBe(6)
			expect(store.getOwnCapacityForDay('tuesday')).toBe(0)
		})
	})

	describe('Actions', () => {
		describe('fetchUserPreferences', () => {
			it('fetches preferences successfully (D7: no consumed-energy field)', async () => {
				const prefs = {
					energyBudget: { monday: 20 },
					weekdayAvailability: { monday: false },
					maxDailyTasks: null,
				}
				api.get.mockResolvedValue(prefs)

				await store.fetchUserPreferences()

				expect(store.energyBudget.monday).toBe(20)
				expect(store.weekdayAvailability.monday).toBe(false)
				expect(store.preferredWeekdays).toEqual([])
			})

			it('reads the preferred days (ADR-022 decision 5)', async () => {
				api.get.mockResolvedValue({ preferredWeekdays: ['wednesday', 'saturday'] })

				await store.fetchUserPreferences()

				expect(store.preferredWeekdays).toEqual(['wednesday', 'saturday'])
			})

			// RF-090-37: Today and the planning settings both read the
			// preferences; an older answer landing last must not replace the
			// newer one, or the form's watcher discards an unsaved edit.
			const deferredGet = () => {
				let resolve
				api.get.mockImplementationOnce(() => new Promise((done) => {
					resolve = done
				}))
				return (data) => resolve(data)
			}

			it('never lets an older read overwrite a newer one (RF-090-37)', async () => {
				const answerOlder = deferredGet()
				const answerNewer = deferredGet()
				const older = store.fetchUserPreferences()
				const newer = store.fetchUserPreferences()

				answerNewer({ energyBudget: { monday: 9 } })
				await newer
				const landed = store.energyBudget
				answerOlder({ energyBudget: { monday: 5 } })
				await older

				expect(store.energyBudget).toBe(landed)
				expect(store.energyBudget.monday).toBe(9)
			})

			it('lets the caller of an overtaken read wait for the winning read (RF-090-37)', async () => {
				const answerOlder = deferredGet()
				const answerNewer = deferredGet()
				let olderDone = false
				const older = store.fetchUserPreferences().then(() => {
					olderDone = true
				})
				const newer = store.fetchUserPreferences()

				answerOlder({ energyBudget: { monday: 5 } })
				await new Promise((resolve) => setTimeout(resolve, 0))
				expect(olderDone).toBe(false)
				expect(store.energyBudget.monday).toBe(10)

				answerNewer({ energyBudget: { monday: 9 } })
				await Promise.all([older, newer])
				expect(olderDone).toBe(true)
				expect(store.energyBudget.monday).toBe(9)
			})

			it('drops a read that started before an accepted save or a reset (RF-090-37)', async () => {
				const answerBeforeSave = deferredGet()
				const beforeSave = store.fetchUserPreferences()
				api.put.mockResolvedValue({ energyBudget: { monday: 12 } })
				await store.saveWeeklyPreferences({ energyBudget: { monday: 12 } })
				answerBeforeSave({ energyBudget: { monday: 5 } })
				await beforeSave
				expect(store.energyBudget.monday).toBe(12)

				const answerBeforeReset = deferredGet()
				const beforeReset = store.fetchUserPreferences()
				store.reset()
				answerBeforeReset({ energyBudget: { monday: 5 } })
				await beforeReset
				expect(store.energyBudget.monday).toBe(10)
			})
		})

		describe('saveWeeklyPreferences', () => {
			const fullAvailability = {
				monday: true, tuesday: false, wednesday: true, thursday: true,
				friday: true, saturday: true, sunday: true,
			}
			const fullEnergy = {
				monday: 12, tuesday: 12, wednesday: 12, thursday: 12,
				friday: 12, saturday: 15, sunday: 15,
			}

			it('sends exactly one PUT with both sections and replaces state from the response', async () => {
				api.put.mockResolvedValue({
					weekdayAvailability: fullAvailability,
					energyBudget: fullEnergy,
				})

				await store.saveWeeklyPreferences({ weekdayAvailability: fullAvailability, energyBudget: fullEnergy })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
					weekdayAvailability: fullAvailability,
					energyBudget: fullEnergy,
				})
				expect(store.weekdayAvailability).toEqual(fullAvailability)
				expect(store.energyBudget).toEqual(fullEnergy)
			})

			it('sends only the provided section (energy-only payload)', async () => {
				api.put.mockResolvedValue({ energyBudget: fullEnergy })

				await store.saveWeeklyPreferences({ energyBudget: fullEnergy })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/preferences', {
					energyBudget: fullEnergy,
				})
				// Availability state untouched by the response.
				expect(store.weekdayAvailability.monday).toBe(true)
			})

			it('sends the preferred days, also none, and keeps what the server stored', async () => {
				api.put.mockResolvedValue({ preferredWeekdays: ['saturday'] })

				await store.saveWeeklyPreferences({ preferredWeekdays: ['saturday'] })
				expect(api.put).toHaveBeenLastCalledWith('/api/v1/scheduling/preferences', { preferredWeekdays: ['saturday'] })
				expect(store.preferredWeekdays).toEqual(['saturday'])

				api.put.mockResolvedValue({ preferredWeekdays: [] })
				await store.saveWeeklyPreferences({ preferredWeekdays: [] })
				expect(api.put).toHaveBeenLastCalledWith('/api/v1/scheduling/preferences', { preferredWeekdays: [] })
				expect(store.preferredWeekdays).toEqual([])
			})

			it('propagates errors and records them in state', async () => {
				api.put.mockRejectedValue(new Error('Request failed'))

				await expect(store.saveWeeklyPreferences({ energyBudget: fullEnergy })).rejects.toThrow('Request failed')

				expect(api.put).toHaveBeenCalledTimes(1)
			})
		})

		describe('fetchMemberAvailability', () => {
			it('fetches member availability', async () => {
				const members = [{ userId: 'user1' }, { userId: 'user2' }]
				api.get.mockResolvedValue(members)

				await store.fetchMemberAvailability()

				expect(store.memberAvailability).toEqual(members)
			})
		})

		describe('updateMemberPreferences (UX-014)', () => {
			it('PUTs the updates and merges them into the matching member', async () => {
				store.memberAvailability = [{ userId: 'user1', energyBudget: { monday: 10 } }]
				api.put.mockResolvedValue(undefined)

				await store.updateMemberPreferences('user1', { energyBudget: { monday: 15 } })

				expect(api.put).toHaveBeenCalledTimes(1)
				expect(api.put).toHaveBeenCalledWith('/api/v1/scheduling/members/user1', { energyBudget: { monday: 15 } })
				expect(store.memberAvailability[0].energyBudget.monday).toBe(15)
			})

			it('propagates errors', async () => {
				api.put.mockRejectedValue(new Error('Forbidden'))

				await expect(store.updateMemberPreferences('user1', {})).rejects.toThrow('Forbidden')
			})
		})

		describe('fetchSuggestion (ADR-022 decision 6)', () => {
			const answer = {
				days: [
					{ day: '2026-11-23', entries: [{ roomTaskId: 11, cycleKey: 'c88', userIds: ['kid'], energy: 2, overload: false }] },
					{ day: '2026-11-24', entries: [] },
				],
				overdue: [{ roomTaskId: 12, cycleKey: 'd2026-11-20', userIds: ['kid'], energy: 3 }],
				members: [
					{ userId: 'kid', day: '2026-11-23', budget: 6, placed: 5 },
					{ userId: 'mom', day: '2026-11-23', budget: 8, placed: 0 },
				],
			}

			it('reads the range and keys the entries by day and the loads by day and member', async () => {
				api.get.mockResolvedValue(answer)

				await expect(store.fetchSuggestion('2026-11-23', '2026-11-24')).resolves.toBe(true)

				expect(api.get).toHaveBeenCalledWith('/api/v1/scheduling/suggestion', { params: { from: '2026-11-23', to: '2026-11-24' } })
				expect(store.suggestion.days).toEqual({ '2026-11-23': answer.days[0].entries, '2026-11-24': [] })
				expect(store.suggestion.overdue).toEqual(answer.overdue)
				expect(store.suggestion.loads['2026-11-23'].kid).toEqual(answer.members[0])
				expect(store.suggestion.loads['2026-11-23'].mom.budget).toBe(8)
			})

			it('drops an answer that a newer read or a cleared range overtook', async () => {
				let resolveFirst
				api.get.mockReturnValueOnce(new Promise((resolve) => { resolveFirst = resolve }))
				api.get.mockResolvedValueOnce({ days: [{ day: '2026-11-30', entries: [] }], overdue: [], members: [] })

				const first = store.fetchSuggestion('2026-11-23', '2026-11-29')
				await store.fetchSuggestion('2026-11-30', '2026-12-06')
				resolveFirst(answer)

				await expect(first).resolves.toBe(false)
				expect(Object.keys(store.suggestion.days)).toEqual(['2026-11-30'])

				api.get.mockReturnValueOnce(new Promise((resolve) => { resolveFirst = resolve }))
				const late = store.fetchSuggestion('2026-11-23', '2026-11-29')
				store.clearSuggestion()
				resolveFirst(answer)
				await expect(late).resolves.toBe(false)
				expect(store.suggestion).toEqual({ days: {}, overdue: [], loads: {} })
			})

			it('propagates a failed read and keeps the last suggestion', async () => {
				api.get.mockResolvedValueOnce(answer)
				await store.fetchSuggestion('2026-11-23', '2026-11-24')
				api.get.mockRejectedValueOnce(new Error('down'))

				await expect(store.fetchSuggestion('2026-11-23', '2026-11-24')).rejects.toThrow('down')
				expect(store.suggestion.overdue).toEqual(answer.overdue)
			})
		})

		describe('reset', () => {
			it('resets state to defaults', () => {
				store.memberAvailability = [{ userId: 'user1' }]
				store.energyBudget.monday = 20
				store.weekdayAvailability.monday = false
				store.preferredWeekdays = ['monday']
				store.suggestion = { days: { '2026-11-23': [] }, overdue: [], loads: {} }

				store.reset()

				expect(store.memberAvailability).toEqual([])
				expect(store.energyBudget.monday).toBe(10)
				expect(store.weekdayAvailability.monday).toBe(true)
				expect(store.preferredWeekdays).toEqual([])
				expect(store.suggestion).toEqual({ days: {}, overdue: [], loads: {} })
			})
		})
	})
})
