<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="availability-settings" data-testid="availability-settings">
		<div class="settings-header">
			<h2>{{ t('chorestrike', 'Member Availability') }}</h2>
			<p>{{ t('chorestrike', 'Configure when each member is available for tasks') }}</p>
		</div>

		<div v-if="members.length > 0" class="members-list">
			<div
				v-for="member in members"
				:key="member.userId"
				class="member-card">
				<div class="member-header">
					<div class="member-info">
						<h3>{{ member.userName || member.userId }}</h3>
						<span class="member-status">
							{{ getAvailableDaysCount(member) }}/7 {{ t('chorestrike', 'days available') }}
						</span>
					</div>
					<div class="member-actions">
						<button
							class="edit-button"
							data-testid="edit-availability-button"
							@click="openEditModal(member)">
							{{ t('chorestrike', 'Edit') }}
						</button>
					</div>
				</div>

				<div class="availability-grid" data-testid="availability-grid">
					<div
						v-for="day in weekDays"
						:key="day.key"
						class="day-item" :class="[{ available: isMemberAvailable(member, day.key), unavailable: !isMemberAvailable(member, day.key) }]"
						data-testid="availability-slot">
						<div class="day-name">
							{{ day.name }}
						</div>
						<div class="day-status">
							<span>{{ isMemberAvailable(member, day.key) ? t('chorestrike', 'Available') : t('chorestrike', 'Unavailable') }}</span>
						</div>
					</div>
				</div>

				<div class="energy-budget-display">
					<h4>{{ t('chorestrike', 'Daily Energy Budget') }}</h4>
					<div class="budget-grid">
						<div
							v-for="day in weekDays"
							:key="`energy-${day.key}`"
							class="budget-item">
							<span class="day-name">{{ day.short }}</span>
							<span class="energy-value">{{ getMemberEnergy(member, day.key) }}</span>
						</div>
					</div>
				</div>

				<!-- Decision D5: the member's child daily-task limit (null = unlimited). -->
				<p class="member-limit" data-testid="member-task-limit">
					{{ member.maxDailyTasks === null || member.maxDailyTasks === undefined
						? t('chorestrike', 'Daily tasks: unlimited')
						: t('chorestrike', 'Daily tasks: max {limit}', { limit: member.maxDailyTasks }) }}
				</p>
			</div>
		</div>

		<EmptyState
			v-else
			:message="t('chorestrike', 'No Members Found')"
			:hint="t('chorestrike', 'Add members to your house to configure availability')" />

		<AvailabilityEditModal
			v-if="editingMember"
			:member="editingMember"
			@close="closeEditModal" />
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import EmptyState from '../Common/EmptyState.vue'
import AvailabilityEditModal from './AvailabilityEditModal.vue'
import { getMemberEnergy, isMemberAvailable, weekDays } from '../../constants/scheduling.js'
import { useSchedulingStore } from '../../store/scheduling.js'

const schedulingStore = useSchedulingStore()

const editingMember = ref(null)

// UX-014: every card gets the same Edit entry point — the shared modal
// resolves self vs. member persistence on its own.
const members = computed(() => schedulingStore.memberAvailability)

const getAvailableDaysCount = (member) => {
	if (!member.weekdayAvailability) {
		return 7
	}
	return Object.values(member.weekdayAvailability).filter(Boolean).length
}

const openEditModal = (member) => {
	editingMember.value = member
}

const closeEditModal = () => {
	editingMember.value = null
}
</script>

<style scoped>
.availability-settings {
	background: var(--color-main-background);
	border-radius: 12px;
	padding: 1.5rem;
}

.settings-header {
	margin-bottom: 2rem;
}

.settings-header h2 {
	font-size: 1.5rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.settings-header p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.members-list {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.member-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1.5rem;
	background: var(--color-background-dark);
}

.member-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1.5rem;
}

.member-info h3 {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.member-status {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.edit-button {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 6px;
	padding: 0.5rem 1rem;
	font-size: 0.9rem;
	font-weight: 500;
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.edit-button:hover {
	background: var(--color-primary-hover);
}

.availability-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 0.75rem;
	margin-bottom: 1.5rem;
}

.day-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 0.75rem;
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.day-item.available {
	background: var(--color-success);
	border-color: var(--color-element-success);
}

.day-item.unavailable {
	background: var(--color-error);
	border-color: var(--color-element-error);
}

.day-name {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
	margin-bottom: 0.25rem;
}

.day-status {
	display: flex;
	align-items: center;
	gap: 0.25rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.energy-budget-display h4 {
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: 0.75rem;
}

.budget-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
	gap: 0.5rem;
}

.budget-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background: var(--color-main-background);
	border-radius: 4px;
}

.budget-item .day-name {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.energy-value {
	font-weight: 600;
	color: var(--color-main-text);
}

.member-limit {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 0.75rem 0 0 0;
}

@media (max-width: 768px) {
	.availability-grid {
		grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
	}

	.budget-grid {
		grid-template-columns: repeat(auto-fit, minmax(60px, 1fr));
	}
}
</style>
