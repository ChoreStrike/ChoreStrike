import { test as baseTest, expect } from '@playwright/test'

/**
 * Database fixtures for ChoreStrike E2E tests
 * 
 * Supports three profiles:
 * - empty: Clean database (no data)
 * - minimal: 1 House, 1 Floor, 1 Room, 2 Users (adult, kid)
 * - family: Complete family (6 Users, 3 Floors, 6 Rooms, 12 Tasks)
 */

// Profile types
export type DatabaseProfile = 'empty' | 'minimal' | 'family'

// User credentials for each profile
export const PROFILE_USERS: Record<DatabaseProfile, Array<{ userId: string; password: string; displayName: string }>> = {
	empty: [],
	minimal: [
		{ userId: 'adult', password: 'Adult123!', displayName: 'Adult User' },
		{ userId: 'kid', password: 'Kid123!', displayName: 'Kid User' },
	],
	family: [
		{ userId: 'mom', password: 'Mom123!', displayName: 'Mom' },
		{ userId: 'dad', password: 'Dad123!', displayName: 'Dad' },
		{ userId: 'teen', password: 'Teen123!', displayName: 'Teen' },
		{ userId: 'kid', password: 'Kid123!', displayName: 'Kid' },
		{ userId: 'grandma', password: 'Grandma123!', displayName: 'Grandma' },
		{ userId: 'friend', password: 'Friend123!', displayName: 'Friend' },
	],
}

// Seeded data structure returned from API
export interface SeededData {
	profile: DatabaseProfile
	data: {
		house?: { id: number; name: string }
		floors?: Array<{ id: number; name: string; sortOrder?: number }>
		rooms?: Array<{ id: number; name: string; cleanliness: number; floorId?: number }>
		users?: Array<{ id: number; nextcloudUserId: string; displayName: string; role: string; maxDailyTasks?: number }>
		tasks?: Array<{ id: number; name: string; category: string; energyCost?: number }>
		roomTasks?: Array<{ id: number; roomId: number; taskId: number; assignedToUserId?: string; assignedTo?: string }>
	}
}

// Database manager class
class DatabaseManager {
	private baseURL: string

	constructor(baseURL: string) {
		this.baseURL = baseURL.replace(/\/$/, '')
	}

	/**
	 * Get available profiles
	 */
	async getProfiles(): Promise<Array<{ name: string; description: string }>> {
		const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/profiles`)
		if (!response.ok) {
			throw new Error(`Failed to get profiles: ${response.status}`)
		}
		const result = await response.json()
		return result.data.profiles
	}

	/**
	 * Seed database with a specific profile
	 */
	async seed(profile: DatabaseProfile): Promise<SeededData> {
		const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/seed/${profile}`, {
			method: 'GET',
		})

		if (!response.ok) {
			const error = await response.text()
			throw new Error(`Failed to seed database with profile '${profile}': ${error}`)
		}

		const result = await response.json()
		if (!result.success) {
			throw new Error(`Seed failed: ${result.error?.message || 'Unknown error'}`)
		}

		return result.data
	}

	/**
	 * Reset database (equivalent to seed empty)
	 */
	async reset(): Promise<void> {
		const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/reset`, {
			method: 'GET',
		})

		if (!response.ok) {
			throw new Error(`Failed to reset database: ${response.status}`)
		}
	}

	/**
	 * Check if test mode is enabled
	 */
	async isTestMode(): Promise<boolean> {
		try {
			const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/profiles`)
			if (!response.ok) return false
			const result = await response.json()
			return result.data.testMode === true
		} catch {
			return false
		}
	}
}

// Test fixtures type
type DatabaseFixtures = {
	databaseProfile: DatabaseProfile
	databaseManager: DatabaseManager
	seededData: SeededData | null
}

// Default profile from environment or 'minimal'
const DEFAULT_PROFILE: DatabaseProfile = (process.env.TEST_DB_PROFILE as DatabaseProfile) || 'minimal'

// Extended test with database fixtures
export const test = baseTest.extend<DatabaseFixtures>({
	// Database profile fixture (can be overridden per test)
	databaseProfile: [DEFAULT_PROFILE, { option: true }],

	// Database manager fixture
	databaseManager: async ({ baseURL }, use) => {
		const manager = new DatabaseManager(baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080')
		await use(manager)
	},

	// Seeded data fixture - seeds before test and provides data
	seededData: async ({ databaseProfile, databaseManager }, use, testInfo) => {
		let data: SeededData | null = null

		// Check if test mode is available
		const isTestMode = await databaseManager.isTestMode()
		
		if (isTestMode) {
			console.log(`[${testInfo.title}] Seeding database with profile: ${databaseProfile}`)
			data = await databaseManager.seed(databaseProfile)
			console.log(`[${testInfo.title}] Database seeded successfully`)
		} else {
			console.log(`[${testInfo.title}] Test mode not available, skipping seed`)
		}

		await use(data)
	},
})

// Re-export expect
export { expect }
