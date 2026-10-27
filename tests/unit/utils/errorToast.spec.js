/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { showError } from '@nextcloud/dialogs'
import { errorReference, showCompletionError, showErrorWithReference } from '@/utils/errorToast.js'
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

	// D-088-18: work before its window opens is explained, not a bare failure.
	it('explains a completion before the time window opens', () => {
		showCompletionError(Object.assign(new Error('not open'), { code: 'NOT_OPEN_YET' }))

		expect(showError).toHaveBeenCalledWith('Not open yet — this task can be completed within its time window.')
	})

	it('shows the referenced failure for any other completion error', () => {
		showCompletionError(new ApiError('Conflict', 409, {}, 'CONFLICT'))

		expect(showError).toHaveBeenCalledWith('Could not complete the task (CONFLICT)')
	})
})
