/**
 * Mock for @nextcloud/l10n translation function
 *
 * Mirrors the real Nextcloud `t()` / `translate()` contract: the optional
 * third argument carries placeholder values that get substituted into the
 * string as `{name}` tokens. Tests that don't pass placeholders are
 * unaffected; tests that do (e.g. "{completed} of {due}") now see the
 * interpolated copy instead of the raw token.
 */
export function translate(app, string, vars) {
	if (!vars || typeof vars !== 'object') {
		return string
	}
	return string.replace(/\{(\w+)\}/g, (match, key) => {
		return Object.prototype.hasOwnProperty.call(vars, key)
			? String(vars[key])
			: match
	})
}

export function translatePlural(app, singular, plural, count) {
	return count === 1 ? singular : plural
}

/**
 * Locale + calendar-name mocks. Configurable jest.fn()s so specs can pin
 * formatter output per locale (e.g. mockReturnValueOnce('de')). Defaults
 * mirror an English Nextcloud instance.
 */
export const getLocale = jest.fn(() => 'en')
export const getLanguage = jest.fn(() => 'en')
export const register = jest.fn()

export const getDayNames = jest.fn(() => [
	'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday',
])
export const getDayNamesShort = jest.fn(() => [
	'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat',
])
export const getDayNamesMin = jest.fn(() => [
	'Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa',
])
export const getMonthNamesShort = jest.fn(() => [
	'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
])

/**
 * Mock for `loadTranslations`. The real implementation fetches the bundle for
 * the current language; the mock is a configurable jest.fn (defaulting to a
 * resolved empty bundle) so `i18n.spec.js` and any other importer can assert
 * the call and control success/failure. Default export shape keeps it usable
 * as a plain jest.fn().
 */
export const loadTranslations = jest.fn(() => Promise.resolve({ translations: {} }))
