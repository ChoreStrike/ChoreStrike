/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { showError } from '@nextcloud/dialogs'
import { errorReference, showCompletionError, showErrorWithReference, showPostponeError, showRefusal } from '@/utils/errorToast.js'
import { ApiError } from '@/services/api.js'

describe('errorToast (CS-PLAN-084 WP-F)', () => {
	beforeEach(() => {
		showError.mockClear()
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

	it('shows the server\'s reason of a refused completion and the referenced failure for anything else', () => {
		showCompletionError(new ApiError('This task was <b>deleted</b>', 409, {}, 'CONFLICT'))
		showCompletionError(new ApiError('Boom', 500, {}, 'COMPLETE_TASK_ERROR'))

		expect(showError).toHaveBeenNthCalledWith(1, 'This task was &lt;b&gt;deleted&lt;/b&gt;')
		expect(showError).toHaveBeenNthCalledWith(2, 'Could not complete the task (COMPLETE_TASK_ERROR)')
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

	it('says that a bonus task already counted for the person today (ADR-021 decision 8)', () => {
		showCompletionError(new ApiError('This bonus task was already counted for this person today', 409, {}, 'BONUS_ALREADY_TODAY'))

		expect(showError).toHaveBeenCalledWith('This bonus task already counted for this person today. It counts again tomorrow.')
	})

	it('says that a sleeping task whose rule ended has nothing left to complete (D-091-83)', () => {
		showCompletionError(new ApiError('This task has ended. Wake it up to close it.', 409, {}, 'TASK_ENDED'))

		expect(showError).toHaveBeenCalledWith('This task has ended. Wake it up to close it.')
	})

	describe('showRefusal (RF-091-09)', () => {
		it.each(['VALIDATION_ERROR', 'FORBIDDEN', 'CONFLICT', 'RESOURCE_NOT_FOUND', 'SCOPE_REQUIRED'])('shows the server\'s reason of a refused command as plain text: %s', (code) => {
			showRefusal(Object.assign(new Error('Bonus days are <b>already</b> planned for this time'), { status: 409, code }), 'Could not start the bonus days')

			expect(showError).toHaveBeenCalledWith('Bonus days are &lt;b&gt;already&lt;/b&gt; planned for this time')
		})

		it('hands the toast escaped text, so its one decode parses no markup (final review of CS-PLAN-091)', () => {
			const reason = 'Unknown field: <img src=x onerror="window.pwned=1"> & "more"'
			showRefusal(Object.assign(new Error(reason), { status: 400, code: 'VALIDATION_ERROR' }), 'Could not save the task')

			// What @nextcloud/dialogs does with a text toast: innerHTML, then the text.
			const decoded = document.createElement('div')
			decoded.innerHTML = showError.mock.calls[0][0]
			expect(decoded.querySelector('img')).toBeNull()
			expect(decoded.textContent).toBe(reason)
		})

		it('prefers the message the caller names for a code', () => {
			showRefusal(Object.assign(new Error('server text'), { status: 409, code: 'CONFLICT' }), 'Could not save the task', {
				CONFLICT: 'Someone else changed it',
			})

			expect(showError).toHaveBeenCalledWith('Someone else changed it')
		})

		it('asks to reload after a stale target and references anything unexpected', () => {
			showRefusal(Object.assign(new Error('stale'), { status: 409, code: 'STALE_TARGET' }), 'Could not put the task to sleep')
			showRefusal(new ApiError('Boom', 500, {}, 'SLEEP_TASK_ERROR'), 'Could not put the task to sleep')

			expect(showError).toHaveBeenNthCalledWith(1, 'The task changed since it was displayed. Reload and try again.')
			expect(showError).toHaveBeenNthCalledWith(2, 'Could not put the task to sleep (SLEEP_TASK_ERROR)')
		})

		it('references a coded refusal without a reason and an error without a code', () => {
			showRefusal(Object.assign(new Error(''), { status: 400, code: 'VALIDATION_ERROR' }), 'Could not change the date')
			showRefusal(new Error('Network'), 'Could not change the date')
			showRefusal(undefined, 'Could not change the date')

			expect(showError).toHaveBeenNthCalledWith(1, 'Could not change the date (VALIDATION_ERROR)')
			expect(showError).toHaveBeenNthCalledWith(2, 'Could not change the date')
			expect(showError).toHaveBeenNthCalledWith(3, 'Could not change the date')
		})
	})
})
