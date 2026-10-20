<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="rewards-view" data-testid="rewards-view">
		<header class="rewards-header">
			<h1 data-testid="rewards-title">
				{{ t('chorestrike', 'Rewards') }}
			</h1>
			<HelpEntry variant="context" topic="catalog" />
		</header>

		<!-- Direct entry while browsing is hidden/disabled: a neutral
		     explanation — never an auto-opt-in, stale items or a second
		     authority over the gates. -->
		<EmptyState
			v-if="!browsable && gatesResolved"
			icon="Gift"
			data-testid="rewards-unavailable"
			:message="t('chorestrike', 'The reward catalog is not available right now.')"
			:hint="t('chorestrike', 'An owner can enable it in the Settings, and each person can show or hide it in their own notification settings. Your existing requests stay reachable under My Requests.')">
			<template #action>
				<router-link :to="{ name: 'reward-requests' }" class="button-link" data-testid="rewards-open-requests">
					{{ t('chorestrike', 'Open My Requests') }}
				</router-link>
			</template>
		</EmptyState>
		<ErrorState
			v-else-if="loadError"
			data-testid="rewards-error"
			:message="loadError"
			@retry="loadAll" />

		<template v-else-if="browsable">
			<!-- Available vs reserved: the caller's OWN wallet from the
			     existing game-state read — never a second wallet
			     authority and never a local request-price sum. -->
			<section v-if="gameStore.wallet" class="wallet-banner" data-testid="rewards-wallet">
				<span class="wallet-line">
					{{ t('chorestrike', 'Available:') }}
					<strong data-testid="wallet-available">{{ formatCoinMinor(gameStore.wallet.availableMinor) }}</strong>
				</span>
				<span class="wallet-line">
					{{ t('chorestrike', 'Reserved:') }}
					<strong data-testid="wallet-reserved">{{ formatCoinMinor(gameStore.wallet.reservedMinor) }}</strong>
				</span>
				<p class="wallet-hint">
					{{ t('chorestrike', 'Reserved Coins are set aside for your open requests. A request is only spent once a reward is actually delivered.') }}
				</p>
				<p v-if="gameStore.wallet.fundingHold"
					class="hold-note"
					data-testid="wallet-hold"
					role="status">
					{{ t('chorestrike', 'Some balances are currently being clarified. New requests and deliveries wait until that is resolved — nothing is lost.') }}
				</p>
			</section>

			<p v-if="rewardsStore.conflict"
				class="conflict-note"
				data-testid="catalog-conflict"
				role="alert">
				{{ t('chorestrike', 'The catalog changed while you were looking — please review the current items before requesting again.') }}
			</p>
			<p v-if="rewardsStore.refreshError"
				class="conflict-note"
				data-testid="catalog-refresh-error"
				role="alert">
				{{ t('chorestrike', 'The action succeeded, but refreshing the list failed. Please reload the page.') }}
			</p>

			<div v-if="catalogLoading && catalogItems.length === 0"
				class="status-line"
				data-testid="catalog-loading"
				role="status">
				{{ t('chorestrike', 'Loading the catalog…') }}
			</div>
			<EmptyState
				v-else-if="catalogItems.length === 0"
				icon="Gift"
				data-testid="catalog-empty"
				:message="t('chorestrike', 'The catalog is empty.')"
				:hint="t('chorestrike', 'An owner can add rewards in the Settings.')" />

			<ul v-else class="catalog-list" data-testid="catalog-list">
				<li
					v-for="item in catalogItems"
					:key="item.id"
					class="catalog-item"
					:data-testid="`catalog-item-${item.id}`">
					<div class="catalog-item-body">
						<h3 class="catalog-item-title" data-testid="catalog-item-title">
							{{ item.title }}
						</h3>
						<p v-if="item.description" class="catalog-item-description">
							{{ item.description }}
						</p>
					</div>
					<div class="catalog-item-side">
						<strong class="catalog-item-price" data-testid="catalog-item-price">
							{{ formatCoinMinor(item.priceMinor) }}
						</strong>
						<button
							type="button"
							class="primary-button request-button"
							:data-testid="`catalog-request-${item.id}`"
							:disabled="requesting || walletOnHold"
							@click="beginRequest(item)">
							{{ t('chorestrike', 'Request') }}
						</button>
					</div>
				</li>
			</ul>

			<button
				v-if="catalogItems.length > 0 && !catalogExhausted"
				type="button"
				class="load-more"
				data-testid="catalog-load-more"
				:disabled="catalogLoading"
				@click="loadMore">
				{{ catalogLoading ? t('chorestrike', 'Loading…') : t('chorestrike', 'Load more') }}
			</button>

			<!-- Request confirmation: the frozen displayed terms are
			     reviewed explicitly before anything is reserved. -->
			<Modal
				v-if="requestItem"
				:aria-label="t('chorestrike', 'Request this reward?')"
				data-testid="request-confirm"
				@close="cancelRequest">
				<div class="modal-content">
					<h3>{{ t('chorestrike', 'Request this reward?') }}</h3>
					<p class="frozen-terms">
						<strong data-testid="request-confirm-title">{{ requestItem.title }}</strong>
						<span v-if="requestItem.description" data-testid="request-confirm-description">{{ requestItem.description }}</span>
						<span>{{ t('chorestrike', 'Price: {price} Coins', { price: formatCoinMinor(requestItem.priceMinor) }) }}</span>
					</p>
					<p class="setting-hint">
						{{ t('chorestrike', 'The Coins are reserved now and only spent once the reward is actually delivered. You can cancel while it is not delivered.') }}
					</p>
					<p v-if="requestError"
						class="error-note"
						data-testid="request-error"
						role="alert">
						{{ requestError }}
					</p>
					<div class="modal-actions">
						<button type="button"
							class="secondary-button"
							data-testid="request-cancel"
							@click="cancelRequest">
							{{ t('chorestrike', 'Cancel') }}
						</button>
						<button
							type="button"
							class="primary-button"
							data-testid="request-confirm-button"
							:disabled="rewardsStore.saving"
							@click="submitRequest">
							{{ rewardsStore.saving ? t('chorestrike', 'Requesting…') : t('chorestrike', 'Request') }}
						</button>
					</div>
				</div>
			</Modal>
		</template>
		<div v-else
			class="status-line"
			data-testid="rewards-resolving"
			role="status">
			{{ t('chorestrike', 'Loading…') }}
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import EmptyState from '../components/Common/EmptyState.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import Modal from '../components/Common/Modal.vue'
import HelpEntry from '../components/Help/HelpEntry.vue'
import { useGameStore } from '../store/game.js'
import { useHouseStore } from '../store/house.js'
import { usePreferencesStore } from '../store/preferences.js'
import { useRewardsStore } from '../store/rewards.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'
import { formatCoinMinor } from '../utils/minorUnits.js'

