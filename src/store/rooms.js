/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import { api } from '../services/api.js'
import { logger } from '../utils/logger.js'

export const useRoomsStore = defineStore('rooms', {
	state: () => ({
		floors: [],
		rooms: [],
		loading: false,
		error: null,
		expandedFloors: [],
	}),

	getters: {
		roomsByFloor: (state) => {
			const roomsByFloor = {}
			// First add all known floors
			state.floors.forEach(floor => {
				roomsByFloor[floor.id] = state.rooms.filter(room => room.floorId === floor.id)
			})
			// Then add rooms for floors not yet in state.floors
			state.rooms.forEach(room => {
				if (!roomsByFloor[room.floorId]) {
					roomsByFloor[room.floorId] = []
				}
				if (!roomsByFloor[room.floorId].includes(room)) {
					roomsByFloor[room.floorId].push(room)
				}
			})
			return roomsByFloor
		},

		getRoomById: (state) => (roomId) => {
			return state.rooms.find(room => room.id === roomId)
		},

		getFloorById: (state) => (floorId) => {
			return state.floors.find(floor => floor.id === floorId)
		},

		isFloorExpanded: (state) => (floorId) => {
			return state.expandedFloors.some(id => String(id) === String(floorId))
		},

		totalRooms: (state) => state.rooms.length,

		cleanRooms: (state) => state.rooms.filter(room => room.cleanliness >= 60).length,

		dirtyRooms: (state) => state.rooms.filter(room => room.cleanliness < 60).length,

		averageCleanliness: (state) => {
			if (state.rooms.length === 0) return 100
			const total = state.rooms.reduce((sum, room) => sum + room.cleanliness, 0)
			return Math.round(total / state.rooms.length)
		},
	},

	actions: {
		/**
		 * Fetch all floors with their rooms
		 */
		async fetchFloorsWithRooms() {
			this.loading = true
			this.error = null

			try {
				const data = await api.get('/api/v1/floors/with-rooms')

				this.floors = []
				this.rooms = []

				data.forEach(floorData => {
					this.floors.push(floorData.floor)
					this.rooms.push(...floorData.rooms)
				})
			} catch (error) {
				this.error = error.message
				logger.error('Failed to fetch floors with rooms', error, { store: 'rooms', action: 'fetchFloorsWithRooms' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new floor
		 * @param {string} name Display name of the new floor.
		 * @param {?number} sortOrder Optional sorting position.
		 */
		async createFloor(name, sortOrder = null) {
			if (!name || name.trim() === '') {
				throw new Error('Floor name is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.post('/api/v1/floors', { name, sortOrder })
				this.floors = [...this.floors, data]
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create floor', error, { store: 'rooms', action: 'createFloor' })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new room
		 * @param {number} floorId Floor identifier that owns the room.
		 * @param {string} name Display name of the new room.
		 * @param {?string} icon Icon registry key for the room.
		 */
		async createRoom(floorId, name, icon = null) {
			if (!name || name.trim() === '') {
				throw new Error('Room name is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.post('/api/v1/rooms', { floorId, name, icon })
				this.rooms = [...this.rooms, data]
				return data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to create room', error, { store: 'rooms', action: 'createRoom', floorId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update floor name
		 * @param {number} floorId Floor identifier to update.
		 * @param {string} name New floor name.
		 */
		async updateFloorName(floorId, name) {
			if (!name || name.trim() === '') {
				throw new Error('Floor name is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/floors/${floorId}`, { name })

				const floorIndex = this.floors.findIndex(f => f.id === floorId)
				if (floorIndex >= 0) {
					this.floors[floorIndex] = data
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update floor', error, { store: 'rooms', action: 'updateFloorName', floorId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update room name
		 * @param {number} roomId Room identifier to update.
		 * @param {string} name New room name.
		 */
		async updateRoomName(roomId, name) {
			if (!name || name.trim() === '') {
				throw new Error('Room name is required')
			}

			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/rooms/${roomId}`, { name })

				const roomIndex = this.rooms.findIndex(r => r.id === roomId)
				if (roomIndex >= 0) {
					this.rooms[roomIndex] = data
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update room', error, { store: 'rooms', action: 'updateRoomName', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update room cleanliness
		 * @param {number} roomId Room identifier to update.
		 * @param {number} cleanlinessChange Delta to apply to cleanliness.
		 */
		async updateRoomCleanliness(roomId, cleanlinessChange) {
			this.loading = true
			this.error = null

			try {
				const data = await api.put(`/api/v1/rooms/${roomId}/cleanliness`, { cleanlinessChange })

				const roomIndex = this.rooms.findIndex(r => r.id === roomId)
				if (roomIndex >= 0) {
					this.rooms[roomIndex] = data
				}
			} catch (error) {
				this.error = error.message
				logger.error('Failed to update room cleanliness', error, { store: 'rooms', action: 'updateRoomCleanliness', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete floor
		 * @param {number} floorId Floor identifier to delete.
		 */
		async deleteFloor(floorId) {
			this.loading = true
			this.error = null

			try {
				await api.delete(`/api/v1/floors/${floorId}`)

				this.floors = this.floors.filter(f => f.id !== floorId)
				this.rooms = this.rooms.filter(r => r.floorId !== floorId)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete floor', error, { store: 'rooms', action: 'deleteFloor', floorId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete room
		 * @param {number} roomId Room identifier to delete.
		 */
		async deleteRoom(roomId) {
			this.loading = true
			this.error = null

			try {
				await api.delete(`/api/v1/rooms/${roomId}`)

				this.rooms = this.rooms.filter(r => r.id !== roomId)
			} catch (error) {
				this.error = error.message
				logger.error('Failed to delete room', error, { store: 'rooms', action: 'deleteRoom', roomId })
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reorder floors
		 * @param {Array<number>} floorOrder Ordered list of floor identifiers.
		 */
		async reorderFloors(floorOrder) {
			this.loading = true
			this.error = null

			try {
				const data = await api.post('/api/v1/floors/reorder', { floorOrder })
				this.floors = data
			} catch (error) {
				this.error = error.message
				logger.error('Failed to reorder floors', error, { store: 'rooms', action: 'reorderFloors' })
				throw error
			} finally {
				this.loading = false
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
			this.floors = []
			this.rooms = []
			this.loading = false
			this.error = null
		},

		/**
		 * Toggle floor expansion state
		 * @param {number} floorId Floor identifier to toggle.
		 */
		toggleFloorExpanded(floorId) {
			const key = String(floorId)
			const index = this.expandedFloors.findIndex(id => String(id) === key)
			if (index > -1) {
				this.expandedFloors.splice(index, 1)
			} else {
				this.expandedFloors.push(key)
			}
		},

		/**
		 * Set floor as expanded
		 * @param {number} floorId Floor identifier to expand.
		 */
		setFloorExpanded(floorId) {
			const key = String(floorId)
			if (!this.expandedFloors.includes(key)) {
				this.expandedFloors.push(key)
			}
		},

		/**
		 * Set floor as collapsed
		 * @param {number} floorId Floor identifier to collapse.
		 */
		setFloorCollapsed(floorId) {
			const key = String(floorId)
			const index = this.expandedFloors.findIndex(id => String(id) === key)
			if (index > -1) {
				this.expandedFloors.splice(index, 1)
			}
		},
	},
})
