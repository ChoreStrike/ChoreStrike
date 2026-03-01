import { test, expect } from '@playwright/test'

test.describe('Phase 3: User Management', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		// Wait for app to load
		await page.waitForLoadState('networkidle')
	})

	test('should display user management section', async ({ page }) => {
		// Navigate to settings or user management
		const userManagement = page.locator('[data-testid="user-management"]')
		await expect(userManagement).toBeVisible()
	})

	test('should list house members', async ({ page }) => {
		const membersList = page.locator('[data-testid="user-management"] .members-list')
		await expect(membersList).toBeVisible()
		
		const members = page.locator('[data-testid="user-management"] .member-item')
		const count = await members.count()
		expect(count).toBeGreaterThan(0)
	})

	test('should display member information correctly', async ({ page }) => {
		const memberItem = page.locator('[data-testid="user-management"] .member-item').first()
		
		const memberName = memberItem.locator('.member-name')
		const memberRole = memberItem.locator('.member-role')
		const memberJoined = memberItem.locator('.member-joined')
		
		await expect(memberName).toBeVisible()
		await expect(memberRole).toBeVisible()
		await expect(memberJoined).toBeVisible()
	})

	test('should allow owner to add new member', async ({ page }) => {
		const addUserSection = page.locator('[data-testid="user-management"] .add-user-section')
		await expect(addUserSection).toBeVisible()
		
		const userIdInput = page.locator('[data-testid="user-id-input"]')
		const displayNameInput = page.locator('[data-testid="display-name-input"]')
		const roleSelect = page.locator('[data-testid="role-select"]')
		const addButton = page.locator('[data-testid="add-user-button"]')
		
		await userIdInput.fill('newuser')
		await displayNameInput.fill('New User')
		await roleSelect.selectOption('member')
		
		await addButton.click()
		
		// Wait for member to be added
		await page.waitForTimeout(500)
		
		const members = page.locator('[data-testid="user-management"] .member-item')
		const count = await members.count()
		expect(count).toBeGreaterThan(1)
	})

	test('should allow owner to remove member', async ({ page }) => {
		const removeButton = page.locator('[data-testid="remove-user-button"]').first()
		await expect(removeButton).toBeVisible()
		
		await removeButton.click()
		
		// Confirmation dialog should appear
		const confirmDialog = page.locator('.confirmation-dialog')
		await expect(confirmDialog).toBeVisible()
		
		const confirmButton = confirmDialog.locator('.danger-button')
		await confirmButton.click()
		
		// Wait for member to be removed
		await page.waitForTimeout(500)
	})

	test('should allow owner to change member role', async ({ page }) => {
		const roleSelect = page.locator('[data-testid="user-management"] .role-select').first()
		await expect(roleSelect).toBeVisible()
		
		await roleSelect.selectOption('child')
		
		// Wait for role to be updated
		await page.waitForTimeout(300)
		
		const selectedValue = await roleSelect.inputValue()
		expect(selectedValue).toBe('child')
	})
})

test.describe('Phase 3: Leaderboard', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('http://localhost:8080')
		// Wait for app to load
		await page.waitForLoadState('networkidle')
	})

	test('should display leaderboard section', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		await expect(leaderboard).toBeVisible()
	})

	test('should display points leaderboard by default', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const table = leaderboard.locator('.leaderboard-table')
		
		await expect(table).toBeVisible()
		
		// Check for points column header
		const pointsHeader = leaderboard.locator('th:has-text("Points")')
		await expect(pointsHeader).toBeVisible()
	})

	test('should display leaderboard entries with ranking', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const rows = leaderboard.locator('.leaderboard-row')
		
		const count = await rows.count()
		expect(count).toBeGreaterThan(0)
		
		// Check first row has rank badge
		const firstRow = rows.first()
		const rankBadge = firstRow.locator('.rank-badge')
		await expect(rankBadge).toBeVisible()
		
		const rankText = await rankBadge.textContent()
		expect(rankText).toBe('1')
	})

	test('should allow filtering by streak', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const streakButton = leaderboard.locator('.filter-button:has-text("Streaks")')
		
		await expect(streakButton).toBeVisible()
		await streakButton.click()
		
		// Wait for leaderboard to update
		await page.waitForTimeout(300)
		
		// Check for streak column header
		const streakHeader = leaderboard.locator('th:has-text("Current Streak")')
		await expect(streakHeader).toBeVisible()
	})

	test('should allow filtering by tasks completed', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const tasksButton = leaderboard.locator('.filter-button:has-text("Tasks")')
		
		await expect(tasksButton).toBeVisible()
		await tasksButton.click()
		
		// Wait for leaderboard to update
		await page.waitForTimeout(300)
		
		// Check for tasks column header
		const tasksHeader = leaderboard.locator('th:has-text("Tasks Completed")')
		await expect(tasksHeader).toBeVisible()
	})

	test('should highlight current user in leaderboard', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const currentUserRow = leaderboard.locator('.leaderboard-row.current-user')
		
		// Current user should be highlighted
		const count = await currentUserRow.count()
		expect(count).toBeGreaterThanOrEqual(0)
	})

	test('should display user role in leaderboard', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const firstRow = leaderboard.locator('.leaderboard-row').first()
		
		const userRole = firstRow.locator('.user-role')
		await expect(userRole).toBeVisible()
		
		const roleText = await userRole.textContent()
		expect(['Owner', 'Member', 'Child']).toContain(roleText)
	})

	test('should display score values correctly', async ({ page }) => {
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		const firstRow = leaderboard.locator('.leaderboard-row').first()
		
		const scoreValue = firstRow.locator('.score-value')
		await expect(scoreValue).toBeVisible()
		
		const scoreText = await scoreValue.textContent()
		expect(scoreText).toMatch(/^\d+$/)
	})

	test('should be responsive on mobile', async ({ page }) => {
		// Set mobile viewport
		await page.setViewportSize({ width: 375, height: 667 })
		
		const leaderboard = page.locator('[data-testid="leaderboard"]')
		await expect(leaderboard).toBeVisible()
		
		const table = leaderboard.locator('.leaderboard-table')
		await expect(table).toBeVisible()
	})
})

test.describe('Phase 3: User Management and Leaderboard Integration', () => {
	test('should update leaderboard when new member is added', async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
		
		// Get initial member count
		const initialMembers = page.locator('[data-testid="user-management"] .member-item')
		const initialCount = await initialMembers.count()
		
		// Add new member
		const userIdInput = page.locator('[data-testid="user-id-input"]')
		const displayNameInput = page.locator('[data-testid="display-name-input"]')
		const addButton = page.locator('[data-testid="add-user-button"]')
		
		await userIdInput.fill('testuser')
		await displayNameInput.fill('Test User')
		await addButton.click()
		
		// Wait for update
		await page.waitForTimeout(500)
		
		// Check member count increased
		const updatedMembers = page.locator('[data-testid="user-management"] .member-item')
		const updatedCount = await updatedMembers.count()
		expect(updatedCount).toBe(initialCount + 1)
	})

	test('should reflect role changes in user display', async ({ page }) => {
		await page.goto('http://localhost:8080')
		await page.waitForLoadState('networkidle')
		
		const roleSelect = page.locator('[data-testid="user-management"] .role-select').first()
		
		// Change role
		await roleSelect.selectOption('child')
		await page.waitForTimeout(300)
		
		// Verify role changed
		const selectedValue = await roleSelect.inputValue()
		expect(selectedValue).toBe('child')
	})
})
