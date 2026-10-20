/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

import { defineStore } from 'pinia'
import {
	fetchCatalog,
	createCatalogItem as createCatalogItemRequest,
	updateCatalogItem as updateCatalogItemRequest,
	fetchRewardRequests,
	fetchRewardRequest,
	createRewardRequest as createRewardRequestRequest,
	approveRewardRequest,
	rejectRewardRequest,
	cancelRewardRequest,
	fulfillRewardRequest,
	correctRewardRequest,
} from '../services/api.js'
import { logger } from '../utils/logger.js'

/**
 * Reward catalog & request store (CS-PLAN-070 WP8 §3.2 on the delivered
 * WP7 contract, docs/api/rewards.md). This store owns READ PAGES and
 * COMMAND TRANSPORT — never accounting: the wallet display source stays
 * `game.wallet` (`fetchGameState()`), the house gate flag stays in the
 * house store, and no balance/reservation is ever incremented locally.
 *
 * Read state: catalog pages (active/admin) and request pages
 * (mine/house × optional state filter) are separate contexts with their
 * own ID cursor (ascending for catalog items, descending for requests),
 * deduplication and exhaustion. A context is only refreshed after a
 * command when it was TOUCHED — the owner house scope is never prefetched
 * for ordinary members. Every context read is generation-guarded, so a
 * delayed page can never repopulate another list, role or identity.
 *
 * Commands: one RFC 4122 v4 UUID per logical command; UUID, normalized
 * payload and submitted version travel together until the outcome is
 * known. A definitive 4xx (incl. 409 conflicts: stale version, terms
 * drift, gate/archive, closed transition, insufficient funds, funding
 * hold) discards the identity, flags `conflict` on 409 and refreshes the
 * authorized source data — the caller's draft survives. An uncertain
 * transport/5xx outcome does NOT prove rollback: the SAME UUID/payload is
 * retained in memory (`unresolvedCommand`) and the UI offers an explicit
 * replay — never an automatic fresh-key resend.
 */

/** Default page size (server cap is 100). */
export const REWARDS_PAGE_LIMIT = 50

/** Closed reject/cancel/correct reason codes (docs/api/rewards.md). */
export const REASON_CODES = ['not_needed', 'changed_mind', 'unavailable', 'duplicate', 'not_delivered', 'other']

/**
 * One paged read context.
 * @return {object} Empty page context.
 */
function emptyPage() {
	return {
		items: [],
		cursor: null,
		exhausted: false,
		loading: false,
		error: null,
		/** The state filter the page was loaded with (requests only). */
		state: null,
		/** True once this context has loaded at least once (refresh target). */
		touched: false,
		/** Read generation — reset() and scope/filter switches invalidate. */
		seq: 0,
	}
}

function emptyDetail() {
	return {
		request: null,
		audit: [],
		loading: false,
		error: null,
		/** True after a 403/404 — a generic unavailable state, never a guess. */
		unavailable: false,
		touched: false,
		seq: 0,
	}
}

/**
 * Generate a client idempotency UUID (RFC 4122 v4). Crypto RNG when
 * available, otherwise a spec-shaped fallback.
 * @return {string} Canonical lowercase UUID.
 */
function generateCommandId() {
	const webCrypto = globalThis.crypto
	if (webCrypto && typeof webCrypto.randomUUID === 'function') {
		return webCrypto.randomUUID()
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
		const random = Math.random() * 16 | 0
		const value = char === 'x' ? random : (random & 0x3 | 0x8)
		return value.toString(16)
	})
}

/**
 * True for an uncertain outcome: no HTTP status (transport drop) or a
 * 5xx — the server may have committed the command, so the identity must
 * survive for an explicit same-key retry.
 * @param {object} error ApiError-like rejection.
 * @return {boolean}
 */
function isUncertainOutcome(error) {
	return !(error?.status >= 400 && error?.status < 500)
}

/**
 * Append a page to a context with stable-identity deduplication.
 * @param {object} ctx Page context.
 * @param {Array} incoming New rows.
 * @return {object} The context (mutated).
 */
