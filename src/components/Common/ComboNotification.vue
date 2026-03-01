<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<TransitionGroup
		name="combo"
		tag="div"
		class="combo-notifications"
		:aria-live="announce ? 'polite' : 'off'"
		aria-atomic="true">
		<div
			v-for="combo in activeCombos"
			:key="combo.id"
			class="combo-notification"
			:class="[combo.type, { 'is-ending': combo.isEnding }]"
			:style="combo.style">
			<div class="combo-content">
				<div class="combo-icon">
					{{ comboIcon(combo.type) }}
				</div>
				<div class="combo-details">
					<div class="combo-title">
						{{ comboTitle(combo.type) }}
					</div>
					<div class="combo-description">
						{{ combo.description }}
					</div>
					<div class="combo-multiplier">
						×{{ combo.multiplier }} {{ t('chorestrike', 'Bonus') }}
					</div>
				</div>
			</div>
			<div class="combo-progress">
				<div
					class="progress-bar"
					:style="{ width: `${combo.remainingPercent}%` }" />
			</div>
		</div>
	</TransitionGroup>
</template>

<script>
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'

/**
 * Combo notifications component for displaying active combos
 */
export default {
	name: 'ComboNotification',
	props: {
		/**
		 * Array of combo objects from gameStore
		 */
		combos: {
			type: Array,
			default: () => [],
		},
		/**
		 * Whether to announce combos for screen readers
		 */
		announce: {
			type: Boolean,
			default: true,
		},
		/**
		 * Default display duration in ms
		 */
		duration: {
			type: Number,
			default: 5000,
		},
		/**
		 * Position on screen
		 */
		position: {
			type: String,
			default: 'top-right',
			validator: (value) => [
				'top-left', 'top-center', 'top-right',
				'bottom-left', 'bottom-center', 'bottom-right',
			].includes(value),
		},
	},
	emits: ['combo-expired'],
	setup(props, { emit }) {
		const comboTimers = ref(new Map())

		const getComboStyle = (index) => {
			const baseDelay = index * 100
			return {
				animationDelay: `${baseDelay}ms`,
			}
		}

		const activeCombos = computed(() => {
			return props.combos.map((combo, index) => {
				const timer = comboTimers.value.get(combo.id)
				const remainingPercent = timer
					? (timer.remaining / props.duration) * 100
					: 100

				return {
					...combo,
					remainingPercent,
					isEnding: remainingPercent < 20,
					style: getComboStyle(index),
				}
			})
		})

		const comboIcon = (type) => {
			const icons = {
				speed: '⚡',
				room: '🏠',
				streak: '🔥',
				team: '🤝',
				mystery: '✨',
			}
			return icons[type] || icons.mystery
		}

		const comboTitle = (type) => {
			const titles = {
				speed: t('chorestrike', 'Speed Combo!'),
				room: t('chorestrike', 'Room Combo!'),
				streak: t('chorestrike', 'Streak Combo!'),
				team: t('chorestrike', 'Team Combo!'),
				mystery: t('chorestrike', 'Mystery Combo!'),
			}
			return titles[type] || titles.mystery
		}

		const startComboTimer = (comboId) => {
			const startTime = Date.now()

			const timer = {
				startTime,
				remaining: props.duration,
				interval: setInterval(() => {
					const elapsed = Date.now() - startTime
					const remaining = Math.max(0, props.duration - elapsed)

					timer.remaining = remaining

					if (remaining === 0) {
						clearInterval(timer.interval)
						comboTimers.value.delete(comboId)
						emit('combo-expired', comboId)
					}
				}, 50),
			}

			comboTimers.value.set(comboId, timer)
		}

		// Watch for new combos and start timers
		watch(
			() => props.combos,
			(newCombos, oldCombos) => {
				const oldIds = new Set(oldCombos?.map(c => c.id) || [])

				newCombos.forEach((combo) => {
					if (!oldIds.has(combo.id) && !comboTimers.value.has(combo.id)) {
						startComboTimer(combo.id)
					}
				})
			},
			{ immediate: true, deep: true },
		)

		return {
			activeCombos,
			comboIcon,
			comboTitle,
			t,
		}
	},
}
</script>

<style scoped>
.combo-notifications {
	position: fixed;
	top: 1rem;
	right: 1rem;
	z-index: 1000;
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	pointer-events: none;
}

.combo-notification {
	pointer-events: auto;
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-radius: 12px;
	padding: 1rem;
	min-width: 280px;
	max-width: 400px;
	box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
	transform-origin: top right;
	animation: combo-entrance 0.4s ease-out;
}

.combo-notification.is-ending {
	animation: combo-pulse 0.5s ease-in-out infinite;
	opacity: 0.8;
}

.combo-content {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
}

.combo-icon {
	font-size: 2rem;
	line-height: 1;
	animation: icon-bounce 1s ease-in-out infinite;
}

.combo-details {
	flex: 1;
	color: white;
}

.combo-title {
	font-weight: 700;
	font-size: 1.1rem;
	margin-bottom: 0.25rem;
}

.combo-description {
	font-size: 0.9rem;
	opacity: 0.9;
	margin-bottom: 0.5rem;
}

.combo-multiplier {
	display: inline-block;
	background: rgba(255, 255, 255, 0.2);
	padding: 0.25rem 0.5rem;
	border-radius: 6px;
	font-weight: 700;
	font-size: 0.85rem;
}

.combo-progress {
	margin-top: 0.75rem;
	height: 4px;
	background: rgba(255, 255, 255, 0.2);
	border-radius: 2px;
	overflow: hidden;
}

.progress-bar {
	height: 100%;
	background: rgba(255, 255, 255, 0.8);
	border-radius: 2px;
	transition: width 0.1s linear;
}

/* Combo type variants */
.combo-notification.speed {
	background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.combo-notification.room {
	background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.combo-notification.streak {
	background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.combo-notification.team {
	background: linear-gradient(135deg, #30cfd0 0%, #330867 100%);
}

/* Animations */
@keyframes combo-entrance {
	0% {
		transform: translateX(100%) scale(0.8);
		opacity: 0;
	}
	60% {
		transform: translateX(-5%) scale(1.02);
	}
	100% {
		transform: translateX(0) scale(1);
		opacity: 1;
	}
}

@keyframes combo-pulse {
	0%, 100% {
		transform: scale(1);
	}
	50% {
		transform: scale(1.02);
	}
}

@keyframes icon-bounce {
	0%, 100% {
		transform: scale(1) rotate(0deg);
	}
	25% {
		transform: scale(1.1) rotate(-5deg);
	}
	75% {
		transform: scale(1.1) rotate(5deg);
	}
}

/* Vue transition classes */
.combo-enter-active {
	animation: combo-entrance 0.4s ease-out;
}

.combo-leave-active {
	animation: combo-exit 0.3s ease-in;
}

@keyframes combo-exit {
	0% {
		transform: translateX(0) scale(1);
		opacity: 1;
	}
	100% {
		transform: translateX(100%) scale(0.8);
		opacity: 0;
	}
}

/* Responsive */
@media (max-width: 768px) {
	.combo-notifications {
		left: 1rem;
		right: 1rem;
	}

	.combo-notification {
		min-width: auto;
		max-width: none;
	}
}
</style>
