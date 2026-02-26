/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'

export const useRoomsStore = defineStore('rooms', {
	state: () => ({
		floors: [],
		rooms: [],
		currentFloor: null,
		currentRoom: null,
		loading: false,
		error: null,
	}),

	getters: {
		roomsByFloor: (state) => {
			const roomsByFloor = {}
			state.floors.forEach(floor => {
				roomsByFloor[floor.id] = state.rooms.filter(room => room.floorId === floor.id)
			})
			return roomsByFloor
		},

		getRoomById: (state) => (roomId) => {
			return state.rooms.find(room => room.id === roomId)
		},

		getFloorById: (state) => (floorId) => {
			return state.floors.find(floor => floor.id === floorId)
		},

		roomsNeedingCleaning: (state) => {
			return state.rooms.filter(room => room.cleanliness < 60 || room.monsterCount > 1)
		},

		totalRooms: (state) => state.rooms.length,

		cleanRooms: (state) => state.rooms.filter(room => room.cleanliness >= 60).length,

		dirtyRooms: (state) => state.rooms.filter(room => room.cleanliness < 60).length,

		averageCleanliness: (state) => {
			if (state.rooms.length === 0) return 100
			const total = state.rooms.reduce((sum, room) => sum + room.cleanliness, 0)
			return Math.round(total / state.rooms.length)
		},

		totalMonsters: (state) => {
			return state.rooms.reduce((sum, room) => sum + room.monsterCount, 0)
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
				const response = await axios.get('/apps/chorestrike/api/v1/floors/with-rooms')

				if (response.data.success) {
					this.floors = []
					this.rooms = []

					response.data.data.forEach(floorData => {
						this.floors.push(floorData.floor)
						this.rooms.push(...floorData.rooms)
					})
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch floors with rooms')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch floors with rooms:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch all floors
		 */
		async fetchFloors() {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get('/apps/chorestrike/api/v1/floors')

				if (response.data.success) {
					this.floors = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch floors')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch floors:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch rooms for a specific floor
		 * @param {number} floorId Floor identifier to load rooms for.
		 */
		async fetchRooms(floorId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/rooms?floorId=${floorId}`)

				if (response.data.success) {
					// Update rooms in state, removing old ones for this floor
					this.rooms = this.rooms.filter(room => room.floorId !== floorId)
					this.rooms.push(...response.data.data)
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch rooms')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch rooms:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get floor with rooms and stats
		 * @param {number} floorId Floor identifier to load with details.
		 */
		async fetchFloorWithRooms(floorId) {
			this.loading = true
			this.error = null

			try {
				const response = await axios.get(`/apps/chorestrike/api/v1/floors/${floorId}`)

				if (response.data.success) {
					const floorData = response.data.data

					// Update floor in state
					const floorIndex = this.floors.findIndex(f => f.id === floorId)
					if (floorIndex >= 0) {
						this.floors[floorIndex] = floorData.floor
					} else {
						this.floors.push(floorData.floor)
					}

					// Update rooms in state
					this.rooms = this.rooms.filter(room => room.floorId !== floorId)
					this.rooms.push(...floorData.rooms)

					return floorData
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch floor with rooms')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch floor with rooms:', error)
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
				const response = await axios.post('/apps/chorestrike/api/v1/floors', { name, sortOrder })

				if (response.data.success) {
					this.floors.push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to create floor')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to create floor:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new room
		 * @param {number} floorId Floor identifier that owns the room.
		 * @param {string} name Display name of the new room.
		 */
		async createRoom(floorId, name) {
			if (!name || name.trim() === '') {
				throw new Error('Room name is required')
			}

			this.loading = true
			this.error = null

			try {
				const response = await axios.post('/apps/chorestrike/api/v1/rooms', { floorId, name })

				if (response.data.success) {
					this.rooms.push(response.data.data)
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to create room')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to create room:', error)
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
				const response = await axios.put(`/apps/chorestrike/api/v1/floors/${floorId}`, { name })

				if (response.data.success) {
					const floorIndex = this.floors.findIndex(f => f.id === floorId)
					if (floorIndex >= 0) {
						this.floors[floorIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update floor')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update floor:', error)
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
				const response = await axios.put(`/apps/chorestrike/api/v1/rooms/${roomId}`, { name })

				if (response.data.success) {
					const roomIndex = this.rooms.findIndex(r => r.id === roomId)
					if (roomIndex >= 0) {
						this.rooms[roomIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update room')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update room:', error)
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
				const response = await axios.put(`/apps/chorestrike/api/v1/rooms/${roomId}/cleanliness`, { cleanlinessChange })

				if (response.data.success) {
					const roomIndex = this.rooms.findIndex(r => r.id === roomId)
					if (roomIndex >= 0) {
						this.rooms[roomIndex] = response.data.data
					}
				} else {
					throw new Error(response.data.error?.message || 'Failed to update room cleanliness')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to update room cleanliness:', error)
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
				const response = await axios.delete(`/apps/chorestrike/api/v1/floors/${floorId}`)

				if (response.data.success) {
					// Remove floor from state
					this.floors = this.floors.filter(f => f.id !== floorId)
					// Remove rooms for this floor
					this.rooms = this.rooms.filter(r => r.floorId !== floorId)
				} else {
					throw new Error(response.data.error?.message || 'Failed to delete floor')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to delete floor:', error)
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
				const response = await axios.delete(`/apps/chorestrike/api/v1/rooms/${roomId}`)

				if (response.data.success) {
					// Remove room from state
					this.rooms = this.rooms.filter(r => r.id !== roomId)
				} else {
					throw new Error(response.data.error?.message || 'Failed to delete room')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to delete room:', error)
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
				const response = await axios.post('/apps/chorestrike/api/v1/floors/reorder', { floorOrder })

				if (response.data.success) {
					this.floors = response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to reorder floors')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to reorder floors:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get rooms needing cleaning
		 * @param {?number} floorId Optional floor identifier for filtering.
		 */
		async fetchRoomsNeedingCleaning(floorId = null) {
			this.loading = true
			this.error = null

			try {
				const url = floorId
					? `/apps/chorestrike/api/v1/rooms/needing-cleaning?floorId=${floorId}`
					: '/apps/chorestrike/api/v1/rooms/needing-cleaning'

				const response = await axios.get(url)

				if (response.data.success) {
					return response.data.data
				} else {
					throw new Error(response.data.error?.message || 'Failed to fetch rooms needing cleaning')
				}
			} catch (error) {
				this.error = error.message
				console.error('Failed to fetch rooms needing cleaning:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Set current floor
		 * @param {number} floorId Floor identifier to set as active.
		 */
		setCurrentFloor(floorId) {
			this.currentFloor = this.getFloorById(floorId)
		},

		/**
		 * Set current room
		 * @param {number} roomId Room identifier to set as active.
		 */
		setCurrentRoom(roomId) {
			this.currentRoom = this.getRoomById(roomId)
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
			this.currentFloor = null
			this.currentRoom = null
			this.loading = false
			this.error = null
		},
	},
})
