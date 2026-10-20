<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="pause-settings" data-testid="pause-settings">
		<p class="pause-intro">
			{{ t('chorestrike', 'A pause is neutral: during a pause nothing counts for or against anyone. Your own requests stay visible to you; owners decide pending child requests.') }}
		</p>

		<!-- Request form: children create a PENDING request, adults are
		     acknowledged directly (the server owns the state transition). -->
		<form class="pause-form" data-testid="pause-request-form" @submit.prevent="submitRequest">
			<h4 class="pause-subtitle">
				{{ t('chorestrike', 'Request a pause') }}
			</h4>
			<div class="pause-field">
				<label for="pause-start">{{ t('chorestrike', 'From') }}</label>
				<input
					id="pause-start"
					v-model="draft.startDate"
					type="date"
					class="pause-input"
					data-testid="pause-start-input">
			</div>
			<div class="pause-field">
				<label for="pause-end">{{ t('chorestrike', 'Until (inclusive)') }}</label>
				<input
					id="pause-end"
					v-model="draft.endDate"
					type="date"
					class="pause-input"
					data-testid="pause-end-input">
			</div>
			<div class="pause-field">
				<label for="pause-reason">{{ t('chorestrike', 'Reason') }}</label>
				<select
					id="pause-reason"
					v-model="draft.reasonCode"
					class="pause-input"
					data-testid="pause-reason-select">
					<option value="illness">
						{{ t('chorestrike', 'Illness') }}
					</option>
					<option value="travel">
						{{ t('chorestrike', 'Travel') }}
					</option>
					<option value="overload">
						{{ t('chorestrike', 'Too much at once') }}
					</option>
					<option value="other">
						{{ t('chorestrike', 'Other') }}
					</option>
				</select>
			</div>
			<button
				type="submit"
				class="pause-button"
				data-testid="pause-request-submit"
				:disabled="!draft.startDate || !draft.endDate || pausesStore.saving">
				{{ submitLabel }}
			</button>
		</form>

		<p
			v-if="pausesStore.conflict"
			class="pause-note conflict"
			data-testid="pause-conflict"
			role="alert">
			{{ t('chorestrike', 'The pause changed in the meantime. Your entries were kept — review and send again.') }}
		</p>
		<p
			v-else-if="pausesStore.error"
			class="pause-note conflict"
			data-testid="pause-error"
			role="alert">
			{{ t('chorestrike', 'The request could not be sent. Please try again.') }}
		</p>

		<div class="pause-list" data-testid="pause-list">
			<h4 class="pause-subtitle">
				{{ t('chorestrike', 'Your pauses') }}
			</h4>
			<p v-if="ownPauses.length === 0" class="pause-empty">
				{{ t('chorestrike', 'No pauses recorded.') }}
			</p>
			<ul v-else class="pause-entries">
				<li
					v-for="pause in ownPauses"
					:key="pause.pauseGroupId"
					class="pause-entry"
					:data-testid="`pause-entry-${pause.pauseGroupId}`">
					<span class="pause-range">{{ formatRange(pause) }}</span>
					<span class="pause-state" :class="pause.state">{{ stateLabel(pause.state) }}</span>
					<span v-if="pause.reasonCode" class="pause-reason">{{ reasonLabel(pause.reasonCode) }}</span>
					<span v-if="pause.isRetrospectiveIllness" class="pause-reason">
						{{ t('chorestrike', 'Recorded retrospectively by the house owner') }}
					</span>
					<span
						v-if="pause.state === 'requested'"
						class="pause-hint">
						{{ t('chorestrike', 'Waiting for the house owner') }}
					</span>
					<span
						v-if="pause.state === 'rejected'"
						class="pause-hint">
						{{ t('chorestrike', 'The house owner declined this request.') }}
					</span>
					<div v-if="pause.state === 'acknowledged'" class="pause-shorten">
						<label :for="`pause-shorten-${pause.pauseGroupId}`" class="pause-label">
							{{ t('chorestrike', 'End early on') }}
						</label>
						<input
							:id="`pause-shorten-${pause.pauseGroupId}`"
							v-model="shortenDrafts[pause.pauseGroupId]"
							type="date"
							class="pause-input"
							:min="pause.startDate"
							:data-testid="`pause-shorten-input-${pause.pauseGroupId}`">
						<button
							type="button"
							class="pause-button secondary"
							:data-testid="`pause-shorten-submit-${pause.pauseGroupId}`"
							:disabled="!shortenDrafts[pause.pauseGroupId] || pausesStore.saving"
							@click="submitShorten(pause)">
							{{ t('chorestrike', 'End early') }}
						</button>
					</div>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { usePausesStore } from '../../store/pauses.js'
import { useUserStore } from '../../store/user.js'
import { logger } from '../../utils/logger.js'

