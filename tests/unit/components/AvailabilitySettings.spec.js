/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

describe('AvailabilitySettings', () => {
	beforeEach(() => {
		cy.visit('/apps/chorestrike')
	})

	it('should load member availability', () => {
		cy.get('[data-testid="availability-settings"]').should('exist')
		cy.get('[data-testid="availability-settings"]').within(() => {
			cy.get('.loading-state').should('not.exist')
			cy.get('.members-list').should('exist')
		})
	})

	it('should open edit modal for current user', () => {
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		cy.get('[data-testid="cancel-edit-button"]').click()
		cy.get('[data-testid="edit-availability-modal"]').should('not.exist')
	})

	it('should apply weekend warrior template', () => {
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		cy.get('[data-testid="template-weekend-warrior"]').click()
		
		// Check weekend availability
		cy.get('[data-testid="availability-saturday"]').should('be.checked')
		cy.get('[data-testid="availability-sunday"]').should('be.checked')
		cy.get('[data-testid="availability-monday"]').should('not.be.checked')
		
		// Check energy budget
		cy.get('[data-testid="budget-saturday-input"]').should('have.value', 20)
		cy.get('[data-testid="budget-sunday-input"]').should('have.value', 20)
		cy.get('[data-testid="budget-monday-input"]').should('have.value', 3)
	})

	it('should validate energy budget input', () => {
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Try invalid energy value
		cy.get('[data-testid="budget-monday-input"]').clear().type('25')
		cy.get('[data-testid="save-availability-button"]').click()
		
		// Should show error and not close modal
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
	})

	it('should save availability settings', () => {
		cy.get('[data-testid="edit-availability-button"]').first().click()
		cy.get('[data-testid="edit-availability-modal"]').should('be.visible')
		
		// Make some changes
		cy.get('[data-testid="availability-monday"]').check()
		cy.get('[data-testid="budget-monday-input"]').clear().type('8')
		
		// Save
		cy.get('[data-testid="save-availability-button"]').click()
		
		// Modal should close
		cy.get('[data-testid="edit-availability-modal"]').should('not.exist')
		
		// Changes should be reflected
		cy.get('.members-list').first().within(() => {
			cy.get('.day-item.available').should('contain', 'Monday')
		})
	})

	it('should show empty state when no members', () => {
		// Mock empty response
		cy.intercept('GET', '/api/v1/smart-schedule/availability', {
			body: { success: true, data: [] }
		}).as('getAvailability')
		
		cy.visit('/apps/chorestrike')
		cy.wait('@getAvailability')
		
		cy.get('[data-testid="availability-settings"]').within(() => {
			cy.get('.empty-state').should('be.visible')
			cy.get('.empty-state').should('contain', 'No Members Found')
		})
	})
})
