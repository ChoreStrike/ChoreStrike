<template>
	<form class="task-form" @submit.prevent="submit">
		<div class="form-group">
			<label for="taskId">{{ t('chorestrike', 'Task') }}</label>
			<select id="taskId"
				v-model.number="form.taskId"
				class="form-input"
				required>
				<option :value="null" disabled>
					{{ t('chorestrike', 'Select a task') }}
				</option>
				<option v-for="task in availableTasks" :key="task.id" :value="task.id">
					{{ task.name }} ({{ t('chorestrike', 'Priority') }} {{ task.priority }})
				</option>
			</select>
		</div>

		<div class="form-group">
			<label for="nextDue">{{ t('chorestrike', 'Next Due Date') }}</label>
			<input id="nextDue"
				v-model="form.nextDue"
				type="date"
				class="form-input">
		</div>

		<div class="form-group">
			<label for="assignedToUserId">{{ t('chorestrike', 'Assignee (optional)') }}</label>
			<input id="assignedToUserId"
				v-model.trim="form.assignedToUserId"
				type="text"
				class="form-input">
		</div>

		<div class="form-actions">
			<button type="button" class="secondary-button" @click="$emit('cancel')">
				{{ t('chorestrike', 'Cancel') }}
			</button>
			<button type="submit" class="primary-button" :disabled="!form.taskId || loading">
				{{ t('chorestrike', 'Add Task') }}
			</button>
		</div>
	</form>
</template>

<script>
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useTasksStore } from '../../store/tasks.js'
import { useRoomTasksStore } from '../../store/roomTasks.js'

export default {
	name: 'TaskForm',
	props: {
		roomId: {
			type: Number,
			required: true,
		},
	},
	emits: ['submit', 'cancel'],
	setup(props, { emit }) {
		const tasksStore = useTasksStore()
		const roomTasksStore = useRoomTasksStore()
		const loading = ref(false)
		const form = ref({
			taskId: null,
			nextDue: '',
			assignedToUserId: '',
		})

		const existingTaskIds = computed(() => {
			return roomTasksStore.roomTasks
				.filter(item => item.roomId === props.roomId)
				.map(item => item.taskId)
		})

		const availableTasks = computed(() => {
			return tasksStore.tasks.filter(task => !existingTaskIds.value.includes(task.id))
		})

		const loadTasks = async () => {
			loading.value = true
			try {
				await tasksStore.fetchTasks()
			} finally {
				loading.value = false
			}
		}

		const submit = () => {
			if (!form.value.taskId) {
				return
			}
			emit('submit', {
				taskId: form.value.taskId,
				nextDue: form.value.nextDue || null,
				assignedToUserId: form.value.assignedToUserId || null,
			})
		}

		onMounted(loadTasks)

		return {
			form,
			loading,
			availableTasks,
			submit,
			t,
		}
	},
}
</script>

<style scoped>
.task-form {
	padding: 1.5rem;
}

.form-group {
	margin-bottom: 1rem;
}

.form-group label {
	display: block;
	margin-bottom: 0.4rem;
	font-size: 0.9rem;
	font-weight: 600;
}

.form-input {
	width: 100%;
	padding: 0.6rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.6rem;
	margin-top: 1rem;
}

.primary-button,
.secondary-button {
	border: none;
	border-radius: 6px;
	padding: 0.6rem 0.9rem;
	cursor: pointer;
}

.primary-button {
	background: var(--color-primary);
	color: #fff;
}

.secondary-button {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
