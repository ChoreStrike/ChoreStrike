/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { describe, expect, it } from '@jest/globals'
import { generateCommandId, rememberCommand, settleCommand } from '@/utils/commandId.js'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/**
 * The idempotency UUIDs of the cycle and form commands (CS-PLAN-072 WP1,
 * RF-091-09: one helper for every store).
 */
describe('commandId', () => {
	it('generates a fresh v4 UUID per call', () => {
		const first = generateCommandId()

		expect(first).toMatch(UUID)
		expect(generateCommandId()).not.toBe(first)
	})

	it('reuses the UUID of a pending command and adds a new one without touching the old map', () => {
		const pending = {}
		const first = rememberCommand(pending, 'save:1:0')

		expect(first.commandId).toMatch(UUID)
		expect(first.pending).toEqual({ 'save:1:0': first.commandId })
		expect(pending).toEqual({})
		expect(rememberCommand(first.pending, 'save:1:0')).toEqual({ commandId: first.commandId, pending: first.pending })
		expect(rememberCommand(first.pending, 'save:1:1').commandId).not.toBe(first.commandId)
	})

	it('closes a command on success and on a definite refusal', () => {
		const pending = { a: 'uuid-a', b: 'uuid-b' }

		expect(settleCommand(pending, 'a')).toEqual({ b: 'uuid-b' })
		expect(settleCommand(pending, 'a', { status: 409 })).toEqual({ b: 'uuid-b' })
		expect(pending).toEqual({ a: 'uuid-a', b: 'uuid-b' })
	})

	it('keeps the UUID after an uncertain failure: no answer or a server error', () => {
		const pending = { a: 'uuid-a' }

		expect(settleCommand(pending, 'a', new Error('Network Error'))).toBe(pending)
		expect(settleCommand(pending, 'a', { status: 502 })).toBe(pending)
		// No status at all is no answer, also when a caller sets it to null.
		expect(settleCommand(pending, 'a', { status: null })).toBe(pending)
	})
})
