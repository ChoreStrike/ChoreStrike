/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Occurrence-receipt completion helpers (CS-PLAN-072 WP1).
 *
 * The completion endpoint is a CLOSED-SET receipt contract: the body must
 * carry `commandId` (a fresh client idempotency UUID), `occurrenceId` (the
 * shown target's public `<scheduleRevisionId>:<ordinal>` key),
 * `expectedVersion` and `selectionToken`. Direct-API specs resolve the
 * receipt of the CURRENT canonical selection from the house-wide index
 * (the same block the UI store consumes) and post it unchanged.
 */

import type { Page, APIResponse } from '@playwright/test'

export interface OccurrenceReceipt {
	occurrenceId: string
	expectedVersion: number
	selectionToken: string
}

/** A fresh RFC 4122 v4 command id (one per logical completion command). */
export function newCommandId(): string {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = (Math.random() * 16) | 0
		const value = char === 'x' ? random : (random & 0x3) | 0x8
		return value.toString(16)
	})
}

/**
 * Resolve the shown occurrence receipt of a room task from the house-wide
 * index. Returns null when the row is invisible to this user or carries no
 * completable target (the read block mirrors the UI store's source).
 */
export async function resolveOccurrenceReceipt(page: Page, roomTaskId: number): Promise<OccurrenceReceipt | null> {
	const response = await page.request.get('/apps/chorestrike/api/v1/room-tasks', {
		headers: { Accept: 'application/json' },
	})
	if (response.status() !== 200) {
		return null
	}
	const rows = ((await response.json()).data ?? []) as Array<{ id: number; occurrence?: OccurrenceReceipt | null }>
	const occurrence = rows.find((row) => row.id === roomTaskId)?.occurrence
	if (!occurrence?.occurrenceId || !occurrence?.selectionToken) {
		return null
	}
	return {
		occurrenceId: occurrence.occurrenceId,
		expectedVersion: occurrence.expectedVersion,
		selectionToken: occurrence.selectionToken,
	}
}

/** Post a completion with an explicit receipt and a fresh command id. */
export async function postCompletionWithReceipt(
	page: Page,
	roomTaskId: number,
	receipt: OccurrenceReceipt,
): Promise<APIResponse> {
	return page.request.post(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`, {
		data: { commandId: newCommandId(), ...receipt },
		headers: { Accept: 'application/json' },
	})
}

/**
 * Complete the room task's CURRENT shown occurrence as this page's user:
 * resolve the live receipt, then post it. Throws when no completable target
 * is visible (a stale/invisible row is a test-setup error, never a silent
 * completion of another occurrence).
 */
export async function completeRoomTaskViaApi(page: Page, roomTaskId: number): Promise<APIResponse> {
	const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
	if (!receipt) {
		throw new Error(`No completable occurrence visible for room task ${roomTaskId}`)
	}
	return postCompletionWithReceipt(page, roomTaskId, receipt)
}

/**
 * A syntactically valid placeholder receipt for AUTHORIZATION-denial proofs:
 * the visibility gate (assertCanView → 404 anti-enumeration) fires before the
 * occurrence is resolved, so a non-viewer is denied on the format-valid
 * receipt without the test depending on a real visible target.
 */
export function placeholderReceipt(): OccurrenceReceipt {
	return { occurrenceId: '1:1', expectedVersion: 0, selectionToken: 'placeholder-selection-token' }
}
