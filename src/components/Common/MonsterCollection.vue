<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="monster-collection" :class="{ interactive }">
		<div class="collection-header">
			<span class="header-icon">👾</span>
			<span class="header-label">{{ t('chorestrike', 'Monsters') }}</span>
			<span class="monster-count">{{ monsters.length }}</span>
		</div>

		<div class="monsters-stage" :style="stageStyle">
			<MonsterSprite
				v-for="monster in monsters"
				:key="monster.id"
				:monster-type="monster.type"
				:size="spriteSize"
				:position="monster.position"
				:animated="animated"
				:show-effects="showEffects"
				:glow-intensity="getGlowIntensity(monster.type)"
				:clickable="interactive"
				@click="handleMonsterClick" />
		</div>

		<div v-if="showSummary" class="collection-summary">
			<div class="threat-level" :class="threatClass">
				{{ threatLabel }}
			</div>
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MonsterSprite from './MonsterSprite.vue'

/**
 * Monster collection component for displaying multiple monsters
 */
export default {
	name: 'MonsterCollection',
	components: {
		MonsterSprite,
	},
	props: {
		/**
		 * Array of monster objects
		 */
		monsters: {
			type: Array,
			default: () => [],
		},
		/**
		 * Sprite size for all monsters
		 */
		spriteSize: {
			type: String,
			default: 'medium',
		},
		/**
		 * Whether sprites are animated
		 */
		animated: {
			type: Boolean,
			default: true,
		},
		/**
		 * Whether to show visual effects
		 */
		showEffects: {
			type: Boolean,
			default: true,
		},
		/**
		 * Whether the collection is interactive (clickable)
		 */
		interactive: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether to show threat summary
		 */
		showSummary: {
			type: Boolean,
			default: true,
		},
		/**
		 * Stage dimensions in pixels
		 */
		stageDimensions: {
			type: Object,
			default: () => ({ width: 200, height: 150 }),
		},
	},
	emits: ['monster-click'],
	setup(props, { emit }) {
		const stageStyle = computed(() => ({
			width: `${props.stageDimensions.width}px`,
			height: `${props.stageDimensions.height}px`,
		}))

		const threatClass = computed(() => {
			const count = props.monsters.length
			if (count === 0) return 'none'
			if (count <= 1) return 'low'
			if (count <= 2) return 'medium'
			if (count <= 3) return 'high'
			return 'critical'
		})

		const threatLabel = computed(() => {
			const labels = {
				none: t('chorestrike', 'All Clear'),
				low: t('chorestrike', 'Minor Threat'),
				medium: t('chorestrike', 'Moderate Threat'),
				high: t('chorestrike', 'High Threat'),
				critical: t('chorestrike', 'Critical Infestation'),
			}
			return labels[threatClass.value] || labels.medium
		})

		const getGlowIntensity = (type) => {
			const intensityMap = {
				'dust-bunny': 'low',
				'slime-blob': 'medium',
				'pixel-spider': 'medium',
				'dust-demon': 'high',
			}
			return intensityMap[type] || 'medium'
		}

		const handleMonsterClick = (monster) => {
			emit('monster-click', monster)
		}

		return {
			stageStyle,
			threatClass,
			threatLabel,
			getGlowIntensity,
			handleMonsterClick,
			t,
		}
	},
}
</script>

<style scoped>
.monster-collection {
	background: var(--color-background-dark);
	border-radius: 12px;
	padding: 1rem;
}

.collection-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.75rem;
	font-weight: 600;
}

.header-icon {
	font-size: 1.2rem;
}

.monster-count {
	background: var(--color-primary);
	color: var(--color-primary-text);
	padding: 0.15rem 0.5rem;
	border-radius: 12px;
	font-size: 0.85rem;
	font-weight: 700;
}

.monsters-stage {
	position: relative;
	background: var(--color-background-darker);
	border-radius: 8px;
	overflow: hidden;
	min-height: 100px;
}

.collection-summary {
	margin-top: 0.75rem;
	padding-top: 0.75rem;
	border-top: 1px solid var(--color-border);
}

.threat-level {
	display: inline-block;
	padding: 0.25rem 0.75rem;
	border-radius: 6px;
	font-size: 0.85rem;
	font-weight: 600;
}

.threat-level.none {
	background: #22c55e;
	color: white;
}

.threat-level.low {
	background: #84cc16;
	color: white;
}

.threat-level.medium {
	background: #eab308;
	color: white;
}

.threat-level.high {
	background: #f97316;
	color: white;
}

.threat-level.critical {
	background: #ef4444;
	color: white;
}
</style>
