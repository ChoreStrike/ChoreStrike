<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<li class="help-entry">
		<button
			type="button"
			class="help-entry-button"
			data-testid="nav-help"
			:aria-expanded="open ? 'true' : 'false'"
			@click="open = true">
			<span class="help-entry-icon" aria-hidden="true">?</span>
			<span>{{ t('chorestrike', 'Help') }}</span>
		</button>

		<NcModal
			v-if="open"
			:can-close="true"
			data-testid="help-modal"
			@close="open = false">
			<div class="help-modal-body">
				<h2 class="help-modal-title">
					{{ t('chorestrike', 'Help') }}
				</h2>
				<HelpGlossary />
				<button
					type="button"
					class="help-close"
					data-testid="help-close"
					@click="open = false">
					{{ t('chorestrike', 'Close') }}
				</button>
			</div>
		</NcModal>
	</li>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcModal } from '@nextcloud/vue'
import HelpGlossary from './HelpGlossary.vue'

/**
 * The help entry point (CS-PLAN-070 WP6 §3.7, FEAT-UX-023 essential
 * slice): a navigation entry opening the plain glossary in a modal —
 * always reachable, never intrusive.
 */
export default {
	name: 'HelpEntry',

	components: {
		NcModal,
		HelpGlossary,
	},

	data() {
		return {
			open: false,
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.help-entry {
	list-style: none;
}

.help-entry-button {
	display: flex;
	align-items: center;
	gap: 0.6rem;
	width: 100%;
	min-height: 44px;
	padding: 0.5rem 0.75rem;
	border: none;
	border-radius: 8px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.95rem;
	text-align: start;
}

.help-entry-button:hover {
	background: var(--color-background-hover);
}

.help-entry-icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border: 1.5px solid currentColor;
	border-radius: 50%;
	font-size: 0.8rem;
	font-weight: 700;
}

.help-modal-body {
	padding: 1.5rem;
	max-height: 80vh;
	overflow-y: auto;
}

.help-modal-title {
	margin: 0 0 1rem 0;
}

.help-close {
	margin-top: 1.25rem;
	min-height: 44px;
	padding: 0.5rem 1.25rem;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-weight: 600;
}
</style>
