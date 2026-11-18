/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { fetchMyPreferences, updateMyColorScheme, updateMyPreferences } from '../services/api.js'
import { initialColorScheme, normalizeColorScheme } from '../utils/colorSchemes.js'
import { generateCommandId } from '../utils/commandId.js'
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
 *
 * The colour scheme (CS-PLAN-090 WP-K) is no field of the versioned row:
 * it starts from the page's initial state, is saved on its own route and
 * arrives with every read. A read that started before a scheme save never
 * overwrites that save's choice (client-state contract).
 */

function DEFAULT_STATE() {
	return {
		version: 0,
		taskDigestEnabled: false,
		orgDigestEnabled: false,
		digestTime: '18:00',
		quietStart: null,
		quietEnd: null,
		soundEnabled: false,
		// On by default (CS-PLAN-090 D-090-05), like the server's default
		// for a member without a row; sound stays an opt-in.
		animationEnabled: true,
		arcadeVisible: true,
		catalogVisible: true,
		ranking: { state: 'none', generation: null, effectiveMonday: null },
		colorScheme: initialColorScheme(),
		// Counts scheme saves, so an overtaken read or save is dropped.
		colorSchemeRevision: 0,
		savingColorScheme: false,
		/**
		 * True only after an authoritative read/write landed (CS-PLAN-070 WP8
		 * §3.1): the initial neutral defaults are NOT a completed preference
		 * read — optional-area consumers must treat unknown/loading/failed
		 * preferences as fail-closed instead of trusting the initial `true`.
		 */
		loaded: false,
		loading: false,
		saving: false,
		error: null,
		conflict: false,
	}
}

export const usePreferencesStore = defineStore('preferences', {
	state: () => DEFAULT_STATE(),

	actions: {
		/**
		 * Fetch the caller's own preferences (never creates a row — the
		 * defaults arrive at version 0).
		 */
		async fetchPreferences() {
			this.loading = true
			this.error = null
			const schemeRevision = this.colorSchemeRevision
			try {
				const data = await fetchMyPreferences()
				this.applyServer(data, schemeRevision)
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
					const schemeRevision = this.colorSchemeRevision
					try {
						const fresh = await fetchMyPreferences()
						this.applyServer(fresh, schemeRevision)
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
		 * Save the viewer's own colour scheme. The app recolours at once;
		 * a failed save restores the previous scheme and rethrows, so the
		 * form can report it. Of two quick saves only the later one counts.
		 *
		 * @param {string} scheme A listed scheme key.
		 * @return {Promise<void>}
		 */
		async saveColorScheme(scheme) {
			const previous = this.colorScheme
			const revision = ++this.colorSchemeRevision
			this.colorScheme = normalizeColorScheme(scheme)
			this.savingColorScheme = true
			try {
				const data = await updateMyColorScheme(this.colorScheme)
				if (revision === this.colorSchemeRevision) {
					this.colorScheme = normalizeColorScheme(data?.colorScheme)
				}
			} catch (error) {
				if (revision === this.colorSchemeRevision) {
					this.colorScheme = previous
				}
				logger.error('Failed to save the colour scheme', error, { store: 'preferences', action: 'saveColorScheme' })
				throw error
			} finally {
				if (revision === this.colorSchemeRevision) {
					this.savingColorScheme = false
				}
			}
		},

		/**
		 * Apply an authoritative server payload (shared by fetch and the
		 * save re-read). The colour scheme is taken only from a payload that
		 * carries it and only when no scheme save started after the read.
		 * @param {object} data Server payload.
		 * @param {number} [schemeRevision] Scheme revision when the read started.
		 */
		applyServer(data, schemeRevision = this.colorSchemeRevision) {
			if (data.colorScheme !== undefined && schemeRevision === this.colorSchemeRevision) {
				this.colorScheme = normalizeColorScheme(data.colorScheme)
			}
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
			this.loaded = true
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
