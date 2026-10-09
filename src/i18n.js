/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { getLanguage, register } from '@nextcloud/l10n'
import german from '../l10n/de.json'

export const APP_ID = 'chorestrike'

/**
 * Register the supported user language without a static JSON HTTP request.
 * AIO can deny l10n JSON while serving app JavaScript normally. Bundling the
 * canonical catalog also makes translation registration synchronous at startup.
 */
export function initI18n() {
	const language = (getLanguage() || '').replaceAll('_', '-').split('-')[0].toLowerCase()
	if (language === 'de') {
		register(APP_ID, german.translations)
	}
}
