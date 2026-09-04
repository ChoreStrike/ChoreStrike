/**
 * Shared frontend logger for ChoreStrike.
 */
const APP_PREFIX = '[ChoreStrike]'
const isDevelopment = process.env.NODE_ENV !== 'production'

/**
 * Redact ApiError objects before they reach a log sink (CS-PLAN-061 §3.6):
 * only status, stable error code, and the backend message survive — the
 * raw response body (member display names, invite emails, …) is stripped.
 *
 * @param {unknown} value Candidate value.
 * @return {unknown} The redacted value or the original.
 */
function redact(value) {
	if (value && typeof value === 'object' && value.name === 'ApiError') {
		return {
			name: 'ApiError',
			status: value.status,
			code: value.code,
			message: value.message,
		}
	}
	return value
}

/**
 * Build a stable log prefix.
 *
 * @param {string} level Log level name.
 * @param {string} message Message to log.
 * @return {string}
 */
function formatMessage(level, message) {
	return `${APP_PREFIX} [${level}] ${message}`
}

/**
 * Normalize optional context into a loggable shape.
 *
 * @param {unknown} context Optional diagnostic context.
 * @return {unknown[]}
 */
function normalizeContext(context) {
	if (context === undefined) {
		return []
	}

	return [context]
}

/**
 * Write a development-only debug log.
 *
 * @param {string} message Message to log.
 * @param {unknown} [context] Optional diagnostic context.
 */
function debug(message, context) {
	if (!isDevelopment) {
		return
	}

	console.debug(formatMessage('debug', message), ...normalizeContext(redact(context)))
}

/**
 * Write an info log.
 *
 * @param {string} message Message to log.
 * @param {unknown} [context] Optional diagnostic context.
 */
function info(message, context) {
	if (!isDevelopment) {
		return
	}

	console.info(formatMessage('info', message), ...normalizeContext(redact(context)))
}

/**
 * Write a warning log.
 *
 * @param {string} message Message to log.
 * @param {unknown} [context] Optional diagnostic context.
 */
function warn(message, context) {
	console.warn(formatMessage('warn', message), ...normalizeContext(redact(context)))
}

/**
 * Write an error log. ApiError objects are redacted to
 * {name, status, code, message} — no response bodies.
 *
 * @param {string} message Message to log.
 * @param {unknown} [error] Optional error object.
 * @param {unknown} [context] Optional diagnostic context.
 */
function error(message, error, context) {
	const payload = []

	if (error !== undefined) {
		payload.push(redact(error))
	}

	if (context !== undefined) {
		payload.push(redact(context))
	}

	console.error(formatMessage('error', message), ...payload)
}

export const logger = {
	debug,
	info,
	warn,
	error,
}

export default logger
