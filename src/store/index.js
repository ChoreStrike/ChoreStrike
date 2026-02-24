import { useHouseStore } from './house.js'
import { useRoomsStore } from './rooms.js'
import { useTasksStore } from './tasks.js'
import { useRoomTasksStore } from './roomTasks.js'
import { useGameStore } from './game.js'
import { useUserStore } from './user.js'
import { useSchedulingStore } from './scheduling.js'
import { perfMonitor } from '../utils/performance.js'

// Export all stores
export {
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
	useSchedulingStore,
}

// Store initialization utilities with performance tracking

export const initializeStores = async () => {
	perfMonitor.start('store-initialization')
	try {
		// Initialize user first (needed for authentication)
		const userStore = useUserStore()
		await perfMonitor.measureAsync('user-init', () => userStore.initializeCurrentUser())

		// Initialize house data in parallel with other independent stores
		const houseStore = useHouseStore()
		const housePromise = perfMonitor.measureAsync('house-init', () => houseStore.initializeHouse())

		// Wait for house then initialize rooms
		await housePromise
		const roomsStore = useRoomsStore()
		await perfMonitor.measureAsync('rooms-init', () => roomsStore.fetchFloorsWithRooms())

		// Initialize tasks and game state in parallel
		const tasksStore = useTasksStore()
		const gameStore = useGameStore()

		const [_tasksResult, _gameResult] = await Promise.all([
			perfMonitor.measureAsync('tasks-init', () => tasksStore.initializeTasks()),
			perfMonitor.measureAsync('game-init', () => gameStore.initializeGame()),
		])

		perfMonitor.end('store-initialization', { success: true })

		return {
			user: userStore,
			house: houseStore,
			rooms: roomsStore,
			tasks: tasksStore,
			game: gameStore,
		}
	} catch (error) {
		perfMonitor.end('store-initialization', { success: false, error: error.message })
		console.error('Failed to initialize stores:', error)
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
	const schedulingStore = useSchedulingStore()

	userStore.reset()
	houseStore.reset()
	roomsStore.reset()
	tasksStore.reset()
	roomTasksStore.reset()
	gameStore.reset()
	schedulingStore.reset()
}

// Store error clearing utilities
export const clearAllStoreErrors = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const schedulingStore = useSchedulingStore()

	userStore.clearError()
	houseStore.clearError()
	roomsStore.clearError()
	tasksStore.clearError()
	roomTasksStore.clearError()
	gameStore.clearError()
	schedulingStore.clearError()
}

// Store loading state utilities
export const getAnyStoreLoading = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()
	const schedulingStore = useSchedulingStore()

	return (
		userStore.loading
		|| houseStore.loading
		|| roomsStore.loading
		|| tasksStore.loading
		|| roomTasksStore.loading
		|| gameStore.loading
		|| schedulingStore.loading
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
	const schedulingStore = useSchedulingStore()

	return (
		userStore.error
		|| houseStore.error
		|| roomsStore.error
		|| tasksStore.error
		|| roomTasksStore.error
		|| gameStore.error
		|| schedulingStore.error
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
	const schedulingStore = useSchedulingStore()

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
			totalMonsters: roomsStore.totalMonsters,
		},
		tasks: {
			tasksCount: tasksStore.tasks.length,
			templatesCount: tasksStore.templates.length,
			activeRoomTasksCount: roomTasksStore.totalActiveTasks,
			overdueTasksCount: roomTasksStore.totalOverdueTasks,
		},
		game: {
			totalMonsters: gameStore.totalMonsters,
			leaderboardCount: gameStore.leaderboard.length,
			medalsCount: gameStore.medals.length,
			hasCombos: gameStore.hasActiveCombos,
		},
		scheduling: {
			totalWeeklyEnergy: schedulingStore.totalWeeklyEnergy,
			availableDays: schedulingStore.availableWeekdays.length,
			averageDailyEnergy: schedulingStore.averageDailyEnergy,
		},
	}
}

// Pinia plugin for store initialization
export const choreStrikePiniaPlugin = ({ pinia }) => {
	// Auto-initialize stores when they are first used
	pinia.use(({ store }) => {
		if (store.$id === 'user') {
			// Auto-initialize user store
			if (!store.currentUser) {
				store.initializeCurrentUser().catch(console.error)
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
	useSchedulingStore,
	initializeStores,
	resetAllStores,
	clearAllStoreErrors,
	getAnyStoreLoading,
	getAnyStoreError,
	getStoreSummary,
	choreStrikePiniaPlugin,
}
