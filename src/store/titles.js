/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

export const useTitlesStore = defineStore('titles', {
	state: () => ({
		currentTitle: null,
		unlockedTitles: [],
		selectedTitlePack: null,
		catalog: {},
		loading: false,
		error: null,
	}),

	actions: {
		/**
		 * Fetch the active user's title state + catalog.
		 * @param {number} houseUserId Internal house user id.
		 */
		async fetchTitles(houseUserId) {
			if (!houseUserId) {
				throw new Error('House user id is required')
			}
			this.loading = true
			this.error = null
			try {
				const data = await api.get(`/api/v1/users/${houseUserId}/titles`)
				this.currentTitle = data.currentTitle
				this.unlockedTitles = data.unlockedTitles || []
				this.selectedTitlePack = data.selectedTitlePack
				this.catalog = data.catalog || {}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch titles', error, { store: 'titles', action: 'fetchTitles', houseUserId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Select a title pack for the active user.
		 * @param {number} houseUserId Internal house user id.
		 * @param {string} packKey Pack key to display.
		 */
		async selectPack(houseUserId, packKey) {
			if (!houseUserId) {
				throw new Error('House user id is required')
			}
			this.loading = true
			this.error = null
			try {
				const data = await api.put(
					`/api/v1/users/${houseUserId}/titles/select`,
					{ pack: packKey },
				)
				this.selectedTitlePack = data.selectedTitlePack
				this.currentTitle = data.currentTitle
			} catch (error) {
				this.error = error.message
				logger.error('Failed to select title', error, { store: 'titles', action: 'selectPack', houseUserId, packKey })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset store state.
		 */
		reset() {
			this.currentTitle = null
			this.unlockedTitles = []
			this.selectedTitlePack = null
			this.catalog = {}
			this.loading = false
			this.error = null
		},
	},
})
