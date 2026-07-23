/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, it, expect, jest, beforeEach } from '@jest/globals'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: jest.fn(),
}))

jest.mock('@nextcloud/l10n', () => ({
	translate: jest.fn((app, message) => `translated:${message}`),
}))

import axios from '@nextcloud/axios'
import { api, get, post, put, patch, del, ApiError, API_PREFIX } from '../../../src/services/api.js'

describe('api.js', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	describe('API_PREFIX', () => {
		it('is /apps/chorestrike', () => {
			expect(API_PREFIX).toBe('/apps/chorestrike')
		})
	})

	describe('request (via get)', () => {
		it('builds URL from API_PREFIX + path', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { ok: true } } })

			await get('/api/v1/house')

			expect(axios).toHaveBeenCalledWith(
				expect.objectContaining({ method: 'get', url: '/apps/chorestrike/api/v1/house' }),
			)
		})

		it('returns unwrapped data on success', async () => {
			const payload = { name: 'My House', id: 1 }
			axios.mockResolvedValue({ data: { success: true, data: payload } })

			const result = await get('/api/v1/house')

			expect(result).toEqual(payload)
		})

		it('throws ApiError when success is false with backend message', async () => {
			axios.mockResolvedValue({
				data: { success: false, error: { message: 'House not found' } },
			})

			await expect(get('/api/v1/house')).rejects.toThrow(ApiError)
			await expect(get('/api/v1/house')).rejects.toThrow('House not found')
		})

		it('throws ApiError with generic fallback when success is false without backend message', async () => {
			axios.mockResolvedValue({ data: { success: false, error: {} } })

			await expect(get('/api/v1/house')).rejects.toThrow(ApiError)
			await expect(get('/api/v1/house')).rejects.toThrow('translated:Request failed')
		})

		it('throws ApiError when response.data is missing success field', async () => {
			axios.mockResolvedValue({ data: {} })

			await expect(get('/api/v1/house')).rejects.toThrow(ApiError)
			await expect(get('/api/v1/house')).rejects.toThrow('translated:Request failed')
		})

		it('throws ApiError on HTTP error with backend message', async () => {
			const axiosError = new Error('Network error')
			axiosError.response = {
				status: 500,
				data: { success: false, error: { message: 'Internal server error' } },
			}
			axios.mockRejectedValue(axiosError)

			try {
				await get('/api/v1/house')
			} catch (error) {
				expect(error).toBeInstanceOf(ApiError)
				expect(error.message).toBe('Internal server error')
				expect(error.status).toBe(500)
			}
		})

		it('throws ApiError on HTTP error with generic fallback when no backend message', async () => {
			const axiosError = new Error('Network error')
			axiosError.response = {
				status: 502,
				data: {},
			}
			axios.mockRejectedValue(axiosError)

			try {
				await get('/api/v1/house')
			} catch (error) {
				expect(error).toBeInstanceOf(ApiError)
				expect(error.message).toBe('translated:Request failed')
				expect(error.status).toBe(502)
			}
		})

		it('passes config through to axios', async () => {
			axios.mockResolvedValue({ data: { success: true, data: null } })
			const config = { params: { limit: 10 }, headers: { 'X-Custom': '1' } }

			await get('/api/v1/leaderboard', config)

			expect(axios).toHaveBeenCalledWith(
				expect.objectContaining({
					method: 'get',
					url: '/apps/chorestrike/api/v1/leaderboard',
					params: { limit: 10 },
					headers: { 'X-Custom': '1' },
				}),
			)
		})
	})

	describe('method verb shims', () => {
		it('get calls axios with method get', async () => {
			axios.mockResolvedValue({ data: { success: true, data: null } })
			await get('/api/v1/test')
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({ method: 'get' }))
		})

		it('post calls axios with method post and data', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { id: 1 } } })
			await post('/api/v1/tasks', { name: 'Test' })
			expect(axios).toHaveBeenCalledWith(
				expect.objectContaining({ method: 'post', data: { name: 'Test' } }),
			)
		})

		it('put calls axios with method put and data', async () => {
			axios.mockResolvedValue({ data: { success: true, data: null } })
			await put('/api/v1/house', { name: 'New' })
			expect(axios).toHaveBeenCalledWith(
				expect.objectContaining({ method: 'put', data: { name: 'New' } }),
			)
		})

		it('patch calls axios with method patch and data', async () => {
			axios.mockResolvedValue({ data: { success: true, data: null } })
			await patch('/api/v1/partial', { key: 'value' })
			expect(axios).toHaveBeenCalledWith(
				expect.objectContaining({ method: 'patch', data: { key: 'value' } }),
			)
		})

		it('delete calls axios with method delete', async () => {
			axios.mockResolvedValue({ data: { success: true, data: null } })
			await del('/api/v1/items/1')
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({ method: 'delete' }))
		})
	})

	describe('api default export', () => {
		it('exposes all methods', () => {
			expect(api.get).toBe(get)
			expect(api.post).toBe(post)
			expect(api.put).toBe(put)
			expect(api.patch).toBe(patch)
			expect(api.delete).toBe(del)
		})
	})

	describe('ApiError', () => {
		it('is instance of Error', () => {
			const err = new ApiError('test', 400, { detail: 'bad' })
			expect(err).toBeInstanceOf(Error)
			expect(err.name).toBe('ApiError')
			expect(err.status).toBe(400)
			expect(err.data).toEqual({ detail: 'bad' })
		})
	})
})