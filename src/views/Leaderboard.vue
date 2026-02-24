<template>
	<div class="leaderboard">
		<header class="page-header">
			<h1>{{ t('chorestrike', 'Leaderboard') }}</h1>
			<p>{{ t('chorestrike', 'Track team contribution and weekly medals.') }}</p>
		</header>

		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading leaderboard...') }}</p>
		</div>

		<div v-else>
			<p v-if="error" class="error-message">
				{{ error }}
			</p>

			<section class="card">
				<h2>{{ t('chorestrike', 'Top Contributors') }}</h2>
				<ol v-if="rankedEntries.length" class="ranking-list">
					<li v-for="entry in rankedEntries" :key="entry.id || entry.nextcloudUserId" class="ranking-item">
						<div class="rank">
							#{{ entry.rank }}
						</div>
						<div class="person">
							<strong>{{ entry.displayName || entry.name || t('chorestrike', 'Unknown') }}</strong>
							<span>{{ t('chorestrike', 'Streak: {n}', { n: entry.currentStreak || 0 }) }}</span>
						</div>
						<div class="metrics">
							<span class="chip contribution">⭐ {{ entry.contributionScore || 0 }}</span>
							<span class="chip sparks">⚡ {{ entry.teamSparks || 0 }}</span>
						</div>
					</li>
				</ol>
				<p v-else class="empty-state">
					{{ t('chorestrike', 'No ranking data available yet.') }}
				</p>
			</section>

			<section class="card">
				<h2>{{ t('chorestrike', 'Medals') }}</h2>
				<MedalList
					v-if="gameStore.medals.length"
					:medals="gameStore.medals"
					show-dates
					:empty-message="t('chorestrike', 'No medals awarded yet.')" />
				<p v-else class="empty-state">
					{{ t('chorestrike', 'No medals awarded yet.') }}
				</p>
			</section>
		</div>
	</div>
</template>

<script>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useGameStore } from '../store/game.js'
import { useUserStore } from '../store/user.js'
import MedalList from '../components/Common/MedalList.vue'
import MedalBadge from '../components/Common/MedalBadge.vue'

export default {
	name: 'Leaderboard',
	components: {
		MedalList,
		MedalBadge,
	},
	setup() {
		const gameStore = useGameStore()
		const userStore = useUserStore()
		const loading = ref(false)
		const error = ref('')

		const rankedEntries = computed(() => {
			const source = gameStore.leaderboard.length ? gameStore.leaderboard : userStore.getLeaderboard
			return [...source]
				.sort((a, b) => (b.contributionScore || 0) - (a.contributionScore || 0))
				.map((entry, index) => ({ ...entry, rank: index + 1 }))
		})

		const loadData = async () => {
			loading.value = true
			error.value = ''
			try {
				await Promise.all([
					gameStore.initializeGame(),
					userStore.initializeUsers(),
				])
			} catch (loadError) {
				error.value = t('chorestrike', 'Some game data could not be loaded. Showing available data.')
				try {
					await userStore.initializeUsers()
				} catch (userError) {
					console.error(userError)
				}
			}
			loading.value = false
		}

		onMounted(loadData)

		return {
			loading,
			error,
			rankedEntries,
			t,
		}
	},
}
</script>

<style scoped>
.leaderboard {
	padding: 2rem;
	max-width: 960px;
	margin: 0 auto;
}

.page-header {
	margin-bottom: 1rem;
}

.page-header h1 {
	margin: 0 0 0.5rem;
}

.page-header p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.card {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background: var(--color-main-background);
	padding: 1rem;
	margin-top: 1rem;
}

.card h2 {
	margin: 0 0 1rem;
	font-size: 1.1rem;
}

.ranking-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 0.5rem;
}

.ranking-item {
	display: grid;
	grid-template-columns: auto 1fr auto;
	gap: 0.75rem;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 0.65rem;
}

.rank {
	font-weight: 700;
	font-size: 1rem;
}

.person span {
	display: block;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.metrics {
	display: flex;
	gap: 0.4rem;
}

.chip {
	padding: 0.25rem 0.5rem;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
}

.chip.contribution {
	background: #eef4ff;
	color: #2f5ea8;
}

.chip.sparks {
	background: #fff8e8;
	color: #b7791f;
}

.medal-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 0.75rem;
}

.medal-item {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 0.75rem;
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 0.6rem;
	align-items: center;
}

.medal-icon {
	font-size: 1.35rem;
}

.medal-item p {
	margin: 0.2rem 0 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.error-message {
	background: #fdeeee;
	color: #b03a2e;
	padding: 0.6rem 0.75rem;
	border-radius: 6px;
	margin-top: 0.75rem;
}

.empty-state {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.loading-state {
	display: grid;
	justify-items: center;
	padding: 2rem 0;
}

.loading-spinner {
	width: 28px;
	height: 28px;
	border: 3px solid var(--color-border);
	border-top-color: var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}
</style>
