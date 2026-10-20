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
let noise = null

const DEFAULT_GAIN = 0.3
const NOISE_SECONDS = 0.25

/**
 * Every profile is a list of notes played relative to the call, all
 * synthesized (no assets): an oscillator note slides from `frequency` to
 * `ramp` over `duration` seconds with waveform `type`, a noise note
 * (`noise: true`) is a short burst of white noise band-passed at
 * `frequency`. `at` delays a note, `gain` scales its volume.
 */
const SOUND_PROFILES = {
	complete: [{ frequency: 523.25, ramp: 784, duration: 0.15, type: 'sine' }],
	medal: [{ frequency: 440, ramp: 880, duration: 0.3, type: 'sine' }],
	milestone: [{ frequency: 392, ramp: 784, duration: 0.4, type: 'sine' }],
	// Arcade (CS-PLAN-086): pickups, bonus items and the events of a round.
	pop: [{ frequency: 660, ramp: 990, duration: 0.06, type: 'triangle' }],
	swish: [{ frequency: 880, ramp: 1760, duration: 0.18, type: 'sine' }],
	start: [
		{ frequency: 523.25, ramp: 523.25, duration: 0.08, type: 'triangle' },
		{ at: 0.1, frequency: 783.99, ramp: 783.99, duration: 0.14, type: 'triangle' },
	],
	bounce: [
		{ frequency: 240, ramp: 170, duration: 0.05, type: 'triangle', gain: 0.22 },
		{ noise: true, frequency: 1500, duration: 0.04, gain: 0.08 },
	],
	miss: [
		{ frequency: 330, ramp: 300, duration: 0.12, type: 'sine', gain: 0.25 },
		{ at: 0.14, frequency: 262, ramp: 220, duration: 0.22, type: 'sine', gain: 0.25 },
	],
	crash: [{ frequency: 320, ramp: 80, duration: 0.5, type: 'sawtooth', gain: 0.16 }],
	cleared: [
		{ frequency: 523.25, ramp: 523.25, duration: 0.09, type: 'triangle' },
		{ at: 0.09, frequency: 659.25, ramp: 659.25, duration: 0.09, type: 'triangle' },
		{ at: 0.18, frequency: 783.99, ramp: 783.99, duration: 0.09, type: 'triangle' },
		{ at: 0.27, frequency: 1046.5, ramp: 1046.5, duration: 0.2, type: 'triangle' },
	],
	penalty: [{ frequency: 150, ramp: 110, duration: 0.18, type: 'square', gain: 0.1 }],
	flip: [
		{ noise: true, frequency: 2500, duration: 0.05, gain: 0.12 },
		{ frequency: 900, ramp: 700, duration: 0.03, type: 'triangle', gain: 0.12 },
	],
	wrong: [
		{ frequency: 220, ramp: 220, duration: 0.08, type: 'triangle', gain: 0.2 },
		{ at: 0.1, frequency: 196, ramp: 185, duration: 0.14, type: 'triangle', gain: 0.2 },
	],
}

/** Every sound name the module plays. */
export const SOUND_NAMES = Object.freeze(Object.keys(SOUND_PROFILES))

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
 * @param {string} name Sound name (one of SOUND_NAMES)
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
		for (const note of profile) {
			const at = ctx.currentTime + (note.at ?? 0)
			if (note.noise) {
				playNoise(ctx, note, at)
			} else {
				playTone(ctx, note, at)
			}
		}
	} catch (error) {
		logger.error('Failed to play sound', { name, error: error.message })
	}
}

/**
 * A gain node that fades the note out over its duration.
 *
 * @param {AudioContext} ctx Audio context
 * @param {object} note The note
 * @param {number} at Start time on the context clock
 * @return {GainNode} Connected to the destination
 */
function envelope(ctx, note, at) {
	const gain = ctx.createGain()
	gain.gain.setValueAtTime(note.gain ?? DEFAULT_GAIN, at)
	gain.gain.exponentialRampToValueAtTime(0.01, at + note.duration)
	gain.connect(ctx.destination)
	return gain
}

function playTone(ctx, note, at) {
	const osc = ctx.createOscillator()
	osc.type = note.type
	osc.frequency.setValueAtTime(note.frequency, at)
	osc.frequency.linearRampToValueAtTime(note.ramp, at + note.duration)
	osc.connect(envelope(ctx, note, at))
	osc.start(at)
	osc.stop(at + note.duration)
}

/**
 * A band-passed burst of white noise (a wipe, a thud). Skipped where the
 * context offers no buffers or filters; the tone notes still play.
 *
 * @param {AudioContext} ctx Audio context
 * @param {object} note The noise note
 * @param {number} at Start time on the context clock
 */
function playNoise(ctx, note, at) {
	if (typeof ctx.createBuffer !== 'function' || typeof ctx.createBufferSource !== 'function'
		|| typeof ctx.createBiquadFilter !== 'function') {
		return
	}
	const source = ctx.createBufferSource()
	source.buffer = noiseBuffer(ctx)
	const filter = ctx.createBiquadFilter()
	filter.type = 'bandpass'
	filter.frequency.value = note.frequency
	filter.Q.value = 1
	source.connect(filter)
	filter.connect(envelope(ctx, note, at))
	source.start(at)
	source.stop(at + note.duration)
}

function noiseBuffer(ctx) {
	if (!noise) {
		const length = Math.floor(ctx.sampleRate * NOISE_SECONDS)
		noise = ctx.createBuffer(1, length, ctx.sampleRate)
		const data = noise.getChannelData(0)
		for (let i = 0; i < length; i++) {
			data[i] = Math.random() * 2 - 1
		}
	}
	return noise
}

/**
 * Unlock audio on a user gesture (required by mobile autoplay policy).
 * Call it from a click/touch handler: the app does on its first gesture,
 * the arcade on every round start and when its Sound switch turns on.
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
 * Sync the personal sound opt-in (C5g, default off). Called through
 * `syncEffectGates()` on every celebration and arcade effect, so a
 * mid-session preference change applies to the next effect.
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
