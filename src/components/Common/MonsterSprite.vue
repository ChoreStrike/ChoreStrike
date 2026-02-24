<template>
	<div
		class="monster-sprite"
		:class="[monsterType, size, { animated, clickable }]"
		:style="positionStyle"
		@click="handleClick">
		<div class="sprite-container">
			<span class="sprite-emoji">{{ emoji }}</span>
			<div v-if="showLabel" class="sprite-label">
				{{ label }}
			</div>
		</div>
		<div v-if="showEffects" class="sprite-effects">
			<div class="effect-bounce" />
			<div class="effect-glow" :class="glowIntensity" />
		</div>
	</div>
</template>

<script>
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Monster sprite component for displaying game monsters
 * Uses emoji-based sprites with CSS animations
 */
export default {
	name: 'MonsterSprite',
	props: {
		/**
		 * Monster type identifier
		 */
		monsterType: {
			type: String,
			required: true,
			validator: (value) => [
				'dust-bunny',
				'slime-blob',
				'pixel-spider',
				'dust-demon',
			].includes(value),
		},
		/**
		 * Sprite size variant
		 */
		size: {
			type: String,
			default: 'medium',
			validator: (value) => ['small', 'medium', 'large', 'xl'].includes(value),
		},
		/**
		 * Position object with x, y coordinates (0-100 percentage)
		 */
		position: {
			type: Object,
			default: () => ({ x: 50, y: 50 }),
		},
		/**
		 * Whether to animate the sprite
		 */
		animated: {
			type: Boolean,
			default: true,
		},
		/**
		 * Whether to show the monster label
		 */
		showLabel: {
			type: Boolean,
			default: false,
		},
		/**
		 * Whether to show visual effects
		 */
		showEffects: {
			type: Boolean,
			default: true,
		},
		/**
		 * Glow intensity based on monster threat
		 */
		glowIntensity: {
			type: String,
			default: 'medium',
			validator: (value) => ['low', 'medium', 'high'].includes(value),
		},
		/**
		 * Whether the sprite is clickable
		 */
		clickable: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['click'],
	setup(props, { emit }) {
		const monsterData = {
			'dust-bunny': {
				emoji: '🐰',
				label: t('chorestrike', 'Dust Bunny'),
				description: t('chorestrike', 'A harmless but annoying creature'),
			},
			'slime-blob': {
				emoji: '🟢',
				label: t('chorestrike', 'Slime Blob'),
				description: t('chorestrike', 'Sticky and unpleasant'),
			},
			'pixel-spider': {
				emoji: '🕷️',
				label: t('chorestrike', 'Pixel Spider'),
				description: t('chorestrike', 'Crawling in the corners'),
			},
			'dust-demon': {
				emoji: '👹',
				label: t('chorestrike', 'Dust Demon'),
				description: t('chorestrike', 'A powerful filth creature!'),
			},
		}

		const emoji = computed(() => monsterData[props.monsterType]?.emoji || '👾')
		const label = computed(() => monsterData[props.monsterType]?.label || props.monsterType)
		const description = computed(() => monsterData[props.monsterType]?.description || '')

		const positionStyle = computed(() => ({
			left: `${props.position.x}%`,
			top: `${props.position.y}%`,
		}))

		const handleClick = () => {
			if (props.clickable) {
				emit('click', {
					type: props.monsterType,
					label: label.value,
					description: description.value,
				})
			}
		}

		return {
			emoji,
			label,
			description,
			positionStyle,
			handleClick,
		}
	},
}
</script>

<style scoped>
.monster-sprite {
	position: absolute;
	transform: translate(-50%, -50%);
	z-index: 10;
}

.sprite-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.25rem;
}

.sprite-emoji {
	line-height: 1;
	display: block;
	transition: transform 0.2s ease;
}

.sprite-label {
	font-size: 0.7rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0.15rem 0.4rem;
	border-radius: 4px;
	white-space: nowrap;
	opacity: 0;
	transition: opacity 0.2s ease;
}

.monster-sprite:hover .sprite-label {
	opacity: 1;
}

.monster-sprite.clickable {
	cursor: pointer;
}

.monster-sprite.clickable:hover .sprite-emoji {
	transform: scale(1.2);
}

/* Size variants */
.monster-sprite.small .sprite-emoji {
	font-size: 1rem;
}

.monster-sprite.medium .sprite-emoji {
	font-size: 1.5rem;
}

.monster-sprite.large .sprite-emoji {
	font-size: 2rem;
}

.monster-sprite.xl .sprite-emoji {
	font-size: 3rem;
}

/* Animations */
.monster-sprite.animated .sprite-emoji {
	animation: monster-idle 2s ease-in-out infinite;
}

.monster-sprite.animated.dust-bunny .sprite-emoji {
	animation: bunny-hop 1.5s ease-in-out infinite;
}

.monster-sprite.animated.slime-blob .sprite-emoji {
	animation: slime-wobble 2s ease-in-out infinite;
}

.monster-sprite.animated.pixel-spider .sprite-emoji {
	animation: spider-crawl 3s linear infinite;
}

.monster-sprite.animated.dust-demon .sprite-emoji {
	animation: demon-float 2.5s ease-in-out infinite;
}

@keyframes monster-idle {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-3px); }
}

@keyframes bunny-hop {
	0%, 100% { transform: translateY(0) scale(1); }
	25% { transform: translateY(-8px) scale(1.1); }
	50% { transform: translateY(0) scale(1); }
	75% { transform: translateY(-4px) scale(1.05); }
}

@keyframes slime-wobble {
	0%, 100% { transform: scale(1, 1); }
	25% { transform: scale(1.1, 0.9); }
	50% { transform: scale(0.9, 1.1); }
	75% { transform: scale(1.05, 0.95); }
}

@keyframes spider-crawl {
	0% { transform: translate(0, 0) rotate(0deg); }
	25% { transform: translate(5px, -3px) rotate(10deg); }
	50% { transform: translate(10px, 0) rotate(0deg); }
	75% { transform: translate(5px, 3px) rotate(-10deg); }
	100% { transform: translate(0, 0) rotate(0deg); }
}

@keyframes demon-float {
	0%, 100% {
		transform: translateY(0);
		text-shadow: 0 0 10px rgba(255, 0, 0, 0.5);
	}
	50% {
		transform: translateY(-10px);
		text-shadow: 0 0 20px rgba(255, 0, 0, 0.8), 0 0 40px rgba(255, 0, 0, 0.4);
	}
}

/* Visual effects */
.sprite-effects {
	position: absolute;
	inset: -50%;
	pointer-events: none;
}

.effect-glow {
	position: absolute;
	inset: 0;
	border-radius: 50%;
	background: radial-gradient(circle, rgba(255, 255, 0, 0.2) 0%, transparent 70%);
	opacity: 0.6;
	animation: glow-pulse 3s ease-in-out infinite;
}

.effect-glow.high {
	background: radial-gradient(circle, rgba(255, 0, 0, 0.4) 0%, transparent 70%);
	opacity: 0.8;
	animation: glow-pulse 1.5s ease-in-out infinite;
}

.effect-glow.low {
	background: radial-gradient(circle, rgba(0, 255, 0, 0.15) 0%, transparent 70%);
	opacity: 0.4;
	animation: glow-pulse 4s ease-in-out infinite;
}

@keyframes glow-pulse {
	0%, 100% { transform: scale(1); opacity: 0.6; }
	50% { transform: scale(1.2); opacity: 0.3; }
}
</style>
