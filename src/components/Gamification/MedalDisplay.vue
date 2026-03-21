<template>
	<div class="medal-display" data-testid="medal-display">
		<div v-if="medals.length > 0" class="medals-container">
			<div
				v-for="medal in medals"
				:key="medal.id"
				:class="['medal-badge', `rarity-${medal.rarity}`]"
				:title="medal.description"
				data-testid="medal-badge">
				<div class="medal-icon">
					{{ medal.icon }}
				</div>
				<div class="medal-info">
					<div class="medal-name">
						{{ medal.name }}
					</div>
					<div class="medal-points">
						+{{ medal.points }} {{ t('chorestrike', 'Coins') }}
					</div>
				</div>
			</div>
		</div>
		<div v-else class="no-medals">
			<p>{{ t('chorestrike', 'No medals yet') }}</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { logger } from '../../utils/logger.js'

interface Medal {
	id: number
	userId: string
	medalType: string
	name: string
	description: string
	icon: string
	points: number
	rarity: string
	awardedAt: string
}

const props = defineProps<{
	userId: string
}>()

const medals = ref<Medal[]>([])
const loading = ref<boolean>(false)

const medalInfo: Record<string, any> = {
	team_anchor: {
		name: t('chorestrike', 'Team Anchor'),
		description: t('chorestrike', 'Complete 10+ tasks in a week'),
		icon: '🏆',
		points: 50,
		rarity: 'common',
	},
	streak_keeper: {
		name: t('chorestrike', 'Streak Keeper'),
		description: t('chorestrike', 'Maintain a 7+ day streak'),
		icon: '🔥',
		points: 100,
		rarity: 'rare',
	},
	combo_master: {
		name: t('chorestrike', 'Combo Master'),
		description: t('chorestrike', 'Achieve a 5+ task combo'),
		icon: '⚡',
		points: 75,
		rarity: 'rare',
	},
	perfect_week: {
		name: t('chorestrike', 'Perfect Week'),
		description: t('chorestrike', 'Complete all tasks assigned in a week'),
		icon: '✨',
		points: 200,
		rarity: 'epic',
	},
	legendary_helper: {
		name: t('chorestrike', 'Legendary Helper'),
		description: t('chorestrike', 'Complete 100+ total tasks'),
		icon: '👑',
		points: 500,
		rarity: 'legendary',
	},
}

const loadMedals = async (): Promise<void> => {
	loading.value = true
	try {
		const response = await axios.get(`/api/v1/game/medals/${props.userId}`)
		if (response.data.success) {
			medals.value = response.data.data.map((medal: any) => ({
				...medal,
				...medalInfo[medal.medalType],
			}))
		}
	} catch (error) {
		logger.error('Failed to load medals', error, { component: 'MedalDisplay', action: 'loadMedals', userId: props.userId })
	} finally {
		loading.value = false
	}
}

onMounted(() => {
	loadMedals()
})
</script>

<style scoped>
.medal-display {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.medals-container {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}

.medal-badge {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem 1rem;
	border-radius: 8px;
	border: 2px solid;
	cursor: pointer;
	transition: all 0.2s ease;
}

.medal-badge:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.medal-badge.rarity-common {
	background: linear-gradient(135deg, #e0e0e0, #f5f5f5);
	border-color: #9e9e9e;
	color: #424242;
}

.medal-badge.rarity-rare {
	background: linear-gradient(135deg, #bbdefb, #e3f2fd);
	border-color: #1976d2;
	color: #0d47a1;
}

.medal-badge.rarity-epic {
	background: linear-gradient(135deg, #ce93d8, #f3e5f5);
	border-color: #7b1fa2;
	color: #4a148c;
}

.medal-badge.rarity-legendary {
	background: linear-gradient(135deg, #ffd54f, #fff9c4);
	border-color: #f57f17;
	color: #f57c00;
}

.medal-icon {
	font-size: 1.5rem;
	line-height: 1;
}

.medal-info {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.medal-name {
	font-weight: 600;
	font-size: 0.9rem;
}

.medal-points {
	font-size: 0.8rem;
	opacity: 0.8;
}

.no-medals {
	padding: 1rem;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

@media (max-width: 768px) {
	.medal-badge {
		padding: 0.5rem 0.75rem;
		font-size: 0.9rem;
	}

	.medal-icon {
		font-size: 1.25rem;
	}
}
</style>
