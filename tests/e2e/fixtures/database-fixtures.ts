import { test as baseTest, expect } from '@playwright/test'
import { assertTestTarget } from '../../../scripts/test-target'

/**
 * Database fixtures for ChoreStrike E2E tests
 *
 * Supports four profiles:
 * - empty: Clean database (no data)
 * - emptyhouse: Clean ChoreStrike tables — reuses the family Nextcloud
 *   accounts (mom/dad, …) for the first-run setup wizard journey
 *   (CS-PLAN-062 §3.6); no memberships, so the first login bootstraps
 *   to owner
 * - minimal: 1 House, 1 Floor, 1 Room, 2 Users (adult, kid)
 * - family: Complete family (6 Users, 3 Floors, 6 Rooms, 12 Tasks)
 *
 * CS-PLAN-019 hardened the test endpoints to authenticated sessions
 * (no `@PublicPage`), so every request below authenticates with the admin
 * account (Basic auth — disposable test environment; the same credentials
 * auth.setup.ts uses).
 */

// Profile types
export type DatabaseProfile = 'empty' | 'emptyhouse' | 'minimal' | 'family'

// User credentials for each profile
export const PROFILE_USERS: Record<DatabaseProfile, Array<{ userId: string; password: string; displayName: string }>> = {
	empty: [],
	// The emptyhouse profile seeds no rows — the NC accounts come from the
	// auth-setup family seed and survive resets; the journey spec logs in
	// mom (bootstraps to owner) and dad (invitee).
	emptyhouse: [
		{ userId: 'mom', password: 'Mom123!', displayName: 'Mom' },
		{ userId: 'dad', password: 'Dad123!', displayName: 'Dad' },
	],
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
		roomTasks?: Array<{ id: number; roomId: number; taskId: number; assignees?: string[] }>
	}
}

// Database manager class
export class DatabaseManager {
	private baseURL: string
	private project: string

	constructor(baseURL: string, project: string) {
		this.baseURL = baseURL.replace(/\/$/, '')
		this.project = project
	}

	private assertResetOwner(): void {
		if (!RESET_OWNING_PROJECTS.has(this.project)) {
			throw new Error(`Pool guard violation: '${this.project}' cannot seed/reset the shared database`)
		}
	}

	/**
	 * Basic-auth header for the disposable test instance (CS-PLAN-019
	 * requires an authenticated session on every test endpoint; Basic auth
	 * also satisfies the CSRF gate that rejects cookie-session POSTs).
	 */
	private authHeaders(): Record<string, string> {
		const user = process.env.NEXTCLOUD_USER || 'admin'
		const pass = process.env.NEXTCLOUD_PASSWORD || 'admin'
		const token = Buffer.from(`${user}:${pass}`).toString('base64')
		return { Authorization: `Basic ${token}` }
	}

	/**
	 * Get available profiles
	 */
	async getProfiles(): Promise<Array<{ name: string; description: string }>> {
		const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/profiles`, {
			headers: this.authHeaders(),
		})
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
		this.assertResetOwner()
		const { url } = assertTestTarget(this.baseURL)
		const response = await fetch(`${url}/apps/chorestrike/api/v1/test/seed/${profile}`, {
			method: 'POST',
			headers: this.authHeaders(),
		})

		if (!response.ok) {
			throw new Error(`Failed to seed database with profile '${profile}': HTTP ${response.status}`)
		}

		const result = await response.json()
		if (!result.success) {
			throw new Error(`Seed failed for profile '${profile}'`)
		}

		return result.data
	}

	/**
	 * Reset database (equivalent to seed empty)
	 */
	async reset(): Promise<void> {
		this.assertResetOwner()
		const { url } = assertTestTarget(this.baseURL)
		const response = await fetch(`${url}/apps/chorestrike/api/v1/test/reset`, {
			method: 'POST',
			headers: this.authHeaders(),
		})

		if (!response.ok) {
			throw new Error(`Failed to reset database: ${response.status}`)
		}
	}

	/**
	 * Check if test mode is enabled.
	 *
	 * The profiles endpoint is itself gated by isTestMode() (403
	 * NOT_TEST_MODE when off), so a successful authenticated probe is the
	 * signal — the payload carries no testMode flag (CS-PLAN-019).
	 */
	async isTestMode(): Promise<boolean> {
		try {
			const response = await fetch(`${this.baseURL}/apps/chorestrike/api/v1/test/profiles`, {
				headers: this.authHeaders(),
			})
			if (!response.ok) return false
			const result = await response.json()
			return result.success === true
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

/**
 * The ONLY projects that may seed/reset the shared database
 * (CS-PLAN-068 §3.2, decision D1): the serial DB pool and the owner-scoped
 * scheduling pool run sequentially behind the e2e gate against one
 * disposable database. A seed attempt from any other project (e.g. the
 * parallel `chromium` pool or an optional firefox/webkit matrix run) would
 * wipe data under live workers — it fails loudly instead of racing.
 */
const RESET_OWNING_PROJECTS = new Set(['chromium-serial-db', 'chromium-scheduling-owner'])

// Extended test with database fixtures
export const test = baseTest.extend<DatabaseFixtures>({
	// Database profile fixture (can be overridden per test)
	databaseProfile: [DEFAULT_PROFILE, { option: true }],

	// Database manager fixture
	databaseManager: async ({ baseURL }, use, testInfo) => {
		const manager = new DatabaseManager(baseURL || process.env.NEXTCLOUD_URL || 'http://localhost:8080', testInfo.project.name)
		await use(manager)
	},

	// Seeded data fixture - seeds before test and provides data
	seededData: async ({ databaseProfile, databaseManager }, use, testInfo) => {
		if (!RESET_OWNING_PROJECTS.has(testInfo.project.name)) {
			throw new Error(
				`Pool guard violation: the '${databaseProfile}' seed/reset may only run in a reset-owning project`
				+ ` (${[...RESET_OWNING_PROJECTS].join(', ')}), but '${testInfo.project.name}' requested it`
				+ ' — move the spec into the serial pool selection or make it read-only.',
			)
		}

		let data: SeededData | null = null

		// Check if test mode is available
		const isTestMode = await databaseManager.isTestMode()

		if (isTestMode) {
			console.log(`[${testInfo.title}] Seeding database with profile: ${databaseProfile}`)
			data = await databaseManager.seed(databaseProfile)
			console.log(`[${testInfo.title}] Database seeded successfully`)
		} else {
			// CS-PLAN-068 §3.4: a missing test environment is a failure, not
			// a silent null the assertions have to trip over.
			throw new Error(
				`Test mode unavailable: cannot seed the '${databaseProfile}' profile required by '${testInfo.title}'`
				+ ' — the gate requires NEXTCLOUD_TEST_MODE=true on the test container.',
			)
		}

		await use(data)
	},
})

// Re-export expect
export { expect }
