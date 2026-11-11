<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="absence-view" data-testid="absence-view">
		<header class="absence-header">
			<h1>{{ t('chorestrike', 'Absence') }}</h1>
		</header>

		<AbsenceRangeForm
			v-model="draft"
			idPrefix="absence-own"
			:title="t('chorestrike', 'I am away')"
			:hint="t('chorestrike', 'No reason, no request. Nothing is planned for you during this time.')"
			:min="todayKey"
			:busy="pausesStore.saving"
			@submit="submitOwn" />

		<p
			v-if="pausesStore.conflict"
			class="absence-note"
			data-testid="absence-conflict"
			role="alert">
			{{ t('chorestrike', 'The entries changed in the meantime. Check the list and try again.') }}
		</p>

		<section class="absence-list" data-testid="absence-list">
			<h2 class="absence-list-title">
				{{ t('chorestrike', 'Entered') }}
			</h2>
			<ErrorState v-if="pausesStore.error && entries.length === 0" @retry="load" />
			<NcLoadingIcon v-else-if="pausesStore.loading && entries.length === 0" />
			<p v-else-if="entries.length === 0" class="absence-empty" data-testid="absence-empty">
				{{ t('chorestrike', 'No absences entered.') }}
			</p>
			<ul v-else class="absence-entries">
				<li
					v-for="entry in entries"
					:key="entry.pauseGroupId"
					class="absence-entry"
					:class="{ ended: entry.ended }"
					:data-testid="`absence-entry-${entry.pauseGroupId}`">
					<span class="absence-avatar" aria-hidden="true">
						<Icon v-if="entry.house" name="Home" :size="16" />
						<template v-else>{{ entry.name.charAt(0).toUpperCase() }}</template>
					</span>
					<div class="absence-entry-text">
						<span class="absence-entry-name">{{ entry.name }}</span>
						<span class="absence-entry-range">{{ entry.range }}</span>
						<span v-if="entry.pending" class="absence-entry-range" :data-testid="`absence-pending-${entry.pauseGroupId}`">
							{{ t('chorestrike', 'Waiting for the house owner') }}
						</span>
					</div>
					<!-- Requests stored before absences needed none (D-090-09). -->
					<template v-if="entry.pending && userStore.isHouseOwner">
						<button
							class="absence-decide"
							:data-testid="`absence-approve-${entry.pauseGroupId}`"
							:disabled="pausesStore.saving"
							@click="decide(entry, true)">
							{{ t('chorestrike', 'Acknowledge') }}
						</button>
						<button
							class="absence-decide"
							:data-testid="`absence-reject-${entry.pauseGroupId}`"
							:disabled="pausesStore.saving"
							@click="decide(entry, false)">
							{{ t('chorestrike', 'Decline') }}
						</button>
					</template>
					<button
						v-if="entry.deletable"
						class="absence-delete"
						:aria-label="entry.deleteLabel"
						:title="entry.deleteLabel"
						:data-testid="`absence-delete-${entry.pauseGroupId}`"
						:disabled="pausesStore.saving"
						@click="pendingDelete = entry">
						<Icon name="Trash" :size="20" />
					</button>
				</li>
			</ul>
			<p class="absence-footnote">
				{{ t('chorestrike', 'Everyone sees the entries. The house owner can delete any entry.') }}
			</p>
		</section>

		<AbsenceOwnerPanel
			v-if="userStore.isHouseOwner"
			:members="members"
			:todayKey="todayKey" />

		<ConfirmDialog
			v-if="pendingDelete"
			:title="t('chorestrike', 'Delete this absence?')"
			:description="t('chorestrike', 'From tomorrow the days count as usual again. What was already excused stays excused.')"
			:confirmLabel="t('chorestrike', 'Delete')"
			:loading="pausesStore.saving"
			@confirm="confirmDelete"
			@cancel="pendingDelete = null" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import AbsenceOwnerPanel from '../components/Absence/AbsenceOwnerPanel.vue'
import AbsenceRangeForm from '../components/Absence/AbsenceRangeForm.vue'
import ConfirmDialog from '../components/Common/ConfirmDialog.vue'
import ErrorState from '../components/Common/ErrorState.vue'
import Icon from '../components/Common/Icon.vue'
import { useHouseMemberNames } from '../composables/useHouseMemberNames.js'
import { useHouseStore } from '../store/house.js'
import { usePausesStore } from '../store/pauses.js'
import { useUserStore } from '../store/user.js'
import { formatDayKey, todayKeyInZone } from '../utils/dateKeys.js'
import { logger } from '../utils/logger.js'

/**
 * Absence (ADR-020, FEAT-UX-065): a period, nothing more. Everyone
 * enters one for themselves and it takes effect at once; everyone sees
 * all entries; a member deletes their own and the owner any, as long as
 * the absence has not ended. No reason is asked or shown.
 */
