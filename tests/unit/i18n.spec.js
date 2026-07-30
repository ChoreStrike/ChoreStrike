import { loadTranslations } from '@nextcloud/l10n'

jest.mock('@/utils/logger', () => ({
	logger: {
		warn: jest.fn(),
		info: jest.fn(),
		error: jest.fn(),
	},
}))

const logger = require('@/utils/logger').logger
const { initI18n, APP_ID, LOAD_TIMEOUT_MS } = require('@/i18n')

describe('initI18n', () => {
	beforeEach(() => {
		loadTranslations.mockClear()
		logger.warn.mockClear()
		loadTranslations.mockResolvedValue({ translations: {} })
	})

	it('loads the chorestrike translation bundle for the current language', async () => {
		await initI18n()

		expect(loadTranslations).toHaveBeenCalledTimes(1)
		expect(loadTranslations).toHaveBeenCalledWith(APP_ID)
	})

	it('resolves without throwing when the bundle load fails (non-fatal)', async () => {
		loadTranslations.mockRejectedValueOnce(new Error('network down'))

		await expect(initI18n()).resolves.toBeUndefined()
	})

	it('logs a warning when the bundle load fails', async () => {
		loadTranslations.mockRejectedValueOnce(new Error('network down'))

		await initI18n()

		expect(logger.warn).toHaveBeenCalledTimes(1)
	})

	it('does not warn on a successful load', async () => {
		await initI18n()

		expect(logger.warn).not.toHaveBeenCalled()
	})

	it('falls back gracefully when the bundle load times out (does not hang startup)', async () => {
		jest.useFakeTimers()
		try {
			// Never-resolving pending fetch simulates a stalled network.
			loadTranslations.mockReturnValueOnce(new Promise(() => {}))

			const promise = initI18n()

			// Fast-forward past the timeout budget. The race rejects with the
			// timeout error, the catch logs a warning, and startup may proceed.
			jest.advanceTimersByTime(LOAD_TIMEOUT_MS + 10)
			await expect(promise).resolves.toBeUndefined()

			expect(logger.warn).toHaveBeenCalledTimes(1)
		} finally {
			jest.useRealTimers()
		}
	})
})