/**
 * The self-only pause surface (CS-PLAN-070 WP6 §3.4, FEAT-UX-025
 * remainder; contract CS-PLAN-073 WP2): request a personal pause (child
 * → pending request, adult → acknowledged), watch the viewer-whitelisted
 * own entries, and shorten an acknowledged pause. Every command sends a
 * fresh command UUID; decisions/shortening send the displayed
 * expectedRevision; a 409 PRESERVES the draft. Neutral domain: pending
 * child requests change nothing until an owner decides them.
 */
export default {
	name: 'PauseSettings',

	setup() {
		const pausesStore = usePausesStore()
		const userStore = useUserStore()
		return { pausesStore, userStore }
	},

	data() {
		return {
			draft: { startDate: '', endDate: '', reasonCode: 'illness' },
			shortenDrafts: {},
		}
	},

	computed: {
		ownPauses() {
			const myUserId = this.userStore.currentUser?.nextcloudUserId
			if (!myUserId) {
				return []
			}
			return this.pausesStore.pauses.filter(pause => pause.subjectUserId === myUserId)
		},

		submitLabel() {
			return this.pausesStore.saving
				? t('chorestrike', 'Sending…')
				: t('chorestrike', 'Request pause')
		},
	},

	mounted() {
		this.pausesStore.fetchPauses().catch((error) => {
			logger.error('Failed to load the pause list', error, { component: 'PauseSettings', action: 'mounted' })
		})
	},

	methods: {
		t,

		async submitRequest() {
			if (!this.draft.startDate || !this.draft.endDate) {
				return
			}
			try {
				await this.pausesStore.requestPersonalPause({
					startDate: this.draft.startDate,
					endDate: this.draft.endDate,
					reasonCode: this.draft.reasonCode,
				})
				showSuccess(t('chorestrike', 'The pause was recorded.'))
				this.draft = { startDate: '', endDate: '', reasonCode: this.draft.reasonCode }
			} catch (error) {
				if (error?.status !== 409) {
					showError(t('chorestrike', 'The request could not be sent.'))
					logger.error('Failed to request the pause', error, { component: 'PauseSettings', action: 'submitRequest' })
				}
				// A 409 keeps the draft; the conflict note renders.
			}
		},

		async submitShorten(pause) {
			const endDate = this.shortenDrafts[pause.pauseGroupId]
			if (!endDate) {
				return
			}
			try {
				await this.pausesStore.shortenPause(pause.pauseGroupId, {
					endDate,
					expectedRevision: pause.revision,
				})
				showSuccess(t('chorestrike', 'The pause was shortened.'))
				this.shortenDrafts = { ...this.shortenDrafts, [pause.pauseGroupId]: '' }
			} catch (error) {
				if (error?.status !== 409) {
					showError(t('chorestrike', 'The pause could not be shortened.'))
					logger.error('Failed to shorten the pause', error, { component: 'PauseSettings', action: 'submitShorten', pauseGroupId: pause.pauseGroupId })
				}
				// A 409 keeps the draft; the conflict note renders.
			}
		},

		// Literal t() keys keep the labels extractable for the l10n
		// artifacts — a shared constant map would be invisible to the key
		// check and render untranslated outside English.
		stateLabel(state) {
			const labels = {
				requested: t('chorestrike', 'Waiting for the house owner'),
				acknowledged: t('chorestrike', 'Acknowledged'),
				rejected: t('chorestrike', 'Declined'),
			}
			return labels[state] ?? state
		},

		reasonLabel(code) {
			const labels = {
				illness: t('chorestrike', 'Illness'),
				travel: t('chorestrike', 'Travel'),
				overload: t('chorestrike', 'Too much at once'),
				other: t('chorestrike', 'Other'),
			}
			return labels[code] ?? code
		},

		formatRange(pause) {
			return this.t('chorestrike', '{start} – {end}', { start: pause.startDate, end: pause.endDate })
		},
	},
}
</script>

<style scoped>
.pause-settings {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.pause-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	line-height: 1.4;
}

.pause-form,
.pause-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.pause-subtitle {
	margin: 0;
	font-size: 0.95rem;
}

.pause-field {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem;
}

.pause-field label {
	min-width: 10rem;
	font-size: 0.9rem;
}

.pause-input {
	max-width: 11rem;
	padding: 0.35rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	min-height: 44px;
}

.pause-button {
	align-self: flex-start;
	min-height: 44px;
	padding: 0.4rem 1rem;
	border: none;
	border-radius: 8px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	cursor: pointer;
	font-weight: 600;
}

.pause-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.pause-button.secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
}

.pause-note {
	margin: 0;
	padding: 0.4rem 0.6rem;
	font-size: 0.85rem;
	color: var(--color-main-text);
	border-inline-start: 3px solid var(--color-element-warning);
}

.pause-empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.pause-entries {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pause-entry {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	font-size: 0.9rem;
}

.pause-range {
	font-variant-numeric: tabular-nums;
}

.pause-state {
	padding: 0.1rem 0.5rem;
	border-radius: 10px;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.pause-state.acknowledged {
	color: var(--color-success-text);
}

.pause-state.rejected {
	color: var(--color-error-text);
}

.pause-reason,
.pause-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.pause-shorten {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-inline-start: auto;
}
</style>
