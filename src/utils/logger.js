/**
 * Shared frontend logger for ChoreStrike.
 */
const APP_PREFIX = '[ChoreStrike]'
const isDevelopment = process.env.NODE_ENV !== 'production'

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

	console.debug(formatMessage('debug', message), ...normalizeContext(context))
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

	console.info(formatMessage('info', message), ...normalizeContext(context))
}

/**
 * Write a warning log.
 *
 * @param {string} message Message to log.
 * @param {unknown} [context] Optional diagnostic context.
 */
function warn(message, context) {
	console.warn(formatMessage('warn', message), ...normalizeContext(context))
}

/**
 * Write an error log.
 *
 * @param {string} message Message to log.
 * @param {unknown} [error] Optional error object.
 * @param {unknown} [context] Optional diagnostic context.
 */
function error(message, error, context) {
	const payload = []

	if (error !== undefined) {
		payload.push(error)
	}

	if (context !== undefined) {
		payload.push(context)
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
