<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal
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
							:data-testid="`participant-active-${participant.userId}`">
						{{ participant.displayName }}
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
			<p v-if="!actorParticipates" role="status" data-testid="participation-actor-required">
				{{ t('chorestrike', 'To complete a task, include yourself in the work split. If your account is missing, close this dialog and reload.') }}
			</p>

			<div class="dialog-actions">
				<button
					class="btn btn-secondary"
					:data-testid="'participation-cancel'"
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
// work split at completion. Without it, the server credits the effective
// concrete agreement; a deviation from that agreement sent from here
// carries the `allocationReviewed` confirmation (one reviewer decision, no
// per-person confirmation queue). Exact whole-percent shares only — the
// server enforces the 1-100 unique-membership and sum-100 invariants.
import { translate as t } from '@nextcloud/l10n'
import Modal from './Modal.vue'

export default {
	name: 'ParticipationDialog',

	components: { Modal },

	props: {
		/** Candidate members `{userId, displayName}` (the assignee set). */
		members: {
			type: Array,
			required: true,
		},
		/**
		 * Server-derived actual-work proposal (WP3 §3.6):
		 * `{occurrenceId, basis, participants: [{userId, sharePct}]}` —
		 * exactly what a participant-less completion would credit. The
		 * dialog pre-fills the agreed shares; `solo` (no proposal
		 * participants) falls back to the equal split of the candidates.
		 */
		proposal: {
			type: Object,
			default: null,
		},
		/**
		 * The acting user (WP3 Q-074-02): the completer must take part in
		 * the work. When the proposal names other people, the actor is
		 * added to the split automatically (equal renormalized shares) —
		 * confirming someone else's split unchanged is not an option.
		 */
		actorUserId: {
			type: String,
			default: null,
		},
		/** Whether any member currently has availability constraints (hint only). */
		showAvailabilityHint: {
			type: Boolean,
			default: false,
		},
		/**
		 * Attribution-correction mode (WP3 §3.7 "reuse the form for
		 * attribution correction"): the recorded split of the corrected
		 * completion pre-fills the rows — still-member participants stay
		 * active with their exact percents, everyone else starts inactive.
		 * Departed recorded participants cannot be re-added (the server
		 * accepts current members only); the caller's hint explains the
		 * reassignment. The completer auto-join does NOT run in this mode —
		 * the correter need not take part (Q-074-02: completer or owner).
		 */
		initialParticipants: {
			type: Array,
			default: null,
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
			return this.participants.filter(participant => participant.active)
		},

		totalPct() {
			return this.activeParticipants.reduce((sum, participant) => sum + (Number.isFinite(participant.sharePct) ? participant.sharePct : 0), 0)
		},

		actorParticipates() {
			return this.initialParticipants !== null || (Boolean(this.actorUserId)
				&& this.activeParticipants.some(participant => participant.userId === this.actorUserId && participant.sharePct > 0))
		},

		isValid() {
			return this.actorParticipates && this.activeParticipants.length > 0
				&& this.totalPct === 100
				&& this.activeParticipants.every(participant => Number.isInteger(participant.sharePct) && participant.sharePct >= 1 && participant.sharePct <= 100)
		},
	},

	created() {
		// Server truth first (WP3 §3.6): the agreed occurrence shares are
		// the pre-fill; only without a proposal split does the dialog
		// default to the equal wallet-order mirror client-side.
		const proposed = this.proposal?.participants ?? []
		const namesByUser = {}
		this.members.forEach(member => {
			namesByUser[member.userId] = member.displayName
		})

		if (this.initialParticipants) {
			// Attribution correction: the recorded split drives the
			// pre-fill over the CURRENT member list (departed recorded
			// participants are not members anymore and cannot be named
			// again — the correter redistributes their share).
			const recordedByUser = {}
			this.initialParticipants.forEach(participant => {
				if (participant.userId != null) {
					recordedByUser[participant.userId] = participant.sharePct
				}
			})
			this.participants = this.members
				.map(member => ({
					userId: member.userId,
					displayName: member.displayName,
					active: recordedByUser[member.userId] != null,
					sharePct: recordedByUser[member.userId] ?? 0,
				}))
				.sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
		} else if (proposed.length > 0) {
			this.participants = proposed
				.map(participant => ({
					userId: participant.userId,
					displayName: namesByUser[participant.userId] ?? participant.userId,
					active: true,
					sharePct: participant.sharePct,
				}))
				.sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
		} else {
			// Default: everyone equal, remainder split by stable user order
			// (the wallet-order 34/33/33 shape mirrored client-side for display).
			const entries = this.members.map(member => ({ ...member }))
			entries.sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
			this.participants = this.buildEqualSplit(entries)
		}

		// Q-074-02: the completer must take part in the work. When the
		// proposal names other people, the actor joins the split with an
		// equal renormalized share — confirming someone else's split
		// unchanged is not an option. Not in attribution-correction mode:
		// the correter (completer or owner) need not be a participant.
		if (!this.initialParticipants
			&& this.actorUserId
			&& this.participants.length > 0
			&& !this.participants.some(participant => participant.userId === this.actorUserId)) {
			this.participants.push({
				userId: this.actorUserId,
				displayName: namesByUser[this.actorUserId] ?? this.actorUserId,
				active: true,
				sharePct: 1,
			})
			this.participants = this.buildEqualSplit(this.participants)
		}
	},

	methods: {
		t,

		/**
		 * Equal split with largest-remainder distribution over the stable
		 * user order (totals exactly 100).
		 * @param {Array<{userId: string, displayName: string}>} entries The candidate rows.
		 * @return {Array<{userId: string, displayName: string, active: boolean, sharePct: number}>}
		 */
		buildEqualSplit(entries) {
			const sorted = [...entries].sort((a, b) => String(a.userId).localeCompare(String(b.userId)))
			const count = Math.max(1, sorted.length)
			const base = Math.floor(100 / count)
			let remainder = 100 - base * count
			return sorted.map(entry => {
				const pct = base + (remainder > 0 ? 1 : 0)
				if (remainder > 0) remainder--
				return {
					userId: entry.userId,
					displayName: entry.displayName,
					active: true,
					sharePct: pct,
				}
			})
		},

		confirm() {
			if (!this.isValid) return
			this.$emit('confirm', {
				participants: this.activeParticipants.map(participant => ({
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
	padding: 1.5rem;
	min-width: min(420px, calc(100vw - 3rem));
}

.participation-dialog h2 {
	margin: 0 0 0.5rem 0;
	font-size: 1.2rem;
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