function appendPage(ctx, incoming) {
	const seen = new Set(ctx.items.map((item) => item.id))
	for (const item of incoming) {
		if (!seen.has(item.id)) {
			seen.add(item.id)
			ctx.items.push(item)
		}
	}
	ctx.cursor = ctx.items.length > 0 ? ctx.items[ctx.items.length - 1].id : null
	ctx.exhausted = incoming.length < REWARDS_PAGE_LIMIT
	return ctx
}

export const useRewardsStore = defineStore('rewards', {
	state: () => ({
		catalog: { active: emptyPage(), admin: emptyPage() },
		requests: { mine: emptyPage(), house: emptyPage() },
		detail: emptyDetail(),
		/** Household gate flag as last reported by a catalog read. */
		catalogEnabled: null,
		saving: false,
		error: null,
		/** True after a definitive 409 — source data was re-read, drafts stay. */
		conflict: false,
		/**
		 * The last uncertain command, kept IN MEMORY ONLY: the same
		 * UUID/payload replay, never a new effect. Cleared on reset,
		 * on a definitive 4xx and after a successful retry.
		 */
		unresolvedCommand: null,
		/** A command succeeded but a follow-up read failed — visible, non-fatal. */
		refreshError: null,
	}),

	actions: {
		// ---------------------------------------------------------------
		// Reads
		// ---------------------------------------------------------------

		/**
		 * Load the next catalog page for a scope (`active`/`admin`).
		 * @param {string} scope Catalog scope.
		 * @param {object} [options] Read options.
		 * @param {boolean} [options.reset] True restarts the page from scratch.
		 * @return {Promise<object>} The context.
		 */
		async fetchCatalogPage(scope, { reset = false } = {}) {
			const ctx = this.catalog[scope]
			if (!ctx) {
				throw new Error(`Unknown catalog scope: ${scope}`)
			}
			if (reset) {
				ctx.items = []
				ctx.cursor = null
				ctx.exhausted = false
				ctx.error = null
			}
			if ((ctx.loading && !reset) || (ctx.exhausted && ctx.items.length > 0)) {
				return ctx
			}
			const seq = ++ctx.seq
			ctx.loading = true
			ctx.error = null
			// The context was explicitly requested (even a failed load is a
			// refresh target after a command); only the generation decides
			// which in-flight answer may land.
			ctx.touched = true
			try {
				const params = { scope, limit: REWARDS_PAGE_LIMIT }
				if (ctx.cursor !== null) {
					params.cursor = ctx.cursor
				}
				const data = await fetchCatalog(params)
				if (seq !== ctx.seq) {
					logger.debug('Dropping superseded catalog read', { store: 'rewards', action: 'fetchCatalogPage', scope })
					return ctx
				}
				this.catalogEnabled = data.catalogEnabled === true
				appendPage(ctx, data.items ?? [])
			} catch (error) {
				if (seq === ctx.seq) {
					ctx.error = error.message
				}
				logger.error('Failed to fetch the catalog', error, { store: 'rewards', action: 'fetchCatalogPage', scope })
				throw error
			} finally {
				if (seq === ctx.seq) {
					ctx.loading = false
				}
			}
			return ctx
		},

		/**
		 * Load the next request page for a scope (`mine`/`house`). The
		 * optional state filter changes the context identity: switching it
		 * invalidates the cursor and restarts the page.
		 * @param {string} scope Request scope.
		 * @param {object} [options] Read options.
		 * @param {string|null} [options.state] Optional state filter.
		 * @param {boolean} [options.reset] True restarts the page from scratch.
		 * @return {Promise<object>} The context.
		 */
		async fetchRequestPage(scope, { state = null, reset = false } = {}) {
			const ctx = this.requests[scope]
			if (!ctx) {
				throw new Error(`Unknown request scope: ${scope}`)
			}
			const identityChanged = reset || ctx.state !== state
			if (identityChanged) {
				ctx.items = []
				ctx.cursor = null
				ctx.exhausted = false
				ctx.error = null
				ctx.state = state
			}
			if ((ctx.loading && !identityChanged) || (ctx.exhausted && ctx.items.length > 0)) {
				return ctx
			}
			const seq = ++ctx.seq
			ctx.loading = true
			ctx.error = null
			// The context was explicitly requested (even a failed load is
			// a refresh target after a command); only the generation
			// decides which in-flight answer may land.
			ctx.touched = true
			try {
				const params = { scope, limit: REWARDS_PAGE_LIMIT }
				if (state) {
					params.state = state
				}
				if (ctx.cursor !== null) {
					params.cursor = ctx.cursor
				}
				const data = await fetchRewardRequests(params)
				if (seq !== ctx.seq) {
					logger.debug('Dropping superseded request read', { store: 'rewards', action: 'fetchRequestPage', scope, state })
					return ctx
				}
				appendPage(ctx, data.requests ?? [])
			} catch (error) {
				if (seq === ctx.seq) {
					ctx.error = error.message
				}
				logger.error('Failed to fetch reward requests', error, { store: 'rewards', action: 'fetchRequestPage', scope, state })
				throw error
			} finally {
				if (seq === ctx.seq) {
					ctx.loading = false
				}
			}
			return ctx
		},

		/**
		 * Load one authorized request detail incl. the audit trail.
		 * Foreign/invisible ids answer 403/404 — rendered as a GENERIC
		 * unavailable state, never reconstructed from cached wishes.
		 * @param {number|string} id Request id.
		 * @return {Promise<object>} The detail state.
		 */
		async fetchRequestDetail(id) {
			const detail = this.detail
			const seq = ++detail.seq
			detail.loading = true
			detail.error = null
			detail.unavailable = false
			try {
				const data = await fetchRewardRequest(id)
				if (seq !== detail.seq) {
					logger.debug('Dropping superseded request detail', { store: 'rewards', action: 'fetchRequestDetail' })
					return detail
				}
				detail.request = data
				detail.audit = data.audit ?? []
				detail.touched = true
			} catch (error) {
				if (seq === detail.seq) {
					if (error?.status === 403 || error?.status === 404) {
						detail.request = null
						detail.audit = []
						detail.unavailable = true
					} else {
						detail.error = error.message
					}
				}
				logger.error('Failed to fetch the reward request detail', error, { store: 'rewards', action: 'fetchRequestDetail' })
				throw error
			} finally {
				if (seq === detail.seq) {
					detail.loading = false
				}
			}
			return detail
		},

		/**
		 * Clear the selected detail (e.g. leaving the detail route).
		 */
		clearDetail() {
			this.detail.seq++
			this.detail.request = null
			this.detail.audit = []
			this.detail.error = null
			this.detail.unavailable = false
			this.detail.touched = false
		},

		// ---------------------------------------------------------------
		// Commands
		// ---------------------------------------------------------------

		/**
		 * Create a catalog item (owner only).
		 * @param {object} fields {title, description?, priceMinor}.
		 * @return {Promise<object>} The created item DTO.
		 */
		async createCatalogItem(fields) {
			return this.runCommand('catalog-item-create', fields, (commandId) => createCatalogItemRequest({
				...fields,
				commandId,
			}))
		},

		/**
		 * Edit or archive a catalog item (owner only; archive is
		 * `archived: true` on this route — never DELETE).
		 * @param {object} item The displayed item (version authority).
		 * @param {object} changes Whitelisted field changes.
		 * @return {Promise<object>} The updated item DTO.
		 */
		async updateCatalogItem(item, changes) {
			return this.runCommand('catalog-item-update', { itemId: item.id, ...changes }, (commandId) => updateCatalogItemRequest(item.id, {
				...changes,
				expectedVersion: item.version,
				commandId,
			}))
		},

		/**
		 * Create an own request from the explicitly reviewed displayed
		 * terms. No requester/role/balance is supplied — the server owns
		 * state and reservation.
		 * @param {object} item The displayed catalog item.
		 * @return {Promise<object>} The created request DTO.
		 */
		async createRequest(item) {
			return this.runCommand('request-create', { itemId: item.id, priceMinor: item.priceMinor }, (commandId) => createRewardRequestRequest({
				itemId: item.id,
				itemVersion: item.version,
				priceMinor: item.priceMinor,
				commandId,
			}))
		},

		/**
		 * Approve a pending request (owner on a child; an adult requester
		 * explicitly self-approves).
		 * @param {object} request The displayed request.
		 * @return {Promise<object>} The updated DTO.
		 */
		async approveRequest(request) {
			return this.runRequestCommand(request, 'approve', {}, (commandId) => approveRewardRequest(request.id, {
				expectedVersion: request.version,
				commandId,
			}))
		},

		/**
		 * Reject a child's pending request (owner only, closed reason set).
		 * @param {object} request The displayed request.
		 * @param {string} [reasonCode] Closed-set reason (optional).
		 * @return {Promise<object>} The updated DTO.
		 */
		async rejectRequest(request, reasonCode = null) {
			return this.runRequestCommand(request, 'reject', { reasonCode }, (commandId) => rejectRewardRequest(request.id, {
				expectedVersion: request.version,
				reasonCode,
				commandId,
			}))
		},

		/**
		 * Cancel an unfulfilled request (requester or owner).
		 * @param {object} request The displayed request.
		 * @param {string} [reasonCode] Closed-set reason (optional).
		 * @return {Promise<object>} The updated DTO.
		 */
		async cancelRequest(request, reasonCode = null) {
			return this.runRequestCommand(request, 'cancel', { reasonCode }, (commandId) => cancelRewardRequest(request.id, {
				expectedVersion: request.version,
				reasonCode,
				commandId,
			}))
		},

		/**
		 * Confirm actual delivery — the explicit attestation; approval is
		 * never fulfillment.
		 * @param {object} request The displayed request.
		 * @return {Promise<object>} The updated DTO.
		 */
		async fulfillRequest(request) {
			return this.runRequestCommand(request, 'fulfill', { delivered: true }, (commandId) => fulfillRewardRequest(request.id, {
				expectedVersion: request.version,
				delivered: true,
				commandId,
			}))
		},

		/**
		 * Owner-attested FALSE fulfillment correction: refunds the server's
		 * ORIGINAL settlement entry exactly once (referenced, never a
		 * client amount). Terminal; no new reservation.
		 * @param {object} request The displayed request (carries the
		 *   original `settlementEntryId`).
		 * @param {string} reasonCode Required closed-set reason.
		 * @return {Promise<object>} The corrected DTO.
		 */
		async correctRequest(request, reasonCode) {
			return this.runRequestCommand(request, 'correct', { reasonCode, settlementEntryId: request.settlementEntryId }, (commandId) => correctRewardRequest(request.id, {
				expectedVersion: request.version,
				notDelivered: true,
				reasonCode,
				settlementEntryId: request.settlementEntryId,
				commandId,
			}))
		},

		/**
		 * Explicit replay of the last uncertain command with the SAME
		 * UUID/payload — never a fresh key, never an edited payload.
		 * @return {Promise<object|null>} The command DTO on success.
		 */
		async retryUnresolved() {
			const pending = this.unresolvedCommand
			if (!pending || this.saving) {
				return null
			}
			this.saving = true
			this.error = null
			try {
				const dto = await pending.send(pending.commandId)
				this.unresolvedCommand = null
				this.refreshAfterCommand()
				return dto
			} catch (error) {
				this.classifyCommandFailure(error)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Discard an unresolved intent WITHOUT submitting it (the user
		 * abandoned the retry). Reload never auto-submits.
		 */
		dismissUnresolved() {
			this.unresolvedCommand = null
		},

		/**
		 * Run one request-lifecycle command with the shared identity /
		 * conflict / uncertain-outcome contract.
		 * @param {object} request The displayed request (version authority).
		 * @param {string} action Stable action label.
		 * @param {object} identity The normalized payload (retry identity).
		 * @param {function(string): Promise<object>} send API call factory.
		 * @return {Promise<object>} The updated DTO.
		 */
		async runRequestCommand(request, action, identity, send) {
			return this.runCommand(`request-${action}`, { requestId: request.id, ...identity }, send)
		},

		/**
		 * Shared command runner: one UUID per logical command, identity
		 * retained across uncertain outcomes, definitive 4xx discards it,
		 * 409 flags the conflict and re-reads the authorized sources.
		 * @param {string} label Stable action label (logging only).
		 * @param {object} identity Normalized payload (retry identity).
		 * @param {function(string): Promise<object>} send API call factory.
		 * @return {Promise<object>} The command DTO.
		 */
		async runCommand(label, identity, send) {
			if (this.saving) {
				throw new Error('A reward command is already in flight')
			}
			const commandId = generateCommandId()
			this.saving = true
			this.error = null
			this.conflict = false
			try {
				const dto = await send(commandId)
				this.unresolvedCommand = null
				this.refreshAfterCommand()
				return dto
			} catch (error) {
				this.classifyCommandFailure(error, { commandId, label, identity, send })
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Classify a command failure: a definitive 4xx (incl. 409)
		 * discards the command identity; anything else is uncertain and
		 * retains the identity for an explicit same-key retry. The
		 * payload is stored in memory only and NEVER logged (wish text,
		 * prices and balances are sensitive context).
		 * @param {object} error The rejection.
		 * @param {object} [pending] {commandId, label, identity, send} of the attempt.
		 */
		classifyCommandFailure(error, pending = null) {
			if (!isUncertainOutcome(error)) {
				this.unresolvedCommand = null
				if (error?.status === 409) {
					// Not necessarily a stale version: terms drift, gate,
					// closed transition, insufficient funds or funding
					// hold. Refresh the authorized sources; the caller's
					// draft survives for a reviewed re-submission.
					this.conflict = true
					this.refreshAfterCommand()
				} else {
					this.error = error.message
				}
				logger.error('Reward command was rejected', error, { store: 'rewards', action: 'classifyCommandFailure', status: error?.status ?? null })
				return
			}
			if (pending) {
				this.unresolvedCommand = pending
			}
			logger.error('Reward command outcome is uncertain', error, { store: 'rewards', action: 'classifyCommandFailure', status: error?.status ?? null })
		},

		/**
		 * Re-read every TOUCHED read context after a command. A failed
		 * refresh is a visible `refreshError`, never a failed command and
		 * never permission to submit a new effect.
		 * @return {Promise<void>}
		 */
		async refreshAfterCommand() {
			const jobs = []
			for (const scope of ['mine', 'house']) {
				if (this.requests[scope].touched) {
					// Keep the context's current state filter: a refresh
					// must never silently flip a filtered list to All.
					jobs.push(this.fetchRequestPage(scope, { state: this.requests[scope].state, reset: true }))
				}
			}
			for (const scope of ['active', 'admin']) {
				if (this.catalog[scope].touched) {
					jobs.push(this.fetchCatalogPage(scope, { reset: true }))
				}
			}
			if (this.detail.touched && this.detail.request) {
				jobs.push(this.fetchRequestDetail(this.detail.request.id))
			}
			if (jobs.length === 0) {
				this.refreshError = null
				return
			}
			const results = await Promise.allSettled(jobs)
			const failed = results.find((result) => result.status === 'rejected')
			this.refreshError = failed ? (failed.reason?.message ?? String(failed.reason)) : null
		},

		/**
		 * Clear transient error/conflict/refresh state.
		 */
		clearError() {
			this.error = null
			this.conflict = false
			this.refreshError = null
		},

		/**
		 * Reset store state (identity/house switch): every in-flight read
		 * is invalidated BEFORE clearing, so a late success can never
		 * repopulate a previous login's wishes, drafts or commands.
		 */
		reset() {
			for (const ctx of [this.catalog.active, this.catalog.admin, this.requests.mine, this.requests.house]) {
				ctx.seq++
			}
			this.detail.seq++
			this.catalog = { active: emptyPage(), admin: emptyPage() }
			this.requests = { mine: emptyPage(), house: emptyPage() }
			this.detail = emptyDetail()
			this.catalogEnabled = null
			this.saving = false
			this.error = null
			this.conflict = false
			this.unresolvedCommand = null
			this.refreshError = null
		},
	},
})
