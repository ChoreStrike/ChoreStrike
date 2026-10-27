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
		// CS-PLAN-066 §3.3: mutations invalidate structure reads at dispatch
		// and reconciliation, including reads started during the write.
		// reset() discards in-flight read results.
		requestSeq: 0,
		resetSeq: 0,
		pendingRequests: 0,
		structureRequest: null,
		structureRequestSeq: 0,
	}),

	getters: {
		roomsByFloor: (state) => {
			const roomsByFloor = {}
			// First add all known floors
			state.floors.forEach((floor) => {
				roomsByFloor[floor.id] = state.rooms.filter((room) => room.floorId === floor.id)
			})
			// Then add rooms for floors not yet in state.floors
			state.rooms.forEach((room) => {
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
			return state.rooms.find((room) => room.id === roomId)
		},

		getFloorById: (state) => (floorId) => {
			return state.floors.find((floor) => floor.id === floorId)
		},

		isFloorExpanded: (state) => (floorId) => {
			return state.expandedFloors.some((id) => String(id) === String(floorId))
		},

		totalRooms: (state) => state.rooms.length,

		cleanRooms: (state) => state.rooms.filter((room) => room.cleanliness >= 60).length,

		dirtyRooms: (state) => state.rooms.filter((room) => room.cleanliness < 60).length,

		averageCleanliness: (state) => {
			if (state.rooms.length === 0) {
				return 100
			}
			const total = state.rooms.reduce((sum, room) => sum + room.cleanliness, 0)
			return Math.round(total / state.rooms.length)
		},
	},

	actions: {
		/** Track current-generation operations without letting old callbacks clear loading. */
		_beginRequest() {
			this.pendingRequests++
			this.loading = true
			this.error = null
			return this.resetSeq
		},

		_endRequest(resetSeq) {
			if (resetSeq !== this.resetSeq) {
				return
			}
			this.pendingRequests--
			this.loading = this.pendingRequests > 0
		},

		/**
		 * Fetch all floors with their rooms
		 */
		async fetchFloorsWithRooms() {
			const seq = ++this.requestSeq
			const resetSeq = this._beginRequest()
			this.structureRequestSeq = seq
			const request = (async () => {
				try {
					const data = await api.get('/api/v1/floors/with-rooms')
					if (resetSeq !== this.resetSeq) {
						return null
					}
					if (seq !== this.requestSeq) {
						// Keep hydration callers attached to a complete authoritative
						// read, even when a write invalidated their initial snapshot.
						return this.structureRequestSeq === this.requestSeq
							? this.structureRequest
							: this.fetchFloorsWithRooms()
					}
					this.floors = data.map((item) => item.floor)
					this.rooms = data.flatMap((item) => item.rooms)
					return data
				} catch (error) {
					if (resetSeq === this.resetSeq && seq === this.requestSeq) {
						this.error = error.message
					}
					logger.error('Failed to fetch floors with rooms', error, { store: 'rooms', action: 'fetchFloorsWithRooms' })
					throw error
				} finally {
					this._endRequest(resetSeq)
				}
			})()
			// Retain the latest settled promise too: older callers must share
			// its result/error, not retry a failed read or return a partial cache.
			this.structureRequest = request
			return request
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

			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.post('/api/v1/floors', { name, sortOrder })
				if (resetSeq !== this.resetSeq) {
					return null
				}
				// Also invalidate reads that started while the write was pending.
				this.requestSeq++
				this.floors = [...this.floors.filter((floor) => floor.id !== data.id), data]
				return data
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to create floor', error, { store: 'rooms', action: 'createFloor' })
				throw error
			} finally {
				this._endRequest(resetSeq)
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

			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.post('/api/v1/rooms', { floorId, name, icon })
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++
				this.rooms = [...this.rooms.filter((room) => room.id !== data.id), data]
				return data
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to create room', error, { store: 'rooms', action: 'createRoom', floorId })
				throw error
			} finally {
				this._endRequest(resetSeq)
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

			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.put(`/api/v1/floors/${floorId}`, { name })
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++

				const floorIndex = this.floors.findIndex((f) => f.id === floorId)
				if (floorIndex >= 0) {
					this.floors[floorIndex] = data
				}
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to update floor', error, { store: 'rooms', action: 'updateFloorName', floorId })
				throw error
			} finally {
				this._endRequest(resetSeq)
			}
		},

		/**
		 * Update room name
		 * @param {number} roomId Room identifier to update.
		 * @param {string} name New room name.
		 * @return {Promise<?object>} The renamed room with its final (unique) name.
		 */
		async updateRoomName(roomId, name) {
			if (!name || name.trim() === '') {
				throw new Error('Room name is required')
			}

			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.put(`/api/v1/rooms/${roomId}`, { name })
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++

				const roomIndex = this.rooms.findIndex((r) => r.id === roomId)
				if (roomIndex >= 0) {
					this.rooms[roomIndex] = data
				}
				return data
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to update room', error, { store: 'rooms', action: 'updateRoomName', roomId })
				throw error
			} finally {
				this._endRequest(resetSeq)
			}
		},

		/**
		 * Update room cleanliness (WP3 §3.5: the REQUESTED delta is
		 * journaled server-side under a receipt-protected command
		 * identity — a replayed command never repeats a delta over later
		 * work).
		 * @param {number} roomId Room identifier to update.
		 * @param {number} cleanlinessChange Requested delta to apply to cleanliness.
		 */
		async updateRoomCleanliness(roomId, cleanlinessChange) {
			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.put(`/api/v1/rooms/${roomId}/cleanliness`, {
					cleanlinessChange,
					commandId: crypto.randomUUID(),
				})
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++

				const roomIndex = this.rooms.findIndex((r) => r.id === roomId)
				if (roomIndex >= 0) {
					this.rooms[roomIndex] = data
				}
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to update room cleanliness', error, { store: 'rooms', action: 'updateRoomCleanliness', roomId })
				throw error
			} finally {
				this._endRequest(resetSeq)
			}
		},

		/**
		 * Reconcile a cached room from an authoritative completion/undo
		 * payload (CS-PLAN-064 §3.5, decision D10): the aggregate response
		 * carries the LOCKED, post-mutation room row — cleanliness and
		 * lastCleaned included — so the cached entry is replaced as-is.
		 * Unknown rooms stay absent; their views own a separate source.
		 * @param {object} room Serialized room from `{roomTask, room, task, rewards}`.
		 */
		reconcileRoom(room) {
			if (!room?.id) {
				return
			}
			this.requestSeq++

			const index = this.rooms.findIndex((r) => r.id === room.id)
			if (index >= 0) {
				this.rooms[index] = room
			}
		},

		/**
		 * Delete floor
		 * @param {number} floorId Floor identifier to delete.
		 */
		async deleteFloor(floorId) {
			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				await api.delete(`/api/v1/floors/${floorId}`)
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++

				this.floors = this.floors.filter((f) => f.id !== floorId)
				this.rooms = this.rooms.filter((r) => r.floorId !== floorId)
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to delete floor', error, { store: 'rooms', action: 'deleteFloor', floorId })
				throw error
			} finally {
				this._endRequest(resetSeq)
			}
		},

		/**
		 * Delete room
		 * @param {number} roomId Room identifier to delete.
		 */
		async deleteRoom(roomId) {
			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				await api.delete(`/api/v1/rooms/${roomId}`)
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++

				this.rooms = this.rooms.filter((r) => r.id !== roomId)
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to delete room', error, { store: 'rooms', action: 'deleteRoom', roomId })
				throw error
			} finally {
				this._endRequest(resetSeq)
			}
		},

		/**
		 * Reorder floors
		 * @param {Array<number>} floorOrder Ordered list of floor identifiers.
		 */
		async reorderFloors(floorOrder) {
			const resetSeq = this._beginRequest()

			try {
				this.requestSeq++
				const data = await api.post('/api/v1/floors/reorder', { floorOrder })
				if (resetSeq !== this.resetSeq) {
					return null
				}
				this.requestSeq++
				this.floors = data
			} catch (error) {
				if (resetSeq === this.resetSeq) {
					this.error = error.message
				}
				logger.error('Failed to reorder floors', error, { store: 'rooms', action: 'reorderFloors' })
				throw error
			} finally {
				this._endRequest(resetSeq)
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
			this.requestSeq++
			this.resetSeq++
			this.pendingRequests = 0
			this.structureRequest = null
			this.structureRequestSeq = 0
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
			const index = this.expandedFloors.findIndex((id) => String(id) === key)
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
			const index = this.expandedFloors.findIndex((id) => String(id) === key)
			if (index > -1) {
				this.expandedFloors.splice(index, 1)
			}
		},
	},
})
