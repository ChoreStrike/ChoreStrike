<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="request-detail-view" data-testid="request-detail-view">
		<header class="detail-header">
			<router-link :to="{ name: 'reward-requests' }" class="back-link" data-testid="detail-back">
				{{ t('chorestrike', 'Back to requests') }}
			</router-link>
		</header>

		<section v-if="rewardsStore.unresolvedCommand"
			class="uncertain-banner"
			data-testid="unresolved-command"
			role="alert">
			<p>{{ t('chorestrike', 'The last action did not return a result. You can safely retry it — it will not be applied twice.') }}</p>
			<div class="uncertain-actions">
				<button
					type="button"
					class="primary-button"
					data-testid="unresolved-retry"
					:disabled="rewardsStore.saving"
					@click="retryUnresolved">
					{{ rewardsStore.saving ? t('chorestrike', 'Retrying…') : t('chorestrike', 'Retry action') }}
				</button>
				<button
					type="button"
					class="secondary-button"
					data-testid="unresolved-dismiss"
					:disabled="rewardsStore.saving"
					@click="rewardsStore.dismissUnresolved()">
					{{ t('chorestrike', 'Dismiss') }}
				</button>
			</div>
		</section>

		<div v-if="detail.loading"
			class="status-line"
			data-testid="detail-loading"
			role="status">
			{{ t('chorestrike', 'Loading the request…') }}
		</div>
		<EmptyState
			v-else-if="detail.unavailable"
			icon="Lock"
			data-testid="detail-unavailable"
			:message="t('chorestrike', 'This request is not available.')"
			:hint="t('chorestrike', 'It may belong to someone else or no longer exist.')" />
		<ErrorState
			v-else-if="detail.error"
			data-testid="detail-error"
			:message="detail.error"
			@retry="load" />

		<template v-else-if="request">
			<h1 class="detail-title" data-testid="detail-title">
				{{ request.title }}
			</h1>

			<section class="terms-card" data-testid="detail-terms">
				<p v-if="request.description" class="terms-description">
					{{ request.description }}
				</p>
				<p class="terms-line">
					{{ t('chorestrike', 'Price: {price} Coins', { price: formatCoinMinor(request.priceMinor) }) }}
					<span class="state-chip">{{ stateLabel(request.state) }}</span>
				</p>
				<p class="terms-line">
					{{ t('chorestrike', 'Requested by {name}', { name: requesterLabel }) }}
				</p>
				<p v-if="request.fundingHold"
					class="hold-note"
					data-testid="detail-hold"
					role="status">
					{{ t('chorestrike', 'Some balances are currently being clarified. Delivery and new requests wait until that is resolved — approval, rejection and cancellation stay possible.') }}
				</p>
			</section>

			<p v-if="rewardsStore.conflict"
				class="conflict-note"
				data-testid="detail-conflict"
				role="alert">
				{{ t('chorestrike', 'The request changed in the meantime — the current state is shown. Please review it before acting again.') }}
			</p>
			<p v-if="rewardsStore.refreshError"
				class="conflict-note"
				data-testid="detail-refresh-error"
				role="alert">
				{{ t('chorestrike', 'The action succeeded, but refreshing failed. Please reload the page.') }}
			</p>

			<!-- Current-role action hints (never authorization): the server
			     re-checks authority on every command. -->
			<section v-if="actions.length > 0" class="actions-bar" data-testid="detail-actions">
				<button
					v-for="action in actions"
					:key="action"
					type="button"
					class="action-button"
					:class="{ danger: action === 'reject' || action === 'correct' }"
					:data-testid="`action-${action}`"
					:disabled="actionDisabled(action)"
					@click="beginAction(action)">
					{{ actionLabel(action) }}
				</button>
			</section>

			<section class="audit-section" data-testid="detail-audit">
				<h2>{{ t('chorestrike', 'History') }}</h2>
				<EmptyState
					v-if="audit.length === 0"
					icon="List"
					:message="t('chorestrike', 'No history yet')" />
				<ol v-else class="audit-list">
					<li v-for="event in audit"
						:key="event.revision"
						class="audit-row"
						:data-testid="`audit-${event.revision}`">
						<span class="audit-action">{{ auditActionLabel(event.action) }}</span>
						<span class="audit-actor">{{ auditActorLabel(event) }}</span>
						<span v-if="event.reasonCode" class="audit-reason">{{ reasonLabel(event.reasonCode) }}</span>
						<time class="audit-time" :datetime="event.createdAt">{{ formatAuditTime(event.createdAt) }}</time>
					</li>
				</ol>
			</section>
		</template>

		<!-- Delivery confirmation: identifies the frozen reward and
		     requires the explicit attestation. -->
		<ConfirmDialog
			v-if="pendingAction === 'fulfill'"
			:title="fulfillTitle"
			:description="t('chorestrike', 'Only confirm once the reward was actually handed over. The {price} Coins are then spent exactly once.', { price: formatCoinMinor(request?.priceMinor ?? 0) })"
			:confirm-label="t('chorestrike', 'It was delivered')"
			:loading="rewardsStore.saving"
			data-testid="fulfill-dialog"
			@confirm="runFulfill"
			@cancel="pendingAction = null" />

		<ConfirmDialog
			v-if="pendingAction === 'approve'"
			:title="isOwnRequest
				? t('chorestrike', 'Approve your own request?')
				: t('chorestrike', 'Approve the request of {name}?', { name: requesterLabel })"
			:description="t('chorestrike', 'Approving reserves nothing new — the Coins stay reserved until delivery. A child’s request needs this owner approval; an adult explicitly approves their own request.')"
			:confirm-label="t('chorestrike', 'Approve')"
			:loading="rewardsStore.saving"
			data-testid="approve-dialog"
			@confirm="runApprove"
			@cancel="pendingAction = null" />

		<!-- Reject/cancel: closed-set reasons only, never free text. -->
		<Modal
			v-if="pendingAction === 'reject' || pendingAction === 'cancel'"
			:aria-label="pendingAction === 'reject' ? t('chorestrike', 'Decline the request?') : t('chorestrike', 'Cancel the request?')"
			:data-testid="`${pendingAction}-dialog`"
			@close="pendingAction = null">
			<div class="modal-content">
				<h3>
					{{ pendingAction === 'reject'
						? t('chorestrike', 'Decline the request?')
						: t('chorestrike', 'Cancel the request?') }}
				</h3>
				<p class="setting-hint">
					{{ pendingAction === 'reject'
						? t('chorestrike', 'The reserved Coins are released without a booked credit.')
						: t('chorestrike', 'The reservation is released without a booked credit. Cancellation stays possible until the reward is delivered.') }}
				</p>
				<label class="reason-field">
					{{ t('chorestrike', 'Reason (optional)') }}
					<select v-model="reasonCode" class="text-input" data-testid="reason-select">
						<option value="">{{ t('chorestrike', 'No reason given') }}</option>
						<option v-for="code in optionalReasons" :key="code" :value="code">
							{{ reasonLabel(code) }}
						</option>
					</select>
				</label>
				<div class="modal-actions">
					<button type="button"
						class="secondary-button"
						data-testid="reason-cancel"
						@click="pendingAction = null">
						{{ t('chorestrike', 'Back') }}
					</button>
					<button
						type="button"
						class="danger-button"
						:data-testid="`${pendingAction}-confirm`"
						:disabled="rewardsStore.saving"
						@click="pendingAction === 'reject' ? runReject() : runCancel()">
						{{ pendingAction === 'reject' ? t('chorestrike', 'Decline') : t('chorestrike', 'Cancel request') }}
					</button>
				</div>
			</div>
		</Modal>

		<!-- False-fulfillment correction: ONLY for a reward that was never
		     delivered, a required reason and the explicit attestation;
		     the refund is the server's original settlement entry. -->
		<Modal
			v-if="pendingAction === 'correct'"
			:aria-label="t('chorestrike', 'Report a reward as not delivered?')"
			data-testid="correct-dialog"
			@close="pendingAction = null">
			<div class="modal-content">
				<h3>{{ t('chorestrike', 'Report a reward as not delivered?') }}</h3>
				<p class="setting-hint">
					{{ t('chorestrike', 'This is only for a reward that was marked as delivered but never actually arrived. The original {price} Coins are refunded exactly once; there is no refund for a reward that was really delivered.', { price: formatCoinMinor(request?.priceMinor ?? 0) }) }}
				</p>
				<label class="reason-field">
					{{ t('chorestrike', 'Reason') }}
					<select v-model="reasonCode" class="text-input" data-testid="correct-reason-select">
						<option value="" disabled>{{ t('chorestrike', 'Choose a reason') }}</option>
						<option v-for="code in correctReasons" :key="code" :value="code">
							{{ reasonLabel(code) }}
						</option>
					</select>
				</label>
				<label class="attestation-field">
					<input
						v-model="attested"
						type="checkbox"
						class="attestation-checkbox"
						data-testid="correct-attestation">
					{{ t('chorestrike', 'I attest that this reward was never delivered.') }}
				</label>
				<div class="modal-actions">
					<button type="button"
						class="secondary-button"
						data-testid="correct-cancel"
						@click="pendingAction = null">
						{{ t('chorestrike', 'Back') }}
					</button>
					<button
						type="button"
						class="danger-button"
						data-testid="correct-confirm"
						:disabled="rewardsStore.saving || !reasonCode || !attested"
						@click="runCorrect">
						{{ t('chorestrike', 'Refund the Coins') }}
					</button>
				</div>
			</div>
		</Modal>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mapStores } from 'pinia'
