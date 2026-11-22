<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="calendar-agenda" data-testid="calendar-agenda">
		<section v-for="day in days"
			:key="day.day"
			class="agenda-day"
			:class="{ today: day.isToday, past: day.isPast }"
			:aria-label="formatLongDayKey(day.day)"
			:data-testid="`calendar-agenda-day-${day.day}`">
			<div class="day-head">
				<h2>{{ formatShortDayKey(day.day) }}</h2>
				<span v-if="day.isToday" class="today-badge">{{ t('chorestrike', 'Today') }}</span>
				<span v-if="day.load?.preferred" class="preferred" data-testid="calendar-agenda-preferred">
					<Icon name="Star" :size="14" /> {{ t('chorestrike', 'preferred') }}
				</span>
				<span class="spacer" />
				<span v-if="day.load?.absent" class="load-text">{{ t('chorestrike', 'Absent') }}</span>
				<template v-else-if="day.load">
					<span class="load-bar" :class="{ over: isOver(day.load) }" aria-hidden="true">
						<span class="load-fill" :style="{ width: `${fill(day.load)}%` }" />
					</span>
					<span class="load-text"
						:class="{ over: isOver(day.load) }"
						:data-testid="`calendar-agenda-load-${day.day}`"
						:aria-label="loadLabel(day.load)">
						{{ used(day.load) }}/{{ day.load.budget }}
					</span>
				</template>
			</div>

			<ul class="day-entries">
				<li v-for="entry in day.overdue"
					:key="entry.key"
					class="entry overdue"
					data-testid="calendar-agenda-overdue">
					<span class="entry-name">{{ entry.name }}</span>
					<span class="entry-energy"><Icon name="Zap" :size="14" /> {{ entry.energy }}</span>
					<span class="entry-note">{{ t('chorestrike', 'overdue') }}</span>
				</li>
				<li v-for="entry in day.entries"
					:key="entry.key"
					class="entry"
					:class="{ overload: entry.overload }"
					data-testid="calendar-agenda-entry">
					<span class="entry-name">
						{{ entry.name }}
						<span v-if="people(entry)" class="entry-people">{{ people(entry) }}</span>
					</span>
					<span class="entry-energy"><Icon name="Zap" :size="14" /> {{ entry.energy }}</span>
					<span class="entry-note">
						{{ entry.overload ? t('chorestrike', 'does not fit') : windowRangeLabel(entry.windowStartOn, entry.windowEndOn) }}
					</span>
				</li>
				<li v-for="entry in day.completed"
					:key="entry.key"
					class="entry completed"
					data-testid="calendar-agenda-done">
					<!-- A task the viewer may not see carries no name. -->
					<span class="entry-name">{{ entry.name || t('chorestrike', 'Unknown task') }}</span>
					<span class="entry-note">{{ t('chorestrike', 'done') }}</span>
				</li>
				<li v-if="day.overdue.length + day.entries.length + day.completed.length === 0" class="entry empty">
					{{ day.isPast ? t('chorestrike', 'Nothing done') : t('chorestrike', 'Nothing planned') }}
				</li>
			</ul>
		</section>
		<p v-if="horizonDays && days.some((day) => day.beyond)" class="agenda-note" data-testid="calendar-agenda-beyond">
			{{ t('chorestrike', 'The suggestion looks {days} days ahead. Later days show when the work is due.', { days: horizonDays }) }}
		</p>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import Icon from '../Common/Icon.vue'
import { formatLongDayKey, formatShortDayKey } from '../../utils/dateKeys.js'
import { memberNames } from '../../utils/taskDisplay.js'
import { windowRangeLabel } from '../../utils/windowLabels.js'

/**
 * The week of the calendar as a list of days (CS-PLAN-091 WP-C, ADR-022):
 * what the live suggestion places on each day with the window it lies in,
 * the viewer's placed energy against the day's energy, the overdue work on
 * today and what was done. Presentation only; {@link buildAgendaDays}
 * prepares the days.
 */
