/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { fetchMyPreferences, updateMyPreferences } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Self-only personal preferences store (CS-PLAN-070 WP5, C5h,
 * Q-076-01 Option A).
 *
 * Reads and writes ONLY /preferences/me — the server is the sole
 * authority and there is no foreign-preferences surface. Updates are
 * version-aware: the server answers 409 on a stale `expectedVersion`,
 * and this store PRESERVES the caller's draft in that case (the local
 * edits are never silently overwritten) while refreshing the authoritative
 * server state for reconciliation. Identity/house switches reset the
 * store like every other store.
 */

const DEFAULT_STATE = () => ({
	version: 0,
	taskDigestEnabled: false,
	orgDigestEnabled: false,
	digestTime: '18:00',
	quietStart: null,
	quietEnd: null,
	soundEnabled: false,
	animationEnabled: false,
	arcadeVisible: true,
	catalogVisible: true,
	ranking: { state: 'none', generation: null, effectiveMonday: null },
	loading: false,
	saving: false,
	error: null,
	conflict: false,
})

/**
 * Generate a client idempotency UUID (one FRESH UUID per save attempt —
 * a 409 retry carries changed input, so the same command key must never
 * be reused). Crypto RNG when available, otherwise an RFC 4122 v4
 * fallback.
 * @return {string} Canonical lowercase UUID.
 */
function generateCommandId() {
	const webCrypto = globalThis.crypto
	if (webCrypto && typeof webCrypto.randomUUID === 'function') {
		return webCrypto.randomUUID()
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = Math.random() * 16 | 0
		const value = char === 'x' ? random : (random & 0x3 | 0x8)
		return value.toString(16)
	})
}

export const usePreferencesStore = defineStore('preferences', {
	state: () => DEFAULT_STATE(),

	actions: {
		/**
		 * Fetch the caller's own preferences (never creates a row — the
		 * neutral defaults arrive at version 0).
		 */
		async fetchPreferences() {
			this.loading = true
			this.error = null
			try {
				const data = await fetchMyPreferences()
				this.applyServer(data)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch preferences', error, { store: 'preferences', action: 'fetchPreferences' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the given fields as one version-aware update (a fresh
		 * command identity per attempt). On 409 the draft is PRESERVED
		 * (the caller's edits stay) and `conflict` is set after refreshing
		 * the authoritative server state; a retried save then carries the
		 * fresh version.
		 *
		 * @param {object} fields Whitelisted delivery fields to save.
		 * @return {Promise<boolean>} True when saved, false on 409 conflict.
		 */
		async savePreferences(fields) {
			this.saving = true
			this.error = null
			this.conflict = false
			try {
				const data = await updateMyPreferences({
					...fields,
					expectedVersion: this.version,
					commandId: generateCommandId(),
				})
				this.applyServer(data)
				return true
			} catch (error) {
				if (error?.status === 409) {
					// Draft preservation: keep the local edits, refresh the
					// authoritative version for the retry.
					this.conflict = true
					try {
						const fresh = await fetchMyPreferences()
						this.applyServer(fresh)
					} catch (refreshError) {
						logger.error('Failed to refresh preferences after conflict', refreshError, { store: 'preferences', action: 'savePreferences' })
					}
					return false
				}
				this.error = error.message
				logger.error('Failed to save preferences', error, { store: 'preferences', action: 'savePreferences' })
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Apply an authoritative server payload (shared by fetch and the
		 * save re-read).
		 * @param {object} data Server payload.
		 */
		applyServer(data) {
			this.version = data.version
			this.taskDigestEnabled = data.taskDigestEnabled
			this.orgDigestEnabled = data.orgDigestEnabled
			this.digestTime = data.digestTime
			this.quietStart = data.quietStart
			this.quietEnd = data.quietEnd
			this.soundEnabled = data.soundEnabled
			this.animationEnabled = data.animationEnabled
			this.arcadeVisible = data.arcadeVisible
			this.catalogVisible = data.catalogVisible
			this.ranking = data.ranking || { state: 'none', generation: null, effectiveMonday: null }
		},

		/**
		 * Clear error state.
		 */
		clearError() {
			this.error = null
			this.conflict = false
		},

		/**
		 * Reset store state (identity/house switch).
		 */
		reset() {
			Object.assign(this, DEFAULT_STATE())
		},
	},
})
