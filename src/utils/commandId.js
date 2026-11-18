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
