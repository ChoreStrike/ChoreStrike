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

/**
 * Post a completion with an explicit receipt and a fresh command id.
 * `$participants` adds the WP3 §3.6 explicit actual-work split (the actor
 * takes part, Q-074-02) for non-assignee completions.
 */
export async function postCompletionWithReceipt(
	page: Page,
	roomTaskId: number,
	receipt: OccurrenceReceipt,
	participants?: Array<{ userId: string; sharePct: number }>,
): Promise<APIResponse> {
	const data: Record<string, unknown> = { commandId: newCommandId(), ...receipt }
	if (participants) {
		data.participants = participants
		data.allocationReviewed = true
	}
	return page.request.post(`/apps/chorestrike/api/v1/room-tasks/${roomTaskId}/complete`, {
		data,
		headers: { Accept: 'application/json' },
	})
}

/**
 * Complete the room task's CURRENT shown occurrence as this page's user:
 * resolve the live receipt, then post it. Throws when no completable target
 * is visible (a stale/invisible row is a test-setup error, never a silent
 * completion of another occurrence).
 *
 * WP3 (Q-074-02): when the row's server proposal names someone else (or
 * several people), the request carries the explicit solo split naming the
 * actor — exactly what the participation dialog sends in the UI.
 */
export async function completeRoomTaskViaApi(page: Page, roomTaskId: number): Promise<APIResponse> {
	const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
	if (!receipt) {
		throw new Error(`No completable occurrence visible for room task ${roomTaskId}`)
	}
	const userId = await currentPageUserId(page)
	const rows = await (await page.request.get('/apps/chorestrike/api/v1/room-tasks')).json()
	const participants = ((rows.data ?? []) as Array<{ id: number; proposal: { participants: Array<{ userId: string }> } | null }>)
		.find(row => row.id === roomTaskId)?.proposal?.participants ?? []
	const needsSplit = participants.length > 1
		|| (participants.length === 1 && userId !== null && participants[0].userId !== userId)
	return postCompletionWithReceipt(
		page,
		roomTaskId,
		receipt,
		needsSplit && userId !== null ? [{ userId, sharePct: 100 }] : undefined,
	)
}

/**
 * The current page session's NC user id (null when unreachable — the
 * caller then keeps the participant-less request).
 */
async function currentPageUserId(page: Page): Promise<string | null> {
	try {
		const body = await (await page.request.get('/apps/chorestrike/api/v1/users/current')).json()
		return body?.data?.nextcloudUserId ?? null
	} catch {
		return null
	}
}

/**
 * Complete with an EXPLICIT solo split for a NON-assignee actor (WP3
 * Q-074-02: the completer must take part in the work — a takeover names
 * the helper).
 */
export async function completeAsSoleParticipantViaApi(page: Page, roomTaskId: number, userId: string): Promise<APIResponse> {
	const receipt = await resolveOccurrenceReceipt(page, roomTaskId)
	if (!receipt) {
		throw new Error(`No completable occurrence visible for room task ${roomTaskId}`)
	}
	return postCompletionWithReceipt(page, roomTaskId, receipt, [{ userId, sharePct: 100 }])
}

/**
 * Click a UI complete button and settle the WP3 §3.6 participation dialog
 * when the server proposal opens one (multi-person work, or an agreement
 * naming someone else). Resolves with the completion response.
 */
export async function completeWithOptionalDialog(page: Page, button: { click(): Promise<void> }): Promise<APIResponse> {
	const responsePromise = page.waitForResponse((response) => {
		const pathname = new URL(response.url()).pathname
		return response.request().method() === 'POST' && pathname.endsWith('/complete')
	}, { timeout: 20000 })
	await button.click()
	const dialog = page.getByTestId('participation-dialog')
	if (await dialog.isVisible({ timeout: 3000 }).catch(() => false)) {
		await page.getByTestId('participation-confirm').click()
	}
	return responsePromise
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
