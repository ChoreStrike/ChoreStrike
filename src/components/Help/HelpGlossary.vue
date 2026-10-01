<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="help-glossary"
		data-testid="help-glossary"
		role="region"
		:aria-label="t('chorestrike', 'Glossary')">
		<p class="glossary-intro">
			{{ t('chorestrike', 'Short explanations of the words ChoreStrike uses — in plain family language.') }}
		</p>
		<dl class="glossary-list">
			<div v-for="term in terms"
				:key="term.id"
				class="glossary-term"
				:data-testid="`help-term-${term.id}`">
				<dt>{{ term.title }}</dt>
				<dd>{{ term.text }}</dd>
			</div>
		</dl>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The essential help slice (CS-PLAN-070 WP6 §3.7, FEAT-UX-023): a plain
 * glossary of the core household terms, aligned with the delivered
 * semantics (C1/C2). Static content — no backend reads, no tour.
 */
export default {
	name: 'HelpGlossary',

	computed: {
		// Literal t() keys keep the glossary extractable for the l10n
		// artifacts — a static data array would be invisible to the key
		// check and render untranslated outside English.
		terms() {
			return [
				{
					id: 'my-day',
					title: t('chorestrike', 'My Day'),
					text: t('chorestrike', 'Your day at a glance: what is due today, what is catch-up, and which appointments are coming.'),
				},
				{
					id: 'agreement',
					title: t('chorestrike', 'Agreement'),
					text: t('chorestrike', 'What the family has agreed for the coming days — nothing more. Work only counts once it is done.'),
				},
				{
					id: 'catch-up',
					title: t('chorestrike', 'Catch-up'),
					text: t('chorestrike', 'A missed chore stays visible as catch-up with its original date. Completing it now covers the miss — it stays a catch-up.'),
				},
				{
					id: 'pause',
					title: t('chorestrike', 'Pause'),
					text: t('chorestrike', 'A pause is neutral: during a pause nothing counts for or against anyone. Illness, travel or simply too much at once are all fine reasons.'),
				},
				{
					id: 'streak',
					title: t('chorestrike', 'Streak'),
					text: t('chorestrike', 'The number of days in a row you kept your agreements. Today stays pending until the day closes.'),
				},
				{
					id: 'weekly-goal',
					title: t('chorestrike', 'Weekly goal'),
					text: t('chorestrike', 'The shared goal of the whole household for this week.'),
				},
				{
					id: 'quiet-time',
					title: t('chorestrike', 'Quiet time'),
					text: t('chorestrike', 'During your quiet time no summaries are delivered. In-app notifications are not affected.'),
				},
				{
					id: 'ranking',
					title: t('chorestrike', 'Weekly ranking'),
					text: t('chorestrike', 'An optional weekly comparison of on-time shares. Participation is voluntary and shows shares, never amounts.'),
				},
				{
					id: 'arcade',
					title: t('chorestrike', 'Arcade'),
					text: t('chorestrike', 'An optional play area outside the daily routine. You can hide it in the Settings.'),
				},
				{
					id: 'coins',
					title: t('chorestrike', 'Coins'),
					text: t('chorestrike', 'The household reward currency for completed work. The balance lives in the household overview — never on My Day.'),
				},
			]
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.glossary-intro {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.glossary-list {
	display: flex;
	flex-direction: column;
	gap: 0.9rem;
	margin: 0;
}

.glossary-term dt {
	font-weight: 600;
	color: var(--color-main-text);
}

.glossary-term dd {
	margin: 0.15rem 0 0 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}
</style>
