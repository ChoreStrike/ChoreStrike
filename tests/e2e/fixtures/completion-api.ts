/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Cycle-receipt completion helpers (ADR-021, CS-PLAN-091).
 *
 * The completion endpoint is a CLOSED-SET receipt contract: the body carries
 * `commandId` (a fresh client idempotency UUID), `expectedVersion` and — for
 * every task but a bonus one — the `cycleKey` of the shown cycle. Direct-API
 * specs resolve the receipt of the CURRENT cycle from the house-wide index
 * (the same `cycle` block the UI store consumes) and post it unchanged.
 */

import type { APIResponse, Locator, Page, Response } from '@playwright/test'

import { holdToConfirm } from './hold-to-confirm.ts'

export interface CycleReceipt {
	/** Absent for a bonus (voluntary) task. */
	cycleKey?: string
	expectedVersion: number
}

/** The `cycle` block of a room-task row (ADR-021 decision 4). */
export interface CycleBlock extends CycleReceipt {
	windowStartOn?: string
	windowEndOn?: string
	centreOn?: string
	state: 'upcoming' | 'open' | 'overdue' | 'bonus'
	postponeCount?: number
	canPostpone?: boolean
}

export interface FlatRow {
	id: number
	assignees?: string[]
	cycle?: CycleBlock | null
	[key: string]: unknown
}

/** A fresh RFC 4122 v4 command id (one per logical completion command). */
export function newCommandId(): string {
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = (Math.random() * 16) | 0
		const value = char === 'x' ? random : (random & 0x3) | 0x8
		return value.toString(16)
	})
}

/** The flat row of one room task from the house-wide index (null when invisible). */
export async function readFlatRow(page: Page, roomTaskId: number): Promise<FlatRow | null> {
	const response = await page.request.get('/apps/chorestrike/api/v1/room-tasks', {
		headers: { Accept: 'application/json' },
	})
	if (response.status() !== 200) {
		return null
	}
	const rows = ((await response.json()).data ?? []) as FlatRow[]
	return rows.find((row) => row.id === roomTaskId) ?? null
}

/** The receipt a cycle block names (`cycleKey` stays absent for a bonus task). */
export function receiptOf(cycle: CycleBlock): CycleReceipt {
	const receipt: CycleReceipt = { expectedVersion: cycle.expectedVersion }
	if (cycle.cycleKey) {
		receipt.cycleKey = cycle.cycleKey
	}
	return receipt
}

/**
 * Resolve the shown cycle receipt of a room task from the house-wide index.
 * Returns null when the row is invisible to this user or carries no cycle
 * (a finished one-time task, an inactive one).
 */
export async function resolveCycleReceipt(page: Page, roomTaskId: number): Promise<CycleReceipt | null> {
	const cycle = (await readFlatRow(page, roomTaskId))?.cycle
	return cycle ? receiptOf(cycle) : null
}

/**
 * Post a completion with an explicit receipt and a fresh command id.
 * `participants` adds the explicit actual-work split (the actor takes part)
 * a completer outside the assignees must send.
 */
export async function postCompletionWithReceipt(
	page: Page,
	roomTaskId: number,
	receipt: CycleReceipt,
	participants?: Array<{ userId: string, sharePct: number }>,
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
 * Whether the UI asks "who took part" for these assignees: several people,
 * or one person who is not the actor. Unassigned work and the actor's own
 * task complete directly (the same rule as `useTaskCompletion`).
 */
export function needsParticipantSplit(assignees: string[], userId: string | null): boolean {
	if (assignees.length === 1) {
		return userId !== null && assignees[0] !== userId
	}
	return assignees.length > 1
}

/**
 * Complete the room task's CURRENT shown cycle as this page's user: resolve
 * the live receipt, then post it. Throws when no cycle is visible (a stale
 * or invisible row is a test-setup error, never a silent completion of
 * something else).
 *
 * When the row's assignees are several people, or one other person, the
 * request carries the explicit solo split naming the actor — exactly what
 * the participation dialog sends in the UI.
 */
export async function completeRoomTaskViaApi(page: Page, roomTaskId: number): Promise<APIResponse> {
	const row = await readFlatRow(page, roomTaskId)
	if (!row?.cycle) {
		throw new Error(`No completable cycle visible for room task ${roomTaskId}`)
	}
	const userId = await currentPageUserId(page)
	return postCompletionWithReceipt(
		page,
		roomTaskId,
		receiptOf(row.cycle),
		needsParticipantSplit(row.assignees ?? [], userId) && userId !== null ? [{ userId, sharePct: 100 }] : undefined,
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
 * Complete with an EXPLICIT solo split for a completer outside the
 * assignees: the helper did the work themself (a takeover when it is
 * overdue).
 */
export async function completeAsSoleParticipantViaApi(page: Page, roomTaskId: number, userId: string): Promise<APIResponse> {
	const receipt = await resolveCycleReceipt(page, roomTaskId)
	if (!receipt) {
		throw new Error(`No completable cycle visible for room task ${roomTaskId}`)
	}
	return postCompletionWithReceipt(page, roomTaskId, receipt, [{ userId, sharePct: 100 }])
}

/**
 * Hold a UI Done button until it confirms (CS-PLAN-084 WP-E). The hold
 * completes everything at once, whoever the task belongs to: the server
 * credits its assignees, and no dialog opens (CS-PLAN-091 WP-B2; the
 * picker is behind a short tap). Resolves with the completion response.
 */
export async function completeWithOptionalDialog(page: Page, button: Locator): Promise<Response> {
	// Registered before the hold, so an earlier completion still in flight
	// can never be mistaken for this one.
	const requestPromise = page.waitForRequest((request) => {
		const pathname = new URL(request.url()).pathname
		return request.method() === 'POST' && pathname.endsWith('/complete')
	}, { timeout: 20000 })
	await holdToConfirm(button)
	const response = await (await requestPromise).response()
	if (!response) {
		throw new Error('Completion request received no response')
	}
	return response
}

/**
 * A syntactically valid placeholder receipt for AUTHORIZATION-denial proofs:
 * the visibility gate (assertCanView → 404 anti-enumeration) fires before the
 * cycle is resolved, so a non-viewer is denied on the format-valid receipt
 * without the test depending on a real visible target.
 */
export function placeholderReceipt(): CycleReceipt {
	return { cycleKey: 'd2000-01-01', expectedVersion: 0 }
}
