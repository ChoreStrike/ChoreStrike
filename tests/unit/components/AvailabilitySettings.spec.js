import { resolveCurrentUser } from '../../../src/utils/currentUser.js'
import { getCurrentUser } from '@nextcloud/auth'

describe('AvailabilitySettings current user resolution', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('maps the Nextcloud uid to the member userId field', () => {
		getCurrentUser.mockReturnValue({ uid: 'alice', displayName: 'Alice' })

		const currentUser = resolveCurrentUser()

		expect(getCurrentUser).toHaveBeenCalledTimes(1)
		expect(currentUser).toEqual({ uid: 'alice', displayName: 'Alice', userId: 'alice' })
	})

	it('returns null when the Nextcloud auth layer has no current user', () => {
		getCurrentUser.mockReturnValue(null)

		expect(resolveCurrentUser()).toBeNull()
	})
})
