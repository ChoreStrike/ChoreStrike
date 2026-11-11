<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section class="absence-owner" data-testid="absence-owner-panel">
		<h2 class="absence-owner-title">
			{{ t('chorestrike', 'For the household') }}
		</h2>
		<AbsenceRangeForm
			v-model="houseDraft"
			idPrefix="absence-house"
			:title="t('chorestrike', 'The whole household is away')"
			:hint="t('chorestrike', 'Nothing is planned for anyone during this time.')"
			:min="todayKey"
			:busy="pausesStore.saving"
			@submit="submitHouse" />
		<AbsenceRangeForm
			v-model="laterDraft"
			idPrefix="absence-later"
			:title="t('chorestrike', 'Enter for someone afterwards')"
			:hint="t('chorestrike', 'For days that have already passed. Tasks missed in that time no longer count against the person.')"
			:max="todayKey"
			:incomplete="!laterSubject"
			:busy="pausesStore.saving"
			@submit="submitLater">
			<div class="absence-owner-field">
				<label for="absence-later-member">{{ t('chorestrike', 'Person') }}</label>
				<select
					id="absence-later-member"
					v-model="laterSubject"
					class="absence-owner-select"
					data-testid="absence-later-member">
					<option v-for="member in members" :key="member.nextcloudUserId" :value="member.nextcloudUserId">
						{{ member.displayName }}
					</option>
				</select>
			</div>
		</AbsenceRangeForm>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import AbsenceRangeForm from './AbsenceRangeForm.vue'
import { usePausesStore } from '../../store/pauses.js'
import { logger } from '../../utils/logger.js'

const EMPTY_RANGE = () => ({ startDate: '', endDate: '' })

/**
 * The owner's part of the Absence view (ADR-020): an absence for the
 * whole household, and an entry for someone else afterwards — until
 * misses are gone, the only way to excuse one that has already expired
 * (D-090-17). No reason is asked for either.
 */
export default {
	name: 'AbsenceOwnerPanel',

	components: {
		AbsenceRangeForm,
	},

	props: {
		/** Household members: `{ nextcloudUserId, displayName }`. */
		members: {
			type: Array,
			default: () => [],
		},

		/** Today in the household's timezone (`YYYY-MM-DD`). */
		todayKey: {
			type: String,
			required: true,
		},
	},

	setup() {
		return { pausesStore: usePausesStore() }
	},

	data() {
		return {
			houseDraft: EMPTY_RANGE(),
			laterDraft: EMPTY_RANGE(),
			laterSubject: '',
		}
	},

	methods: {
		t,

		async submitHouse() {
			if (await this.enter('createHousePause', { ...this.houseDraft })) {
				this.houseDraft = EMPTY_RANGE()
			}
		},

		async submitLater() {
			if (await this.enter('recognizeIllness', { subjectUserId: this.laterSubject, ...this.laterDraft })) {
				this.laterDraft = EMPTY_RANGE()
			}
		},

		/**
		 * Run one store command. A failure keeps the draft; a 409 shows
		 * through the view's conflict note.
		 * @param {string} action Store action name.
		 * @param {object} fields Command fields.
		 * @return {Promise<boolean>} Whether the absence was entered.
		 */
		async enter(action, fields) {
			try {
				await this.pausesStore[action](fields)
				showSuccess(t('chorestrike', 'The absence was entered.'))
				return true
			} catch (error) {
				if (error?.status !== 409) {
					showError(t('chorestrike', 'The absence could not be entered.'))
					logger.error('Failed to enter the absence', error, { component: 'AbsenceOwnerPanel', action })
				}
				return false
			}
		},
	},
}
</script>

<style scoped>
.absence-owner {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin-top: 1.5rem;
}

.absence-owner-title {
	margin: 0;
	font-size: 0.85rem;
	font-weight: 700;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.absence-owner-field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.absence-owner-field label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.absence-owner-select {
	min-height: 44px;
	margin: 0;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 10px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
