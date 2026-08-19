/**
 * Canonical shared `@nextcloud/axios` mock for the whole Jest suite
 * (wired via moduleNameMapper in jest.config.js).
 *
 * Production calls axios two ways (CS-PLAN-034):
 * - `src/services/api.js` calls the **default export** as a function
 *   (`axios({ method, url, ...config })`) and unwraps the
 *   `{ success, data }` envelope, so the default mock must be callable
 *   and resolve a success envelope.
 * - Some components (e.g. DailyTaskList.vue) call the named verbs
 *   (`axios.get(...)`) directly, so the verbs carry the same envelope.
 *
 * Specs override per test via `axios.mockResolvedValue(...)` (default
 * export) or `axios.get.mockResolvedValue(...)` (named verb).
 *
 * The verbs exist for axios-API parity, not because every one has a direct
 * caller: `patch` is exposed because `api.js` offers it, and `defaults`/
 * `interceptors` mirror the real axios surface for any consumer that
 * touches them.
 */
const envelope = () => Promise.resolve({ data: { success: true, data: {} } })

const axiosMock = jest.fn(envelope)
axiosMock.get = jest.fn(envelope)
axiosMock.post = jest.fn(envelope)
axiosMock.put = jest.fn(envelope)
axiosMock.patch = jest.fn(envelope)
axiosMock.delete = jest.fn(envelope)
axiosMock.defaults = {
	headers: {
		common: {},
		get: {},
		post: {},
		put: {},
		delete: {},
	},
}
axiosMock.interceptors = {
	request: {
		use: jest.fn(),
		eject: jest.fn(),
	},
	response: {
		use: jest.fn(),
		eject: jest.fn(),
	},
}

export default axiosMock
