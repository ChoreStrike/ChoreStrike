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
 * Mock for `loadTranslations`. The real implementation fetches the bundle for
 * the current language; the mock is a configurable jest.fn (defaulting to a
 * resolved empty bundle) so `i18n.spec.js` and any other importer can assert
 * the call and control success/failure. Default export shape keeps it usable
 * as a plain jest.fn().
 */
export const loadTranslations = jest.fn(() => Promise.resolve({ translations: {} }))

