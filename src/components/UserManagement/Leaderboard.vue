<template>
	<div class="leaderboard" data-testid="leaderboard">
		<div class="leaderboard-header">
			<h2>{{ t('chorestrike', 'Leaderboard') }}</h2>
			<div class="leaderboard-filters">
				<button
					v-for="filter in filters"
					:key="filter.value"
					:class="['filter-button', { active: activeFilter === filter.value }]"
					@click="activeFilter = filter.value">
					{{ filter.label }}
				</button>
			</div>
		</div>

		<!-- Loading State -->
		<div v-if="loading" class="loading-state">
			<div class="loading-spinner" />
			<p>{{ t('chorestrike', 'Loading leaderboard...') }}</p>
		</div>

		<!-- Leaderboard Table -->
		<div v-else-if="leaderboardData.length > 0" class="leaderboard-container">
			<table class="leaderboard-table">
				<thead>
					<tr>
						<th class="rank-column">{{ t('chorestrike', 'Rank') }}</th>
						<th class="name-column">{{ t('chorestrike', 'Member') }}</th>
						<th v-if="activeFilter === 'points'" class="score-column">{{ t('chorestrike', 'Points') }}</th>
						<th v-if="activeFilter === 'streak'" class="score-column">{{ t('chorestrike', 'Current Streak') }}</th>
						<th v-if="activeFilter === 'tasks'" class="score-column">{{ t('chorestrike', 'Tasks Completed') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(user, index) in leaderboardData"
						:key="user.id"
						:class="['leaderboard-row', { 'current-user': user.userId === currentUserId }]">
						<td class="rank-column">
							<div class="rank-badge">{{ index + 1 }}</div>
						</td>
						<td class="name-column">
							<div class="user-info">
								<div class="user-name">{{ user.displayName }}</div>
								<div class="user-role">{{ getRoleLabel(user.role) }}</div>
							</div>
						</td>
						<td v-if="activeFilter === 'points'" class="score-column">
							<div class="score-value">{{ user.totalPoints }}</div>
						</td>
						<td v-if="activeFilter === 'streak'" class="score-column">
							<div class="score-value">{{ user.currentStreak }}</div>
							<div class="longest-streak">{{ t('chorestrike', 'Best: {count}', { count: user.longestStreak }) }}</div>
						</td>
						<td v-if="activeFilter === 'tasks'" class="score-column">
							<div class="score-value">{{ user.tasksCompleted }}</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Empty State -->
		<div v-else class="empty-state">
			<div class="empty-icon">📊</div>
			<h3>{{ t('chorestrike', 'No Data Yet') }}</h3>
			<p>{{ t('chorestrike', 'Complete tasks to appear on the leaderboard') }}</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

interface LeaderboardUser {
	id: number
	userId: string
	displayName: string
	role: string
	totalPoints: number
	currentStreak: number
	longestStreak: number
	tasksCompleted: number
}

interface Filter {
	label: string
	value: string
}

const loading = ref<boolean>(false)
const activeFilter = ref<string>('points')
const leaderboardData = ref<LeaderboardUser[]>([])
const currentUserId = ref<string>('current-user')

const filters: Filter[] = [
	{ label: t('chorestrike', 'Points'), value: 'points' },
	{ label: t('chorestrike', 'Streaks'), value: 'streak' },
	{ label: t('chorestrike', 'Tasks'), value: 'tasks' }
]

const loadLeaderboard = async (): Promise<void> => {
	loading.value = true
	try {
		let endpoint = '/api/v1/user-stats/leaderboard'

		if (activeFilter.value === 'streak') {
			endpoint = '/api/v1/user-stats/streak-leaderboard'
		} else if (activeFilter.value === 'tasks') {
			endpoint = '/api/v1/user-stats/tasks-leaderboard'
		}

		const response = await axios.get(endpoint)
		if (response.data.success) {
			leaderboardData.value = response.data.data
		}
	} catch (error) {
		console.error('Failed to load leaderboard:', error)
	} finally {
		loading.value = false
	}
}

const getRoleLabel = (role: string): string => {
	const labels: Record<string, string> = {
		owner: t('chorestrike', 'Owner'),
		member: t('chorestrike', 'Member'),
		child: t('chorestrike', 'Child')
	}
	return labels[role] || role
}

onMounted(() => {
	loadLeaderboard()
})
</script>

<style scoped>
.leaderboard {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.leaderboard-header {
	margin-bottom: 2rem;
}

.leaderboard-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 1rem;
}

.leaderboard-filters {
	display: flex;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.filter-button {
	padding: 0.5rem 1rem;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	cursor: pointer;
	font-weight: 500;
	transition: all 0.2s ease;
}

.filter-button:hover {
	background: var(--color-border);
}

.filter-button.active {
	background: var(--color-primary);
	color: white;
	border-color: var(--color-primary);
}

.loading-state {
	text-align: center;
	padding: 3rem 0;
}

.loading-spinner {
	width: 40px;
	height: 40px;
	border: 3px solid var(--color-border);
	border-top: 3px solid var(--color-primary);
	border-radius: 50%;
	animation: spin 1s linear infinite;
	margin: 0 auto 1rem;
}

@keyframes spin {
	0% { transform: rotate(0deg); }
	100% { transform: rotate(360deg); }
}

.leaderboard-container {
	overflow-x: auto;
}

.leaderboard-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 1rem;
}

.leaderboard-table thead {
	background: var(--color-background-dark);
	border-bottom: 2px solid var(--color-border);
}

.leaderboard-table th {
	padding: 1rem;
	text-align: left;
	font-weight: 600;
	color: var(--color-main-text);
	font-size: 0.95rem;
}

.leaderboard-table td {
	padding: 1rem;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-main-text);
}

.leaderboard-row {
	transition: background-color 0.2s ease;
}

.leaderboard-row:hover {
	background: var(--color-background-dark);
}

.leaderboard-row.current-user {
	background: rgba(0, 0, 0, 0.05);
	font-weight: 500;
}

.rank-column {
	width: 80px;
	text-align: center;
}

.rank-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	background: var(--color-primary);
	color: white;
	border-radius: 50%;
	font-weight: 600;
	font-size: 1.1rem;
}

.name-column {
	flex: 1;
	min-width: 200px;
}

.user-info {
	display: flex;
	flex-direction: column;
}

.user-name {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.user-role {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.score-column {
	width: 150px;
	text-align: right;
}

.score-value {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-primary);
}

.longest-streak {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
}

.empty-state {
	text-align: center;
	padding: 3rem 0;
}

.empty-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.empty-state h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.empty-state p {
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.leaderboard-table {
		font-size: 0.9rem;
	}

	.leaderboard-table th,
	.leaderboard-table td {
		padding: 0.75rem 0.5rem;
	}

	.rank-badge {
		width: 32px;
		height: 32px;
		font-size: 0.9rem;
	}

	.score-column {
		width: 120px;
	}
}
</style>