export default {
	name: 'AbsenceView',

	components: {
		AbsenceOwnerPanel,
		AbsenceRangeForm,
		ConfirmDialog,
		ErrorState,
		Icon,
		NcLoadingIcon,
	},

	setup() {
		const userStore = useUserStore()
		useHouseMemberNames(userStore)
		return { houseStore: useHouseStore(), pausesStore: usePausesStore(), userStore }
	},

	data() {
		return {
			draft: { startDate: '', endDate: '' },
			pendingDelete: null,
		}
	},

	computed: {
		todayKey() {
			return todayKeyInZone(this.houseStore.timezone)
		},

		members() {
			return this.userStore.houseUsers ?? []
		},

		/**
		 * Running and upcoming absences first, by start; ended ones after
		 * them, the latest first. Declined requests are not absences.
		 */
		entries() {
			const rows = this.pausesStore.pauses
				.filter((pause) => pause.state === 'acknowledged' || pause.state === 'requested')
				.map((pause) => this.toEntry(pause))
			const open = rows.filter((row) => !row.ended).sort((a, b) => a.startDate.localeCompare(b.startDate))
			const ended = rows.filter((row) => row.ended).sort((a, b) => b.endDate.localeCompare(a.endDate))
			return [...open, ...ended]
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		load() {
			this.pausesStore.fetchPauses().catch(() => {
				// The store logged it; the list shows the retry state.
			})
		},

		toEntry(pause) {
			const house = pause.scope === 'house'
			const pending = pause.state === 'requested'
			// A request never covered anything, so it has not "ended".
			const ended = !pending && pause.endDate < todayKeyInZone(pause.timezone)
			const name = house ? t('chorestrike', 'Whole household') : this.memberName(pause.subjectUserId)
			return {
				pauseGroupId: pause.pauseGroupId,
				revision: pause.revision,
				startDate: pause.startDate,
				endDate: pause.endDate,
				house,
				pending,
				ended,
				name,
				range: t('chorestrike', '{start} – {end}', { start: formatDayKey(pause.startDate), end: formatDayKey(pause.endDate) }),
				// The server applies the delete rule for this viewer (D-090-22,
				// D-090-25), including stored shapes the list cannot show.
				deletable: pause.deletable === true,
				deleteLabel: house
					? t('chorestrike', 'Delete the absence of the whole household')
					: t('chorestrike', 'Delete the absence of {name}', { name }),
			}
		},

		memberName(userId) {
			const member = this.members.find((candidate) => candidate.nextcloudUserId === userId)
			return member?.displayName ?? userId ?? t('chorestrike', 'Former member')
		},

		async submitOwn() {
			try {
				await this.pausesStore.requestPersonalPause({ ...this.draft })
				showSuccess(t('chorestrike', 'The absence was entered.'))
				this.draft = { startDate: '', endDate: '' }
			} catch (error) {
				this.report(error, t('chorestrike', 'The absence could not be entered.'), 'submitOwn')
			}
		},

		async confirmDelete() {
			const entry = this.pendingDelete
			try {
				await this.pausesStore.deletePause(entry.pauseGroupId, entry.revision)
				showSuccess(t('chorestrike', 'The absence was deleted.'))
			} catch (error) {
				this.report(error, t('chorestrike', 'The absence could not be deleted.'), 'confirmDelete')
			} finally {
				this.pendingDelete = null
			}
		},

		async decide(entry, approve) {
			try {
				await this.pausesStore[approve ? 'approvePause' : 'rejectPause'](entry.pauseGroupId, entry.revision)
			} catch (error) {
				this.report(error, t('chorestrike', 'The decision could not be recorded.'), 'decide')
			}
		},

		/**
		 * A 409 shows through the conflict note and keeps the draft; any
		 * other failure gets a toast.
		 * @param {Error} error The failed command.
		 * @param {string} message Localized toast text.
		 * @param {string} action Calling method, for the log context.
		 */
		report(error, message, action) {
			if (error?.status === 409) {
				return
			}
			showError(message)
			logger.error('Absence command failed', error, { view: 'AbsenceView', action })
		},
	},
}
</script>

<style scoped>
.absence-view {
	padding: 1rem var(--cs-view-gutter) 2rem;
	max-width: 640px;
	margin: 0 auto;
}

.absence-header {
	margin-bottom: 1rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.absence-header h1 {
	margin: 0;
	font-size: 2rem;
	font-weight: bold;
}

.absence-note {
	margin: 0.75rem 0 0;
	padding: 0.4rem 0.6rem;
	font-size: 0.85rem;
	border-inline-start: 3px solid var(--color-element-warning);
}

.absence-list {
	margin-top: 1.25rem;
}

.absence-list-title {
	margin: 0 0 0.25rem;
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.absence-entries {
	margin: 0;
	padding: 0;
	list-style: none;
}

.absence-entry {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.6rem;
	min-height: 56px;
	border-bottom: 1px solid var(--color-border);
}

.absence-entry.ended {
	color: var(--color-text-maxcontrast);
}

.absence-avatar {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 14px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.75rem;
	font-weight: 700;
}

.absence-entry-text {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
}

.absence-entry-name {
	font-weight: 600;
}

.absence-entry-range,
.absence-empty,
.absence-footnote {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.absence-empty,
.absence-footnote {
	margin: 0;
	padding: 0.6rem 0;
}

.absence-decide,
.absence-delete {
	min-height: 44px;
	margin: 0;
	border: none;
	border-radius: 22px;
	background: transparent;
	cursor: pointer;
}

.absence-decide {
	padding: 0 0.9rem;
	border: 1px solid var(--color-border-maxcontrast);
	color: var(--color-main-text);
}

.absence-delete {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 44px;
	padding: 0;
	color: var(--color-element-error);
}

.absence-decide:disabled,
.absence-delete:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
