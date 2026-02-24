/**
 * Mock for @nextcloud/l10n translation function
 */
export function translate(app, string) {
	return string;
}

export function translatePlural(app, singular, plural, count) {
	return count === 1 ? singular : plural;
}
