/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

export const API_PREFIX = '/apps/chorestrike'

const GENERIC_ERROR = () => t('chorestrike', 'Request failed')

export class ApiError extends Error {

	/**
	 * @param {string} message Error message from backend or generic fallback
	 * @param {number} [status] HTTP status code if available
	 * @param {object} [data] Full response data for diagnostics
	 * @param {string|null} [code] Stable backend error code (error.code) if available
	 */
	constructor(message, status, data, code = null) {
		super(message)
		this.name = 'ApiError'
		this.status = status
		this.code = code
		this.data = data
	}

}

/**
 * Core request function. Builds the canonical URL from API_PREFIX + path,
 * calls `@nextcloud/axios`, unwraps the { success, data, error } envelope,
 * and throws ApiError on any failure.
 *
 * @param {string} method HTTP method (get, post, put, patch, delete)
 * @param {string} path URL path relative to API_PREFIX (e.g. '/api/v1/house')
 * @param {object} [config] Additional axios config (data, params, headers, …)
 * @return {Promise<object>} Unwrapped response data
 * @throws {ApiError} When the request fails (HTTP non-2xx or success === false)
 */
async function request(method, path, config = {}) {
	const url = `${API_PREFIX}${path}`

	let response
	try {
		response = await axios({ method, url, ...config })
	} catch (error) {
		const backendMessage = error.response?.data?.error?.message
		const status = error.response?.status
		throw new ApiError(
			backendMessage || GENERIC_ERROR(),
			status,
			error.response?.data,
			error.response?.data?.error?.code ?? null,
		)
	}

	if (response.data?.success) {
		return response.data.data
	}

	const backendMessage = response.data?.error?.message
	throw new ApiError(
		backendMessage || GENERIC_ERROR(),
		response.status,
		response.data,
		response.data?.error?.code ?? null,
	)
}

/**
 * GET request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [config] Additional axios config (params, headers, …)
 * @return {Promise<object>} Unwrapped response data
 */
export function get(path, config) {
	return request('get', path, config)
}

/**
 * POST request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<object>} Unwrapped response data
 */
export function post(path, data, config) {
	return request('post', path, { ...config, data })
}

/**
 * PUT request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<object>} Unwrapped response data
 */
export function put(path, data, config) {
	return request('put', path, { ...config, data })
}

/**
 * PATCH request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<object>} Unwrapped response data
 */
export function patch(path, data, config) {
	return request('patch', path, { ...config, data })
}

/**
 * DELETE request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [config] Additional axios config
 * @return {Promise<object>} Unwrapped response data
 */
export function del(path, config) {
	return request('delete', path, config)
}

export const api = { get, post, put, patch, delete: del }

/**
 * GET the calling member's own preferences (CS-PLAN-070 WP5, C5h —
 * self-only surface; there is deliberately no foreign-preferences
 * endpoint).
 * @return {Promise<object>} Versioned own preference payload.
 */
export function fetchMyPreferences() {
	return get('/api/v1/preferences/me')
}

/**
 * PUT the calling member's own delivery preferences (version-aware,
 * receipt-protected; a stale expectedVersion answers 409 and the local
 * draft is preserved).
 * @param {object} payload Whitelisted fields + expectedVersion + commandId.
 * @return {Promise<object>} The fresh versioned preference payload.
 */
export function updateMyPreferences(payload) {
	return put('/api/v1/preferences/me', payload)
}

// Pause API (CS-PLAN-073 WP2 §3.5, consumed by the WP6 pause UI): the
// revisioned pause lifecycle with command UUIDs and expectedRevision.

/**
 * GET the viewer-safe pause list (bounded; pending personal requests are
 * self/owner data).
 * @return {Promise<Array>} Viewer-whitelisted pause entries.
 */
export function fetchPauses() {
	return get('/api/v1/pauses')
}

/**
 * POST a pause: personal for the caller (child → pending request, adult
 * → acknowledged) or household (owner only) when `scope: 'house'`.
 * @param {object} payload {commandId, startDate, endDate, reasonCode, scope?}.
 * @return {Promise<object>} Identity refs {pauseGroupId, revision, state}.
 */
export function createPause(payload) {
	return post('/api/v1/pauses', payload)
}

/**
 * POST the explicit retrospective-illness recognition (owner only).
 * @param {object} payload {commandId, subjectUserId, startDate, endDate}.
 * @return {Promise<object>} Identity refs.
 */
export function recognizeIllness(payload) {
	return post('/api/v1/pauses/recognize-illness', payload)
}

/**
 * POST the owner approval of a pending pause request.
 * @param {number} id Pause group id.
 * @param {object} payload {commandId, expectedRevision}.
 * @return {Promise<object>} Identity refs.
 */
export function approvePause(id, payload) {
	return post(`/api/v1/pauses/${id}/approve`, payload)
}

/**
 * POST the owner rejection of a pending pause request.
 * @param {number} id Pause group id.
 * @param {object} payload {commandId, expectedRevision}.
 * @return {Promise<object>} Identity refs.
 */
export function rejectPause(id, payload) {
	return post(`/api/v1/pauses/${id}/reject`, payload)
}

/**
 * PUT the next-day shortening/revocation of an acknowledged pause.
 * @param {number} id Pause group id.
 * @param {object} payload {commandId, endDate, expectedRevision}.
 * @return {Promise<object>} Identity refs.
 */
export function updatePause(id, payload) {
	return put(`/api/v1/pauses/${id}`, payload)
}

// Occurrence agreement API (CS-PLAN-073 WP2 §3.5, consumed by the WP6
// entry points): owner-recorded single-occurrence agreements. `{id}` is
// the public occurrence key `<scheduleRevisionId>:<ordinal>`.

/**
 * POST the owner-recorded single-occurrence amendment (date/handover).
 * @param {string} id Public occurrence key.
 * @param {object} payload {roomTaskId, commandId, occurrenceId, expectedVersion, selectionToken, consent, date?, participants?}.
 * @return {Promise<object>} Identity refs {occurrenceId, expectedVersion}.
 */
export function amendOccurrence(id, payload) {
	return post(`/api/v1/occurrences/${id}/amend`, payload)
}

/**
 * POST the agreed catch-up work appointment on the current miss.
 * @param {string} id Public occurrence key.
 * @param {object} payload {roomTaskId, commandId, occurrenceId, expectedVersion, selectionToken, consent, workDate, workZone?, participants?}.
 * @return {Promise<object>} Identity refs.
 */
export function catchUpOccurrence(id, payload) {
	return post(`/api/v1/occurrences/${id}/catch-up`, payload)
}

/**
 * POST the linked restart of a neutrally suspended interval predecessor.
 * @param {string} id Public occurrence key.
 * @param {object} payload {roomTaskId, commandId, occurrenceId, expectedVersion, consent, workDate}.
 * @return {Promise<object>} Identity refs.
 */
export function restartOccurrence(id, payload) {
	return post(`/api/v1/occurrences/${id}/restart`, payload)
}

export default api
