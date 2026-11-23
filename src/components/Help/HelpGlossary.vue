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
 * semantics (C1/C2; due windows since ADR-021). Static content — no
 * backend reads, no tour.
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
					text: t('chorestrike', 'Your day at a glance: your own status, what is overdue, what is suggested for today and what is already possible. Upcoming work is in the calendar.'),
				},
				{
					id: 'suggestion',
					title: t('chorestrike', 'Suggestion'),
					text: t('chorestrike', 'ChoreStrike suggests a day inside its window for every task: one of your preferred days first, else the day you have the most energy left. The suggestion adapts when something is done, postponed or changed; what it suggested for today stays on today\'s list. It is advice — you may do any task on any day.'),
				},
				{
					id: 'window',
					title: t('chorestrike', 'Due window'),
					text: t('chorestrike', 'Every task has a window instead of a single due day: its planned day plus and minus its flex days. Work done inside the window, or earlier, is on time.'),
				},
				{
					id: 'overdue',
					title: t('chorestrike', 'Overdue'),
					text: t('chorestrike', 'A task is overdue once its window has ended. It shows as one red entry until it is done; on a fixed day, until the next window opens. Missed rounds do not pile up.'),
				},
				{
					id: 'postpone',
					title: t('chorestrike', 'Postpone'),
					text: t('chorestrike', 'Moves the window of a task by half its flex days, up to three times per round. A postponed task still counts as on time. A task without flex days, or one that is already overdue, cannot be postponed.'),
				},
				{
					id: 'sleeping',
					title: t('chorestrike', 'Sleeping task'),
					text: t('chorestrike', 'A task that rests, for example over the winter: it has no window, is never overdue and is not suggested. It wakes on its wake date, with Wake up, or when someone completes it.'),
				},
				{
					id: 'bonus-task',
					title: t('chorestrike', 'Bonus task'),
					text: t('chorestrike', 'A voluntary task that is always available and never overdue, such as cooking. It earns energy and Coins once per person and day and counts neither for nor against the weekly goal, the streak or the ranking.'),
				},
				{
					id: 'coin-factor',
					title: t('chorestrike', 'Coin factor and bonus days'),
					text: t('chorestrike', 'An owner gives each person a coin factor of × 1, × 2 or × 3, so that someone with less time can collect as many Coins as the others. On bonus days, which an owner starts for one to three days, everyone\'s Coins count × 1.5 on top. Both change Coins only, never energy, the weekly goal or the streak.'),
				},
				{
					id: 'shares',
					title: t('chorestrike', 'Work shares'),
					text: t('chorestrike', 'People who do a chore together share it. Actual shares split the total work; they never multiply it: a total energy-1 chore earns 10 Coins once, so 34/33/33% give 3.4/3.3/3.3 Coins, before coin factors and bonus days.'),
				},
				{
					id: 'absence',
					title: t('chorestrike', 'Absence'),
					text: t('chorestrike', 'An absence is a period in which nothing is planned for you. A task whose whole window lies inside it never counts against you; work you do during it still counts. Everyone enters their own under Absence; no reason and no request are needed.'),
				},
				{
					id: 'category',
					title: t('chorestrike', 'Category'),
					text: t('chorestrike', 'Sorts a task and lets the task list filter by it. Owners keep the list in the household settings; a task whose category was deleted counts as Other.'),
				},
				{
					id: 'streak',
					title: t('chorestrike', 'Streak'),
					text: t('chorestrike', 'The number of days in a row on which every task of yours whose window ended was done on time. Days without such a task neither add to it nor break it. Today stays pending until the day closes.'),
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
					id: 'color-scheme',
					title: t('chorestrike', 'Colour scheme'),
					text: t('chorestrike', 'The accent colour of the app, which everyone picks for themselves under Settings → Personal → Appearance. It applies on all your devices; backgrounds and text follow your Nextcloud theme, and a high-contrast theme keeps the standard colours.'),
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
