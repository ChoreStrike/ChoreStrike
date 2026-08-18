/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { logger } from '../utils/logger.js'

/**
 * Pinia plugin: fire-and-forget initialization of the user store on first
 * use, so role getters like `isHouseOwner` resolve reactively in any view.
 *
 * Lives in a leaf module (not the store barrel) so importing it from the app
 * bootstrap does not pull all store modules into the eager entry bundle.
 *
 * @param {object} options Plugin options
 * @param {import('pinia').Pinia} options.pinia Pinia instance to hook into
 */
export function piniaUserAutoInitPlugin({ pinia }) {
	pinia.use(({ store }) => {
		if (store.$id !== 'user' || store.currentUser) {
			return
		}

		// One retry protects role-dependent UI (e.g. the owner-only member
		// grid) from a single transient failure of GET /api/v1/users/current;
		// the store's in-flight dedup keeps this to one request per attempt.
		store.initializeCurrentUser()
			.catch(() => store.initializeCurrentUser())
			.catch((error) => {
				logger.error('Failed to auto-initialize user store', error, { module: 'store/userAutoInit', action: 'piniaUserAutoInitPlugin', storeId: store.$id })
			})
	})
}
