/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

/**
 * A client idempotency UUID (CS-PLAN-072 WP1): one fresh UUID per logical
 * command, kept across uncertain retries so the server replays its
 * receipt. Crypto RNG when available, otherwise a RFC 4122 v4 fallback.
 * @return {string} Canonical lowercase UUID.
 */
export function generateCommandId() {
	const webCrypto = globalThis.crypto
	if (webCrypto && typeof webCrypto.randomUUID === 'function') {
		return webCrypto.randomUUID()
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = Math.random() * 16 | 0
		const value = char === 'x' ? random : (random & 0x3 | 0x8)
		return value.toString(16)
	})
}

/**
 * The UUID of a logical command (RF-072-WP1-07): a retry of the same shown
 * target reuses it, so the server replays the receipt of a command whose
 * answer was lost.
 * @param {Object<string, string>} pending Command key → UUID, as a store keeps it.
 * @param {string} commandKey The command and the target it was sent for.
 * @return {{commandId: string, pending: Object<string, string>}} The UUID and the map to keep.
 */
export function rememberCommand(pending, commandKey) {
	if (pending[commandKey]) {
		return { commandId: pending[commandKey], pending }
	}
	const commandId = generateCommandId()
	return { commandId, pending: { ...pending, [commandKey]: commandId } }
}

/**
 * A definite answer (any status below 500) closes the command; an
 * uncertain failure — no answer or a 5xx, where the server may have
 * committed — keeps its UUID for the retry.
 * @param {Object<string, string>} pending Command key → UUID.
 * @param {string} commandKey The command and its target.
 * @param {?object} [error] The failure, absent on success.
 * @return {Object<string, string>} The map to keep.
 */
export function settleCommand(pending, commandKey, error = null) {
	if (error && (error.status === undefined || error.status >= 500)) {
		return pending
	}
	const rest = { ...pending }
	delete rest[commandKey]
	return rest
}
