import { getLanguage, register, loadTranslations } from '@nextcloud/l10n'
import german from '../../l10n/de.json'
import { initI18n, APP_ID } from '@/i18n'

describe('initI18n', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it.each(['de', 'de-DE', 'de-AT', 'de_CH'])('registers the actual German catalog synchronously for %s', language => {
		getLanguage.mockReturnValue(language)
		initI18n()
		expect(register).toHaveBeenCalledTimes(1)
		expect(register).toHaveBeenCalledWith(APP_ID, german.translations)
		expect(loadTranslations).not.toHaveBeenCalled()
	})

	it.each(['en', 'en-GB', 'fr', undefined, null])('keeps English source fallback without forcing German for %s', language => {
		getLanguage.mockReturnValue(language)
		initI18n()
		expect(register).not.toHaveBeenCalled()
		expect(loadTranslations).not.toHaveBeenCalled()
	})
})
