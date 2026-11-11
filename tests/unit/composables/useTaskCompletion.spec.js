import { useTaskCompletion } from '../../../src/composables/useTaskCompletion.js'
import { showCompletionError } from '../../../src/utils/errorToast.js'
import { logger } from '../../../src/utils/logger.js'

jest.mock('../../../src/utils/errorToast.js', () => ({ showCompletionError: jest.fn() }))
jest.mock('../../../src/utils/logger.js', () => ({ logger: { error: jest.fn() } }))

describe('useTaskCompletion', () => {
	let roomTasksStore
	let userStore
	let onCompleted

	const flow = () => useTaskCompletion({ roomTasksStore, userStore, view: 'TestView', onCompleted })
	const target = (proposal = null, assignees = []) => ({ roomTaskId: 7, proposal, assignees })
	const split = (...userIds) => ({ participants: userIds.map((userId) => ({ userId, sharePct: 100 / userIds.length })) })

	beforeEach(() => {
		jest.clearAllMocks()
		roomTasksStore = { completeRoomTask: jest.fn().mockResolvedValue({}) }
		userStore = {
			currentUser: { nextcloudUserId: 'mom' },
			houseUsers: [
				{ nextcloudUserId: 'mom', displayName: 'Parent' },
				{ nextcloudUserId: 'teen', displayName: 'Teen' },
			],
			initializeCurrentUser: jest.fn().mockResolvedValue(undefined),
			fetchHouseUsers: jest.fn().mockResolvedValue([]),
		}
		onCompleted = jest.fn()
	})

	it('completes work without a proposal directly and without options', async () => {
		const { requestCompletion, participation } = flow()

		await requestCompletion(target())

		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7)
		expect(participation.value).toBeNull()
		expect(onCompleted).toHaveBeenCalledWith(7)
	})

	it('completes a solo agreement naming the actor directly', async () => {
		const { requestCompletion, participation } = flow()

		await requestCompletion(target(split('mom')))

		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7)
		expect(participation.value).toBeNull()
	})

	it.each([
		['several people', () => split('mom', 'teen')],
		['someone else', () => split('teen')],
	])('opens the dialog for work proposed for %s', async (label, proposal) => {
		const { requestCompletion, participation } = flow()
		const shown = target(proposal())

		await requestCompletion(shown)

		expect(participation.value).toEqual(shown)
		expect(roomTasksStore.completeRoomTask).not.toHaveBeenCalled()
		expect(onCompleted).not.toHaveBeenCalled()
	})

	it('opens the dialog while the identity is unresolved, after trying to resolve it', async () => {
		userStore.currentUser = null
		userStore.houseUsers = []
		const { requestCompletion, participation, actorUserId } = flow()

		await requestCompletion(target(split('mom')))

		expect(userStore.initializeCurrentUser).toHaveBeenCalledTimes(1)
		expect(userStore.fetchHouseUsers).toHaveBeenCalledTimes(1)
		expect(actorUserId.value).toBeNull()
		expect(participation.value).not.toBeNull()
		expect(roomTasksStore.completeRoomTask).not.toHaveBeenCalled()
	})

	it('opens the dialog even when the identity and member reads fail', async () => {
		userStore.currentUser = null
		userStore.houseUsers = []
		userStore.initializeCurrentUser.mockRejectedValue(new Error('offline'))
		userStore.fetchHouseUsers.mockRejectedValue(new Error('offline'))
		const { requestCompletion, participation } = flow()

		await requestCompletion(target(split('mom', 'teen')))

		expect(participation.value).not.toBeNull()
	})

	it('completes a confirmed claim with its reviewed split, never through the dialog', async () => {
		const claim = { participants: [{ userId: 'mom', sharePct: 100 }], allocationReviewed: true }
		const { requestCompletion, participation } = flow()

		await requestCompletion(target(split('teen')), claim)

		expect(participation.value).toBeNull()
		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7, claim)
	})

	it('offers the assignees as candidates, with the user id where a name is missing', async () => {
		const { requestCompletion, participationMembers } = flow()
		expect(participationMembers.value).toEqual([])

		await requestCompletion(target(split('mom', 'guest'), ['mom', 'guest']))

		expect(participationMembers.value).toEqual([
			{ userId: 'mom', displayName: 'Parent' },
			{ userId: 'guest', displayName: 'guest' },
		])
	})

	it('offers the whole household for unassigned work', async () => {
		const { requestCompletion, participationMembers } = flow()

		await requestCompletion(target(split('mom', 'teen')))

		expect(participationMembers.value.map((member) => member.userId)).toEqual(['mom', 'teen'])
	})

	it('completes with the confirmed split and closes the dialog first', async () => {
		const payload = { ...split('mom', 'teen'), allocationReviewed: true }
		const { requestCompletion, confirmParticipation, participation } = flow()
		await requestCompletion(target(split('teen')))

		await confirmParticipation(payload)

		expect(participation.value).toBeNull()
		expect(roomTasksStore.completeRoomTask).toHaveBeenCalledWith(7, payload)
		expect(onCompleted).toHaveBeenCalledWith(7)
	})

	it('completes nothing when the dialog is closed or confirmed without a target', async () => {
		const { requestCompletion, confirmParticipation, closeParticipation, participation } = flow()
		await requestCompletion(target(split('teen')))

		closeParticipation()
		await confirmParticipation(split('mom'))

		expect(participation.value).toBeNull()
		expect(roomTasksStore.completeRoomTask).not.toHaveBeenCalled()
	})

	it('shows a failure, logs it with the view and reports no completion', async () => {
		const failure = Object.assign(new Error('Conflict'), { status: 409, code: 'CONFLICT' })
		roomTasksStore.completeRoomTask.mockRejectedValue(failure)
		const { requestCompletion } = flow()

		await expect(requestCompletion(target())).resolves.toBeUndefined()

		expect(showCompletionError).toHaveBeenCalledWith(failure)
		expect(logger.error).toHaveBeenCalledWith('Failed to complete task', failure, { view: 'TestView', action: 'completeTask', roomTaskId: 7 })
		expect(onCompleted).not.toHaveBeenCalled()
	})

	it('works without a completion callback', async () => {
		const { requestCompletion } = useTaskCompletion({ roomTasksStore, userStore, view: 'TestView' })

		await expect(requestCompletion(target())).resolves.toBeUndefined()
	})
})
