import { test as baseTest, expect } from '@playwright/test'

/**
 * Extended test fixture with data reset capability
 * Ensures test isolation by resetting ChoreStrike data before each test
 */

// Simple API client for test data management
class TestDataManager {
	private baseURL: string
	private credentials: { user: string; pass: string }

	constructor(baseURL: string, credentials: { user: string; pass: string }) {
		this.baseURL = baseURL.replace(/\/$/, '')
		this.credentials = credentials
	}

	/**
	 * Reset all ChoreStrike test data
	 * Calls the reset API or database directly
	 */
	async resetData(): Promise<boolean> {
		try {
			// Try to call reset endpoint via HTTP
			const response = await fetch(`${this.baseURL}/apps/chorestrike/api/reset-test-data`, {
				method: 'POST',
				headers: {
					Authorization: 'Basic ' + Buffer.from(`${this.credentials.user}:${this.credentials.pass}`).toString('base64'),
					'Content-Type': 'application/json',
				},
			})

			if (response.ok) {
				console.log('Test data reset successfully')
				return true
			}
		} catch {
			console.log('Reset endpoint not available, using default state')
		}

		// If endpoint not available, tests should handle their own cleanup
		return false
	}

	/**
	 * Seed minimal test data (one house, one floor, one room)
	 */
	async seedMinimalData(): Promise<boolean> {
		try {
			const response = await fetch(`${this.baseURL}/apps/chorestrike/api/seed-minimal`, {
				method: 'POST',
				headers: {
					Authorization: 'Basic ' + Buffer.from(`${this.credentials.user}:${this.credentials.pass}`).toString('base64'),
					'Content-Type': 'application/json',
				},
			})

			return response.ok
		} catch {
			console.log('Seed endpoint not available')
			return false
		}
	}
}

// Extended test with fixtures
type TestFixtures = {
	dataManager: TestDataManager
	resetData: () => Promise<void>
}

export const test = baseTest.extend<TestFixtures>({
	// Data manager fixture
	dataManager: async ({ baseURL }, use) => {
		const manager = new TestDataManager(
			baseURL || 'http://localhost:8080',
			{
				user: process.env.NEXTCLOUD_USER || 'admin',
				pass: process.env.NEXTCLOUD_PASSWORD || 'admin',
			},
		)
		await use(manager)
	},

	// Auto-reset data before each test
	resetData: async ({ dataManager }, use, testInfo) => {
		const reset = async () => {
			// Only reset for tests that need clean state
			const needsCleanState = !testInfo.title.includes('sequential')
			if (needsCleanState) {
				await dataManager.resetData()
			}
		}

		await use(reset)
	},
})

export { expect }
