<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="request-list-view" data-testid="request-list-view">
		<header class="request-header">
			<h1 data-testid="request-list-title">
				{{ t('chorestrike', 'My Requests') }}
			</h1>
			<HelpEntry variant="context" topic="catalog" />
		</header>

		<!-- Uncertain command outcome: the SAME UUID/payload replay is
		     reachable from Requests even when browsing was hidden or
		     disabled afterwards — replay, not a new request. -->
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
		<p v-if="rewardsStore.refreshError"
			class="conflict-note"
			data-testid="requests-refresh-error"
			role="alert">
			{{ t('chorestrike', 'The action succeeded, but refreshing the list failed. Please reload the page.') }}
		</p>

		<!-- Available vs reserved from the existing wallet source; loads
		     independently — an empty request list still shows the own
		     wallet state. -->
		<section v-if="gameStore.wallet" class="wallet-banner" data-testid="requests-wallet">
			<span>{{ t('chorestrike', 'Available:') }} <strong data-testid="requests-wallet-available">{{ formatCoinMinor(gameStore.wallet.availableMinor) }}</strong></span>
			<span>{{ t('chorestrike', 'Reserved:') }} <strong data-testid="requests-wallet-reserved">{{ formatCoinMinor(gameStore.wallet.reservedMinor) }}</strong></span>
			<p class="wallet-hint">
				{{ t('chorestrike', 'Reserved Coins back your open requests. They are only spent once a reward is actually delivered.') }}
			</p>
			<p v-if="gameStore.wallet.fundingHold"
				class="hold-note"
				data-testid="requests-wallet-hold"
				role="status">
				{{ t('chorestrike', 'Some balances are currently being clarified. New requests and deliveries wait until that is resolved.') }}
			</p>
		</section>

		<div class="list-controls">
			<div class="scope-tabs" role="group" :aria-label="t('chorestrike', 'Request scope')">
				<button
					type="button"
					class="filter-button"
					:class="{ active: scope === 'mine' }"
					data-testid="scope-mine"
					@click="switchScope('mine')">
					{{ t('chorestrike', 'Mine') }}
				</button>
				<!-- Owner-only household administration: explicit scope,
				     never prefetched for ordinary members. -->
				<button
					v-if="userStore.isHouseOwner"
					type="button"
					class="filter-button"
					:class="{ active: scope === 'house' }"
					data-testid="scope-house"
					@click="switchScope('house')">
					{{ t('chorestrike', 'Household') }}
				</button>
			</div>
			<label class="state-filter">
				{{ t('chorestrike', 'Status') }}
				<select
					v-model="stateFilter"
					class="text-input"
					data-testid="state-filter"
					@change="applyFilter">
					<option value="">{{ t('chorestrike', 'All') }}</option>
					<option value="pending">{{ t('chorestrike', 'Waiting for approval') }}</option>
					<option value="approved">{{ t('chorestrike', 'Approved, not delivered') }}</option>
					<option value="fulfilled">{{ t('chorestrike', 'Delivered') }}</option>
					<option value="rejected">{{ t('chorestrike', 'Declined') }}</option>
					<option value="cancelled">{{ t('chorestrike', 'Cancelled') }}</option>
					<option value="corrected">{{ t('chorestrike', 'Corrected') }}</option>
				</select>
			</label>
		</div>

		<div v-if="page.loading && page.items.length === 0"
			class="status-line"
			data-testid="requests-loading"
			role="status">
			{{ t('chorestrike', 'Loading requests…') }}
		</div>
		<ErrorState
			v-else-if="page.error && page.items.length === 0"
			data-testid="requests-error"
			:message="page.error"
			@retry="reload" />
		<EmptyState
			v-else-if="page.items.length === 0"
			icon="List"
			data-testid="requests-empty"
			:message="scope === 'mine'
				? t('chorestrike', 'You have no reward requests yet.')
				: t('chorestrike', 'There are no household requests for this filter.')"
			:hint="scope === 'mine' ? t('chorestrike', 'Open the Rewards catalog to request a reward.') : ''">
			<template v-if="scope === 'mine'" #action>
				<router-link :to="{ name: 'rewards' }" class="button-link" data-testid="requests-open-catalog">
					{{ t('chorestrike', 'Open Rewards') }}
				</router-link>
			</template>
		</EmptyState>

		<template v-else>
			<ul class="request-list" data-testid="request-list">
				<li v-for="request in page.items" :key="request.id">
					<router-link
						class="request-row"
						:data-testid="`request-row-${request.id}`"
						:to="{ name: 'reward-request-detail', params: { requestId: request.id } }">
						<span class="request-main">
							<strong class="request-title">{{ request.title }}</strong>
							<span v-if="scope === 'house'" class="request-requester">
								{{ requesterLabel(request) }}
							</span>
						</span>
						<span class="request-side">
							<span class="request-price">{{ formatCoinMinor(request.priceMinor) }}</span>
							<span class="state-chip" :data-testid="`request-state-${request.id}`">{{ stateLabel(request.state) }}</span>
							<span v-if="request.fundingHold" class="hold-chip" :data-testid="`request-hold-${request.id}`">
								{{ t('chorestrike', 'Balance clarification') }}
							</span>
						</span>
					</router-link>
				</li>
			</ul>
			<button
				v-if="!page.exhausted"
				type="button"
				class="load-more"
				data-testid="requests-load-more"
				:disabled="page.loading"
				@click="loadMore">
				{{ page.loading ? t('chorestrike', 'Loading…') : t('chorestrike', 'Load more') }}
			</button>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mapStores } from 'pinia'
