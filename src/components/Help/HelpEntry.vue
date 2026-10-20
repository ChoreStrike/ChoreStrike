<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<!-- Single dynamic root: a list item inside the app navigation, an
	     inline span everywhere else. The modal renders inside the root
	     (v-if) — its fixed overlay positioning does not depend on the DOM
	     nesting. -->
	<component :is="variant === 'navigation' ? 'li' : 'span'" class="help-entry-root">
		<button
			v-if="variant === 'navigation'"
			type="button"
			class="help-entry-button"
			data-testid="nav-help"
			:aria-expanded="open ? 'true' : 'false'"
			@click="open = true">
			<span class="help-entry-icon" aria-hidden="true">?</span>
			<span>{{ t('chorestrike', 'Help') }}</span>
		</button>
		<!-- Context variant: a small inline trigger for one contextual
		     explanation, usable outside navigation (views, task details,
		     settings sections). -->
		<button
			v-else
			type="button"
			class="help-context-button"
			:data-testid="`help-context-${topic ?? 'general'}`"
			:aria-expanded="open ? 'true' : 'false'"
			@click="open = true">
			<span class="help-entry-icon" aria-hidden="true">?</span>
			<span class="help-context-label">{{ triggerLabel }}</span>
		</button>

		<Modal
			v-if="open"
			:aria-label="modalTitle"
			data-testid="help-modal"
			@close="open = false">
			<div class="help-modal-body">
				<h2 class="help-modal-title">
					{{ modalTitle }}
				</h2>
				<template v-if="activeTopic">
					<p class="topic-body">
						{{ activeTopic.body }}
					</p>
				</template>
				<HelpGlossary v-else />
				<button
					type="button"
					class="help-close"
					data-testid="help-close"
					@click="open = false">
					{{ t('chorestrike', 'Close') }}
				</button>
			</div>
		</Modal>
	</component>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import HelpGlossary from './HelpGlossary.vue'
import Modal from '../Common/Modal.vue'

/**
 * The help entry point (CS-PLAN-070 WP6 §3.7 + WP8 §3.6, FEAT-UX-023
 * essential slice). Two variants share one modal:
 *
 * - `navigation` (default): the navigation entry opening the plain
 *   glossary — always reachable, never intrusive.
 * - `context`: a small inline trigger rendering ONE of the essential
 *   contextual explanations (account/role, work shares, progress and
 *   windows, catalog gates, owner pricing) next to the surface it
 *   explains. The topics are manually reachable only — no auto-started
 *   tour, no persisted dismissal state.
 *
 * Both variants use the shared named Modal (focus trap, Escape and
 * focus restore) — never a second glossary or a raw NcModal.
 */
export default {
	name: 'HelpEntry',

	components: {
		HelpGlossary,
		Modal,
	},

	props: {
		/** 'navigation' renders a list-item root; 'context' an inline span. */
		variant: {
			type: String,
			default: 'navigation',
			validator: (value) => ['navigation', 'context'].includes(value),
		},
		/**
		 * The contextual topic id ('setup' | 'agreements' | 'progress' |
		 * 'catalog' | 'owner-pricing'). Null/unknown opens the full
		 * glossary.
		 */
		topic: {
			type: String,
			default: null,
		},
		/** Optional trigger label override for the context variant. */
		label: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			open: false,
		}
	},

	computed: {
		// Literal t() keys keep every topic extractable for the l10n
		// artifacts — a static data array would be invisible to the key
		// check and render untranslated outside English.
		topics() {
			return {
				setup: {
					title: t('chorestrike', 'Accounts and roles'),
					body: t('chorestrike', 'Every person — including a child — needs their own existing Nextcloud account; creating accounts and signing in belongs to Nextcloud. Wizard invites add members, and an owner sets the child role in the member settings. On a shared device everyone uses their own sign-in — there are no local child profiles.'),
				},
				agreements: {
					title: t('chorestrike', 'Agreements and shares'),
					body: t('chorestrike', 'Agreements are what the family has planned — responsibility and joint obligations differ from voluntary help. Actual shares split the total work; they never multiply it: a total energy-1 chore earns 10 Coins once, so 34/33/33% give 3.4/3.3/3.3 Coins.'),
				},
				progress: {
					// The trigger label renders inline on My Day, which
					// stays Coin-free (C5f) — the Coins line lives in the
					// modal body only.
					title: t('chorestrike', 'Progress, windows and rhythm'),
					body: t('chorestrike', 'Each chore has a completion window around its planned day. Free and paused days are neutral, and catch-up covers a miss without backdating on-time credit. Your contribution share is separate from your Coins: Coins are what you can spend or reserve.'),
				},
				catalog: {
					title: t('chorestrike', 'The reward catalog'),
					body: t('chorestrike', 'Owners control the household catalog, and each person controls whether they see it — the two gates are independent. Child requests and owner approvals, and adult requests too, reserve the Coins first; delivery is confirmed separately afterwards. Hiding the catalog never touches existing requests, and spending Coins never removes earned rhythm titles or awards. Games only have their own scores.'),
				},
				'owner-pricing': {
					title: t('chorestrike', 'Pricing rewards as an owner'),
					body: t('chorestrike', 'Suggest affordable, family-agreed treats that are not essentials, measured against the child\'s own realistic effort and share — not adult output. Care, affection, meals, rest and necessary chore exemptions are not purchasable rewards. There are no automatic catalog templates and no household earning-rate controls.'),
				},
			}
		},

		activeTopic() {
			return this.topic ? this.topics[this.topic] ?? null : null
		},

		triggerLabel() {
			return this.label ?? this.activeTopic?.title ?? t('chorestrike', 'Help')
		},

		modalTitle() {
			return this.activeTopic?.title ?? t('chorestrike', 'Help')
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.help-entry-root {
	list-style: none;
}

.help-entry-button,
.help-context-button {
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

.help-context-button {
	width: auto;
	padding: 0.25rem 0.6rem;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.help-entry-button:hover,
.help-context-button:hover {
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
	flex-shrink: 0;
}

.help-modal-body {
	padding: 1.5rem;
	max-height: 80vh;
	overflow-y: auto;
}

.help-modal-title {
	margin: 0 0 1rem 0;
}

.topic-body {
	margin: 0 0 1rem 0;
	line-height: 1.5;
	color: var(--color-main-text);
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
