/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import {
	approvePause,
	createPause,
	fetchPauses,
	recognizeIllness as recognizeIllnessRequest,
	rejectPause,
	updatePause,
} from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Pause store (CS-PLAN-070 WP6 §3.4, FEAT-UX-025 remainder; contract:
 * CS-PLAN-073 WP2 §3.4/§3.5). Reads only the viewer-whitelisted pause
 * list and sends every lifecycle command exactly as the delivered
 * service defines it: one FRESH command UUID per attempt, the displayed
 * `expectedRevision` on decisions/shortening. A 409 (stale revision or
 * business conflict) PRESERVES the caller's draft: the store flags
 * `conflict` and re-reads the authoritative list; the caller retries
 * with fresh inputs. Neutral domain: pending child requests change
 * nothing until an owner decides them.
 */

function DEFAULT_STATE() {
	return {
	/** @type {Array} Viewer-whitelisted pause entries. */
		pauses: [],
		loading: false,
		saving: false,
		error: null,
		/** True after a 409 — the authoritative list was re-read, the caller's draft stays. */
		conflict: false,
		/** Request generation — a superseded read never lands after a reset. */
		requestSeq: 0,
	}
}

/**
 * Generate a client idempotency UUID (one FRESH UUID per command
 * attempt — a 409 retry carries changed input, so the same command key
 * must never be reused). Crypto RNG when available, otherwise an
 * RFC 4122 v4 fallback.
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

export const usePausesStore = defineStore('pauses', {
	state: () => DEFAULT_STATE(),

	getters: {
		/**
		 * Pending personal requests visible to the viewer: the viewer's own
		 * pending request plus — for owners — every pending request.
		 * Pending requests of OTHER members are never whitelisted for
		 * non-owners (server-side 404 class); this getter mirrors that
		 * surface for rendering.
		 *
		 * @param {object} state Pinia state.
		 * @return {Array} Pending personal pause entries.
		 */
		pendingRequests: (state) => state.pauses.filter((pause) => pause.state === 'requested' && pause.scope === 'personal'),

		/**
		 * Acknowledged pauses in every scope (the effective neutral suspensions).
		 *
		 * @param {object} state Pinia state.
		 * @return {Array} Acknowledged pause entries.
		 */
		activePauses: (state) => state.pauses.filter((pause) => pause.state === 'acknowledged'),

		/**
		 * Decided-but-not-yet-expired history the viewer may see (rejected/…).
		 *
		 * @param {object} state Pinia state.
		 * @return {Array} Rejected pause entries.
		 */
		decidedPauses: (state) => state.pauses.filter((pause) => pause.state === 'rejected'),
	},

	actions: {
		/**
		 * Fetch the viewer-whitelisted pause list (generation-guarded).
		 */
		async fetchPauses() {
			const seq = ++this.requestSeq
			this.loading = true
			this.error = null
			try {
				const data = await fetchPauses()
				if (seq !== this.requestSeq) {
					logger.debug('Dropping superseded pause read', { store: 'pauses', action: 'fetchPauses' })
					return this.pauses
				}
				this.pauses = data
				return this.pauses
			} catch (error) {
				if (seq === this.requestSeq) {
					this.error = error.message
				}
				logger.error('Failed to fetch pauses', error, { store: 'pauses', action: 'fetchPauses' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Request a personal pause for the CALLER (child → pending request,
		 * adult → acknowledged directly). The server owns the state
		 * transition; the fresh list is re-read afterwards.
		 * @param {object} fields {startDate, endDate, reasonCode}.
		 * @return {Promise<object>} Identity refs {pauseGroupId, revision, state}.
		 */
		async requestPersonalPause(fields) {
			return this.runCommand(() => createPause({
				...fields,
				scope: 'personal',
				commandId: generateCommandId(),
			}))
		},

		/**
		 * Create the household pause (owner only).
		 * @param {object} fields {startDate, endDate, reasonCode}.
		 * @return {Promise<object>} Identity refs.
		 */
		async createHousePause(fields) {
			return this.runCommand(() => createPause({
				...fields,
				scope: 'house',
				commandId: generateCommandId(),
			}))
		},

		/**
		 * Record the explicit retrospective-illness recognition (owner only).
		 * @param {object} fields {subjectUserId, startDate, endDate}.
		 * @return {Promise<object>} Identity refs.
		 */
		async recognizeIllness(fields) {
			return this.runCommand(() => recognizeIllnessRequest({
				...fields,
				commandId: generateCommandId(),
			}))
		},

		/**
		 * Owner approval of a pending request.
		 * @param {number} pauseGroupId Pause group id.
		 * @param {number} expectedRevision The displayed revision.
		 * @return {Promise<object>} Identity refs.
		 */
		async approvePause(pauseGroupId, expectedRevision) {
			return this.runCommand(() => approvePause(pauseGroupId, {
				commandId: generateCommandId(),
				expectedRevision,
			}))
		},

		/**
		 * Owner rejection of a pending request.
		 * @param {number} pauseGroupId Pause group id.
		 * @param {number} expectedRevision The displayed revision.
		 * @return {Promise<object>} Identity refs.
		 */
		async rejectPause(pauseGroupId, expectedRevision) {
			return this.runCommand(() => rejectPause(pauseGroupId, {
				commandId: generateCommandId(),
				expectedRevision,
			}))
		},

		/**
		 * Shorten/revoke an acknowledged pause (next-local-day boundary
		 * server-side). A child's shortening creates a pending revision.
		 *
		 * @param {number} pauseGroupId Pause group id.
		 * @param {object} fields The shortening fields.
		 * @param {string} fields.endDate The new inclusive end date.
		 * @param {number} fields.expectedRevision The displayed revision.
		 * @return {Promise<object>} Identity refs.
		 */
		async shortenPause(pauseGroupId, { endDate, expectedRevision }) {
			return this.runCommand(() => updatePause(pauseGroupId, {
				commandId: generateCommandId(),
				endDate,
				expectedRevision,
			}))
		},

		/**
		 * Run one pause command: 409 → flag `conflict`, re-read the
		 * authoritative list, and REJECT with the original error so the
		 * caller's draft survives untouched.
		 * @param {function(): Promise<object>} command The API call factory.
		 * @return {Promise<object>} Identity refs.
		 */
		async runCommand(command) {
			this.saving = true
			this.error = null
			this.conflict = false
			try {
				const refs = await command()
				// The projection changed — refresh the viewer-safe list
				// without failing the command when the optional read does.
				this.fetchPauses().catch(() => {})
				return refs
			} catch (error) {
				if (error?.status === 409) {
					this.conflict = true
					this.fetchPauses().catch(() => {})
				} else {
					this.error = error.message
				}
				logger.error('Pause command failed', error, { store: 'pauses', action: 'runCommand' })
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Clear error/conflict state.
		 */
		clearError() {
			this.error = null
			this.conflict = false
		},

		/**
		 * Reset store state (identity/house switch).
		 */
		reset() {
			this.requestSeq++
			Object.assign(this, DEFAULT_STATE())
		},
	},
})