const props = defineProps({
	/** The days as `buildAgendaDays` returns them. */
	days: { type: Array, required: true },
	/** The household members, for the names of the people an entry names. */
	members: { type: Array, default: () => [] },
	/** The viewer: an entry that names only them shows no name. */
	userId: { type: String, default: null },
	/** How many days the suggestion looks ahead, as the server names it. */
	horizonDays: { type: Number, default: null },
})

/**
 * Who else an entry names: the others of a team task, or whom the day suggests it to.
 * @param {object} entry An entry of a day.
 * @return {string}
 */
function people(entry) {
	const others = entry.userIds.filter((id) => id !== props.userId)
	return others.length === 0 ? '' : memberNames(others, props.members)
}

/**
 * The energy a day carries: what is placed on it and, on today, what was
 * already done — the suggestion places today's work on what is left.
 * @param {object} load A load of the suggestion read.
 * @return {number} Energy, to one decimal place.
 */
function used(load) {
	return Math.round((load.placed + (load.doneMinor ?? 0) / 100) * 10) / 10
}

function isOver(load) {
	return used(load) > load.budget
}

function fill(load) {
	return load.budget > 0 ? Math.min(100, Math.round((used(load) / load.budget) * 100)) : (used(load) > 0 ? 100 : 0)
}

function loadLabel(load) {
	if (load.doneMinor > 0) {
		return isOver(load)
			? t('chorestrike', '{used} of {budget} energy done or placed: more than the day holds', { used: used(load), budget: load.budget })
			: t('chorestrike', '{used} of {budget} energy done or placed', { used: used(load), budget: load.budget })
	}
	return isOver(load)
		? t('chorestrike', '{placed} of {budget} energy placed: more than the day holds', { placed: load.placed, budget: load.budget })
		: t('chorestrike', '{placed} of {budget} energy placed', { placed: load.placed, budget: load.budget })
}
</script>

<style scoped>
.agenda-day {
	padding: 0.4rem 0.25rem 0.5rem;
	border-top: 1px solid var(--color-border);
}

.agenda-day:last-of-type {
	border-bottom: 1px solid var(--color-border);
}

.agenda-day.today {
	background: var(--color-primary-element-light);
}

.day-head {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-height: 28px;
}

.day-head h2 {
	margin: 0;
	font-size: 1rem;
	font-weight: 700;
}

.today-badge {
	padding: 0.1rem 0.5rem;
	border-radius: 10px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.75rem;
	font-weight: 700;
}

.preferred {
	display: inline-flex;
	align-items: center;
	gap: 0.2rem;
	color: var(--color-main-text);
	font-size: 0.75rem;
}

.spacer {
	flex: 1;
}

.load-bar {
	display: block;
	width: 56px;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	/* A track needs an outline to stand out from its surface (BUG-UX-006). */
	box-shadow: var(--cs-graphic-track-outline);
	overflow: hidden;
}

.load-fill {
	display: block;
	height: 6px;
	background: var(--color-primary-element);
}

.load-bar.over .load-fill {
	background: var(--color-element-error);
}

.load-text {
	min-width: 2.5rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	text-align: end;
}

.load-text.over {
	color: var(--color-main-text);
	font-weight: 700;
}

.day-entries {
	margin: 0;
	padding: 0;
	list-style: none;
}

.entry {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-height: 30px;
	font-size: 0.9rem;
}

.entry-name {
	flex: 1;
	min-width: 0;
	overflow-wrap: anywhere;
}

.entry-people {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
}

.entry-energy {
	display: inline-flex;
	align-items: center;
	gap: 0.1rem;
	font-weight: 600;
}

.entry-note {
	min-width: 4.5rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	text-align: end;
}

.entry.overdue,
.entry.overdue .entry-note {
	color: var(--color-error-text);
	font-weight: 600;
}

.entry.overload .entry-note {
	color: var(--color-main-text);
	font-weight: 600;
}

.entry.completed .entry-name {
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.entry.empty,
.agenda-note {
	color: var(--color-text-maxcontrast);
}

.agenda-note {
	margin: 0.75rem 0 0;
	font-size: 0.85rem;
}
</style>
