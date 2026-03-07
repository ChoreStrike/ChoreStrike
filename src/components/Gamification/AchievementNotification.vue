<template>
	<div class="achievement-notification-container" data-testid="achievement-notification">
		<transition-group name="notification" tag="div">
			<div
				v-for="notification in notifications"
				:key="notification.id"
				:class="['notification-item', `type-${notification.type}`]"
				@animationend="removeNotification(notification.id)">
				<div class="notification-icon">
					{{ notification.icon }}
				</div>
				<div class="notification-content">
					<div class="notification-title">
						{{ notification.title }}
					</div>
					<div class="notification-message">
						{{ notification.message }}
					</div>
					<div v-if="notification.points" class="notification-points">
						+{{ notification.points }} {{ t('chorestrike', 'points') }}
					</div>
				</div>
				<button class="notification-close" @click="removeNotification(notification.id)">
					<Icon name="Close" :size="16" />
				</button>
			</div>
		</transition-group>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'

interface Notification {
	id: string
	title: string
	message: string
	icon: string
	type: 'medal' | 'combo' | 'streak' | 'level'
	points?: number
	duration: number
}

const notifications = ref<Notification[]>([])
let notificationId = 0

const showNotification = (notification: Omit<Notification, 'id'>): void => {
	const id = `notification-${++notificationId}`
	const newNotification = { ...notification, id }
	notifications.value.push(newNotification)

	// Auto-remove after duration
	setTimeout(() => {
		removeNotification(id)
	}, notification.duration)
}

const removeNotification = (id: string): void => {
	const index = notifications.value.findIndex(n => n.id === id)
	if (index > -1) {
		notifications.value.splice(index, 1)
	}
}

// Medal notification helper
const showMedalNotification = (medalType: string, medalName: string, points: number): void => {
	const medalIcons: Record<string, string> = {
		team_anchor: '🏆',
		streak_keeper: '🔥',
		combo_master: '⚡',
		perfect_week: '✨',
		legendary_helper: '👑',
	}

	showNotification({
		title: t('chorestrike', 'Medal Earned!'),
		message: medalName,
		icon: medalIcons[medalType] || '🏅',
		type: 'medal',
		points,
		duration: 5000,
	})
}

// Combo notification helper
const showComboNotification = (taskCount: number, bonusPoints: number): void => {
	showNotification({
		title: t('chorestrike', 'Combo x{count}!', { count: taskCount }),
		message: t('chorestrike', 'Keep going to earn more bonus points'),
		icon: '⚡',
		type: 'combo',
		points: bonusPoints,
		duration: 3000,
	})
}

// Streak notification helper
const showStreakNotification = (streakCount: number): void => {
	showNotification({
		title: t('chorestrike', '{count} Day Streak!', { count: streakCount }),
		message: t('chorestrike', 'Amazing consistency!'),
		icon: '🔥',
		type: 'streak',
		duration: 4000,
	})
}

// Level up notification helper
const showLevelUpNotification = (level: number): void => {
	showNotification({
		title: t('chorestrike', 'Level Up!'),
		message: t('chorestrike', 'You reached level {level}', { level }),
		icon: '📈',
		type: 'level',
		duration: 5000,
	})
}

// Expose methods for parent component
defineExpose({
	showNotification,
	showMedalNotification,
	showComboNotification,
	showStreakNotification,
	showLevelUpNotification,
})
</script>

<style scoped>
.achievement-notification-container {
	position: fixed;
	top: 1rem;
	right: 1rem;
	z-index: 1000;
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	max-width: 400px;
}

.notification-item {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-main-background);
	border-radius: 12px;
	border-left: 4px solid;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
	animation: slideIn 0.3s ease-out;
}

.notification-item.type-medal {
	border-left-color: #ffd700;
	background: linear-gradient(135deg, #fff9c4, #ffffff);
}

.notification-item.type-combo {
	border-left-color: #4caf50;
	background: linear-gradient(135deg, #e8f5e9, #ffffff);
}

.notification-item.type-streak {
	border-left-color: #ff5722;
	background: linear-gradient(135deg, #ffccbc, #ffffff);
}

.notification-item.type-level {
	border-left-color: #2196f3;
	background: linear-gradient(135deg, #e3f2fd, #ffffff);
}

@keyframes slideIn {
	from {
		transform: translateX(100%);
		opacity: 0;
	}
	to {
		transform: translateX(0);
		opacity: 1;
	}
}

.notification-enter-active,
.notification-leave-active {
	transition: all 0.3s ease;
}

.notification-enter-from,
.notification-leave-to {
	transform: translateX(100%);
	opacity: 0;
}

.notification-icon {
	font-size: 2rem;
	line-height: 1;
	animation: bounce 0.5s ease;
}

@keyframes bounce {
	0%, 100% { transform: scale(1); }
	50% { transform: scale(1.2); }
}

.notification-content {
	flex: 1;
}

.notification-title {
	font-weight: 600;
	font-size: 1rem;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.notification-message {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.notification-points {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-primary);
	margin-top: 0.25rem;
}

.notification-close {
	padding: 0.25rem;
	background: transparent;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	border-radius: 50%;
	transition: background-color 0.2s ease;
}

.notification-close:hover {
	background: var(--color-background-dark);
}

@media (max-width: 768px) {
	.achievement-notification-container {
		left: 1rem;
		right: 1rem;
		max-width: none;
	}

	.notification-item {
		padding: 0.75rem;
	}

	.notification-icon {
		font-size: 1.5rem;
	}
}
</style>
