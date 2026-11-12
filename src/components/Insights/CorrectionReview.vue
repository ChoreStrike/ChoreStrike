<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<Modal
		persistent
		:aria-label="t('chorestrike', 'Correct the work split')"
		@close="$emit('cancel')">
		<section
			class="correction-review"
			data-testid="correction-review"
			role="alert">
			<h2>{{ t('chorestrike', 'Correct the work split') }}</h2>
			<p v-if="correction.conflict" data-testid="correction-conflict">
				{{ t('chorestrike', 'The completion changed since it was displayed') }}
			</p>
			<p v-else data-testid="correction-uncertain">
				{{ t('chorestrike', 'Could not save the correction') }}
			</p>
			<div class="split-comparison">
				<div>
					<h3>{{ t('chorestrike', 'Your draft') }}</h3>
					<ul data-testid="correction-draft">
						<li v-for="entry in draft" :key="entry.userId">
							{{ memberName(entry.userId) }}: {{ entry.sharePct }}%
						</li>
					</ul>
				</div>
				<div v-if="correction.conflict && correction.current">
					<h3>{{ t('chorestrike', 'Current split') }}</h3>
					<ul data-testid="correction-current">
						<li v-for="(entry, index) in correction.current.allocation" :key="index">
							{{ entry.displayName }}: {{ entry.sharePct }}%
						</li>
					</ul>
				</div>
			</div>
			<div class="review-actions cs-dialog-actions">
				<button type="button" @click="$emit('cancel')">
					{{ t('chorestrike', 'Cancel') }}
				</button>
				<button v-if="correction.conflict && !correction.current"
					type="button"
					:disabled="correction.loadingCurrent"
					data-testid="correction-refresh"
					@click="$emit('refresh')">
					{{ t('chorestrike', 'Refresh') }}
				</button>
				<button v-if="correction.conflict && correction.current?.canCorrect"
					type="button"
					data-testid="correction-review-button"
					@click="$emit('review')">
					{{ t('chorestrike', 'Review current split') }}
				</button>
				<button v-if="correction.uncertain"
					type="button"
					:disabled="correction.saving"
					data-testid="correction-retry"
					@click="$emit('retry')">
					{{ t('chorestrike', 'Save correction') }}
				</button>
			</div>
		</section>
	</Modal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Modal from '../Common/Modal.vue'

/**
 * The review step of a history correction that did not go through (WP3
 * §3.6): the completion changed in the meantime, or the save is uncertain.
 * It shows the draft next to the current split and never resubmits by
 * itself. Presentation only; the Insights view owns the correction session.
 */
export default {
	name: 'CorrectionReview',

	components: { Modal },

	props: {
		/** The correction session: `{ conflict, uncertain, current, loadingCurrent, saving }`. */
		correction: {
			type: Object,
			required: true,
		},

		/** The submitted split: `[{ userId, sharePct }]`. */
		draft: {
			type: Array,
			required: true,
		},

		/** Current members as `{ userId, displayName }`, for the draft's names. */
		members: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['cancel', 'refresh', 'review', 'retry'],

	methods: {
		t,

		memberName(userId) {
			return this.members.find((member) => member.userId === userId)?.displayName ?? userId
		},
	},
}
</script>

<style scoped>
.correction-review {
	border: 1px solid var(--color-element-warning);
	border-radius: 12px;
	margin: 0;
	padding: 1.5rem;
	padding-inline-end: 3.5rem;
	background: var(--color-background-dark);
}

.correction-review h2 {
	margin: 0 0 1rem 0;
	font-size: 1.3rem;
	color: var(--color-main-text);
}

.split-comparison {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 1rem;
	margin: 1rem 0;
}

.split-comparison h3 {
	font-size: 1rem;
	margin: 0 0 0.5rem;
}

.split-comparison ul {
	margin: 0;
	padding-inline-start: 1.5rem;
}

.review-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}

.review-actions button {
	min-height: 44px;
	padding: 0.5rem 1rem;
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: 6px;
	cursor: pointer;
}

.review-actions button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
