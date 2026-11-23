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
 * Escape text for `showError`, which decodes its input once (innerHTML →
 * innerText) on an element of the live document: escaped, a server's
 * reason shows as the plain text it is, and no markup in it is ever
 * parsed — an `<img onerror>` would run during that decode.
 * @param {string} text Plain text.
 * @return {string} The text with its markup characters escaped.
 */
function escapeForToast(text) {
	return String(text)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;')
}

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
 * The refusals of derived windows (ADR-021 decision 4) say what happened:
 * done today already, or the cycle was closed meanwhile.
 * @param {unknown} error The caught error.
 */
export function showCompletionError(error) {
	showRefusal(error, t('chorestrike', 'Could not complete the task'), {
		COMPLETION_IN_FLIGHT: t('chorestrike', 'This task is already being completed with another work split. Check the split once it is done.'),
		SAME_DAY: t('chorestrike', 'This task was already done today. It can be done again from tomorrow.'),
		ALREADY_COMPLETED: t('chorestrike', 'This task was already completed. Reload to see what is next.'),
		BONUS_ALREADY_TODAY: t('chorestrike', 'This bonus task already counted for this person today. It counts again tomorrow.'),
		TASK_ENDED: t('chorestrike', 'This task has ended. Wake it up to close it.'),
	})
}

/**
 * The one failure toast of a refused command (RF-091-09). A code the
 * caller names gets its translated message; a task that changed meanwhile
 * says to reload; a refusal the server explains — a rule of the command, a
 * missing right, a conflict, a task one may no longer see — shows its
 * localized reason as plain text, never decoded as markup. Anything else
 * is the caller's message with the error reference.
 * @param {unknown} error The caught error.
 * @param {string} fallback Output of `t()` for an unexpected failure.
 * @param {Object<string, string>} [reasons] Translated messages by stable code.
 */
export function showRefusal(error, fallback, reasons = {}) {
	const code = error?.code
	if (typeof code === 'string' && reasons[code]) {
		showError(reasons[code])
		return
	}
	if (code === 'STALE_TARGET') {
		showError(t('chorestrike', 'The task changed since it was displayed. Reload and try again.'))
		return
	}
	if (['VALIDATION_ERROR', 'FORBIDDEN', 'CONFLICT', 'RESOURCE_NOT_FOUND', 'SCOPE_REQUIRED'].includes(code) && error?.message) {
		showError(escapeForToast(error.message))
		return
	}
	showErrorWithReference(fallback, error)
}

/**
 * The failure toast of a postponement (ADR-021 decision 6): the refusals
 * say which rule applied.
 * @param {unknown} error The caught error.
 */
export function showPostponeError(error) {
	showRefusal(error, t('chorestrike', 'Could not postpone the task'), {
		NOT_POSTPONABLE: t('chorestrike', 'This task cannot be postponed any further'),
		WINDOW_ENDED: t('chorestrike', 'An overdue task cannot be postponed'),
	})
}
