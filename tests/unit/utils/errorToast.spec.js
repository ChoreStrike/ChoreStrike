/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { showError } from '@nextcloud/dialogs'
import { errorReference, refusalReason, showCompletionError, showErrorWithReference, showHistoryError, showPostponeError } from '@/utils/errorToast.js'
import { ApiError } from '@/services/api.js'

describe('errorToast (CS-PLAN-084 WP-F)', () => {
	beforeEach(() => {
		showError.mockClear()
	})

	it.each(['CONFLICT', 'STALE_TARGET', 'VALIDATION_ERROR'])('reads the reason of a coded refusal: %s', (code) => {
		expect(refusalReason({ code, message: 'Over the limit' })).toBe('Over the limit')
	})

	it('finds no reason in anything else', () => {
		expect(refusalReason({ code: 'INTERNAL_ERROR', message: 'Boom' })).toBe('')
		expect(refusalReason({ code: 'CONFLICT' })).toBe('')
		expect(refusalReason(new Error('Network'))).toBe('')
		expect(refusalReason(undefined)).toBe('')
	})

	it('prefers the stable API code', () => {
		expect(errorReference(new ApiError('Conflict detail', 409, {}, 'CONFLICT'))).toBe('CONFLICT')
		const stale = new Error('The task changed since it was displayed.')
		stale.code = 'STALE_TARGET'
		expect(errorReference(stale)).toBe('STALE_TARGET')
	})

	it('falls back to the HTTP status, then NETWORK for an API call without a response', () => {
		expect(errorReference(new ApiError('Request failed', 502, null, null))).toBe('HTTP 502')
		expect(errorReference(new ApiError('Request failed', undefined, undefined, null))).toBe('NETWORK')
	})

	it('never echoes free text, malformed codes or unknown errors', () => {
		expect(errorReference(new ApiError('x', undefined, undefined, 'drop table; <b>'))).toBe('NETWORK')
		expect(errorReference(Object.assign(new Error('boom'), { code: 'lower_case' }))).toBeNull()
		expect(errorReference(new Error('boom at line 3'))).toBeNull()
		expect(errorReference(undefined)).toBeNull()
	})

	it('appends the reference to the translated message', () => {
		showErrorWithReference('Could not complete the task', new ApiError('Conflict', 409, {}, 'CONFLICT'))

		expect(showError).toHaveBeenCalledWith('Could not complete the task (CONFLICT)')
	})

	it('shows the plain message when no reference is known', () => {
		showErrorWithReference('Could not complete the task', new Error('boom'))

		expect(showError).toHaveBeenCalledWith('Could not complete the task')
	})

	// CR-084-02: a refused join names the split instead of a bare failure.
	it('explains a completion refused behind another in-flight split', () => {
		showCompletionError(Object.assign(new Error('in flight'), { code: 'COMPLETION_IN_FLIGHT' }))

		expect(showError).toHaveBeenCalledWith('This task is already being completed with another work split. Check the split once it is done.')
	})

	// ADR-021 decision 4: the refusals of derived windows say what happened
	// (CS-PLAN-091 WP-B1 interim review; the window-not-open refusal is gone).
	it('explains a completion on the day of the latest one', () => {
		showCompletionError(new ApiError('Already today', 409, {}, 'SAME_DAY'))

		expect(showError).toHaveBeenCalledWith('This task was already done today. It can be done again from tomorrow.')
	})

	it('explains a completion of a cycle that was closed meanwhile', () => {
		showCompletionError(new ApiError('Closed', 409, {}, 'ALREADY_COMPLETED'))

		expect(showError).toHaveBeenCalledWith('This task was already completed. Reload to see what is next.')
	})

	it('shows the referenced failure for any other completion error', () => {
		showCompletionError(new ApiError('Conflict', 409, {}, 'CONFLICT'))

		expect(showError).toHaveBeenCalledWith('Could not complete the task (CONFLICT)')
	})

	it.each([
		['NOT_POSTPONABLE', 'This task cannot be postponed any further'],
		['WINDOW_ENDED', 'An overdue task cannot be postponed'],
		['STALE_TARGET', 'The task changed since it was displayed. Reload and try again.'],
	])('explains a refused postponement: %s', (code, message) => {
		showPostponeError(Object.assign(new Error('server text'), { status: 409, code }))

		expect(showError).toHaveBeenCalledWith(message)
	})

	it('shows the referenced failure for any other postponement error', () => {
		showPostponeError(new ApiError('Boom', 500, {}, 'POSTPONE_TASK_ERROR'))

		expect(showError).toHaveBeenCalledWith('Could not postpone the task (POSTPONE_TASK_ERROR)')
	})

	it.each(['VALIDATION_ERROR', 'FORBIDDEN', 'CONFLICT'])('shows the reason of a refused date change as plain text: %s', (code) => {
		showHistoryError(Object.assign(new Error('The date <b>cannot</b> be in the future'), { status: 400, code }))

		expect(showError).toHaveBeenCalledWith('The date <b>cannot</b> be in the future', { isHTML: false })
	})

	it('shows the referenced failure for any other date change error', () => {
		showHistoryError(new ApiError('Boom', 500, {}, 'COMPLETION_DATE_ERROR'))

		expect(showError).toHaveBeenCalledWith('Could not change the date (COMPLETION_DATE_ERROR)')
	})
})
