/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { useHouseStore } from './house.js'
import { useRoomsStore } from './rooms.js'
import { useTasksStore } from './tasks.js'
import { useRoomTasksStore } from './roomTasks.js'
import { useGameStore } from './game.js'
import { useUserStore } from './user.js'
import { useChecklistStore } from './checklist.js'
import { useMinigameStore } from './minigame.js'
import { useTitlesStore } from './titles.js'
import { logger } from '../utils/logger.js'

// Export all stores
export {
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
	useChecklistStore,
	useMinigameStore,
}

// Store initialization utilities
export const initializeStores = async () => {
	try {
		// Initialize user first (needed for authentication)
		const userStore = useUserStore()
		await userStore.initializeUsers()

		// Initialize house data
		const houseStore = useHouseStore()
		await houseStore.initializeHouse()

		// Initialize rooms and floors
		const roomsStore = useRoomsStore()
		await roomsStore.fetchFloorsWithRooms()

		// Initialize tasks
		const tasksStore = useTasksStore()
		await tasksStore.initializeTasks()

		// Initialize game state
		const gameStore = useGameStore()
		await gameStore.initializeGame()

		return {
			user: userStore,
			house: houseStore,
			rooms: roomsStore,
			tasks: tasksStore,
			game: gameStore,
		}
	} catch (error) {
		logger.error('Failed to initialize stores', error, { module: 'store/index', action: 'initializeStores' })
		throw error
	}
}

// Store reset utilities
export const resetAllStores = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const minigameStore = useMinigameStore()
	const titlesStore = useTitlesStore()

	userStore.reset()
	houseStore.reset()
	roomsStore.reset()
	tasksStore.reset()
	roomTasksStore.reset()
	gameStore.reset()
	minigameStore.reset()
	titlesStore.reset()
}

// Store error clearing utilities
export const clearAllStoreErrors = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const minigameStore = useMinigameStore()

	userStore.clearError()
	houseStore.clearError()
	roomsStore.clearError()
	tasksStore.clearError()
	roomTasksStore.clearError()
	gameStore.clearError()
	minigameStore.error = null
}

// Store loading state utilities
export const getAnyStoreLoading = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const minigameStore = useMinigameStore()

	return (
		userStore.loading
    || houseStore.loading
    || roomsStore.loading
    || tasksStore.loading
    || roomTasksStore.loading
    || gameStore.loading
    || minigameStore.loading
	)
}

// Store error state utilities
export const getAnyStoreError = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const minigameStore = useMinigameStore()

	return (
		userStore.error
    || houseStore.error
    || roomsStore.error
    || tasksStore.error
    || roomTasksStore.error
    || gameStore.error
    || minigameStore.error
	)
}

// Store data utilities
export const getStoreSummary = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()

	return {
		user: {
			currentUser: userStore.currentUser,
			houseUsersCount: userStore.houseUsers.length,
			isOwner: userStore.isHouseOwner,
		},
		house: {
			house: houseStore.house,
			houseHealth: houseStore.houseHealth,
			hasHouse: houseStore.hasHouse,
		},
		rooms: {
			floorsCount: roomsStore.floors.length,
			roomsCount: roomsStore.rooms.length,
			averageCleanliness: roomsStore.averageCleanliness,
		},
		tasks: {
			tasksCount: tasksStore.tasks.length,
			templatesCount: tasksStore.templates.length,
			activeRoomTasksCount: roomTasksStore.totalActiveTasks,
			overdueTasksCount: roomTasksStore.totalOverdueTasks,
		},
		game: {
			leaderboardCount: gameStore.leaderboard.length,
			medalsCount: gameStore.medals.length,
			hasCombos: gameStore.hasActiveCombos,
		},
	}
}

// Pinia plugin for store initialization
export const piniaInitializationPlugin = ({ pinia }) => {
	// Auto-initialize stores when they are first used
	pinia.use(({ store }) => {
		if (store.$id === 'user') {
			// Auto-initialize user store
			if (!store.currentUser) {
				store.initializeCurrentUser().catch(error => {
					logger.error('Failed to auto-initialize user store', error, { module: 'store/index', action: 'piniaInitializationPlugin', storeId: store.$id })
				})
			}
		}
	})
}

export default {
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
	useMinigameStore,
	initializeStores,
	resetAllStores,
	clearAllStoreErrors,
	getAnyStoreLoading,
	getAnyStoreError,
	getStoreSummary,
	piniaInitializationPlugin,
}
