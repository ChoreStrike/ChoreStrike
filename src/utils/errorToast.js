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
 * The server's localized reason for a refused change, shown next to the
 * field. Only a coded refusal has one; anything else stays a generic toast.
 * @param {unknown} error The caught error.
 * @return {string} The reason, or '' when there is none.
 */
export function refusalReason(error) {
	return ['CONFLICT', 'STALE_TARGET', 'VALIDATION_ERROR'].includes(error?.code) && error?.message ? error.message : ''
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
	if (error?.code === 'COMPLETION_IN_FLIGHT') {
		showError(t('chorestrike', 'This task is already being completed with another work split. Check the split once it is done.'))
		return
	}
	if (error?.code === 'SAME_DAY') {
		showError(t('chorestrike', 'This task was already done today. It can be done again from tomorrow.'))
		return
	}
	if (error?.code === 'ALREADY_COMPLETED') {
		showError(t('chorestrike', 'This task was already completed. Reload to see what is next.'))
		return
	}
	if (error?.code === 'BONUS_ALREADY_TODAY') {
		showError(t('chorestrike', 'This bonus task already counted for this person today. It counts again tomorrow.'))
		return
	}
	if (error?.code === 'TASK_ENDED') {
		showError(t('chorestrike', 'This task has ended. Wake it up to close it.'))
		return
	}
	showErrorWithReference(t('chorestrike', 'Could not complete the task'), error)
}

/**
 * The failure toast of a command whose refusals arrive with the server's
 * localized reason (a rule of the command, a missing right, a conflict):
 * the reason is shown as plain text, never decoded as markup. Anything
 * else is the caller's message with the error reference.
 * @param {unknown} error The caught error.
 * @param {string} fallback Output of `t()` for an unexpected failure.
 */
export function showServerRefusal(error, fallback) {
	if (error?.code === 'STALE_TARGET') {
		showError(t('chorestrike', 'The task changed since it was displayed. Reload and try again.'))
		return
	}
	if (['VALIDATION_ERROR', 'FORBIDDEN', 'CONFLICT'].includes(error?.code) && error?.message) {
		showError(error.message, { isHTML: false })
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
	const reasons = {
		NOT_POSTPONABLE: t('chorestrike', 'This task cannot be postponed any further'),
		WINDOW_ENDED: t('chorestrike', 'An overdue task cannot be postponed'),
		STALE_TARGET: t('chorestrike', 'The task changed since it was displayed. Reload and try again.'),
	}
	if (reasons[error?.code]) {
		showError(reasons[error.code])
		return
	}
	showErrorWithReference(t('chorestrike', 'Could not postpone the task'), error)
}

/**
 * The failure toast of a date change (ADR-021 decision 9). A refused day
 * and a missing right arrive with the server's localized reason; it is
 * shown as plain text, never decoded as markup.
 * @param {unknown} error The caught error.
 */
export function showHistoryError(error) {
	if (['VALIDATION_ERROR', 'FORBIDDEN', 'CONFLICT'].includes(error?.code) && error?.message) {
		showError(error.message, { isHTML: false })
		return
	}
	showErrorWithReference(t('chorestrike', 'Could not change the date'), error)
}
