/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { translate as t } from '@nextcloud/l10n'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { showSuccess } from '@nextcloud/dialogs'
import { logger } from '../utils/logger.js'

/**
 * Subscribe to ChoreStrike event-bus events and surface them as Nextcloud
 * toasts via `@nextcloud/dialogs`.
 *
 * Complements `useCelebration`: that composable handles confetti +
 * sound for the dopamine moments; this one renders a textual toast so the
 * user gets an explicit "what just happened" message (and a persistent
 * record in the toast history). Together they give both an emotional beat
 * and a clear status update.
 *
 * Server-side notifications (Nextcloud bell + mobile feed) are dispatched
 * by NotificationService on the backend; this composable only handles the
 * live, in-session toast layer.
 *
 * @return {function(): void} Cleanup function that unsubscribes all handlers
 */
export function useToasts() {
	const handlers = []

	function on(event, handler) {
		subscribe(event, handler)
		handlers.push({ event, handler })
	}

	on('chorestrike:weekly-goal-reached', (data) => {
		const percent = Number.isFinite(data?.percent) ? data.percent : 0
		const target = Number.isFinite(data?.targetPercent) ? data.targetPercent : 80
		showSuccess(t('chorestrike', 'Weekly goal reached! {percent}% of target {target}%', { percent, target }),)
	})

	on('chorestrike:medal-awarded', () => {
		showSuccess(t('chorestrike', 'You earned a new medal!'))
	})

	logger.info('Toast notifications initialized')

	return function cleanup() {
		for (const { event, handler } of handlers) {
			unsubscribe(event, handler)
		}
		handlers.length = 0
	}
}