/**
 * The optional reward catalog surface (CS-PLAN-070 WP8 §3.1/§3.4). Both
 * independent gates decide whether browsing renders; a direct hidden
 * entry gets a neutral explanation. Money/state stays server-
 * authoritative: the wallet banner reads the existing game-state source,
 * and a request is a server command with the displayed item's frozen
 * terms — never an optimistic local reservation.
 */
export default {
	name: 'Rewards',

	components: {
		Modal,
		EmptyState,
		ErrorState,
		HelpEntry,
	},

	data() {
		return {
			loadError: null,
			requestItem: null,
			requestError: null,
			requesting: false,
			catalogRequested: false,
		}
	},

	computed: {
		...mapStores(useRewardsStore, useHouseStore, usePreferencesStore, useGameStore, useUserStore),
		gatesResolved() {
			return this.houseStore.hasHouse
				&& this.houseStore.house !== null
				&& this.preferencesStore.loaded
		},
		/** Both gates open — identical contract to the navigation entry. */
		browsable() {
			return this.houseStore.catalogEnabled
				&& this.preferencesStore.loaded
				&& this.preferencesStore.catalogVisible === true
		},
		catalogItems() {
			return this.rewardsStore.catalog.active.items
		},
		catalogLoading() {
			return this.rewardsStore.catalog.active.loading
		},
		catalogExhausted() {
			return this.rewardsStore.catalog.active.exhausted
		},
		walletOnHold() {
			return this.gameStore.wallet?.fundingHold === true
		},
	},

	watch: {
		/**
		 * The catalog read may only start once BOTH gates resolve — the
		 * bootstrap house/preference reads land asynchronously after mount,
		 * so a one-shot mounted() fetch would silently skip browsing while
		 * the gates were still unknown.
		 */
		browsable: {
			immediate: true,
			handler(value) {
				if (value) {
					this.ensureCatalog()
				}
			},
		},
		/**
		 * Returning to this route reuses the component instance (no
		 * remount): refresh the wallet source so a hold cleared elsewhere
		 * (e.g. an owner cancelled the under-funded request) is reflected
		 * — otherwise the request buttons would stay stalely disabled.
		 * @param {object} to The target route record.
		 */
		$route(to) {
			if (to.name === 'rewards') {
				this.gameStore.fetchGameState().catch(() => {})
			}
		},
	},

	async mounted() {
		await this.loadAll()
	},

	methods: {
		t,
		formatCoinMinor,

		async loadAll() {
			this.loadError = null
			try {
				// Load the house flag through the guarded house read; the
				// personal preference only counts once fully loaded
				// (fail-closed while unknown/loading/failed).
				if (!this.houseStore.hasHouse) {
					await this.houseStore.fetchHouse()
				}
				if (!this.preferencesStore.loaded) {
					await this.preferencesStore.fetchPreferences()
				}
				// The wallet read is independent of the catalog gate and
				// powers the available-vs-reserved explanation.
				this.gameStore.fetchGameState().catch(() => {})
				// The catalog read itself is driven by the `browsable`
				// watcher — only when both gates have resolved may items
				// load. On a retry the gates are already resolved (the
				// watcher will not re-fire), so the catalog read
				// restarts here.
				if (this.browsable) {
					this.ensureCatalog()
				}
			} catch (error) {
				this.loadError = error?.message ?? t('chorestrike', 'Something went wrong')
				logger.error('Failed to load the reward catalog', error, { view: 'Rewards', action: 'loadAll' })
			}
		},

		ensureCatalog() {
			if (this.catalogRequested) {
				return
			}
			this.catalogRequested = true
			this.rewardsStore.fetchCatalogPage('active', { reset: true }).catch((error) => {
				// A failed read may be retried: release the one-shot guard
				// so the ErrorState retry (or a later gate flip) refetches.
				this.catalogRequested = false
				this.loadError = error?.message ?? t('chorestrike', 'Something went wrong')
				logger.error('Failed to load the catalog', error, { view: 'Rewards', action: 'ensureCatalog' })
			})
		},

		loadMore() {
			this.rewardsStore.fetchCatalogPage('active').catch((error) => {
				showError(t('chorestrike', 'The catalog could not be loaded.'))
				logger.error('Failed to load the next catalog page', error, { view: 'Rewards', action: 'loadMore' })
			})
		},

		beginRequest(item) {
			// The intent is built from the EXPLICITLY DISPLAYED item; the
			// server validates the frozen terms against the live item.
			this.requestItem = item
			this.requestError = null
		},

		cancelRequest() {
			if (this.rewardsStore.saving) {
				return
			}
			this.requestItem = null
			this.requestError = null
		},

		async submitRequest() {
			if (!this.requestItem || this.requesting) {
				return
			}
			this.requesting = true
			this.requestError = null
			try {
				const dto = await this.rewardsStore.createRequest(this.requestItem)
				showSuccess(t('chorestrike', 'The request was created.'))
				this.requestItem = null
				this.refreshOwnWallet(dto)
			} catch (error) {
				if (error?.status === 409) {
					// Terms drift, gate/archive or funding: the store
					// re-read the authorized sources; the user reviews the
					// refreshed terms — never a silent reservation.
					this.requestItem = null
				} else {
					this.requestError = error?.message ?? t('chorestrike', 'The request could not be created.')
				}
				logger.error('Failed to create the reward request', error, { view: 'Rewards', action: 'submitRequest' })
			} finally {
				this.requesting = false
			}
		},

		/**
		 * Reconcile the caller's own wallet through the existing reads —
		 * no optimistic arithmetic anywhere.
		 * @param {object} dto The command response (optional own wallet).
		 */
		refreshOwnWallet(dto) {
			if (dto?.wallet) {
				this.userStore.applyOwnWallet(dto.wallet)
			}
			this.gameStore.fetchGameState().catch((error) => {
				showError(t('chorestrike', 'The request was saved, but the balance could not be refreshed.'))
				logger.error('Failed to refresh the wallet after the request', error, { view: 'Rewards', action: 'refreshOwnWallet' })
			})
		},
	},
}
</script>

