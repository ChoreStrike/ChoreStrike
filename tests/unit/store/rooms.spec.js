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
import { flushPromises } from '@vue/test-utils'

describe('Rooms Store', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useRoomsStore()
		jest.resetAllMocks()
		let uuidSequence = 0
		Object.defineProperty(globalThis.crypto, 'randomUUID', {
			configurable: true,
			value: jest.fn(() => `12345678-1234-4234-8234-${String(++uuidSequence).padStart(12, '0')}`),
		})
	})

	describe('State', () => {
		it('has correct initial state', () => {
			expect(store.floors).toEqual([])
			expect(store.rooms).toEqual([])
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
		describe('hydration and reset ownership', () => {
			it.each(['cold', 'partial'])('keeps a %s-cache caller waiting for a fresh full snapshot after reconciliation', async cache => {
				if (cache === 'partial') store.rooms = [{ id: 1, floorId: 1, cleanliness: 20 }]
				let resolveOld, resolveFresh
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveFresh = resolve }))
				let settled = false
				const pending = store.fetchFloorsWithRooms().then(data => { settled = true; return data })
				store.reconcileRoom({ id: 1, floorId: 1, cleanliness: 100 })
				resolveOld([{ floor: { id: 1 }, rooms: [{ id: 1, floorId: 1, cleanliness: 20 }] }])
				await flushPromises()
				expect(settled).toBe(false)
				expect(store.loading).toBe(true)
				expect(api.get).toHaveBeenCalledTimes(2)
				const fresh = [{ floor: { id: 1, name: 'Home' }, rooms: [{ id: 1, floorId: 1, cleanliness: 100 }] }]
				resolveFresh(fresh)
				expect(await pending).toEqual(fresh)
				expect(store.floors).toEqual([fresh[0].floor])
				expect(store.rooms).toEqual(fresh[0].rooms)
				expect(store.loading).toBe(false)
			})

			it('propagates a failed replacement read without retrying the error or completing empty hydration', async () => {
				let resolveOld
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockRejectedValueOnce(new Error('Refresh failed'))
				const pending = store.fetchFloorsWithRooms()
				const rejected = expect(pending).rejects.toThrow('Refresh failed')
				store.reconcileRoom({ id: 1 })
				resolveOld([])
				await rejected
				expect(api.get).toHaveBeenCalledTimes(2)
				expect(store.error).toBe('Refresh failed')
				expect(store.loading).toBe(false)
			})

			it('joins a newer completed read rather than making another request', async () => {
				let resolveOld
				const fresh = [{ floor: { id: 1 }, rooms: [] }]
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockResolvedValueOnce(fresh)
				const old = store.fetchFloorsWithRooms()
				await store.fetchFloorsWithRooms()
				resolveOld([])
				expect(await old).toEqual(fresh)
				expect(api.get).toHaveBeenCalledTimes(2)
			})

			it('does not retry or clear an error from a newer failed read', async () => {
				let resolveOld
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockRejectedValueOnce(new Error('Latest failed'))
				const old = store.fetchFloorsWithRooms()
				await expect(store.fetchFloorsWithRooms()).rejects.toThrow('Latest failed')
				const rejected = expect(old).rejects.toThrow('Latest failed')
				resolveOld([])
				await rejected
				expect(api.get).toHaveBeenCalledTimes(2)
				expect(store.error).toBe('Latest failed')
			})

			it.each([
				['createFloor', ['Old'], 'post'],
				['createRoom', [1, 'Old'], 'post'],
				['updateFloorName', [1, 'Old'], 'put'],
				['updateRoomName', [1, 'Old'], 'put'],
				['updateRoomCleanliness', [1, 10], 'put'],
				['deleteFloor', [1], 'delete'],
				['deleteRoom', [1], 'delete'],
				['reorderFloors', [[1]], 'post'],
			])('%s cannot invalidate or modify the post-reset read', async (action, args, method) => {
				let resolveWrite, resolveRead
				api[method].mockImplementationOnce(() => new Promise(resolve => { resolveWrite = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveRead = resolve }))
				const old = store[action](...args)
				store.reset()
				const current = store.fetchFloorsWithRooms()
				const seq = store.requestSeq
				resolveWrite(action === 'reorderFloors' ? [{ id: 1, name: 'Old' }] : { id: 1, name: 'Old' })
				await old
				expect(store.floors).toEqual([])
				expect(store.rooms).toEqual([])
				expect(store.requestSeq).toBe(seq)
				expect(store.loading).toBe(true)
				const fresh = [{ floor: { id: 2, name: 'Current' }, rooms: [] }]
				resolveRead(fresh)
				await current
				expect(store.floors).toEqual([fresh[0].floor])
				expect(store.loading).toBe(false)
			})

			it('does not expose old write failures or clear new loading after reset', async () => {
				let rejectWrite, resolveRead
				api.post.mockImplementationOnce(() => new Promise((resolve, reject) => { rejectWrite = reject }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveRead = resolve }))
				const old = store.createFloor('Old')
				store.reset()
				const current = store.fetchFloorsWithRooms()
				const rejected = expect(old).rejects.toThrow('Old failure')
				rejectWrite(new Error('Old failure'))
				await rejected
				expect(store.error).toBeNull()
				expect(store.loading).toBe(true)
				resolveRead([])
				await current
			})

			it('does not refresh or clear new loading when a pre-reset read finishes', async () => {
				let resolveOld, resolveNew
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveNew = resolve }))
				const old = store.fetchFloorsWithRooms()
				store.reset()
				const current = store.fetchFloorsWithRooms()
				resolveOld([{ floor: { id: 1 }, rooms: [] }])
				await old
				expect(store.floors).toEqual([])
				expect(store.loading).toBe(true)
				expect(api.get).toHaveBeenCalledTimes(2)
				resolveNew([{ floor: { id: 2 }, rooms: [] }])
				await current
				expect(store.floors[0].id).toBe(2)
			})
		})

		describe('accepted mutation invalidation', () => {
			it.each([
				['createFloor', ['New'], 'floors'],
				['createRoom', [1, 'New'], 'rooms'],
			])('%s does not duplicate a created row already returned by a concurrent read', async (action, args, collection) => {
				const created = { id: 2, floorId: 1, name: 'New' }
				let resolveMutation, resolveRead
				api.post.mockImplementationOnce(() => new Promise(resolve => { resolveMutation = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveRead = resolve }))
				const mutation = store[action](...args)
				const read = store.fetchFloorsWithRooms()
				resolveRead([{ floor: collection === 'floors' ? created : { id: 1 }, rooms: collection === 'rooms' ? [created] : [] }])
				await read
				resolveMutation(created)
				await mutation
				expect(store[collection].filter(row => row.id === created.id)).toHaveLength(1)
			})

			it.each([
				['createFloor', ['New floor'], 'post', { id: 3, name: 'New floor' }],
				['createRoom', [1, 'New room'], 'post', { id: 2, floorId: 1, name: 'New room' }],
				['updateFloorName', [1, 'Renamed'], 'put', { id: 1, name: 'Renamed' }],
				['updateRoomName', [1, 'Renamed'], 'put', { id: 1, floorId: 1, name: 'Renamed' }],
				['updateRoomCleanliness', [1, 10], 'put', { id: 1, floorId: 1, name: 'Room', cleanliness: 90 }],
				['deleteFloor', [1], 'delete', undefined],
				['deleteRoom', [1], 'delete', undefined],
				['reorderFloors', [[2, 1]], 'post', [{ id: 2, name: 'Upper' }, { id: 1, name: 'Ground' }]],
			])('%s keeps its authoritative result when an overlapping read started after the write request', async (action, args, method, response) => {
				store.floors = [{ id: 1, name: 'Ground' }, { id: 2, name: 'Upper' }]
				store.rooms = [{ id: 1, floorId: 1, name: 'Room', cleanliness: 80 }]
				const stale = store.floors.map(floor => ({ floor: { ...floor }, rooms: store.rooms.filter(room => room.floorId === floor.id).map(room => ({ ...room })) }))
				let resolveMutation, resolveRead
				api[method].mockImplementationOnce(() => new Promise(resolve => { resolveMutation = resolve }))
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveRead = resolve }))
				const mutation = store[action](...args)
				const read = store.fetchFloorsWithRooms()
				resolveMutation(response)
				await mutation
				const accepted = JSON.parse(JSON.stringify({ floors: store.floors, rooms: store.rooms }))
				api.get.mockResolvedValueOnce(accepted.floors.map(floor => ({ floor, rooms: accepted.rooms.filter(room => room.floorId === floor.id) })))
				resolveRead(stale)
				await read
				expect({ floors: store.floors, rooms: store.rooms }).toEqual(accepted)
				expect(api.get).toHaveBeenCalledTimes(2)
			})

			it('does not overwrite completion-owned room state with an older read', async () => {
				store.rooms = [{ id: 1, floorId: 1, cleanliness: 20 }]
				let resolveRead
				api.get.mockImplementationOnce(() => new Promise(resolve => { resolveRead = resolve }))
				const pending = store.fetchFloorsWithRooms()
				store.reconcileRoom({ id: 1, floorId: 1, cleanliness: 100 })
				api.get.mockResolvedValueOnce([{ floor: { id: 1 }, rooms: [{ id: 1, floorId: 1, cleanliness: 100 }] }])
				resolveRead([{ floor: { id: 1 }, rooms: [{ id: 1, floorId: 1, cleanliness: 20 }] }])
				await pending
				expect(store.rooms[0].cleanliness).toBe(100)
			})
		})

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

		describe('reorderFloors (CS-PLAN-042, UX-004)', () => {
			it('posts the new floor order and adopts the server payload', async () => {
				const reordered = [
					{ id: 2, name: 'First Floor', sortOrder: 0 },
					{ id: 1, name: 'Ground Floor', sortOrder: 1 },
				]
				api.post.mockResolvedValue(reordered)

				await store.reorderFloors([2, 1])

				expect(api.post).toHaveBeenCalledWith('/api/v1/floors/reorder', { floorOrder: [2, 1] })
				expect(store.floors).toEqual(reordered)
			})

			it('propagates API errors', async () => {
				api.post.mockRejectedValue(new Error('boom'))

				await expect(store.reorderFloors([2, 1])).rejects.toThrow('boom')
				expect(store.error).toBe('boom')
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
				store.loading = true
				store.error = 'Error'

				store.reset()

				expect(store.floors).toEqual([])
				expect(store.rooms).toEqual([])
				expect(store.loading).toBe(false)
				expect(store.error).toBeNull()
			})
		})
	})
})
