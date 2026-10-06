/** Publish counts only: no test titles, errors, requests, attachments or traces. */
const fs = require('node:fs')
const path = require('node:path')

class SummaryReporter {
	constructor() {
		this.counts = { passed: 0, failed: 0, timedOut: 0, skipped: 0, interrupted: 0, retries: 0 }
	}

	onTestEnd(test, result) {
		if (Object.hasOwn(this.counts, result.status) && result.status !== 'retries') this.counts[result.status]++
		if (result.retry > 0) this.counts.retries++
	}

	onEnd(result) {
		const name = process.env.PLAYWRIGHT_SUMMARY_NAME || 'summary'
		if (!/^[a-z0-9-]+$/.test(name)) throw new Error('Invalid summary name')
		const status = ['passed', 'failed', 'timedout', 'interrupted'].includes(result.status) ? result.status : 'failed'
		const directory = path.resolve('build/e2e-summary')
		fs.mkdirSync(directory, { recursive: true })
		fs.writeFileSync(path.join(directory, `${name}.json`), JSON.stringify({ status, ...this.counts }, null, 2) + '\n')
	}
}

module.exports = SummaryReporter
