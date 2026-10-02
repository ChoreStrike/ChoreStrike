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
import {
	api,
	get,
	post,
	put,
	patch,
	del,
	ApiError,
	API_PREFIX,
	setCatalogEnabled,
	fetchCatalog,
	createCatalogItem,
	updateCatalogItem,
	fetchRewardRequests,
	fetchRewardRequest,
	createRewardRequest,
	approveRewardRequest,
	rejectRewardRequest,
	cancelRewardRequest,
	fulfillRewardRequest,
	correctRewardRequest,
} from '../../../src/services/api.js'

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

		it('throws ApiError with backend message and status 403 for authorization denials', async () => {
			// Regression for the OCS-envelope transport bug (CS-PLAN-020):
			// a backend 403 auth-denial must surface as ApiError with the
			// backend message and a meaningful status, not be swallowed as a
			// 2xx "Request failed" (the symptom when OCSController re-wrapped
			// denials into a 200 envelope).
			const axiosError = new Error('Request failed with status code 403')
			axiosError.response = {
				status: 403,
				data: {
					success: false,
					error: { code: 'FORBIDDEN', message: 'Only house owners can create admin-only tasks' },
				},
			}
			axios.mockRejectedValue(axiosError)

			try {
				await post('/api/v1/tasks', { visibilityType: 'admin-only' })
				throw new Error('Expected post() to throw ApiError')
			} catch (error) {
				expect(error).toBeInstanceOf(ApiError)
				expect(error.message).toBe('Only house owners can create admin-only tasks')
				expect(error.status).toBe(403)
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

	describe('reward catalog & request wrappers (WP8 §3.2 on docs/api/rewards.md)', () => {
		it('PUTs the narrow versioned catalog gate writer', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { catalogEnabled: true } } })
			await setCatalogEnabled({ catalogEnabled: true, expectedVersion: 4, commandId: 'cmd-1' })

			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'put',
				url: '/apps/chorestrike/api/v1/house/catalog',
				data: { catalogEnabled: true, expectedVersion: 4, commandId: 'cmd-1' },
			}))
		})

		it('GETs catalog pages with scope/cursor/limit params', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { catalogEnabled: true, items: [] } } })
			await fetchCatalog({ scope: 'admin', cursor: 12, limit: 50 })

			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'get',
				url: '/apps/chorestrike/api/v1/rewards',
				params: { scope: 'admin', cursor: 12, limit: 50 },
			}))
		})

		it('POSTs new catalog items and PUTs updates (never DELETE)', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { id: 1 } } })
			await createCatalogItem({ title: 'Zoo', priceMinor: 500, commandId: 'cmd-2' })
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'post',
				url: '/apps/chorestrike/api/v1/rewards',
				data: { title: 'Zoo', priceMinor: 500, commandId: 'cmd-2' },
			}))

			await updateCatalogItem(7, { archived: true, expectedVersion: 3, commandId: 'cmd-3' })
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'put',
				url: '/apps/chorestrike/api/v1/rewards/7',
				data: { archived: true, expectedVersion: 3, commandId: 'cmd-3' },
			}))
		})

		it('GETs request pages with scope/state/cursor params and the detail with audit', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { requests: [] } } })
			await fetchRewardRequests({ scope: 'house', state: 'pending', cursor: 40, limit: 50 })
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'get',
				url: '/apps/chorestrike/api/v1/reward-requests',
				params: { scope: 'house', state: 'pending', cursor: 40, limit: 50 },
			}))

			await fetchRewardRequest(9)
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'get',
				url: '/apps/chorestrike/api/v1/reward-requests/9',
			}))
		})

		it('POSTs the frozen-terms request create', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { id: 5 } } })
			await createRewardRequest({ itemId: 2, itemVersion: 1, priceMinor: 700, commandId: 'cmd-4' })
			expect(axios).toHaveBeenCalledWith(expect.objectContaining({
				method: 'post',
				url: '/apps/chorestrike/api/v1/reward-requests',
				data: { itemId: 2, itemVersion: 1, priceMinor: 700, commandId: 'cmd-4' },
			}))
		})

		it('POSTs each lifecycle command on its own sub-route', async () => {
			axios.mockResolvedValue({ data: { success: true, data: { id: 5 } } })
			await approveRewardRequest(5, { commandId: 'c', expectedVersion: 0 })
			await rejectRewardRequest(5, { commandId: 'c', expectedVersion: 0, reasonCode: 'not_needed' })
			await cancelRewardRequest(5, { commandId: 'c', expectedVersion: 1 })
			await fulfillRewardRequest(5, { commandId: 'c', expectedVersion: 1, delivered: true })
			await correctRewardRequest(5, {
				commandId: 'c', expectedVersion: 2, notDelivered: true, reasonCode: 'not_delivered', settlementEntryId: 88,
			})

			const urls = axios.mock.calls.map(call => call[0].url)
			expect(urls).toEqual([
				'/apps/chorestrike/api/v1/reward-requests/5/approve',
				'/apps/chorestrike/api/v1/reward-requests/5/reject',
				'/apps/chorestrike/api/v1/reward-requests/5/cancel',
				'/apps/chorestrike/api/v1/reward-requests/5/fulfill',
				'/apps/chorestrike/api/v1/reward-requests/5/correct',
			])
			const correctCall = axios.mock.calls[4][0]
			expect(correctCall.data).toEqual({
				commandId: 'c', expectedVersion: 2, notDelivered: true, reasonCode: 'not_delivered', settlementEntryId: 88,
			})
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

		it('exposes the backend error code as a first-class field (CS-PLAN-061 §3.9)', async () => {
			const axiosError = new Error('Request failed with status code 403')
			axiosError.response = {
				status: 403,
				data: {
					success: false,
					error: { code: 'CANNOT_REMOVE_OWNER', message: 'Cannot remove house owner' },
				},
			}
			axios.mockRejectedValue(axiosError)

			try {
				await del('/api/v1/users/42')
				throw new Error('Expected del() to throw ApiError')
			} catch (error) {
				expect(error).toBeInstanceOf(ApiError)
				expect(error.code).toBe('CANNOT_REMOVE_OWNER')
				expect(error.status).toBe(403)
			}
		})

		it('exposes the error code from non-2xx-in-envelope failures too', async () => {
			axios.mockResolvedValue({
				data: { success: false, error: { code: 'MINIGAME_RATE_LIMITED', message: 'Slow down' } },
			})

			try {
				await post('/api/v1/minigames/snake/scores', { score: 10 })
				throw new Error('Expected post() to throw ApiError')
			} catch (error) {
				expect(error).toBeInstanceOf(ApiError)
				expect(error.code).toBe('MINIGAME_RATE_LIMITED')
			}
		})

		it('defaults code to null when the backend sends none', () => {
			const err = new ApiError('test', 500)
			expect(err.code).toBeNull()
		})
	})
})