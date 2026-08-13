/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Setup wizard store (CS-PLAN-027): fetches the localized catalog (room types
 * + leveled templates) and applies the first-run wizard payload.
 */
export const useSetupStore = defineStore('setup', {
	state: () => ({
		catalog: null,
		loading: false,
		applying: false,
		error: null,
	}),

	getters: {
		levels: (state) => state.catalog?.levels ?? [],
		roomTypes: (state) => state.catalog?.roomTypes ?? [],
		templates: (state) => state.catalog?.templates ?? [],
		hasCatalog: (state) => state.catalog !== null,
	},

	actions: {
		/**
		 * Fetch the setup catalog (levels, room types, leveled templates).
		 */
		async fetchCatalog() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/setup/catalog')
				this.catalog = data
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch setup catalog', error, { store: 'setup', action: 'fetchCatalog' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply the wizard payload (transactional).
		 * @param {object} payload {intensityLevel, options, floors}
		 * @return {Promise<object>} Created-structure counts
		 */
		async applySetup(payload) {
			this.applying = true
			this.error = null

			try {
				const data = await api.post('/api/v1/setup/apply', payload)
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to apply setup', error, { store: 'setup', action: 'applySetup' })
				throw error
			} finally {
				this.applying = false
			}
		},

		/**
		 * Clear error state
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Reset store state
		 */
		reset() {
			this.catalog = null
			this.loading = false
			this.applying = false
			this.error = null
		},
	},
})
