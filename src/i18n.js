/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { loadTranslations } from '@nextcloud/l10n'
import { logger } from './utils/logger.js'

export const APP_ID = 'chorestrike'

/**
 * Upper bound on the translation-bundle fetch.
 *
 * `@nextcloud/l10n`'s `loadTranslations` uses a bare `fetch()` with no timeout.
 * Since `initI18n` is awaited before `app.mount()` in `main.js`, an unanswered
 * fetch would otherwise block the UI forever (blank container, no recovery).
 * If the load exceeds this budget we fall back to English source strings.
 */
export const LOAD_TIMEOUT_MS = 5000

/**
 * Load the translation bundle for the current user's language.
 *
 * `@nextcloud/l10n` does not auto-load an app's bundle — the app has to request
 * it. Without this call `translate()` finds nothing in the global registry and
 * falls back to the English source string, so the UI stays English even when the
 * Nextcloud user language is German.
 *
 * `loadTranslations` is idempotent (the bundle is cached in the global registry),
 * short-circuits for English (the source language), and fetches only the
 * `<lang>.json` matching the current user. Loading is bounded by
 * `LOAD_TIMEOUT_MS`; on timeout or failure it is non-fatal — the UI then renders
 * English source strings rather than blocking startup.
 *
 * @return {Promise<void>} Resolves once the bundle is loaded, the load timed out, or the load failed gracefully.
 */
export async function initI18n() {
	try {
		await Promise.race([
			loadTranslations(APP_ID),
			new Promise((resolve, reject) => setTimeout(
				() => reject(new Error('translation load timed out')),
				LOAD_TIMEOUT_MS,
			)),
		])
	} catch (error) {
		logger.warn(
			'Could not load ChoreStrike translations; UI falls back to English source strings',
			{ error, module: 'i18n' },
		)
	}
}
