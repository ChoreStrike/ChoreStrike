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