import { useRewardsStore, REASON_CODES } from '../../store/rewards.js'
import { useGameStore } from '../../store/game.js'
import { useUserStore } from '../../store/user.js'
import { formatCoinMinor } from '../../utils/minorUnits.js'
import { logger } from '../../utils/logger.js'
import Modal from '../Common/Modal.vue'
import EmptyState from '../Common/EmptyState.vue'
import ErrorState from '../Common/ErrorState.vue'
import ConfirmDialog from '../Common/ConfirmDialog.vue'

/**
 * One authorized reward request (CS-PLAN-070 WP8 §3.4): frozen terms,
 * on-demand audit, and the current actor/state action forms. The action
 * set comes from the server's `allowedActions` VIEWER HINTS (never
 * authorization — the API re-checks authority under the lock); funding
 * holds disable fulfillment only, never approval/rejection/cancellation.
 * Former actors render as localized placeholders; another person's
 * wallet totals are never displayed.
 */
export default {
	name: 'RewardRequestDetails',

	components: {
		Modal,
		EmptyState,
		ErrorState,
		ConfirmDialog,
	},

	props: {
		requestId: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			pendingAction: null,
			reasonCode: '',
			attested: false,
		}
	},

	computed: {
		...mapStores(useRewardsStore, useGameStore, useUserStore),
		detail() {
			return this.rewardsStore.detail
		},
		request() {
			return this.detail.request
		},
		audit() {
			return this.detail.audit
		},
		actions() {
			return this.request?.allowedActions ?? []
		},
		isOwnRequest() {
			return this.request?.requester?.houseUserId != null
				&& this.request.requester.houseUserId === this.userStore.currentUser?.id
		},
		optionalReasons() {
			return REASON_CODES
		},
		correctReasons() {
			return REASON_CODES
		},
		requesterLabel() {
			const requester = this.request?.requester
			if (!requester || requester.isFormerMember || !requester.displayName) {
				return t('chorestrike', 'Former member')
			}
			return requester.displayName
		},
		// The quoted-title pattern must live in script — nested double
		// quotes are not parseable inside template attribute expressions.
		fulfillTitle() {
			return t('chorestrike', 'Confirm delivery of "{title}"?', { title: this.request?.title ?? '' })
		},
	},

	watch: {
		requestId: {
			immediate: true,
			handler() {
				this.pendingAction = null
				this.reasonCode = ''
				this.attested = false
				this.load()
			},
		},
	},

	methods: {
		t,
		formatCoinMinor,

		load() {
			this.rewardsStore.fetchRequestDetail(this.requestId).catch(() => {
				// Error/unavailable state renders from the store.
			})
		},

		stateLabel(state) {
			const labels = {
				pending: t('chorestrike', 'Waiting for approval'),
				approved: t('chorestrike', 'Approved, not delivered'),
				fulfilled: t('chorestrike', 'Delivered'),
				rejected: t('chorestrike', 'Declined'),
				cancelled: t('chorestrike', 'Cancelled'),
				corrected: t('chorestrike', 'Corrected'),
			}
			return labels[state] ?? state
		},

		auditActionLabel(action) {
			const labels = {
				create: t('chorestrike', 'Requested'),
				approve: t('chorestrike', 'Approved'),
				reject: t('chorestrike', 'Declined'),
				cancel: t('chorestrike', 'Cancelled'),
				fulfill: t('chorestrike', 'Delivered'),
				correct: t('chorestrike', 'Corrected (not delivered)'),
			}
			return labels[action] ?? action
		},

		reasonLabel(code) {
			const labels = {
				not_needed: t('chorestrike', 'Not needed'),
				changed_mind: t('chorestrike', 'Changed mind'),
				unavailable: t('chorestrike', 'No longer available'),
				duplicate: t('chorestrike', 'Duplicate'),
				not_delivered: t('chorestrike', 'Not delivered'),
				other: t('chorestrike', 'Other'),
			}
			return labels[code] ?? code
		},

		auditActorLabel(event) {
			const actor = event.actor
			if (!actor || actor.isFormerMember || !actor.displayName) {
				return t('chorestrike', 'Former member')
			}
			return actor.displayName
		},

		formatAuditTime(value) {
			if (!value) {
				return ''
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
		},

		actionLabel(action) {
			const labels = {
				approve: t('chorestrike', 'Approve'),
				reject: t('chorestrike', 'Decline'),
				cancel: t('chorestrike', 'Cancel'),
				fulfill: t('chorestrike', 'Confirm delivery'),
				correct: t('chorestrike', 'Report not delivered'),
			}
			return labels[action] ?? action
		},

		/**
		 * Funding holds disable fulfillment (and new requests elsewhere),
		 * never approval/rejection/cancellation.
		 * @param {string} action Action key.
		 * @return {boolean}
		 */
		actionDisabled(action) {
			return this.rewardsStore.saving || (action === 'fulfill' && this.request?.fundingHold === true)
		},

		beginAction(action) {
			this.pendingAction = action
			this.reasonCode = ''
			this.attested = false
		},

		async runCommand(command, successMessage) {
			try {
				const dto = await command()
				showSuccess(successMessage)
				this.pendingAction = null
				if (dto?.wallet) {
					this.userStore.applyOwnWallet(dto.wallet)
				}
				this.gameStore.fetchGameState().catch((error) => {
					logger.error('Failed to refresh the wallet after the request action', error, { component: 'RewardRequestDetails', action: 'runCommand' })
				})
			} catch (error) {
				if (error?.status === 409) {
					showError(t('chorestrike', 'The request changed in the meantime — the current state is shown. Please review it before acting again.'))
				} else {
					showError(t('chorestrike', 'The action could not be recorded.'))
				}
				this.pendingAction = null
				logger.error('Failed to run the request action', error, { component: 'RewardRequestDetails', action: 'runCommand' })
			}
		},

		runApprove() {
			return this.runCommand(
				() => this.rewardsStore.approveRequest(this.request),
				t('chorestrike', 'The request was approved.'),
			)
		},

		runReject() {
			return this.runCommand(
				() => this.rewardsStore.rejectRequest(this.request, this.reasonCode || null),
				t('chorestrike', 'The request was declined.'),
			)
		},

		runCancel() {
			return this.runCommand(
				() => this.rewardsStore.cancelRequest(this.request, this.reasonCode || null),
				t('chorestrike', 'The request was cancelled.'),
			)
		},

		runFulfill() {
			return this.runCommand(
				() => this.rewardsStore.fulfillRequest(this.request),
				t('chorestrike', 'The delivery was confirmed.'),
			)
		},

		runCorrect() {
			return this.runCommand(
				() => this.rewardsStore.correctRequest(this.request, this.reasonCode),
				t('chorestrike', 'The correction was recorded and the Coins were refunded.'),
			)
		},

		async retryUnresolved() {
			try {
				await this.rewardsStore.retryUnresolved()
				showSuccess(t('chorestrike', 'The action was completed.'))
				this.gameStore.fetchGameState().catch(() => {})
			} catch (error) {
				if (error?.status === 409) {
					showError(t('chorestrike', 'The request changed in the meantime — please review it before acting again.'))
				} else {
					showError(t('chorestrike', 'The action could not be retried.'))
				}
				logger.error('Failed to retry the unresolved command', error, { component: 'RewardRequestDetails', action: 'retryUnresolved' })
			}
		},
	},
}
</script>

