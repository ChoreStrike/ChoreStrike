/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { logger } from './logger.js'

let muted = false
// CS-PLAN-070 WP6 (C5g): sounds are a PERSONAL OPT-IN (default off).
// The preference store syncs this module gate; `muted` stays as the
// additional local kill-switch.
let soundEnabled = false
let audioContext = null

const SOUND_PROFILES = {
	complete: { frequency: 523.25, duration: 0.15, type: 'sine', ramp: 784 },
	medal: { frequency: 440, duration: 0.3, type: 'sine', ramp: 880 },
	milestone: { frequency: 392, duration: 0.4, type: 'sine', ramp: 784 },
}

function getContext() {
	if (!audioContext) {
		try {
			audioContext = new (window.AudioContext || window.webkitAudioContext)()
		} catch (error) {
			logger.error('Web Audio API not available', { error: error.message })
			return null
		}
	}
	return audioContext
}

/**
 * Play a synthesised sound effect. No-op unless the personal opt-in is
 * set (`setSoundEnabled`) and the module is not muted.
 *
 * @param {string} name Sound name (complete, medal, milestone)
 */
export function playSound(name) {
	if (!soundEnabled || muted) {
		return
	}

	const ctx = getContext()
	if (!ctx) {
		return
	}

	const profile = SOUND_PROFILES[name]
	if (!profile) {
		return
	}

	try {
		if (ctx.state === 'suspended') {
			ctx.resume()
		}

		const osc = ctx.createOscillator()
		const gain = ctx.createGain()

		osc.type = profile.type
		osc.frequency.setValueAtTime(profile.frequency, ctx.currentTime)
		osc.frequency.linearRampToValueAtTime(profile.ramp, ctx.currentTime + profile.duration)

		gain.gain.setValueAtTime(0.3, ctx.currentTime)
		gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + profile.duration)

		osc.connect(gain)
		gain.connect(ctx.destination)

		osc.start(ctx.currentTime)
		osc.stop(ctx.currentTime + profile.duration)
	} catch (error) {
		logger.error('Failed to play sound', { name, error: error.message })
	}
}

/**
 * Unlock audio on the first user gesture (required by mobile autoplay policy).
 * Call this from a click/touch handler during app initialization.
 */
export function unlockAudio() {
	const ctx = getContext()
	if (ctx && ctx.state === 'suspended') {
		ctx.resume()
	}
}

/**
 * Set the global mute state.
 *
 * @param {boolean} value True to mute, false to unmute
 */
export function setMuted(value) {
	muted = value
}

/**
 * Get the current mute state.
 *
 * @return {boolean}
 */
export function isMuted() {
	return muted
}

/**
 * Sync the personal sound opt-in (C5g, default off). Called from the
 * celebration composable on every event so a mid-session preference
 * change applies to the next effect.
 *
 * @param {boolean} value True when the user opted into sounds
 */
export function setSoundEnabled(value) {
	soundEnabled = value === true
}

/**
 * Get the current opt-in state.
 *
 * @return {boolean}
 */
export function isSoundEnabled() {
	return soundEnabled
}

/**
 * Preload is a no-op for synthesised sounds.
 */
export function preloadSounds() {}
