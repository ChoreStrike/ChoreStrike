<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal :show="show" :aria-label="t('chorestrike', 'Choose your title')" @close="$emit('close')">
		<div class="title-picker" data-testid="title-picker">
			<div class="picker-header">
				<h3>{{ t('chorestrike', 'Choose your title') }}</h3>
				<p class="picker-subtitle">
					{{ t('chorestrike', 'Pick a progression track. Tiers unlock automatically as you play.') }}
				</p>
			</div>

			<div v-if="titlesStore.loading" class="picker-loading">
				{{ t('chorestrike', 'Loading...') }}
			</div>

			<div v-else class="packs">
				<section
					v-for="packKey in packKeys"
					:key="packKey"
					class="pack-card"
					:class="{ selected: isSelected(packKey) }"
					:data-testid="`title-pack-${packKey}`">
					<header class="pack-header">
						<h4>{{ packLabel(packKey) }}</h4>
						<button
							v-if="!isSelected(packKey)"
							class="select-button"
							data-testid="select-pack-button"
							:disabled="!houseUserId"
							@click="onSelect(packKey)">
							{{ t('chorestrike', 'Set as my title') }}
						</button>
						<span v-else class="active-tag" data-testid="active-pack-tag">
							{{ t('chorestrike', 'Active') }}
						</span>
					</header>

					<ul class="tier-list">
						<li
							v-for="(tier, index) in tiersFor(packKey)"
							:key="tier.key"
							class="tier-item"
							:data-testid="`tier-${packKey}-${index}`"
							:class="tierStyle(index)">
							<span class="tier-name">{{ tierLabel(tier) }}</span>
							<span class="tier-status">
								<template v-if="isUnlocked(packKey, index)">
									✓
								</template>
								<template v-else>
									{{ formatNumber(tier.threshold) }}
								</template>
							</span>
						</li>
					</ul>

					<div class="progress-row" :data-testid="`progress-${packKey}`">
						<div class="progress-bar">
							<div class="progress-fill" :style="{ width: progressPercent(packKey) + '%' }" />
						</div>
						<span class="progress-text">{{ progressText(packKey) }}</span>
					</div>
				</section>
			</div>

			<div v-if="titlesStore.error" class="picker-error" data-testid="title-picker-error">
				{{ titlesStore.error }}
			</div>

			<div class="picker-actions">
				<button class="secondary-button" @click="$emit('close')">
					{{ t('chorestrike', 'Close') }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Modal from '../Common/Modal.vue'
import { useTitlesStore } from '../../store/titles.js'
import { useUserStore } from '../../store/user.js'
import { TIER_STYLES } from '../../constants/titles.js'
import { logger } from '../../utils/logger.js'

/**
 * Title-selection modal. Renders every pack with its tiers, a progress bar
 * toward the next tier, and a "set as my title" action. Unlocks are
 * server-authoritative — this component only displays thresholds/status from
 * the catalog and the current user's stats.
 */
export default {
	name: 'TitlePicker',
	components: { Modal },
	props: {
		/**
		 * Whether the modal is visible.
		 */
		show: {
			type: Boolean,
			default: false,
		},
		/**
		 * Internal house user id of the active user (used for selectPack).
		 */
		houseUserId: {
			type: [Number, String],
			default: null,
		},
	},
	emits: ['close', 'selected'],
	setup(props, { emit }) {
		const titlesStore = useTitlesStore()
		const userStore = useUserStore()

		// Drive the pack list from the server catalog so a pack added
		// server-side is rendered without a frontend change. The PHP
		// getCatalogView preserves PACKS insertion order.
		const packKeys = computed(() => Object.keys(titlesStore.catalog || {}))

		const stats = computed(() => userStore.currentUser || {})

		// Server-authoritative unlocked tiers, as a `pack.tierKey` set. The
		// /titles endpoint returns the live-derived unlock list; we mirror it
		// here instead of recomputing `signal >= threshold` from a separately
		// fetched (and optimistically mutated) stats object, so the checkmarks
		// can never disagree with the server's currentTitle.
		const unlockedSet = computed(() => {
			const set = new Set()
			for (const tier of titlesStore.unlockedTitles || []) {
				if (tier && tier.pack && tier.tierKey) {
					set.add(`${tier.pack}.${tier.tierKey}`)
				}
			}
			return set
		})

		/**
		 * Read the progression-signal value for a pack off the active user.
		 * Used only for the progress-bar fill, not for unlock decisions.
		 * @param {string} packKey Pack key
		 */
		function signalValue(packKey) {
			const pack = titlesStore.catalog?.[packKey]
			if (!pack) {
				return 0
			}
			const value = stats.value[pack.signal]
			return Number.isFinite(value) ? value : 0
		}

		function tiersFor(packKey) {
			return titlesStore.catalog?.[packKey]?.tiers || []
		}

		function packLabel(packKey) {
			const key = titlesStore.catalog?.[packKey]?.labelKey
			return key ? t('chorestrike', key) : packKey
		}

		function tierLabel(tier) {
			return tier?.labelKey ? t('chorestrike', tier.labelKey) : tier?.key
		}

		function isUnlocked(packKey, index) {
			const tier = tiersFor(packKey)[index]
			if (!tier) {
				return false
			}
			return unlockedSet.value.has(`${packKey}.${tier.key}`)
		}

		function tierStyle(index) {
			return TIER_STYLES[index]?.class || null
		}

		function isSelected(packKey) {
			return titlesStore.selectedTitlePack === packKey
		}

		/**
		 * Index of the highest tier unlocked in a pack (-1 when none), derived
		 * from the server-authoritative unlocked set.
		 * @param {string} packKey Pack key
		 */
		function highestUnlockedIndex(packKey) {
			const tiers = tiersFor(packKey)
			let highest = -1
			tiers.forEach((tier, index) => {
				if (unlockedSet.value.has(`${packKey}.${tier.key}`)) {
					highest = index
				}
			})
			return highest
		}

		function progressPercent(packKey) {
			const tiers = tiersFor(packKey)
			const highest = highestUnlockedIndex(packKey)
			// All tiers unlocked → full bar.
			if (highest >= tiers.length - 1) {
				return 100
			}
			const value = signalValue(packKey)
			const from = highest >= 0 ? tiers[highest].threshold : 0
			const to = tiers[highest + 1].threshold
			if (to <= from) {
				return 100
			}
			const pct = ((value - from) / (to - from)) * 100
			return Math.max(0, Math.min(100, Math.round(pct)))
		}

		function progressText(packKey) {
			const tiers = tiersFor(packKey)
			const highest = highestUnlockedIndex(packKey)
			const value = signalValue(packKey)
			if (highest >= tiers.length - 1) {
				return t('chorestrike', 'All tiers unlocked!')
			}
			const next = tiers[highest + 1]
			return `${formatNumber(value)} / ${formatNumber(next.threshold)}`
		}

		function formatNumber(n) {
			return Number(n || 0).toLocaleString()
		}

		async function onSelect(packKey) {
			try {
				await titlesStore.selectPack(props.houseUserId, packKey)
				emit('selected', packKey)
			} catch (error) {
				logger.error('Title selection failed', error, { component: 'TitlePicker', packKey })
			}
		}

		return {
			titlesStore,
			packKeys,
			tiersFor,
			packLabel,
			tierLabel,
			isUnlocked,
			tierStyle,
			isSelected,
			progressPercent,
			progressText,
			onSelect,
			formatNumber,
			t,
		}
	},
}
</script>

<style scoped>
.title-picker {
	padding: 1.25rem;
	max-width: 560px;
}

.picker-header {
	margin-bottom: 1rem;
}

.picker-header h3 {
	margin: 0 0 0.25rem 0;
	font-size: 1.2rem;
}

.picker-subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.picker-loading {
	padding: 2rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.packs {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.pack-card {
	background: var(--color-background-dark);
	border: 2px solid transparent;
	border-radius: var(--cs-radius-md, 10px);
	padding: 1rem;
}

.pack-card.selected {
	border-color: var(--color-primary);
}

.pack-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.75rem;
	gap: 0.5rem;
}

.pack-header h4 {
	margin: 0;
	font-size: 1rem;
}

.select-button {
	padding: 0.4rem 0.75rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: var(--cs-radius-sm, 6px);
	cursor: pointer;
	font-size: 0.8rem;
	font-weight: 500;
	white-space: nowrap;
}

.select-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.active-tag {
	font-size: 0.72rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 0.2rem 0.5rem;
	border-radius: var(--cs-radius-pill, 999px);
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.tier-list {
	list-style: none;
	margin: 0 0 0.75rem 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.3rem;
}

.tier-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.35rem 0.6rem;
	border-radius: var(--cs-radius-sm, 6px);
	font-size: 0.85rem;
	background: var(--color-main-background);
	border: 1px solid transparent;
}

.tier-status {
	font-weight: 600;
}

.tier-bronze {
	border-color: var(--cs-tier-bronze-border);
}

.tier-silver {
	border-color: var(--cs-tier-silver-border);
}

.tier-gold {
	border-color: var(--cs-tier-gold-border);
}

.progress-row {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.progress-bar {
	flex: 1;
	height: 6px;
	background: var(--color-border);
	border-radius: var(--cs-radius-pill, 999px);
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background: var(--color-primary);
	transition: width var(--cs-transition-base, 200ms ease);
}

.progress-text {
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.picker-error {
	margin-top: 0.75rem;
	color: var(--color-error);
	font-size: 0.85rem;
}

.picker-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 1rem;
}

.secondary-button {
	padding: 0.5rem 1rem;
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--cs-radius-sm, 6px);
	cursor: pointer;
	font-size: 0.9rem;
}
</style>
