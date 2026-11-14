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
					id: 'today',
					title: t('chorestrike', 'Today'),
					text: t('chorestrike', 'Your day at a glance: your own status, what is overdue, what is due today and what is already possible. Upcoming work is in the calendar.'),
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
					id: 'absence',
					title: t('chorestrike', 'Absence'),
					text: t('chorestrike', 'An absence is a period in which nothing is planned for you and nothing counts for or against anyone. Everyone enters their own under Absence; no reason and no request are needed.'),
				},
				{
					id: 'category',
					title: t('chorestrike', 'Category'),
					text: t('chorestrike', 'Sorts a task and lets the task list filter by it. Owners keep the list in the household settings; a task whose category was deleted counts as Other.'),
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
					text: t('chorestrike', 'An optional play area outside the daily routine. Games open with finished chores; owners set how many per game and can let a person start over, while the highscores stay. You can hide it in the Settings.'),
				},
				{
					id: 'coins',
					title: t('chorestrike', 'Coins'),
					text: t('chorestrike', 'The household reward currency for completed work. Today shows your available balance. Available Coins can be requested; reserved Coins back open requests and are only spent on actual delivery.'),
				},
				{
					id: 'reward-catalog',
					title: t('chorestrike', 'Reward catalog'),
					text: t('chorestrike', 'The optional list of family-agreed rewards. Owners enable it for the household and each person decides whether to see it — the two choices are independent. Hiding it never touches existing requests.'),
				},
				{
					id: 'reward-request',
					title: t('chorestrike', 'Reward request'),
					text: t('chorestrike', 'A request reserves the Coins first. A child\'s request waits for owner approval; adults confirm their own request explicitly. Delivery is confirmed separately — only then are the Coins actually spent.'),
				},
				{
					id: 'balance-clarification',
					title: t('chorestrike', 'Balance clarification'),
					text: t('chorestrike', 'When a past correction is being clarified, new requests and deliveries wait neutrally — nothing is lost and nothing counts against anyone. Approvals, rejections and cancellations stay possible.'),
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
