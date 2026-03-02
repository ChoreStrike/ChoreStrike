/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

describe('Daily Tasks Flow', () => {
	beforeEach(() => {
		// Mock authentication
		cy.window().then((win) => {
			win.OC = {
				currentUser: 'test-user',
				getCurrentUser: () => ({
					uid: 'test-user',
					displayName: 'Test User'
				})
			}
		})

		// Mock API responses
		cy.intercept('GET', '/api/v1/tasks/daily', {
			body: {
				success: true,
				data: [
					{
						id: 1,
						taskName: 'Clean Kitchen',
						roomName: 'Kitchen',
						nextDue: '2026-03-02',
						lastCompleted: null,
						isActive: true,
						assignedToUserId: 'test-user'
					},
					{
						id: 2,
						taskName: 'Vacuum Living Room',
						roomName: 'Living Room',
						nextDue: '2026-03-02',
						lastCompleted: null,
						isActive: true,
						assignedToUserId: 'test-user'
					}
				]
			}
		}).as('getDailyTasks')

		cy.intercept('PUT', '/api/v1/tasks/1/complete', {
			body: {
				success: true,
				data: {
					id: 1,
					lastCompleted: '2026-03-02'
				}
			}
		}).as('completeTask')

		cy.intercept('PUT', '/api/v1/tasks/2/uncomplete', {
			body: {
				success: true,
				data: {
					id: 2,
					lastCompleted: null
				}
			}
		}).as('uncompleteTask')

		cy.intercept('PUT', '/api/v1/tasks/1/skip', {
			body: {
				success: true,
				data: {
					id: 1,
					nextDue: '2026-03-03'
				}
			}
		}).as('skipTask')

		cy.intercept('PUT', '/api/v1/tasks/2/reschedule', {
			body: {
				success: true,
				data: {
					id: 2,
					nextDue: '2026-03-05'
				}
			}
		}).as('rescheduleTask')

		cy.visit('/apps/chorestrike')
	})

	it('should load and display daily tasks', () => {
		cy.wait('@getDailyTasks')
		
		cy.get('[data-testid="daily-task-list"]').should('be.visible')
		cy.get('[data-testid="daily-task-item"]').should('have.length', 2)
		
		// Verify task details
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="task-name"]').should('contain', 'Clean Kitchen')
			cy.get('[data-testid="room-name"]').should('contain', 'Kitchen')
			cy.get('[data-testid="due-date"]').should('contain', '2026-03-02')
			cy.get('[data-testid="completion-status"]').should('contain', 'Not completed')
		})
	})

	it('should complete a task', () => {
		cy.wait('@getDailyTasks')
		
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="complete-button"]').click()
		})
		
		cy.wait('@completeTask')
		
		// Verify task is marked as completed
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="completion-status"]').should('contain', 'Completed')
			cy.get('[data-testid="complete-button"]').should('not.exist')
			cy.get('[data-testid="uncomplete-button"]').should('be.visible')
		})
	})

	it('should uncomplete a task', () => {
		// First complete the task
		cy.wait('@getDailyTasks')
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="complete-button"]').click()
		})
		cy.wait('@completeTask')
		
		// Then uncomplete it
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="uncomplete-button"]').click()
		})
		
		cy.wait('@uncompleteTask')
		
		// Verify task is marked as not completed
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="completion-status"]').should('contain', 'Not completed')
			cy.get('[data-testid="complete-button"]').should('be.visible')
			cy.get('[data-testid="uncomplete-button"]').should('not.exist')
		})
	})

	it('should skip a task', () => {
		cy.wait('@getDailyTasks')
		
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="skip-button"]').click()
		})
		
		cy.wait('@skipTask')
		
		// Verify task due date was moved forward
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="due-date"]').should('contain', '2026-03-03')
		})
	})

	it('should reschedule a task', () => {
		cy.wait('@getDailyTasks')
		
		cy.get('[data-testid="daily-task-item"]').eq(1).within(() => {
			cy.get('[data-testid="reschedule-button"]').click()
		})
		
		// Mock the date picker
		cy.get('[data-testid="reschedule-modal"]').should('be.visible')
		cy.get('[data-testid="date-input"]').type('2026-03-05')
		cy.get('[data-testid="confirm-reschedule"]').click()
		
		cy.wait('@rescheduleTask')
		
		// Verify task was rescheduled
		cy.get('[data-testid="daily-task-item"]').eq(1).within(() => {
			cy.get('[data-testid="due-date"]').should('contain', '2026-03-05')
		})
	})

	it('should handle empty daily tasks list', () => {
		// Mock empty response
		cy.intercept('GET', '/api/v1/tasks/daily', {
			body: {
				success: true,
				data: []
			}
		}).as('getEmptyDailyTasks')

		cy.visit('/apps/chorestrike')
		cy.wait('@getEmptyDailyTasks')
		
		cy.get('[data-testid="daily-task-list"]').should('be.visible')
		cy.get('[data-testid="empty-state"]').should('be.visible')
		cy.get('[data-testid="empty-state"]').should('contain', 'No tasks scheduled for today')
	})

	it('should handle API errors gracefully', () => {
		// Mock error response
		cy.intercept('GET', '/api/v1/tasks/daily', {
			statusCode: 500,
			body: {
				success: false,
				error: {
					code: 'DAILY_TASKS_ERROR',
					message: 'Database connection failed'
				}
			}
		}).as('getDailyTasksError')

		cy.visit('/apps/chorestrike')
		cy.wait('@getDailyTasksError')
		
		cy.get('[data-testid="error-state"]').should('be.visible')
		cy.get('[data-testid="error-message"]').should('contain', 'Failed to load daily tasks')
	})

	it('should complete full daily task workflow', () => {
		cy.wait('@getDailyTasks')
		
		// Complete first task
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="complete-button"]').click()
		})
		cy.wait('@completeTask')
		
		// Skip second task
		cy.get('[data-testid="daily-task-item"]').eq(1).within(() => {
			cy.get('[data-testid="skip-button"]').click()
		})
		cy.wait('@skipTask')
		
		// Verify final state
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="completion-status"]').should('contain', 'Completed')
		})
		
		cy.get('[data-testid="daily-task-item"]').eq(1).within(() => {
			cy.get('[data-testid="due-date"]').should('contain', '2026-03-03')
		})
		
		// Check progress indicator
		cy.get('[data-testid="daily-progress"]').should('contain', '1 of 2 tasks completed')
	})

	it('should refresh tasks after actions', () => {
		cy.wait('@getDailyTasks')
		
		// Complete a task
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="complete-button"]').click()
		})
		cy.wait('@completeTask')
		
		// Mock updated response
		cy.intercept('GET', '/api/v1/tasks/daily', {
			body: {
				success: true,
				data: [
					{
						id: 1,
						taskName: 'Clean Kitchen',
						roomName: 'Kitchen',
						nextDue: '2026-03-02',
						lastCompleted: '2026-03-02',
						isActive: true,
						assignedToUserId: 'test-user'
					},
					{
						id: 2,
						taskName: 'Vacuum Living Room',
						roomName: 'Living Room',
						nextDue: '2026-03-02',
						lastCompleted: null,
						isActive: true,
						assignedToUserId: 'test-user'
					}
				]
			}
		}).as('getUpdatedDailyTasks')
		
		// Trigger refresh
		cy.get('[data-testid="refresh-button"]').click()
		cy.wait('@getUpdatedDailyTasks')
		
		// Verify updated data is displayed
		cy.get('[data-testid="daily-task-item"]').eq(0).within(() => {
			cy.get('[data-testid="completion-status"]').should('contain', 'Completed')
		})
	})
})