<style scoped>
.rewards-view {
	padding: 1rem 2rem 2rem;
	padding-inline-start: var(--cs-view-inline-start);
	max-width: 900px;
	margin: 0 auto;
}

.rewards-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 1.5rem;
}

.rewards-header h1 {
	font-size: 2rem;
	margin: 0;
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

.status-line {
	padding: 2rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.wallet-banner {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 1.25rem;
	padding: 1rem 1.25rem;
	margin-bottom: 1.5rem;
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
	color: var(--color-main-text);
}

.conflict-note {
	margin: 0 0 1rem 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-warning);
	font-size: 0.9rem;
}

.error-note {
	margin: 0 0 1rem 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-error);
	font-size: 0.9rem;
}

.catalog-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.catalog-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 1rem;
	padding: 1rem 1.25rem;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
}

.catalog-item-title {
	margin: 0 0 0.25rem 0;
	font-size: 1.05rem;
}

.catalog-item-description {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	white-space: pre-wrap;
}

.catalog-item-side {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-shrink: 0;
}

.catalog-item-price {
	font-size: 1.1rem;
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

.modal-content {
	padding: 1rem;
}

.modal-content h3 {
	margin: 0 0 1rem 0;
}

.frozen-terms {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	margin: 0 0 1rem 0;
}

.setting-hint {
	margin: 0 0 1rem 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1.25rem;
}

@media (max-width: 640px) {
	.catalog-item {
		flex-direction: column;
		align-items: stretch;
	}

	.catalog-item-side {
		justify-content: space-between;
	}
}
</style>
