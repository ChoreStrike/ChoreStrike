/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

// Stable machine codes only (`ControllerErrorFormatter` codes, client codes
// such as STALE_TARGET) — never a free-text message.
const CODE_PATTERN = /^[A-Z][A-Z0-9_]{1,63}$/

/**
 * Short diagnostic reference for a failed request (CS-PLAN-084 WP-F): the
 * stable API `code`, else the HTTP status, else `NETWORK` for an API call
 * that received no response. Never a raw message or stack data.
 * @param {unknown} error The caught error.
 * @return {?string} The reference, or null when none is known.
 */
export function errorReference(error) {
	const code = error?.code
	if (typeof code === 'string' && CODE_PATTERN.test(code)) {
		return code
	}
	if (Number.isInteger(error?.status)) {
		return `HTTP ${error.status}`
	}
	if (error?.name === 'ApiError') {
		return 'NETWORK'
	}
	return null
}

/**
 * Show a translated failure toast with the error reference appended, e.g.
 * "Could not complete the task (CONFLICT)", so a reported failure can be
 * matched to the server log.
 * @param {string} message Output of `t()` — already HTML-escaped; never raw
 *   or server-provided text.
 * @param {unknown} error The caught error.
 */
export function showErrorWithReference(message, error) {
	const reference = errorReference(error)
	// `showError` decodes its text once (innerHTML → innerText), so it needs
	// escaped input: `message` is escaped by the caller's `t()` and the
	// reference is limited to [A-Z0-9_ ]; escaping again would show `&amp;`.
	showError(reference
		? t('chorestrike', '{message} ({reference})', { message, reference }, undefined, { escape: false })
		: message)
}

/**
 * The failure toast of a completion attempt. A refused join behind an
 * in-flight completion with another work split (CR-084-02) gets its own
 * hint: the first completion usually succeeds, only the split is not used.
 * @param {unknown} error The caught error.
 */
export function showCompletionError(error) {
	if (error?.code === 'COMPLETION_IN_FLIGHT') {
		showError(t('chorestrike', 'This task is already being completed with another work split. Check the split once it is done.'))
		return
	}
	showErrorWithReference(t('chorestrike', 'Could not complete the task'), error)
}
