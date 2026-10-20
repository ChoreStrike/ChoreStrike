<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="leaderboard" data-testid="leaderboard-view">
		<header class="leaderboard-header">
			<h1 data-testid="leaderboard-title">
				{{ t('chorestrike', 'Leaderboard') }}
			</h1>
			<p class="subtitle">
				{{ t('chorestrike', 'Opt-in weekly rhythm ranking for your household') }}
			</p>
		</header>

		<div class="leaderboard-content">
			<!-- Opt-in / closed state: ranking disabled or no visible consent yet -->
			<div v-if="!rankingStore.rankingEnabled" class="rank-closed" data-testid="ranking-disabled">
				<Icon name="Users" :size="48" />
				<h2>{{ t('chorestrike', 'Weekly ranking is off') }}</h2>
				<p>
					{{ t('chorestrike', 'The household ranking is opt-in. A house owner can enable it in the settings — every member then decides on their own participation.') }}
				</p>
			</div>

			<template v-else>
				<!-- Own participation panel -->
				<div class="participation-panel" data-testid="ranking-participation">
					<template v-if="isPending">
						<p class="participation-state" data-testid="ranking-pending-state">
							{{ t('chorestrike', 'Your participation starts on {monday}. Nothing is counted before then.', { monday: formattedPendingMonday }) }}
						</p>
						<button
							class="secondary-button"
							data-testid="ranking-withdraw-button"
							@click="withdraw">
							{{ t('chorestrike', 'Withdraw participation') }}
						</button>
					</template>
					<template v-else-if="hasConsented">
						<p class="participation-state">
							{{ t('chorestrike', 'You take part in this week\'s ranking.') }}
						</p>
						<button
							class="secondary-button"
							data-testid="ranking-withdraw-button"
							@click="withdraw">
							{{ t('chorestrike', 'Withdraw participation') }}
						</button>
					</template>
					<template v-else>
						<p class="participation-state" data-testid="ranking-optin-hint">
							{{ t('chorestrike', 'You are not part of the ranking. Participation is voluntary and only ever shows your on-time share of agreed public tasks — starting next Monday.') }}
						</p>
						<button
							class="primary-button"
							data-testid="ranking-consent-button"
							@click="giveConsent">
							{{ t('chorestrike', 'Join next week') }}
						</button>
					</template>
				</div>

				<!-- Week header -->
				<div class="week-header" data-testid="ranking-week">
					<span>{{ t('chorestrike', 'Current week') }}</span>
					<span
						v-if="isProvisional"
						class="provisional-badge"
						data-testid="ranking-provisional">
						{{ t('chorestrike', 'Provisional — days are still open') }}
					</span>
				</div>

				<!-- A11y pass (CS-PLAN-070 WP6 §3.6, T7): week and
				     provisional-state changes are announced politely without
				     moving focus. -->
				<p class="visually-hidden"
					role="status"
					aria-live="polite"
					data-testid="ranking-status">
					{{ statusAnnouncement }}
				</p>

				<!-- Ranked entries (consented members only) -->
				<div v-if="rankingStore.entries.length > 0"
					class="leaderboard-list"
					data-testid="leaderboard-list"
					role="list"
					:aria-label="t('chorestrike', 'Ranked household members')">
					<div class="list-header" aria-hidden="true">
						<span class="rank-col">#</span>
						<span class="user-col">{{ t('chorestrike', 'Player') }}</span>
						<span class="ratio-col">{{ t('chorestrike', 'On time') }}</span>
					</div>

					<div
						v-for="entry in rankingStore.entries"
						:key="entry.houseUserId"
						class="leaderboard-row"
						role="listitem"
						data-testid="leaderboard-entry"
						:class="{ 'current-user': entry.isMe, neutral: entry.neutral }">
						<span class="rank-col" data-testid="user-rank">
							<template v-if="entry.neutral">
								<span class="neutral-badge" data-testid="ranking-neutral">{{ t('chorestrike', 'Neutral') }}</span>
							</template>
							<template v-else>{{ entry.rank }}<template v-if="entry.tied"> *</template></template>
						</span>
						<span class="user-col">
							<div class="user-info">
								<div class="avatar-small">
									{{ getInitials(entry.displayName) }}
								</div>
								<span class="user-name">{{ entry.displayName }}</span>
								<span v-if="entry.isMe" class="you-badge">{{ t('chorestrike', 'You') }}</span>
							</div>
						</span>
						<span class="ratio-col" data-testid="ranking-ratio">
							<template v-if="!entry.neutral">{{ entry.onTime }}/{{ entry.eligible }}</template>
							<template v-else>—</template>
						</span>
					</div>
					<p class="tie-hint" data-testid="ranking-tie-hint">
						{{ t('chorestrike', '* same on-time share — tied') }}
					</p>
				</div>

				<div v-else class="empty-state" data-testid="empty-leaderboard">
					<Icon name="Users" :size="48" />
					<p>{{ t('chorestrike', 'Nobody is ranked yet') }}</p>
					<p class="empty-hint">
						{{ t('chorestrike', 'Members appear here after their consent becomes effective on a Monday.') }}
					</p>
				</div>

				<!-- Pending consents (consented, not yet effective — C5l) -->
				<div v-if="rankingStore.pending.length > 0" class="pending-section" data-testid="ranking-pending">
					<h2>{{ t('chorestrike', 'Starting next week') }}</h2>
					<p
						v-for="entry in rankingStore.pending"
						:key="entry.houseUserId"
						class="pending-row"
						:data-testid="`ranking-pending-${entry.houseUserId}`">
						{{ t('chorestrike', '{name} joins on {monday}', { name: entry.displayName, monday: entry.effectiveMonday }) }}
					</p>
				</div>
			</template>

			<!-- Weekly medals (store-driven; CS-PLAN-041) -->
			<div v-if="weeklyMedals.length > 0" class="medals-section" data-testid="medal-summary">
				<h2>{{ t('chorestrike', 'This Week\'s Medals') }}</h2>
				<div class="medals-grid">
					<div
						v-for="medal in weeklyMedals"
						:key="medal.id"
						class="medal-card"
						data-testid="medal-badge"
						:data-medal-type="medal.type">
						<MedalBadge :type="medal.type" size="large" />
						<div class="medal-recipient">
							<span class="medal-label">{{ medal.label }}</span>
							<span class="recipient-name">{{ medal.recipient }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../components/Common/Icon.vue'
import MedalBadge, { MEDAL_LABELS } from '../components/Common/MedalBadge.vue'
import { useGameStore } from '../store/game.js'
import { useRankingStore } from '../store/ranking.js'
import { useUserStore } from '../store/user.js'
import { logger } from '../utils/logger.js'

export default {
	name: 'Leaderboard',

	components: {
		Icon,
		MedalBadge,
	},

	setup() {
		const userStore = useUserStore()
		const gameStore = useGameStore()
		const rankingStore = useRankingStore()
		return { userStore, gameStore, rankingStore }
	},

	computed: {
		/**
		 * A11y pass (T7): the polite live-region text mirrors the visible
		 * week/provisional state so screen-reader users hear state
		 * changes without focus movement.
		 */
		statusAnnouncement() {
			if (!this.rankingStore.rankingEnabled) {
				return this.t('chorestrike', 'Weekly ranking is off')
			}
			if (this.rankingStore.entries.length === 0) {
				return this.t('chorestrike', 'Nobody is ranked yet')
			}
			return this.isProvisional
				? this.t('chorestrike', 'Provisional — days are still open')
				: this.t('chorestrike', 'The weekly ranking is final.')
		},

		hasConsented() {
			return this.rankingStore.me?.state === 'effective'
		},

		isPending() {
			return this.rankingStore.me?.state === 'pending'
		},

		formattedPendingMonday() {
			return this.rankingStore.me?.effectiveMonday ?? ''
		},

		isProvisional() {
			return this.rankingStore.week?.provisional === true
		},

		/**
		 * Weekly medals from the game store, mapped for display. The store is
		 * populated by fetchMedals() (after the check-medals evaluation
		 * pre-pass) in initializeLeaderboard().
		 */
		weeklyMedals() {
			return this.gameStore.medals.map((medal) => ({
				id: medal.id,
				type: medal.medalType,
				label: MEDAL_LABELS[medal.medalType]
					? t('chorestrike', MEDAL_LABELS[medal.medalType])
					: medal.medalType,
				recipient: medal.userName,
			}))
		},
	},

	async mounted() {
		await this.initializeLeaderboard()
	},

	methods: {
		t,

		async initializeLeaderboard() {
			this.loading = true
			try {
				await this.userStore.initializeCurrentUser()
				// The consent-filtered server view is the sole ranking
				// authority (D10b/C5i) — no client-side member ranking.
				await this.rankingStore.fetchRanking()
				// Evaluate any due weekly medals first so fetchMedals() picks
				// up genuinely new awards (celebrations fire once, guarded by
				// the previous snapshot inside the store).
				await this.gameStore.checkMedals()
				await this.gameStore.fetchMedals()
			} catch (error) {
				logger.error('Failed to initialize leaderboard', error, { view: 'Leaderboard', action: 'initializeLeaderboard' })
			} finally {
				this.loading = false
			}
		},

		async giveConsent() {
			try {
				await this.rankingStore.giveConsent()
				showSuccess(t('chorestrike', 'You join the ranking next Monday.'))
			} catch {
				showError(t('chorestrike', 'Failed to update participation'))
			}
		},

		async withdraw() {
			try {
				await this.rankingStore.withdrawConsent()
				showSuccess(t('chorestrike', 'You left the ranking.'))
			} catch {
				showError(t('chorestrike', 'Failed to update participation'))
			}
		},

		getInitials(name) {
			if (!name) {
				return '?'
			}
			return name
				.split(' ')
				.map((word) => word[0])
				.join('')
				.toUpperCase()
				.slice(0, 2)
		},
	},
}
</script>

<style scoped>
.leaderboard {
	padding: 1rem 2rem 2rem;
	padding-inline-start: var(--cs-view-inline-start);
	max-width: 1000px;
	margin: 0 auto;
}

.leaderboard-header {
	text-align: center;
	margin-bottom: 2rem;
}

.leaderboard-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.leaderboard-content {
	display: flex;
	flex-direction: column;
	gap: 2rem;
}

/* Opt-in / closed state */
.rank-closed {
	text-align: center;
	padding: 3rem 2rem;
	background: var(--color-background-dark);
	border-radius: 12px;
	color: var(--color-text-maxcontrast);
}

.rank-closed h2 {
	margin: 1rem 0 0.5rem 0;
	font-size: 1.2rem;
	color: var(--color-main-text);
}

.rank-closed p {
	max-width: 480px;
	margin: 0 auto;
}

/* Own participation panel */
.participation-panel {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	padding: 1rem 1.5rem;
	background: var(--color-background-dark);
	border-radius: 12px;
}

.participation-state {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

/* Week header */
.week-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	font-weight: 500;
}

/* Screen-reader-only live region (T7 a11y pass). */
.visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.provisional-badge {
	font-size: 0.75rem;
	padding: 0.15rem 0.6rem;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Leaderboard List */
.leaderboard-list {
	background: var(--color-background-dark);
	border-radius: 12px;
	overflow: hidden;
}

.list-header {
	display: grid;
	grid-template-columns: 90px 2fr 1fr;
	gap: 1rem;
	padding: 1rem 1.5rem;
	background: var(--color-main-background);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.leaderboard-row {
	display: grid;
	grid-template-columns: 90px 2fr 1fr;
	gap: 1rem;
	padding: 1rem 1.5rem;
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	transition: background-color 0.2s;
}

.leaderboard-row:hover {
	background: var(--color-main-background);
}

.leaderboard-row.current-user {
	background: rgba(var(--color-primary-rgb), 0.1);
	font-weight: 500;
}

.leaderboard-row.neutral {
	opacity: 0.75;
}

.rank-col {
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 600;
}

.neutral-badge {
	font-size: 0.72rem;
	padding: 0.15rem 0.5rem;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.user-col {
	display: flex;
	align-items: center;
}

.user-info {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.avatar-small {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background: var(--color-primary);
	color: var(--color-primary-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
	font-weight: bold;
}

.user-name {
	font-weight: 500;
}

.you-badge {
	font-size: 0.7rem;
	padding: 0.15rem 0.5rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border-radius: 10px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.ratio-col {
	font-weight: 600;
	color: var(--color-primary-element);
	text-align: center;
}

.tie-hint {
	margin: 0;
	padding: 0.75rem 1.5rem;
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

/* Pending consents */
.pending-section h2 {
	font-size: 1.1rem;
	margin: 0 0 0.75rem 0;
}

.pending-row {
	margin: 0 0 0.35rem 0;
	color: var(--color-text-maxcontrast);
}

.primary-button,
.secondary-button {
	padding: 0.5rem 1rem;
	border: none;
	border-radius: var(--border-radius-pill, 999px);
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
}

.primary-button {
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.secondary-button {
	background: transparent;
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.empty-state {
	text-align: center;
	padding: 3rem;
	color: var(--color-text-maxcontrast);
}

.empty-state p {
	margin: 1rem 0 0.5rem 0;
}

.empty-hint {
	font-size: 0.9rem;
}

/* Medals Section */
.medals-section {
	margin-top: 1rem;
}

.medals-section h2 {
	font-size: 1.2rem;
	margin: 0 0 1rem 0;
	text-align: center;
}

.medals-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.medal-card {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: 12px;
}

.medal-recipient {
	display: flex;
	flex-direction: column;
}

.medal-label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.recipient-name {
	font-weight: 500;
}

@media (max-width: 768px) {
	.list-header {
		display: none;
	}

	.leaderboard-row {
		grid-template-columns: 60px 1fr auto;
		gap: 0.75rem;
	}

	.participation-panel {
		flex-direction: column;
		align-items: flex-start;
	}
}
</style>