<style scoped>
.request-detail-view {
	padding: 1rem 2rem 2rem;
	padding-inline-start: var(--cs-view-inline-start);
	max-width: 760px;
	margin: 0 auto;
}

.back-link {
	display: inline-block;
	min-height: 44px;
	padding: 0.5rem 0;
	color: var(--color-text-maxcontrast);
	text-decoration: none;
}

.back-link:hover {
	color: var(--color-main-text);
}

.uncertain-banner {
	margin-bottom: 1rem;
	padding: 1rem 1.25rem;
	border: 1px solid var(--color-element-warning);
	border-radius: 10px;
}

.uncertain-banner p {
	margin: 0 0 0.75rem 0;
}

.uncertain-actions {
	display: flex;
	gap: 0.75rem;
}

.status-line {
	padding: 2rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.detail-title {
	font-size: 1.6rem;
	margin: 0.5rem 0 1rem 0;
}

.terms-card {
	padding: 1rem 1.25rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.terms-description {
	margin: 0 0 0.75rem 0;
	white-space: pre-wrap;
}

.terms-line {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin: 0.25rem 0;
	flex-wrap: wrap;
}

.state-chip {
	padding: 0.15rem 0.6rem;
	border-radius: 999px;
	font-size: 0.8rem;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.hold-note {
	margin: 0.75rem 0 0 0;
	font-size: 0.9rem;
}

.conflict-note {
	margin: 1rem 0 0 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-warning);
	font-size: 0.9rem;
}

.actions-bar {
	display: flex;
	gap: 0.75rem;
	flex-wrap: wrap;
	margin: 1.25rem 0;
}

.action-button {
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--cs-radius-sm, 6px);
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.action-button.danger {
	border-color: var(--color-element-error);
	background: var(--color-error);
	color: var(--color-error-text);
}

.action-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.audit-section h2 {
	font-size: 1.1rem;
	margin: 0 0 0.75rem 0;
}

.audit-list {
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.audit-row {
	display: flex;
	align-items: baseline;
	gap: 0.75rem;
	flex-wrap: wrap;
	padding: 0.6rem 0.9rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	font-size: 0.9rem;
}

.audit-action {
	font-weight: 600;
}

.audit-actor,
.audit-reason,
.audit-time {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1rem 0;
}

.setting-hint {
	margin: 0 0 1rem 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.reason-field {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	margin-bottom: 1rem;
	font-size: 0.9rem;
}

.text-input {
	padding: 0.6rem 0.7rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.attestation-field {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	margin-bottom: 0.5rem;
	font-size: 0.95rem;
}

.attestation-checkbox {
	width: 1.1rem;
	height: 1.1rem;
	accent-color: var(--color-primary-element);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.25rem;
}

.primary-button,
.secondary-button {
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border-radius: var(--cs-radius-sm, 6px);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.primary-button {
	border: none;
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.primary-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.secondary-button {
	border: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-main-text);
}

.danger-button {
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border: 1px solid var(--color-element-error);
	border-radius: var(--cs-radius-sm, 6px);
	background: var(--color-error);
	color: var(--color-error-text);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 600;
}

.danger-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
