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
	 */
	constructor(message, status, data) {
		super(message)
		this.name = 'ApiError'
		this.status = status
		this.data = data
	}

}

/**
 * Core request function. Builds the canonical URL from API_PREFIX + path,
 * calls @nextcloud/axios, unwraps the { success, data, error } envelope,
 * and throws ApiError on any failure.
 *
 * @param {string} method HTTP method (get, post, put, patch, delete)
 * @param {string} path URL path relative to API_PREFIX (e.g. '/api/v1/house')
 * @param {object} [config={}] Additional axios config (data, params, headers, …)
 * @return {Promise<any>} Unwrapped response data
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
		throw new ApiError(backendMessage || GENERIC_ERROR(), status, error.response?.data)
	}

	if (response.data?.success) {
		return response.data.data
	}

	const backendMessage = response.data?.error?.message
	throw new ApiError(backendMessage || GENERIC_ERROR(), response.status, response.data)
}

/**
 * GET request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [config] Additional axios config (params, headers, …)
 * @return {Promise<any>} Unwrapped response data
 */
export function get(path, config) {
	return request('get', path, config)
}

/**
 * POST request
 * @param {string} path URL path relative to API_PREFIX
 * @param {*} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<any>} Unwrapped response data
 */
export function post(path, data, config) {
	return request('post', path, { ...config, data })
}

/**
 * PUT request
 * @param {string} path URL path relative to API_PREFIX
 * @param {*} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<any>} Unwrapped response data
 */
export function put(path, data, config) {
	return request('put', path, { ...config, data })
}

/**
 * PATCH request
 * @param {string} path URL path relative to API_PREFIX
 * @param {*} [data] Request body
 * @param {object} [config] Additional axios config
 * @return {Promise<any>} Unwrapped response data
 */
export function patch(path, data, config) {
	return request('patch', path, { ...config, data })
}

/**
 * DELETE request
 * @param {string} path URL path relative to API_PREFIX
 * @param {object} [config] Additional axios config
 * @return {Promise<any>} Unwrapped response data
 */
export function del(path, config) {
	return request('delete', path, config)
}

export const api = { get, post, put, patch, delete: del }

export default api
