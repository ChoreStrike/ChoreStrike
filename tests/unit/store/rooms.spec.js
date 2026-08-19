import { describe, it, expect, jest, beforeEach } from '@jest/globals'
import { setActivePinia, createPinia } from 'pinia'
import { useRoomsStore } from '../../../src/store/rooms.js'

jest.mock('../../../src/services/api.js', () => ({
	api: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		delete: jest.fn(),
	},
}))

jest.mock('@/utils/logger', () => ({
	logger: {
		error: jest.fn(),
		warn: jest.fn(),
		info: jest.fn(),
		debug: jest.fn(),
	},
}))

import { api } from '../../../src/services/api.js'

describe('Rooms Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useRoomsStore()
		jest.clearAllMocks()
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.floors).toEqual([])
			expect(store.rooms).toEqual([])
			expect(store.currentFloor).toBeNull()
			expect(store.currentRoom).toBeNull()
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})
	})

	describe('Getters', () => {
		beforeEach(() => {
			store.floors = [
				{ id: 1, name: 'Ground Floor' },
				{ id: 2, name: 'First Floor' },
			]
			store.rooms = [
				{ id: 1, floorId: 1, name: 'Kitchen', cleanliness: 80 },
				{ id: 2, floorId: 1, name: 'Living Room', cleanliness: 90 },
				{ id: 3, floorId: 2, name: 'Bedroom', cleanliness: 50 },
			]
		})

		it('roomsByFloor groups rooms correctly', () => {
			const roomsByFloor = store.roomsByFloor
			expect(roomsByFloor[1]).toHaveLength(2)
			expect(roomsByFloor[2]).toHaveLength(1)
			expect(roomsByFloor[1][0].name).toBe('Kitchen')
		})

		it('getRoomById finds room correctly', () => {
			const room = store.getRoomById(2)
			expect(room.name).toBe('Living Room')
		})

		it('getRoomById returns undefined for non-existent', () => {
			const room = store.getRoomById(999)
			expect(room).toBeUndefined()
		})

		it('getFloorById finds floor correctly', () => {
			const floor = store.getFloorById(1)
			expect(floor.name).toBe('Ground Floor')
		})

		it('roomsNeedingCleaning filters correctly', () => {
			const dirtyRooms = store.roomsNeedingCleaning
			expect(dirtyRooms).toHaveLength(1)
			expect(dirtyRooms[0].name).toBe('Bedroom')
		})

		it('totalRooms returns count', () => {
			expect(store.totalRooms).toBe(3)
		})

		it('cleanRooms returns count of clean rooms', () => {
			expect(store.cleanRooms).toBe(2)
		})

		it('dirtyRooms returns count of dirty rooms', () => {
			expect(store.dirtyRooms).toBe(1)
		})

		it('averageCleanliness calculates correctly', () => {
			expect(store.averageCleanliness).toBe(73)
		})

		it('averageCleanliness returns 100 when no rooms', () => {
			store.rooms = []
			expect(store.averageCleanliness).toBe(100)
		})
	})

	describe('Actions', () => {
		describe('fetchFloorsWithRooms', () => {
			it('fetches floors with rooms successfully', async () => {
				const mockData = [
					{
						floor: { id: 1, name: 'Ground Floor' },
						rooms: [{ id: 1, name: 'Kitchen', floorId: 1 }]
					}
				]
				api.get.mockResolvedValue(mockData)

				await store.fetchFloorsWithRooms()

				expect(store.floors).toHaveLength(1)
				expect(store.rooms).toHaveLength(1)
				expect(store.floors[0].name).toBe('Ground Floor')
			})

			it('handles error response', async () => {
				api.get.mockRejectedValue(new Error('Failed'))

				await expect(store.fetchFloorsWithRooms()).rejects.toThrow('Failed')
			})
		})

		describe('fetchFloors', () => {
			it('fetches floors successfully', async () => {
				const mockFloors = [{ id: 1, name: 'Floor 1' }]
				api.get.mockResolvedValue(mockFloors)

				await store.fetchFloors()

				expect(store.floors).toEqual(mockFloors)
			})
		})

		describe('fetchRooms', () => {
			it('fetches rooms for floor', async () => {
				const mockRooms = [{ id: 1, name: 'Room 1', floorId: 1 }]
				api.get.mockResolvedValue(mockRooms)

				await store.fetchRooms(1)

				expect(store.rooms).toEqual(mockRooms)
			})
		})

		describe('createFloor', () => {
			it('creates floor successfully', async () => {
				const newFloor = { id: 3, name: 'Basement' }
				api.post.mockResolvedValue(newFloor)

				store.floors = [{ id: 1, name: 'Floor 1' }]

				const result = await store.createFloor('Basement')

				expect(result).toEqual(newFloor)
				expect(store.floors).toHaveLength(2)
			})
		})

		describe('createRoom', () => {
			it('creates room successfully', async () => {
				const newRoom = { id: 4, name: 'Bathroom', floorId: 1, icon: 'Bath' }
				api.post.mockResolvedValue(newRoom)

				store.rooms = []

				const result = await store.createRoom(1, 'Bathroom', 'Bath')

				expect(result).toEqual(newRoom)
				expect(store.rooms).toHaveLength(1)
				expect(api.post).toHaveBeenCalledWith('/api/v1/rooms', {
					floorId: 1,
					name: 'Bathroom',
					icon: 'Bath',
				})
			})
		})

		describe('deleteFloor', () => {
			it('deletes floor successfully', async () => {
				api.delete.mockResolvedValue(undefined)

				store.floors = [{ id: 1, name: 'Floor 1' }, { id: 2, name: 'Floor 2' }]

				await store.deleteFloor(1)

				expect(store.floors).toHaveLength(1)
				expect(store.floors[0].id).toBe(2)
			})
		})

		describe('clearError', () => {
			it('clears error', () => {
				store.error = 'Some error'
				store.clearError()
				expect(store.error).toBeNull()
			})
		})

		describe('reset', () => {
			it('resets all state', () => {
				store.floors = [{ id: 1 }]
				store.rooms = [{ id: 1 }]
				store.currentFloor = 1
				store.currentRoom = 1
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.floors).toEqual([])
				expect(store.rooms).toEqual([])
				expect(store.currentFloor).toBeNull()
				expect(store.currentRoom).toBeNull()
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})