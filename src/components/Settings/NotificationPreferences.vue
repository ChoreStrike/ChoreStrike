<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<section
		class="notification-preferences"
		data-testid="notification-preferences"
		:aria-labelledby="sectionHeadingId">
		<h3 :id="sectionHeadingId" class="section-title">
			{{ t('chorestrike', 'Notifications') }}
		</h3>

		<NcNoteCard
			type="info"
			data-testid="notification-scope-card">
			{{ t('chorestrike', 'Delivery and attention are different things: the digests arrive as a Nextcloud notification or email at the chosen time — quiet times apply to these deliveries. In-app notifications, however, appear only while ChoreStrike is open and are never delayed by quiet times.') }}
		</NcNoteCard>

		<div class="preference-rows">
			<NcCheckboxRadioSwitch
				:modelValue="draft.taskDigestEnabled"
				type="switch"
				data-testid="task-digest-switch"
				@update:modelValue="onTaskDigestChange">
				{{ t('chorestrike', 'Daily task digest') }}
			</NcCheckboxRadioSwitch>
			<p class="preference-hint">
				{{ t('chorestrike', 'A daily summary of the agreements for the coming day.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="draft.orgDigestEnabled"
				type="switch"
				data-testid="org-digest-switch"
				@update:modelValue="onOrgDigestChange">
				{{ t('chorestrike', 'Weekly organization digest') }}
			</NcCheckboxRadioSwitch>
			<p class="preference-hint">
				{{ t('chorestrike', 'A weekly overview of catch-ups and pause activity.') }}
			</p>

			<div class="preference-field">
				<label for="cs-digest-time">{{ t('chorestrike', 'Digest delivery time') }}</label>
				<input
					id="cs-digest-time"
					v-model="draft.digestTime"
					type="time"
					class="time-input"
					data-testid="digest-time-input">
			</div>

			<div class="preference-field">
				<label for="cs-quiet-start">{{ t('chorestrike', 'Quiet time starts') }}</label>
				<input
					id="cs-quiet-start"
					v-model="draft.quietStart"
					type="time"
					class="time-input"
					data-testid="quiet-start-input">
			</div>

			<div class="preference-field">
				<label for="cs-quiet-end">{{ t('chorestrike', 'Quiet time ends') }}</label>
				<input
					id="cs-quiet-end"
					v-model="draft.quietEnd"
					type="time"
					class="time-input"
					data-testid="quiet-end-input">
			</div>
			<p class="preference-hint">
				{{ t('chorestrike', 'No digest deliveries during the quiet time. Clear both fields to switch it off.') }}
			</p>

			<h4 class="subsection-title">
				{{ t('chorestrike', 'Feedback effects') }}
			</h4>
			<p class="preference-hint">
				{{ t('chorestrike', 'Both are off by default. Celebrations are never a requirement for the household routine.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="draft.soundEnabled"
				type="switch"
				data-testid="sound-toggle"
				@update:modelValue="draft.soundEnabled = $event">
				{{ t('chorestrike', 'Sounds on completions and awards') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:modelValue="draft.animationEnabled"
				type="switch"
				data-testid="animation-toggle"
				@update:modelValue="draft.animationEnabled = $event">
				{{ t('chorestrike', 'Celebration animations') }}
			</NcCheckboxRadioSwitch>
			<p class="preference-hint">
				{{ t('chorestrike', 'Your system reduced-motion setting always wins over this switch.') }}
			</p>

			<h4 class="subsection-title">
				{{ t('chorestrike', 'Optional areas') }}
			</h4>
			<p class="preference-hint">
				{{ t('chorestrike', 'The arcade is opt-in and stays outside the daily surfaces. Hiding it removes its navigation entry immediately.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="draft.arcadeVisible"
				type="switch"
				data-testid="arcade-visible-switch"
				@update:modelValue="draft.arcadeVisible = $event">
				{{ t('chorestrike', 'Show the arcade') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch
				:modelValue="draft.catalogVisible"
				type="switch"
				data-testid="catalog-visible-switch"
				@update:modelValue="draft.catalogVisible = $event">
				{{ t('chorestrike', 'Show the reward catalog') }}
			</NcCheckboxRadioSwitch>
			<p class="preference-hint">
				{{ t('chorestrike', 'Hiding the catalog removes its navigation entry immediately — your requests stay reachable, and the household gate is set by an owner, not here.') }}
			</p>
		</div>

		<p
			v-if="preferencesStore.conflict"
			class="conflict-note"
			data-testid="preferences-conflict"
			role="alert">
			{{ t('chorestrike', 'Your changes were kept — someone else updated the settings in the meantime. Review the server values and save again.') }}
		</p>
		<p
			v-else-if="preferencesStore.error"
			class="error-note"
			data-testid="preferences-error"
			role="alert">
			{{ t('chorestrike', 'The settings could not be saved. Please try again.') }}
		</p>

		<NcButton
			variant="primary"
			:disabled="preferencesStore.saving"
			data-testid="save-preferences"
			@click="save">
			{{ saveLabel }}
		</NcButton>
	</section>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { usePreferencesStore } from '../../store/preferences.js'
import { logger } from '../../utils/logger.js'

/**
 * Self-only delivery preferences (CS-PLAN-070 WP6 §3.3 on the WP5
 * contract, C5g): one NcNoteCard draws the delivery-vs-attention line —
 * digests are DELIVERED (quiet times apply), in-app notifications are
 * ATTENTION-only and never delayed. The form keeps a LOCAL draft; the
 * explicit save sends the whitelisted fields with expectedVersion and a
 * fresh commandId; a 409 preserves the draft, surfaces the conflict and
 * re-reads the authoritative version for the retry.
 */
export default {
	name: 'NotificationPreferences',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	setup() {
		const preferencesStore = usePreferencesStore()
		return { preferencesStore }
	},

	data() {
		return {
			sectionHeadingId: 'cs-notification-preferences-heading',
			draft: {
				taskDigestEnabled: false,
				orgDigestEnabled: false,
				digestTime: '18:00',
				quietStart: null,
				quietEnd: null,
				soundEnabled: false,
				animationEnabled: false,
				arcadeVisible: true,
				catalogVisible: true,
			},
		}
	},

	computed: {
		preferencesVersion() {
			return this.preferencesStore.version
		},

		saveLabel() {
			return this.preferencesStore.saving
				? t('chorestrike', 'Saving…')
				: t('chorestrike', 'Save')
		},
	},

	watch: {
		/**
		 * Re-seed the draft from the authoritative store whenever a
		 * fetch/save re-read lands — EXCEPT while a conflict is flagged
		 * (the draft must survive it).
		 */
		preferencesVersion() {
			if (!this.preferencesStore.conflict) {
				this.seedDraftFromStore()
			}
		},
	},

	created() {
		this.seedDraftFromStore()
		if (this.preferencesStore.version === 0 && !this.preferencesStore.loading) {
			this.preferencesStore.fetchPreferences().catch(() => {})
		}
	},

	methods: {
		t,

		seedDraftFromStore() {
			this.draft = {
				taskDigestEnabled: this.preferencesStore.taskDigestEnabled,
				orgDigestEnabled: this.preferencesStore.orgDigestEnabled,
				digestTime: this.preferencesStore.digestTime,
				quietStart: this.preferencesStore.quietStart,
				quietEnd: this.preferencesStore.quietEnd,
				soundEnabled: this.preferencesStore.soundEnabled,
				animationEnabled: this.preferencesStore.animationEnabled,
				arcadeVisible: this.preferencesStore.arcadeVisible,
				catalogVisible: this.preferencesStore.catalogVisible,
			}
		},

		/**
		 * C5c suggestion: enabling a digest category pre-fills the quiet
		 * inputs with the 20:00/08:00 suggestion while they are empty.
		 */
		maybeSuggestQuietHours() {
			const draft = this.draft
			if ((draft.taskDigestEnabled || draft.orgDigestEnabled) && !draft.quietStart && !draft.quietEnd) {
				draft.quietStart = '20:00'
				draft.quietEnd = '08:00'
			}
		},

		onTaskDigestChange(value) {
			this.draft.taskDigestEnabled = value
			this.maybeSuggestQuietHours()
		},

		onOrgDigestChange(value) {
			this.draft.orgDigestEnabled = value
			this.maybeSuggestQuietHours()
		},

		async save() {
			const ok = await this.preferencesStore.savePreferences({
				taskDigestEnabled: this.draft.taskDigestEnabled,
				orgDigestEnabled: this.draft.orgDigestEnabled,
				digestTime: this.draft.digestTime || null,
				quietStart: this.draft.quietStart || null,
				quietEnd: this.draft.quietEnd || null,
				soundEnabled: this.draft.soundEnabled,
				animationEnabled: this.draft.animationEnabled,
				arcadeVisible: this.draft.arcadeVisible,
				catalogVisible: this.draft.catalogVisible,
			}).catch((error) => {
				logger.error('Failed to save notification preferences', error, { component: 'NotificationPreferences', action: 'save' })
				return false
			})
			if (ok) {
				showSuccess(t('chorestrike', 'Notification preferences saved'))
				this.seedDraftFromStore()
			}
		},
	},
}
</script>

<style scoped>
.notification-preferences {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	max-width: 480px;
}

.section-title {
	font-size: 1.1rem;
	margin: 0;
}

.subsection-title {
	font-size: 1rem;
	margin: 0.75rem 0 0 0;
}

.preference-rows {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.preference-hint {
	margin: -0.25rem 0 0.5rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.preference-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem;
	margin-top: 0.5rem;
}

.preference-field label {
	min-width: 12rem;
}

.time-input {
	max-width: 8rem;
	padding: 0.4rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.conflict-note {
	margin: 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-warning);
	color: var(--color-main-text);
	font-size: 0.9rem;
}

.error-note {
	margin: 0;
	padding: 0.5rem 0.75rem;
	border-inline-start: 3px solid var(--color-element-error);
	color: var(--color-main-text);
	font-size: 0.9rem;
}
</style>
