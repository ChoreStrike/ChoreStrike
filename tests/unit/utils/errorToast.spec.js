/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, beforeEach } from '@jest/globals'
import { showError } from '@nextcloud/dialogs'
import { errorReference, showErrorWithReference } from '@/utils/errorToast.js'
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
})
