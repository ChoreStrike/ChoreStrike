/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { beforeEach, describe, expect, it, jest } from '@jest/globals'
import { useTaskCompletion } from '../../../src/composables/useTaskCompletion.js'
import { showCompletionError } from '../../../src/utils/errorToast.js'
import { logger } from '../../../src/utils/logger.js'

jest.mock('../../../src/utils/errorToast.js', () => ({ showCompletionError: jest.fn() }))
jest.mock('../../../src/utils/logger.js', () => ({ logger: { error: jest.fn(), warn: jest.fn(), info: jest.fn(), debug: jest.fn() } }))

/**
 * The completion every task surface shares (CS-PLAN-091 WP-B2): a hold
 * completes with the server's default split, the picker's or a claim's
 * split rides along, and nobody is asked to take part first.
 */
describe('useTaskCompletion', () => {
	let roomTasksStore
	let onCompleted

	const setup = () => useTaskCompletion({ roomTasksStore, view: 'TestView', onCompleted })
	const split = { participants: [{ userId: 'dad', sharePct: 100 }], allocationReviewed: true }

	beforeEach(() => {
		jest.clearAllMocks()
		roomTasksStore = { completeRoomTask: jest.fn().mockResolvedValue({}) }
		onCompleted = jest.fn()
	})

	it('completes a hold without options: the server credits the default split', async () => {
		await setup().requestCompletion(7)

		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledTimes(1)
		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7)
		expect(onCompleted).toHaveBeenCalledWith(7)
	})

	it('completes someone else\'s task the same way: no dialog asks who took part', async () => {
		// The row belongs to other people; recording it needs no participation.
		const completion = setup()
		await completion.requestCompletion(7)

		expect(Object.keys(completion)).toEqual(['requestCompletion'])
		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7)
	})

	it('completes with the split of the picker or of a confirmed claim', async () => {
		await setup().requestCompletion(7, split)

		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7, split)
		expect(onCompleted).toHaveBeenCalledWith(7)
	})

	it('works without a completion callback', async () => {
		onCompleted = undefined

		await expect(setup().requestCompletion(7)).resolves.toBeUndefined()
	})

	it('shows a failure, logs it with the view and reports no completion', async () => {
		const failure = Object.assign(new Error('Conflict'), { status: 409, code: 'SAME_DAY' })
		roomTasksStore.completeRoomTask.mockRejectedValue(failure)

		await setup().requestCompletion(7)

		expect(showCompletionError).toHaveBeenCalledWith(failure)
		expect(logger.error).toHaveBeenCalledWith('Failed to complete task', failure, { view: 'TestView', action: 'completeTask', roomTaskId: 7 })
		expect(onCompleted).not.toHaveBeenCalled()
	})
})
