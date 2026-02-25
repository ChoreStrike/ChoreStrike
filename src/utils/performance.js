/**
 * Performance monitoring utility for ChoreStrike
 * Tracks metrics and helps identify bottlenecks
 */

class PerformanceMonitor {

	constructor() {
		this.metrics = new Map()
		this.timers = new Map()
		this.enabled = process.env.NODE_ENV !== 'production'
	}

	/**
	 * Start timing an operation
	 * @param {string} name - Metric name
	 */
	start(name) {
		if (!this.enabled) return
		this.timers.set(name, performance.now())
	}

	/**
	 * End timing and record metric
	 * @param {string} name - Metric name
	 * @param {object} metadata - Additional data
	 */
	end(name, metadata = {}) {
		if (!this.enabled) return
		const startTime = this.timers.get(name)
		if (!startTime) return

		const duration = performance.now() - startTime
		this.timers.delete(name)

		this.record(name, duration, metadata)
	}

	/**
	 * Record a metric
	 * @param {string} name - Metric name
	 * @param {number} value - Metric value
	 * @param {object} metadata - Additional data
	 */
	record(name, value, metadata = {}) {
		if (!this.metrics.has(name)) {
			this.metrics.set(name, [])
		}

		this.metrics.get(name).push({
			value,
			timestamp: Date.now(),
			...metadata,
		})

		// Keep only last 100 entries
		const entries = this.metrics.get(name)
		if (entries.length > 100) {
			entries.shift()
		}
	}

	/**
	 * Measure async function execution time
	 * @param {string} name - Metric name
	 * @param {Function} fn - Async function to measure
	 * @return {Promise<any>} - Function result
	 */
	async measureAsync(name, fn) {
		this.start(name)
		try {
			const result = await fn()
			this.end(name, { success: true })
			return result
		} catch (error) {
			this.end(name, { success: false, error: error.message })
			throw error
		}
	}

	/**
	 * Get metrics report
	 * @return {object} - Metrics summary
	 */
	getReport() {
		const report = {}

		for (const [name, entries] of this.metrics) {
			if (entries.length === 0) continue

			const values = entries.map(e => e.value)
			report[name] = {
				count: entries.length,
				avg: values.reduce((a, b) => a + b, 0) / values.length,
				min: Math.min(...values),
				max: Math.max(...values),
				last: values[values.length - 1],
			}
		}

		return report
	}

	/**
	 * Log metrics to console
	 */
	log() {
		if (!this.enabled) return
		const report = this.getReport()
		// eslint-disable-next-line no-console
		console.log('[Performance]', report)
	}

	/**
	 * Clear all metrics
	 */
	clear() {
		this.metrics.clear()
		this.timers.clear()
	}

}

// Singleton instance
export const perfMonitor = new PerformanceMonitor()

/**
 * Vue composable for performance tracking
 * @return {object} - Performance methods
 */
export function usePerformance() {
	return {
		start: (name) => perfMonitor.start(name),
		end: (name, metadata) => perfMonitor.end(name, metadata),
		measureAsync: (name, fn) => perfMonitor.measureAsync(name, fn),
	}
}

/**
 * Debounce function for performance optimization
 * @param {Function} fn - Function to debounce
 * @param {number} delay - Delay in milliseconds
 * @return {Function} - Debounced function
 */
export function debounce(fn, delay = 300) {
	let timeoutId
	return function debounced(...args) {
		clearTimeout(timeoutId)
		timeoutId = setTimeout(() => fn.apply(this, args), delay)
	}
}

/**
 * Throttle function for performance optimization
 * @param {Function} fn - Function to throttle
 * @param {number} limit - Limit in milliseconds
 * @return {Function} - Throttled function
 */
export function throttle(fn, limit = 100) {
	let inThrottle
	return function throttled(...args) {
		if (!inThrottle) {
			fn.apply(this, args)
			inThrottle = true
			setTimeout(() => { inThrottle = false }, limit)
		}
	}
}

/**
 * Request idle callback wrapper with fallback
 * @param {Function} callback - Function to execute
 * @param {object} options - Options
 */
export function requestIdle(callback, options = {}) {
	if ('requestIdleCallback' in window) {
		window.requestIdleCallback(callback, options)
	} else {
		setTimeout(callback, 1)
	}
}
