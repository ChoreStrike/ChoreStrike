<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal
		persistent
		:aria-label="dialogHeading"
		@close="$emit('close')">
		<div class="participation-dialog" data-testid="participation-dialog">
			<h2>{{ dialogHeading }}</h2>
			<p class="dialog-hint">
				{{ dialogHint }}
			</p>

			<ul class="participant-list" data-testid="participant-list">
				<li
					v-for="participant in participants"
					:key="participant.userId"
					class="participant-row"
					:data-testid="`participant-row-${participant.userId}`">
					<label class="participant-name">
						<input
							v-model="participant.active"
							type="checkbox"
							:disabled="unavailable.includes(participant.userId)"
							:data-testid="`participant-active-${participant.userId}`"
							@change="onActiveChange">
						{{ participant.displayName }}
						<span v-if="unavailableNote && unavailable.includes(participant.userId)" class="participant-note">
							{{ unavailableNote }}
						</span>
					</label>
					<input
						v-model.number="participant.sharePct"
						type="number"
						min="1"
						max="100"
						step="1"
						class="share-input"
						:disabled="!participant.active"
						:data-testid="`participant-share-${participant.userId}`">
					<span class="share-unit">%</span>
				</li>
			</ul>

			<p class="share-total" :class="{ invalid: !isValid }" data-testid="share-total">
				{{ t('chorestrike', 'Total: {total}%', { total: totalPct }) }}
			</p>

			<!-- What else belongs to the decision, e.g. the sub-tasks of the work. -->
			<slot />

			<div class="dialog-actions cs-dialog-actions">
				<button
					class="btn btn-secondary"
					data-testid="participation-cancel"
					@click="$emit('close')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button
					class="btn btn-primary"
					:disabled="!isValid"
					data-testid="participation-confirm"
					@click="confirm">
					{{ confirmLabel || t('chorestrike', 'Complete') }}
				</button>
			</div>
		</div>
	</Modal>
</template>

<script>
// CS-PLAN-070 WP3 (§3.6, C2a): the participation dialog records the ACTUAL
// work split at completion. Without it, the server credits the default
// split (equal shares of the assignees); a deviation from it sent from here
// carries the `allocationReviewed` confirmation (one reviewer decision, no
// per-person confirmation queue). Exact whole-percent shares only — the
// server enforces the 1-100 unique-membership and sum-100 invariants.
// CS-PLAN-091 WP-B2 (ADR-021 decision 10): anyone records for anyone, so
// the candidates are the household and the recorder need not take part.
import { translate as t } from '@nextcloud/l10n'
import Modal from './Modal.vue'

