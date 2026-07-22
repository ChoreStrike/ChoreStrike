/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { logger } from '../utils/logger.js'

export const useTitlesStore = defineStore('titles', {
	state: () => ({
		// Current title descriptor for the active user (server-computed).
		currentTitle: null,
		// Every tier the active user qualifies for (live-derived).
		unlockedTitles: [],
		// Pack key the active user has chosen to display (null = none).
		selectedTitlePack: null,
		// Catalog view: pack key -> {labelKey, signal, tiers:[{key,labelKey,threshold}]}.
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
				const response = await axios.get(`/index.php/apps/chorestrike/api/v1/users/${houseUserId}/titles`)
				if (response.data.success) {
					this.currentTitle = response.data.data.currentTitle
					this.unlockedTitles = response.data.data.unlockedTitles || []
					this.selectedTitlePack = response.data.data.selectedTitlePack
					this.catalog = response.data.data.catalog || {}
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch titles')
				}
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
				const response = await axios.put(
					`/index.php/apps/chorestrike/api/v1/users/${houseUserId}/titles/select`,
					{ pack: packKey },
				)
				if (response.data.success) {
					this.selectedTitlePack = response.data.data.selectedTitlePack
					this.currentTitle = response.data.data.currentTitle
				} else {
					throw new Error(response.data.error?.message || 'Failed to select title')
				}
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
