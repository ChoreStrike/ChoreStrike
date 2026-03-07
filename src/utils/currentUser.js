import { getCurrentUser } from '@nextcloud/auth'

/**
 * Resolve the current Nextcloud user into the member shape used by the frontend.
 *
 * @returns {{uid: string, userId: string} | null}
 */
export function resolveCurrentUser() {
	const nextcloudUser = getCurrentUser()

	if (!nextcloudUser) {
		return null
	}

	return {
		...nextcloudUser,
		userId: nextcloudUser.uid,
	}
}

export default resolveCurrentUser
