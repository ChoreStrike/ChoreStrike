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
				{{ t('chorestrike', 'Friendly competition for a cleaner home') }}
			</p>
		</header>

		<div class="leaderboard-content">
			<!-- Podium for top 3 -->
			<div v-if="leaderboard.length >= 3" class="podium" data-testid="podium-section">
				<div
					v-for="user in podiumUsers"
					:key="user.id"
					class="podium-item"
					:data-testid="`podium-rank-${user.rank}`"
					:class="`rank-${user.rank}`">
					<div class="podium-avatar">
						<div class="avatar-placeholder">
							{{ getInitials(user.displayName) }}
						</div>
						<div class="rank-badge">
							{{ user.rank }}
						</div>
					</div>
					<div class="podium-info">
						<h3>{{ user.displayName }}</h3>
						<TitleBadge
							v-if="user.currentTitle"
							:title="user.currentTitle"
							compact />
						<p class="podium-score">
							{{ formatScore(user.contributionMinor) }} {{ t('chorestrike', 'pts') }}
						</p>
					</div>
					<div class="podium-bar" :style="{ height: `${getPodiumHeight(user.rank)}px` }" />
				</div>
			</div>

			<!-- Full list -->
			<div class="leaderboard-list" data-testid="leaderboard-list">
				<div class="list-header">
					<span class="rank-col">#</span>
					<span class="user-col">{{ t('chorestrike', 'Player') }}</span>
					<span class="score-col" data-testid="contribution-score-header">{{ t('chorestrike', 'Score') }}</span>
					<span class="streak-col">{{ t('chorestrike', 'Streak') }}</span>
					<span class="tasks-col">{{ t('chorestrike', 'Tasks') }}</span>
				</div>

				<div
					v-for="user in rankedLeaderboard"
					:key="user.id"
					class="leaderboard-row"
					data-testid="leaderboard-entry"
					:class="{
						'top-3': user.rank <= 3,
						'current-user': isCurrentUser(user)
					}">
					<span class="rank-col" data-testid="user-rank">
						<template v-if="user.rank <= 3">
							<Icon
								:name="user.rank === 1 ? 'Crown' : 'Medal'"
								:size="20"
								:class="`rank-${user.rank}-icon`" />
						</template>
						<template v-else>{{ user.rank }}</template>
					</span>
					<span class="user-col">
						<div class="user-info">
							<div class="avatar-small">
								{{ getInitials(user.displayName) }}
							</div>
							<span class="user-name">{{ user.displayName }}</span>
							<TitleBadge
								v-if="user.currentTitle"
								:title="user.currentTitle"
								compact />
							<span v-if="isCurrentUser(user)" class="you-badge">{{ t('chorestrike', 'You') }}</span>
						</div>
					</span>
					<span class="score-col" data-testid="contribution-score">{{ formatScore(user.contributionMinor) }}</span>
					<span class="streak-col" data-testid="user-streak">
						<span
							class="streak-badge"
							:class="{ active: user.currentStreak > 0, frozen: isFrozen(user) }"
							:data-testid="`user-freezes-${user.id}`">
							<Icon name="Zap" :size="14" />
							{{ user.currentStreak }}
						</span>
					</span>
					<span class="tasks-col" data-testid="tasks-completed-stat">{{ user.totalTasksCompleted || 0 }}</span>
				</div>

				<div v-if="leaderboard.length === 0" class="empty-state" data-testid="empty-leaderboard">
					<Icon name="Users" :size="48" />
					<p>{{ t('chorestrike', 'No players yet') }}</p>
					<p class="empty-hint">
						{{ t('chorestrike', 'Invite family members to start competing!') }}
					</p>
				</div>
			</div>

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
						<MedalBadge :type="medal.type" :size="'large'" />
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
import { translate as t } from '@nextcloud/l10n'
import { useUserStore } from '../store/user.js'
import { useGameStore } from '../store/game.js'
import { logger } from '../utils/logger.js'
import Icon from '../components/Common/Icon.vue'
import MedalBadge, { MEDAL_LABELS } from '../components/Common/MedalBadge.vue'
import TitleBadge from '../components/Profile/TitleBadge.vue'

