<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="task-history" data-testid="task-history">
		<h5 class="history-heading">
			{{ t('chorestrike', 'History') }}
		</h5>
		<p v-if="failed"
			class="history-note"
			role="alert"
			data-testid="task-history-error">
			{{ t('chorestrike', 'Could not load the history') }}
			<button type="button" class="history-button" @click="load">
				{{ t('chorestrike', 'Refresh') }}
			</button>
		</p>
		<p v-else-if="entries === null" class="history-note">
			{{ t('chorestrike', 'Loading…') }}
		</p>
		<p v-else-if="entries.length === 0" class="history-note" data-testid="task-history-empty">
			{{ t('chorestrike', 'Not done yet.') }}
		</p>
		<ul v-else class="history-list">
			<li v-for="entry in entries"
				:key="entry.id"
				class="history-entry"
				data-testid="task-history-entry">
				<div class="history-line">
					<strong>{{ formatDayKey(dayOf(entry)) }}</strong>
					<span>· {{ peopleOf(entry) }}</span>
					<span v-if="entry.recordedForOthers" class="history-recorder" data-testid="task-history-recorder">
						{{ t('chorestrike', 'recorded by {name}', { name: entry.displayName }) }}
					</span>
				</div>
				<!-- The owner corrects any entry, everyone else their own
				     latest one (ADR-021 decision 9): the server says which. -->
				<div v-if="entry.canCorrect" class="history-actions">
					<template v-if="editingId === entry.id">
						<input
							v-model="draftDate"
							type="date"
							class="history-date"
							:max="today"
							:aria-label="t('chorestrike', 'Day the work was done')"
							data-testid="task-history-date">
						<button
							type="button"
							class="history-button primary"
							data-testid="task-history-save"
							:disabled="busy || !draftDate || draftDate === dayOf(entry)"
							@click="saveDate(entry)">
							{{ t('chorestrike', 'Save') }}
						</button>
						<button type="button"
							class="history-button"
							:disabled="busy"
							@click="editingId = null">
							{{ t('chorestrike', 'Cancel') }}
						</button>
					</template>
					<template v-else>
						<button type="button"
							class="history-button"
							data-testid="task-history-redate"
							@click="startEdit(entry)">
							{{ t('chorestrike', 'Change date') }}
						</button>
						<button type="button"
							class="history-button danger"
							data-testid="task-history-delete"
							@click="deleting = entry">
							{{ t('chorestrike', 'Delete') }}
						</button>
					</template>
				</div>
			</li>
		</ul>
		<p v-if="!userStore.isHouseOwner && entries && entries.length > 0" class="history-note">
			{{ t('chorestrike', 'You can change or delete your own latest entry. Everything else is for the owner.') }}
		</p>

		<ConfirmDialog
			v-if="deleting"
			:title="t('chorestrike', 'Delete this entry?')"
			:description="t('chorestrike', 'The Coins and the energy it paid are taken back.')"
			:loading="busy"
			@confirm="confirmDelete"
			@cancel="deleting = null" />
	</section>
</template>

<script setup>
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import ConfirmDialog from '../Common/ConfirmDialog.vue'
import { useHouseStore } from '../../store/house.js'
import { useTaskCycleStore } from '../../store/taskCycle.js'
import { useUserStore } from '../../store/user.js'
import { dateKeyInZone, formatDayKey, todayKeyInZone } from '../../utils/dateKeys.js'
import { showErrorWithReference, showHistoryError } from '../../utils/errorToast.js'

/**
 * The history of one task in its opened row (CS-PLAN-091 WP-B3): who did
 * it when, and — where the server grants it — the date change and the
 * deletion of an entry. Changing the date of the latest entry moves the
 * next window; nobody is asked, the recent activity shows it.
 */
const props = defineProps({
	roomTaskId: { type: Number, required: true },
})

const taskCycleStore = useTaskCycleStore()
const houseStore = useHouseStore()
const userStore = useUserStore()

const failed = ref(false)
const busy = ref(false)
const editingId = ref(null)
const draftDate = ref('')
const deleting = ref(null)

const entries = computed(() => taskCycleStore.historyOf(props.roomTaskId))
const today = computed(() => todayKeyInZone(houseStore.timezone))

/**
 * The house-local day an entry counts on.
 * @param {object} entry History entry.
 * @return {string} `YYYY-MM-DD`
 */
function dayOf(entry) {
	return entry.workDate ?? dateKeyInZone(entry.completedAt, houseStore.timezone)
}

/**
 * The people credited; a withheld split falls back to who recorded it.
 * @param {object} entry History entry.
 * @return {string}
 */
function peopleOf(entry) {
	const names = (entry.allocation ?? []).map((share) => share.displayName)
	return names.length > 0 ? names.join(', ') : entry.displayName
}

async function load() {
	failed.value = false
	try {
		await taskCycleStore.fetchHistory(props.roomTaskId)
	} catch {
		// The store logged it; the section offers the retry.
		failed.value = true
	}
}

function startEdit(entry) {
	draftDate.value = dayOf(entry)
	editingId.value = entry.id
}

async function saveDate(entry) {
	busy.value = true
	try {
		await taskCycleStore.changeDate(props.roomTaskId, entry, draftDate.value)
		editingId.value = null
	} catch (error) {
		// The store logged it; the toast tells the member.
		showHistoryError(error)
	} finally {
		busy.value = false
	}
}

async function confirmDelete() {
	const entry = deleting.value
	busy.value = true
	try {
		await taskCycleStore.deleteEntry(props.roomTaskId, entry)
	} catch (error) {
		// The room-task store logged it; the toast tells the member.
		showErrorWithReference(t('chorestrike', 'Could not delete the entry'), error)
	} finally {
		busy.value = false
		deleting.value = null
	}
}

/**
 * A completion or an undo of this task elsewhere (its Done button, the
 * undo snackbar) changes the entries and who may still correct them.
 * @param {{roomTaskId?: ?number}} event The store's event.
 */
function onTaskChanged(event) {
	if (event?.roomTaskId === props.roomTaskId) {
		load()
	}
}

onMounted(() => {
	load()
	subscribe('chorestrike:task-completed', onTaskChanged)
	subscribe('chorestrike:task-uncompleted', onTaskChanged)
})

onBeforeUnmount(() => {
	unsubscribe('chorestrike:task-completed', onTaskChanged)
	unsubscribe('chorestrike:task-uncompleted', onTaskChanged)
})
</script>

<style scoped>
.task-history {
	margin-block-start: 1rem;
	padding-block-start: 0.75rem;
	border-block-start: 1px solid var(--color-border);
}

.history-heading {
	margin: 0 0 0.25rem;
	font-size: 0.8rem;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.history-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.history-entry {
	padding: 0.4rem 0;
	border-block-end: 1px solid var(--color-border);
}

.history-line {
	display: flex;
	flex-wrap: wrap;
	gap: 0.35rem;
	font-size: 0.9rem;
	overflow-wrap: anywhere;
}

.history-recorder,
.history-note {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.history-note {
	margin: 0.5rem 0 0;
}

.history-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-start: 0.4rem;
}

.history-button {
	min-height: 44px;
	padding: 0 0.9rem;
	border: 1px solid var(--color-text-maxcontrast);
	border-radius: 22px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.history-button.primary {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.history-button.danger {
	border-color: var(--color-element-error);
	color: var(--color-error-text);
}

.history-button:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.history-button:focus-visible,
.history-date:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.history-date {
	min-height: 44px;
	padding: 0 0.5rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
