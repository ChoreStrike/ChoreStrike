/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { logger } from '../utils/logger.js'

const tr = (message) => t('chorestrike', message)

export const useUserStore = defineStore('user', {
	state: () => ({
		currentUser: null,
		houseUsers: [],
		loading: false,
		error: null,
	}),

	getters: {
		userId: (state) => state.currentUser?.id || null,

		userDisplayName: (state) => state.currentUser?.displayName || tr('Unknown User'),

		isHouseOwner: (state) => state.currentUser?.role === 'owner',

		isHouseMember: (state) => ['owner', 'member'].includes(state.currentUser?.role),

		contributionScore: (state) => state.currentUser?.contributionScore || 0,

		teamSparks: (state) => state.currentUser?.teamSparks || 0,

		currentStreak: (state) => state.currentUser?.currentStreak || 0,

		getUserById: (state) => (userId) => {
			return state.houseUsers.find(user => user.nextcloudUserId === userId)
		},

		getLeaderboard: (state) => {
			return [...state.houseUsers]
				.sort((a, b) => b.contributionScore - a.contributionScore)
		},

		getTopContributors: (state) => {
			return state.houseUsers
				.sort((a, b) => b.contributionScore - a.contributionScore)
				.slice(0, 5)
		},

		activeUsers: (state) => {
			const oneWeekAgo = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000)
			return state.houseUsers.filter(user =>
				new Date(user.lastActivity) > oneWeekAgo,
			)
		},
	},

	actions: {
		/**
		 * Initialize current user from Nextcloud
		 */
		async initializeCurrentUser() {
			try {
				// Get current user from Nextcloud
				const response = await axios.get('/apps/chorestrike/api/v1/users/current')

				if (response.data.success) {
					this.currentUser = response.data.data
					return this.currentUser
				} else {
					throw new Error(response.data.error?.message || tr('Failed to initialize current user'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to initialize current user', error, { store: 'user', action: 'initializeCurrentUser' })
				throw error
			}
		},

		/**
		 * Fetch all house users
		 */
		async fetchHouseUsers() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/users')

				if (response.data.success) {
					this.houseUsers = response.data.data
				} else {
					throw new Error(response.data.error?.message || tr('Failed to fetch house users'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch house users', error, { store: 'user', action: 'fetchHouseUsers' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Invite user to house
		 * @param {string} email Email address of the user to invite.
		 * @param {string} role Role to assign to the invited user.
		 */
		async inviteUser(email, role = 'member') {
			if (!email || email.trim() === '') {
				throw new Error(tr('Email is required'))
			}

			if (!['owner', 'member', 'child'].includes(role)) {
				throw new Error(tr('Invalid role'))
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/apps/chorestrike/api/v1/users/invite', { email, role })

				if (response.data.success) {
					// Add to house users if already exists
					if (response.data.data.exists) {
						this.houseUsers.push(response.data.data.user)
					}
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || tr('Failed to invite user'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to invite user', error, { store: 'user', action: 'inviteUser', role })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Remove user from house
		 * @param {string|number} userId Internal identifier of the user to remove.
		 */
		async removeUser(userId) {
			if (!userId) {
				throw new Error(tr('User ID is required'))
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.delete(`/apps/chorestrike/api/v1/users/${userId}`)

				if (response.data.success) {
					// Remove from house users
					this.houseUsers = this.houseUsers.filter(user => user.id !== userId)
				} else {
					throw new Error(response.data.error?.message || tr('Failed to remove user'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to remove user', error, { store: 'user', action: 'removeUser', userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update user role
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {string} role New role value.
		 */
		async updateUserRole(userId, role) {
			if (!userId) {
				throw new Error(tr('User ID is required'))
			}

			if (!['owner', 'member', 'child'].includes(role)) {
				throw new Error(tr('Invalid role'))
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.put(`/apps/chorestrike/api/v1/users/${userId}/role`, { role })

				if (response.data.success) {
					// Update user in house users
					const userIndex = this.houseUsers.findIndex(user => user.id === userId)
					if (userIndex >= 0) {
						this.houseUsers[userIndex] = response.data.data
					}

					// Update current user if it's the same
					if (this.currentUser && this.currentUser.id === userId) {
						this.currentUser = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || tr('Failed to update user role'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update user role', error, { store: 'user', action: 'updateUserRole', userId, role })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update user profile
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {object} profileData Partial profile data payload.
		 */
		async updateUserProfile(userId, profileData) {
			if (!userId) {
				throw new Error(tr('User ID is required'))
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.put(`/apps/chorestrike/api/v1/users/${userId}`, profileData)

				if (response.data.success) {
					// Update user in house users
					const userIndex = this.houseUsers.findIndex(user => user.id === userId)
					if (userIndex >= 0) {
						this.houseUsers[userIndex] = response.data.data
					}

					// Update current user if it's the same
					if (this.currentUser && this.currentUser.id === userId) {
						this.currentUser = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || tr('Failed to update user profile'))
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update user profile', error, { store: 'user', action: 'updateUserProfile', userId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update user statistics
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {object} stats Statistic values to merge into user state.
		 */
		updateUserStats(userId, stats) {
			const userIndex = this.houseUsers.findIndex(user => user.id === userId)
			if (userIndex >= 0) {
				this.houseUsers[userIndex] = {
					...this.houseUsers[userIndex],
					...stats,
					lastActivity: new Date().toISOString(),
				}
			}

			// Update current user if it's the same
			if (this.currentUser && this.currentUser.id === userId) {
				this.currentUser = {
					...this.currentUser,
					...stats,
					lastActivity: new Date().toISOString(),
				}
			}
		},

		/**
		 * Add contribution score
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {number} score Contribution score to add.
		 */
		addContributionScore(userId, score) {
			this.updateUserStats(userId, {
				contributionScore: (this.getUserById(userId)?.contributionScore || 0) + score,
			})
		},

		/**
		 * Add Team Sparks
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {number} sparks Team Sparks value to add.
		 */
		addTeamSparks(userId, sparks) {
			this.updateUserStats(userId, {
				teamSparks: (this.getUserById(userId)?.teamSparks || 0) + sparks,
			})
		},

		/**
		 * Update streak
		 * @param {string|number} userId Internal identifier of the user to update.
		 * @param {number} streak New streak value.
		 */
		updateStreak(userId, streak) {
			this.updateUserStats(userId, {
				currentStreak: streak,
			})
		},

		/**
		 * Get user statistics
		 * @param {string|number} userId Internal identifier of the user to read.
		 */
		getUserStats(userId) {
			const user = this.getUserById(userId)
			if (!user) return null

			return {
				contributionScore: user.contributionScore || 0,
				teamSparks: user.teamSparks || 0,
				currentStreak: user.currentStreak || 0,
				role: user.role,
				joinedAt: user.joinedAt,
				lastActivity: user.lastActivity,
			}
		},

		/**
		 * Check if user can perform action
		 * @param {string|number} userId Internal identifier of the user to evaluate.
		 * @param {string} action Action key to validate.
		 */
		canPerformAction(userId, action) {
			const user = this.getUserById(userId)
			if (!user) return false

			switch (action) {
			case 'manage_users':
				return user.role === 'owner'
			case 'manage_house':
				return ['owner', 'member'].includes(user.role)
			case 'complete_tasks':
				return true // All users can complete tasks
			default:
				return false
			}
		},

		/**
		 * Initialize user data
		 */
		async initializeUsers() {
			await Promise.all([
				this.initializeCurrentUser(),
				this.fetchHouseUsers(),
			])
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
			this.currentUser = null
			this.houseUsers = []
			this.loading = false
			this.error = null
		},
	},
})
