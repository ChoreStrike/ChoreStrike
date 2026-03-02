/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

describe('Availability Settings Flow', () => {
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
		cy.intercept('GET', '/api/v1/smart-schedule/availability', {
			body: {
				success: true,
				data: [
					{
						userId: 'test-user',
						userName: 'Test User',
						weekdayAvailability: {
							monday: true,
							tuesday: true,
							wednesday: false,
							thursday: true,
							friday: true,
							saturday: false,
							sunday: false,
						},
						energyBudget: {
							monday: 10,
							tuesday: 8,
							wednesday: 5,
							thursday: 12,
							friday: 15,
							saturday: 0,
							sunday: 0,
						},
						planningMode: 'auto_balance',
						maxTasksPerDay: 5,
						timeRanges: {
							start: '09:00',
							end: '18:00'
						}
					},
					{
						userId: 'other-user',
						userName: 'Other User',
						weekdayAvailability: {
							monday: false,
							tuesday: true,
							wednesday: true,
							thursday: false,
							friday: true,
							saturday: true,
							sunday: true,
						},
						energyBudget: {
							monday: 0,
							tuesday: 12,
							wednesday: 10,
							thursday: 0,
							friday: 8,
							saturday: 15,
							sunday: 20,
						},
						planningMode: 'weekend_warrior',
						maxTasksPerDay: 3,
						timeRanges: {
							start: '10:00',
							end: '20:00'
						}
					}
				]
			}
		}).as('getAvailability')

		cy.intercept('PUT', '/api/v1/smart-schedule/availability', {
			body: {
				success: true,
				message: 'Availability updated successfully'
			}
		}).as('updateAvailability')

		cy.intercept('PUT', '/api/v1/smart-schedule/energy-budget', {
			body: {
				success: true,
				message: 'Energy budget updated successfully'
			}
		}).as('updateEnergyBudget')

		cy.visit('/apps/chorestrike')
	})

	it('should load and display member availability', () => {
		cy.wait('@getAvailability')
		
		cy.get('[data-testid="availability-settings"]').should('be.visible')
		cy.get('[data-testid="members-list"]').should('be.visible')
		cy.get('[data-testid="member-card"]').should('have.length', 2)
		
		// Verify current user card
		cy.get('[data-testid="member-card"]').eq(0).within(() => {
			cy.get('[data-testid="member-name"]').should('contain', 'Test User')
			cy.get('[data-testid="member-status"]').should('contain', '4/7 days available')
			cy.get('[data-testid="edit-availability-button"]').should('be.visible')
		})
		
		// Verify other user card (no edit button)
		cy.get('[data-testid="member-card"]').eq(1).within(() => {
			cy.get('[data-testid="member-name"]').should('contain', 'Other User')
			cy.get('[data-testid="member-status"]').should('contain', '5/7 days available')
			cy.get('[data-testid="edit-availability-button"]').should('not.exist')
		})
	})

	it('should open edit modal for current user', () => {
		cy.wait('@getAvailability')
		
		cy.get('[data-testid="edit-availability-button"]').first().click()
		
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		cy.get('[data-testid="modal-title"]').should('contain', 'Edit Availability for Test User')
		
		// Verify current values are loaded
		cy.get('[data-testid="availability-monday"]').should('be.checked')
		cy.get('[data-testid="availability-wednesday"]').should('not.be.checked')
		cy.get('[data-testid="availability-saturday"]').should('not.be.checked')
		
		cy.get('[data-testid="budget-monday-input"]').should('have.value', 10)
		cy.get('[data-testid="budget-wednesday-input"]').should('have.value', 5)
		cy.get('[data-testid="budget-saturday-input"]').should('have.value', 0)
	})

	it('should apply weekend warrior template', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Apply template
		cy.get('[data-testid="template-weekend-warrior"]').click()
		
		// Verify template was applied
		cy.get('[data-testid="availability-monday"]').should('not.be.checked')
		cy.get('[data-testid="availability-tuesday"]').should('not.be.checked')
		cy.get('[data-testid="availability-saturday"]').should('be.checked')
		cy.get('[data-testid="availability-sunday"]').should('be.checked')
		
		cy.get('[data-testid="budget-monday-input"]').should('have.value', 3)
		cy.get('[data-testid="budget-saturday-input"]').should('have.value', 20)
		cy.get('[data-testid="budget-sunday-input"]').should('have.value', 20)
	})

	it('should apply balanced template', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Apply template
		cy.get('[data-testid="template-balanced"]').click()
		
		// Verify template was applied
		cy.get('[data-testid="availability-monday"]').should('be.checked')
		cy.get('[data-testid="availability-sunday"]').should('be.checked')
		
		cy.get('[data-testid="budget-monday-input"]').should('have.value', 10)
		cy.get('[data-testid="budget-saturday-input"]').should('have.value', 15)
		cy.get('[data-testid="budget-sunday-input"]').should('have.value', 15)
	})

	it('should apply minimal template', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Apply template
		cy.get('[data-testid="template-minimal"]').click()
		
		// Verify template was applied
		cy.get('[data-testid="availability-monday"]').should('be.checked')
		cy.get('[data-testid="availability-saturday"]').should('not.be.checked')
		cy.get('[data-testid="availability-sunday"]').should('not.be.checked')
		
		cy.get('[data-testid="budget-monday-input"]').should('have.value', 5)
		cy.get('[data-testid="budget-saturday-input"]').should('have.value', 0)
		cy.get('[data-testid="budget-sunday-input"]').should('have.value', 0)
	})

	it('should save availability changes', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Make changes
		cy.get('[data-testid="availability-wednesday"]').check()
		cy.get('[data-testid="availability-saturday"]').check()
		cy.get('[data-testid="budget-wednesday-input"]').clear().type('12')
		cy.get('[data-testid="budget-saturday-input"]').clear().type('8')
		
		// Save
		cy.get('[data-testid="save-availability-button"]').click()
		
		cy.wait('@updateAvailability')
		cy.wait('@updateEnergyBudget')
		
		// Modal should close
		cy.get('[data-testid="edit-availability-modal"]').should('not.exist')
		
		// Success message should be shown
		cy.get('.notification').should('contain', 'Availability settings saved successfully')
	})

	it('should validate energy budget input', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Try invalid energy value (too high)
		cy.get('[data-testid="budget-monday-input"]').clear().type('25')
		cy.get('[data-testid="save-availability-button"]').click()
		
		// Should show validation error
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		cy.get('.error-message').should('contain', 'Energy budget must be between 1 and 20')
		
		// Try invalid energy value (too low)
		cy.get('[data-testid="budget-monday-input"]').clear().type('0')
		cy.get('[data-testid="save-availability-button"]').click()
		
		// Should show validation error
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		cy.get('.error-message').should('contain', 'Energy budget must be between 1 and 20')
	})

	it('should cancel editing without saving', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Make changes
		cy.get('[data-testid="availability-wednesday"]').check()
		cy.get('[data-testid="budget-wednesday-input"]').clear().type('15')
		
		// Cancel
		cy.get('[data-testid="cancel-edit-button"]').click()
		
		// Modal should close without saving
		cy.get('[data-testid="edit-availability-modal"]').should('not.exist')
		
		// Original values should remain
		cy.get('[data-testid="member-card"]').eq(0).within(() => {
			cy.get('[data-testid="member-status"]').should('contain', '4/7 days available')
		})
	})

	it('should handle empty availability list', () => {
		// Mock empty response
		cy.intercept('GET', '/api/v1/smart-schedule/availability', {
			body: {
				success: true,
				data: []
			}
		}).as('getEmptyAvailability')

		cy.visit('/apps/chorestrike')
		cy.wait('@getEmptyAvailability')
		
		cy.get('[data-testid="availability-settings"]').should('be.visible')
		cy.get('[data-testid="empty-state"]').should('be.visible')
		cy.get('[data-testid="empty-state"]').should('contain', 'No Members Found')
		cy.get('[data-testid="empty-state"]').should('contain', 'Add members to your house to configure availability')
	})

	it('should handle API errors gracefully', () => {
		// Mock error response
		cy.intercept('GET', '/api/v1/smart-schedule/availability', {
			statusCode: 500,
			body: {
				success: false,
				error: 'Database connection failed'
			}
		}).as('getAvailabilityError')

		cy.visit('/apps/chorestrike')
		cy.wait('@getAvailabilityError')
		
		cy.get('[data-testid="availability-settings"]').should('be.visible')
		cy.get('[data-testid="error-state"]').should('be.visible')
		cy.get('[data-testid="error-message"]').should('contain', 'Failed to load availability settings')
	})

	it('should complete full availability workflow', () => {
		cy.wait('@getAvailability')
		
		// Step 1: Open edit modal
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Step 2: Apply weekend warrior template
		cy.get('[data-testid="template-weekend-warrior"]').click()
		
		// Step 3: Make additional adjustments
		cy.get('[data-testid="availability-friday"]').check()
		cy.get('[data-testid="budget-friday-input"]').clear().type('10')
		
		// Step 4: Save changes
		cy.get('[data-testid="save-availability-button"]').click()
		cy.wait('@updateAvailability')
		cy.wait('@updateEnergyBudget')
		
		// Step 5: Verify changes were saved
		cy.get('[data-testid="edit-availability-modal"]').should('not.exist')
		cy.get('.notification').should('contain', 'Availability settings saved successfully')
		
		// Step 6: Refresh and verify updated data
		cy.intercept('GET', '/api/v1/smart-schedule/availability', {
			body: {
				success: true,
				data: [
					{
						userId: 'test-user',
						userName: 'Test User',
						weekdayAvailability: {
							monday: false,
							tuesday: false,
							wednesday: false,
							thursday: false,
							friday: true,
							saturday: true,
							sunday: true,
						},
						energyBudget: {
							monday: 3,
							tuesday: 3,
							wednesday: 3,
							thursday: 3,
							friday: 10,
							saturday: 20,
							sunday: 20,
						},
						planningMode: 'auto_balance',
						maxTasksPerDay: 5,
						timeRanges: {
							start: '09:00',
							end: '18:00'
						}
					}
				]
			}
		}).as('getUpdatedAvailability')
		
		cy.get('[data-testid="refresh-button"]').click()
		cy.wait('@getUpdatedAvailability')
		
		// Verify updated availability is displayed
		cy.get('[data-testid="member-card"]').eq(0).within(() => {
			cy.get('[data-testid="member-status"]').should('contain', '3/7 days available')
		})
	})

	it('should handle save errors gracefully', () => {
		cy.wait('@getAvailability')
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Mock save error
		cy.intercept('PUT', '/api/v1/smart-schedule/availability', {
			statusCode: 500,
			body: {
				success: false,
				error: 'Failed to save availability'
			}
		}).as('updateAvailabilityError')
		
		// Try to save
		cy.get('[data-testid="save-availability-button"]').click()
		cy.wait('@updateAvailabilityError')
		
		// Modal should remain open with error message
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		cy.get('.error-message').should('contain', 'Failed to save availability settings')
	})
})