export default {
	name: 'ParticipationDialog',

	components: { Modal },

	props: {
		/** Candidate members `{userId, displayName}`. */
		members: {
			type: Array,
			required: true,
		},

		/**
		 * Completion mode: the user ids ticked when the dialog opens — who
		 * the server would credit without it. Null ticks every candidate.
		 * Ticking and unticking keeps the shares equal among the ticked.
		 */
		preselected: {
			type: Array,
			default: null,
		},

		/**
		 * Attribution-correction mode (WP3 §3.7 "reuse the form for
		 * attribution correction"): the recorded split of the corrected
		 * completion pre-fills the rows — still-member participants stay
		 * active with their exact percents, everyone else starts inactive.
		 * Departed recorded participants cannot be re-added (the server
		 * accepts current members only); the caller's hint explains the
		 * reassignment. The shares stay as entered in this mode.
		 */
		initialParticipants: {
			type: Array,
			default: null,
		},

		/**
		 * Completion mode: user ids that cannot be credited (a bonus task
		 * already paid them today). Their rows stay visible, unticked and
		 * disabled, with `unavailableNote` as the reason.
		 */
		unavailable: {
			type: Array,
			default: () => [],
		},

		unavailableNote: {
			type: String,
			default: '',
		},

		/** Optional dialog heading override (defaults to the completion copy). */
		heading: {
			type: String,
			default: '',
		},

		/** Optional hint override (defaults to the completion copy). */
		hint: {
			type: String,
			default: '',
		},

		/** Optional confirm-button label override. */
		confirmLabel: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			participants: [],
		}
	},

	computed: {
		dialogHeading() {
			return this.heading || t('chorestrike', 'Who did the work?')
		},

		dialogHint() {
			return this.hint || t('chorestrike', 'Record the actual work split. Shares must total exactly 100%.')
		},

		activeParticipants() {
			return this.participants.filter((participant) => participant.active)
		},

		totalPct() {
			return this.activeParticipants.reduce((sum, participant) => sum + (Number.isFinite(participant.sharePct) ? participant.sharePct : 0), 0)
		},

		isValid() {
			return this.activeParticipants.length > 0
				&& this.totalPct === 100
				&& this.activeParticipants.every((participant) => Number.isInteger(participant.sharePct) && participant.sharePct >= 1 && participant.sharePct <= 100)
		},
	},

	created() {
		if (this.initialParticipants) {
			// Attribution correction: the recorded split drives the
			// pre-fill over the CURRENT member list (departed recorded
			// participants are not members anymore and cannot be named
			// again — the correter redistributes their share).
			const recordedByUser = {}
			this.initialParticipants.forEach((participant) => {
				if ((participant.userId ?? null) !== null) {
					recordedByUser[participant.userId] = participant.sharePct
				}
			})
			this.participants = this.members
				.map((member) => ({
					userId: member.userId,
					displayName: member.displayName,
					active: (recordedByUser[member.userId] ?? null) !== null,
					sharePct: recordedByUser[member.userId] ?? 0,
				}))
				.sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
		} else {
			// Completion: equal shares of the ticked members, remainder
			// split by stable user order (the wallet-order 34/33/33 shape
			// mirrored client-side for display).
			const ticked = this.preselected
			this.participants = this.members
				.map((member) => ({
					userId: member.userId,
					displayName: member.displayName,
					active: (ticked === null || ticked.includes(member.userId)) && !this.unavailable.includes(member.userId),
					sharePct: 0,
				}))
				.sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
			this.rebalance()
		}
	},

	methods: {
		t,

		/**
		 * Equal shares of the ticked members with largest-remainder
		 * distribution over the stable user order (totals exactly 100).
		 */
		rebalance() {
			const ticked = this.activeParticipants
			const base = Math.floor(100 / Math.max(1, ticked.length))
			let remainder = 100 - base * ticked.length
			this.participants.forEach((participant) => {
				participant.sharePct = participant.active ? base + (remainder-- > 0 ? 1 : 0) : 0
			})
		},

		/** A tick changed: in completion mode the ticked members share equally again. */
		onActiveChange() {
			if (this.initialParticipants === null) {
				this.rebalance()
			}
		},

		confirm() {
			if (!this.isValid) {
				return
			}
			this.$emit('confirm', {
				participants: this.activeParticipants.map((participant) => ({
					userId: participant.userId,
					sharePct: participant.sharePct,
				})),
				allocationReviewed: true,
			})
		},
	},
}
</script>

<style scoped>
.participation-dialog {
	/* The padding counts into the width: on a phone the dialog is as wide
	   as its modal and never scrolls sideways. */
	box-sizing: border-box;
	padding: 1.5rem;
	min-width: min(420px, 100%);
}

.participation-dialog h2 {
	margin: 0 0 0.5rem 0;
	font-size: 1.2rem;
}

.participant-note {
	margin-inline-start: 0.35rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.dialog-hint {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.participant-list {
	list-style: none;
	margin: 0 0 1rem 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.participant-row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
}

.participant-name {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.share-input {
	width: 5rem;
	padding: 0.35rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.share-input:disabled {
	opacity: 0.5;
}

.share-unit {
	color: var(--color-text-maxcontrast);
}

.share-total {
	font-size: 0.9rem;
	font-weight: 600;
}

.share-total.invalid {
	color: var(--color-error-text);
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.75rem;
	margin-top: 1rem;
}

.btn {
	padding: 0.5rem 1.25rem;
	/* Touch-target minimum (family-core-ux §3.4): every dialog action
	 * stays comfortably tappable at the mobile width. */
	min-height: 44px;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	font-weight: 600;
}

.btn-primary {
	background: var(--color-primary);
	color: var(--color-main-background);
}

.btn-primary:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.btn-secondary {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}
</style>
