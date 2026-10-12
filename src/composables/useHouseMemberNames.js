/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
import { onMounted, ref } from 'vue'

/**
 * Load display names without making task execution depend on the member list.
 * @param {object} userStore The calling view's household user store.
 * @return {{ membersError: object, membersLoading: object, loadMembers: Function }}
 */
export function useHouseMemberNames(userStore) {
	const membersError = ref(false)
	const membersLoading = ref(false)
	const loadMembers = async () => {
		if (membersLoading.value) return
		// Already-loaded members stay cached across view mounts (the same
		// guard TaskList uses) — the retry below only applies to a cold or
		// failed read.
		if ((userStore.houseUsers || []).length > 0) return
		membersLoading.value = true
		membersError.value = false
		try {
			await userStore.fetchHouseUsers()
		} catch {
			// The store logs the failure; views expose a read-only retry.
			membersError.value = true
		} finally {
			membersLoading.value = false
		}
	}
	onMounted(loadMembers)
	return { membersError, membersLoading, loadMembers }
}
