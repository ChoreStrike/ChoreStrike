/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { fireConfetti, fireMilestoneConfetti } from '../utils/confetti.js'
import { playSound } from '../utils/sound.js'
import { logger } from '../utils/logger.js'

const STREAK_MILESTONES = [7, 30, 100]

/**
 * Initialize celebration effects: subscribe to ChoreStrike event-bus events
 * and trigger confetti + sound.
 *
 * Returns a cleanup function that unsubscribes all handlers.
 *
 * @return {function(): void} Cleanup function
 */
export function useCelebration() {
	const handlers = []

	function on(event, handler) {
		subscribe(event, handler)
		handlers.push({ event, handler })
	}

	on('chorestrike:task-completed', (data) => {
		fireConfetti()
		playSound('complete')

		if (data?.isCombo) {
			playSound('combo')
		}

		const streak = data?.currentStreak
		if (streak && STREAK_MILESTONES.includes(streak)) {
			fireMilestoneConfetti()
			playSound('milestone')
		}
	})

	on('chorestrike:medal-awarded', () => {
		fireMilestoneConfetti()
		playSound('medal')
	})

	on('chorestrike:title-unlocked', () => {
		fireMilestoneConfetti()
		playSound('milestone')
	})

	on('chorestrike:weekly-goal-reached', () => {
		fireMilestoneConfetti()
		playSound('milestone')
	})

	on('chorestrike:streak-freeze-used', () => {
		logger.info('Streak freeze used — streak preserved')
	})

	logger.info('Celebration effects initialized')

	return function cleanup() {
		for (const { event, handler } of handlers) {
			unsubscribe(event, handler)
		}
		handlers.length = 0
	}
}
