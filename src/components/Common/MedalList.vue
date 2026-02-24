<template>
	<div class="medal-list">
		<div v-if="groupedMedals.length === 0" class="empty-state">
			{{ emptyMessage }}
		</div>
		<div v-else class="medal-groups">
			<div
				v-for="group in groupedMedals"
				:key="group.type"
				class="medal-group"
				@click="$emit('select', group)">
				<MedalBadge
					:type="group.type"
					:size="badgeSize"
					show-label />
				<span class="medal-count">×{{ group.count }}</span>
				<span v-if="showDates && group.latest" class="medal-date">
					{{ formatDate(group.latest) }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import MedalBadge from './MedalBadge.vue'

/**
 * Medal list component for displaying grouped medals
 */
export default {
	name: 'MedalList',
	components: {
		MedalBadge,
	},
	props: {
		/**
		 * Array of medal objects
		 */
		medals: {
			type: Array,
			default: () => [],
		},
		/**
		 * Size of medal badges
		 */
		badgeSize: {
			type: String,
			default: 'medium',
		},
		/**
		 * Whether to show award dates
		 */
		showDates: {
			type: Boolean,
			default: false,
		},
		/**
		 * Message when no medals exist
		 */
		emptyMessage: {
			type: String,
			default: '',
		},
	},
	emits: ['select'],
	setup(props) {
		/**
		 * Group medals by type with count and latest date
		 */
		const groupedMedals = computed(() => {
			const groups = {}

			props.medals.forEach((medal) => {
				if (!groups[medal.medalType]) {
					groups[medal.medalType] = {
						type: medal.medalType,
						count: 0,
						latest: null,
					}
				}

				const group = groups[medal.medalType]
				group.count++

				const awardedAt = medal.awardedAt || medal.createdAt
				if (awardedAt) {
					const date = new Date(awardedAt)
					if (!group.latest || date > new Date(group.latest)) {
						group.latest = awardedAt
					}
				}
			})

			return Object.values(groups).sort((a, b) => b.count - a.count)
		})

		/**
		 * Format date for display
		 * @param {string} dateString - ISO date string
		 */
		const formatDate = (dateString) => {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleDateString(undefined, {
				month: 'short',
				year: 'numeric',
			})
		}

		return {
			groupedMedals,
			formatDate,
		}
	},
}
</script>

<style scoped>
.medal-list {
	display: flex;
	flex-direction: column;
}

.empty-state {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 0.5rem 0;
}

.medal-groups {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.medal-group {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	padding: 0.25rem;
	border-radius: 8px;
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.medal-group:hover {
	background-color: var(--color-background-hover);
}

.medal-count {
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.medal-date {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	margin-left: 0.25rem;
}
</style>