export default {
	name: 'Leaderboard',

	components: {
		Icon,
		MedalBadge,
		TitleBadge,
	},

	setup() {
		const userStore = useUserStore()
		const gameStore = useGameStore()
		return { userStore, gameStore }
	},

	data() {
		return {
			loading: false,
		}
	},

	computed: {
		leaderboard() {
			return this.userStore.getLeaderboard
		},

		rankedLeaderboard() {
			return this.leaderboard.map((user, index) => ({
				...user,
				rank: index + 1,
			}))
		},

		podiumUsers() {
			return this.rankedLeaderboard.slice(0, 3)
		},

		/**
		 * Weekly medals from the game store, mapped for display. The store is
		 * populated by fetchMedals() (after the check-medals evaluation
		 * pre-pass) in initializeLeaderboard().
		 */
		weeklyMedals() {
			return this.gameStore.medals.map(medal => ({
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
				// Evaluate any due weekly medals first so fetchMedals() picks
				// up genuinely new awards (celebrations fire once, guarded by
				// the previous snapshot inside the store).
				await this.userStore.initializeCurrentUser()
				await this.userStore.fetchHouseUsers()
				await this.gameStore.checkMedals()
				await this.gameStore.fetchMedals()
			} catch (error) {
				logger.error('Failed to initialize leaderboard', error, { view: 'Leaderboard', action: 'initializeLeaderboard' })
			} finally {
				this.loading = false
			}
		},

		getInitials(name) {
			if (!name) return '?'
			return name
				.split(' ')
				.map(word => word[0])
				.join('')
				.toUpperCase()
				.slice(0, 2)
		},

		formatScore(score) {
			return score?.toLocaleString() || '0'
		},

		isCurrentUser(user) {
			return user.nextcloudUserId === this.userStore.userId
		},

		/**
		 * Freeze styling retired with the WP3 cutover (dated pauses are the
		 * neutral absence mechanism) — the badge never renders frozen.
		 *
		 * @return {boolean} Always false.
		 */
		isFrozen() {
			return false
		},

		getPodiumHeight(rank) {
			const heights = { 1: 120, 2: 80, 3: 60 }
			return heights[rank] || 40
		},
	},
}
</script>

<style scoped>
.leaderboard {
	padding: 1rem 2rem 2rem;
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

/* Podium */
.podium {
	display: flex;
	justify-content: center;
	align-items: flex-end;
	gap: 1rem;
	padding: 2rem;
	background: var(--color-background-dark);
	border-radius: 12px;
}

.podium-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.75rem;
}

.podium-avatar {
	position: relative;
}

.avatar-placeholder {
	width: 64px;
	height: 64px;
	border-radius: 50%;
	background: var(--color-primary);
	color: var(--color-primary-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 1.2rem;
	font-weight: bold;
}

.rank-1 .avatar-placeholder {
	width: 80px;
	height: 80px;
	font-size: 1.5rem;
	background: linear-gradient(135deg, #ffd700, #ffb347);
	color: #333;
}

.rank-2 .avatar-placeholder {
	background: linear-gradient(135deg, #c0c0c0, #a0a0a0);
	color: #333;
}

.rank-3 .avatar-placeholder {
	background: linear-gradient(135deg, #cd7f32, #b87333);
}

.rank-badge {
	position: absolute;
	bottom: -4px;
	inset-inline-end: -4px;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--color-primary);
	color: var(--color-primary-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 0.75rem;
	font-weight: bold;
}

.podium-info {
	text-align: center;
}

.podium-info h3 {
	margin: 0 0 0.25rem 0;
	font-size: 1rem;
}

.podium-score {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.podium-bar {
	width: 80px;
	background: var(--color-primary);
	border-radius: 4px 4px 0 0;
	opacity: 0.3;
}

/* Leaderboard List */
.leaderboard-list {
	background: var(--color-background-dark);
	border-radius: 12px;
	overflow: hidden;
}

.list-header {
	display: grid;
	grid-template-columns: 50px 2fr 1fr 80px 80px;
	gap: 1rem;
	padding: 1rem 1.5rem;
	background: var(--color-main-background);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.leaderboard-row {
	display: grid;
	grid-template-columns: 50px 2fr 1fr 80px 80px;
	gap: 1rem;
	padding: 1rem 1.5rem;
	align-items: center;
	border-bottom: 1px solid var(--color-border);
	transition: background-color 0.2s;
}

.leaderboard-row:hover {
	background: var(--color-main-background);
}

.leaderboard-row.top-3 {
	background: rgba(var(--color-primary-rgb), 0.05);
}

.leaderboard-row.current-user {
	background: rgba(var(--color-primary-rgb), 0.1);
	font-weight: 500;
}

.rank-col {
	display: flex;
	align-items: center;
	justify-content: center;
}

.rank-1-icon {
	color: #ffd700;
}

.rank-2-icon {
	color: #c0c0c0;
}

.rank-3-icon {
	color: #cd7f32;
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

.score-col {
	font-weight: 600;
	color: var(--color-primary);
}

.streak-badge {
	display: inline-flex;
	align-items: center;
	gap: 0.25rem;
	padding: 0.25rem 0.5rem;
	background: var(--color-background-dark);
	border-radius: 12px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.streak-badge.active {
	background: #ffeb3b;
	color: #333;
}

.freeze-indicator {
	font-size: 0.75rem;
	padding-inline-start: 0.35rem;
	border-inline-start: 1px solid currentcolor;
}

.streak-badge.frozen {
	background: #b3e5fc;
	color: #01579b;
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
	.podium {
		padding: 1rem;
	}

	.list-header {
		display: none;
	}

	.leaderboard-row {
		grid-template-columns: 40px 1fr auto;
		gap: 0.75rem;
	}

	.streak-col,
	.tasks-col {
		display: none;
	}
}
</style>