import { useRewardsStore } from '../../store/rewards.js'
import { useGameStore } from '../../store/game.js'
import { useUserStore } from '../../store/user.js'
import { formatCoinMinor } from '../../utils/minorUnits.js'
import { logger } from '../../utils/logger.js'
import EmptyState from '../Common/EmptyState.vue'
import ErrorState from '../Common/ErrorState.vue'
import HelpEntry from '../Help/HelpEntry.vue'

/**
 * Own and (owner-only) household reward-request lists (CS-PLAN-070 WP8
 * §3.1/§3.4). ONE persistent surface, independent of both catalog gates:
 * existing promises stay reachable while browsing is hidden or the house
 * gate is off. State labels are rendered as text (never color alone),
 * former actors render as localized placeholders, and another person's
 * wallet totals are never displayed.
 */
export default {
	name: 'RewardRequestList',

	components: {
		EmptyState,
		ErrorState,
		HelpEntry,
	},

	data() {
		return {
			scope: 'mine',
			stateFilter: '',
		}
	},

	computed: {
		...mapStores(useRewardsStore, useGameStore, useUserStore),
		page() {
			return this.rewardsStore.requests[this.scope]
		},
	},

	async mounted() {
		await this.initialize()
	},

	methods: {
		t,
		formatCoinMinor,

		async initialize() {
			try {
				// A forced identity read reconciles roles after changes —
				// the cached initialization is not a fresh authority check.
				await this.userStore.refreshCurrentUser()
			} catch (error) {
				logger.error('Failed to refresh the current role', error, { component: 'RewardRequestList', action: 'initialize' })
			}
			this.reload()
			// The wallet read is independent of the list (an empty list
			// still loads the own-wallet state).
			this.gameStore.fetchGameState().catch((error) => {
				logger.error('Failed to refresh the wallet', error, { component: 'RewardRequestList', action: 'initialize' })
			})
		},

		reload() {
			this.rewardsStore.fetchRequestPage(this.scope, { state: this.stateFilter || null, reset: true }).catch((error) => {
				logger.error('Failed to load the request list', error, { component: 'RewardRequestList', action: 'reload', scope: this.scope })
			})
		},

		switchScope(scope) {
			if (scope === this.scope) return
			this.scope = scope
			this.reload()
		},

		applyFilter() {
			this.reload()
		},

		loadMore() {
			this.rewardsStore.fetchRequestPage(this.scope, { state: this.stateFilter || null }).catch((error) => {
				showError(t('chorestrike', 'The requests could not be loaded.'))
				logger.error('Failed to load the next request page', error, { component: 'RewardRequestList', action: 'loadMore' })
			})
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
				logger.error('Failed to retry the unresolved command', error, { component: 'RewardRequestList', action: 'retryUnresolved' })
			}
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

		/**
		 * The authorized requester reference: departed/erased actors
		 * render as a localized placeholder, never with identifiers.
		 * @param {object} request Request DTO.
		 * @return {string}
		 */
		requesterLabel(request) {
			const requester = request.requester
			if (!requester || requester.isFormerMember || !requester.displayName) {
				return t('chorestrike', 'Former member')
			}
			return requester.displayName
		},
	},
}
</script>

<style scoped>
.request-list-view {
	padding: 1rem 2rem 2rem;
	max-width: 900px;
	margin: 0 auto;
}

.request-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1.25rem;
}

.request-header h1 {
	font-size: 2rem;
	margin: 0;
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

.conflict-note {
	margin: 0 0 1rem 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-warning);
	font-size: 0.9rem;
}

.wallet-banner {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 1.25rem;
	padding: 1rem 1.25rem;
	margin-bottom: 1.25rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-background-dark);
}

.wallet-hint {
	flex-basis: 100%;
	margin: 0.25rem 0 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.hold-note {
	flex-basis: 100%;
	margin: 0.5rem 0 0 0;
	font-size: 0.9rem;
}

.list-controls {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
	margin-bottom: 1rem;
}

.scope-tabs {
	display: flex;
	gap: 0.5rem;
}

.filter-button {
	min-height: 44px;
	padding: 0.4rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--cs-radius-sm, 6px);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
}

.filter-button.active {
	background: var(--color-primary);
	border-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

.state-filter {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 0.9rem;
}

.text-input {
	padding: 0.5rem 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.status-line {
	padding: 2rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.button-link {
	display: inline-block;
	min-height: 44px;
	padding: 0.6rem 1.2rem;
	border-radius: var(--cs-radius-sm, 6px);
	background: var(--color-primary);
	color: var(--color-primary-text);
	font-size: 0.9rem;
	font-weight: 600;
	text-decoration: none;
}

.request-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.request-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 0.9rem 1.1rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	text-decoration: none;
	color: var(--color-main-text);
}

.request-row:hover,
.request-row:focus-visible {
	border-color: var(--color-primary-element);
}

.request-main {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-width: 0;
}

.request-requester {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.request-side {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	flex-shrink: 0;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.request-price {
	font-weight: 600;
}

.state-chip,
.hold-chip {
	padding: 0.15rem 0.6rem;
	border-radius: 999px;
	font-size: 0.8rem;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.hold-chip {
	border-color: var(--color-element-warning);
	color: var(--color-main-text);
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

.load-more {
	display: block;
	margin: 1.25rem auto 0;
	min-height: 44px;
	padding: 0.6rem 1.4rem;
	border: 1px solid var(--color-border);
	border-radius: var(--cs-radius-sm, 6px);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
}

@media (max-width: 640px) {
	.request-row {
		flex-direction: column;
		align-items: stretch;
	}

	.request-side {
		justify-content: flex-start;
	}
}
</style>
