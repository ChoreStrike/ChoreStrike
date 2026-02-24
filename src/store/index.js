import { useHouseStore } from './house'
import { useRoomsStore } from './rooms'
import { useTasksStore } from './tasks'
import { useRoomTasksStore } from './roomTasks'
import { useGameStore } from './game'
import { useUserStore } from './user'

// Export all stores
export {
	useHouseStore,
	useRoomsStore,
	useTasksStore,
	useRoomTasksStore,
	useGameStore,
	useUserStore,
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

	userStore.reset()
	houseStore.reset()
	roomsStore.reset()
	tasksStore.reset()
	roomTasksStore.reset()
	gameStore.reset()
}

// Store error clearing utilities
export const clearAllStoreErrors = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()

	userStore.clearError()
	houseStore.clearError()
	roomsStore.clearError()
	tasksStore.clearError()
	roomTasksStore.clearError()
	gameStore.clearError()
}

// Store loading state utilities
export const getAnyStoreLoading = () => {
	const userStore = useUserStore()
	const houseStore = useHouseStore()
	const roomsStore = useRoomsStore()
	const tasksStore = useTasksStore()
	const roomTasksStore = useRoomTasksStore()
	const gameStore = useGameStore()

	return (
		userStore.loading
    || houseStore.loading
    || roomsStore.loading
    || tasksStore.loading
    || roomTasksStore.loading
    || gameStore.loading
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

	return (
		userStore.error
    || houseStore.error
    || roomsStore.error
    || tasksStore.error
    || roomTasksStore.error
    || gameStore.error
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
	}
}

// Pinia plugin for store initialization
export const choreWarsPiniaPlugin = ({ pinia }) => {
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
	initializeStores,
	resetAllStores,
	clearAllStoreErrors,
	getAnyStoreLoading,
	getAnyStoreError,
	getStoreSummary,
	choreWarsPiniaPlugin,
}
