<!--
  @copyright Copyright (c) 2026 ChoreStrike Contributors
  @license MIT
-->

<template>
	<div class="insights-view" data-testid="insights-view">
		<header class="insights-header">
			<h1 data-testid="insights-title">
				{{ t('chorestrike', 'Insights') }}
			</h1>
			<p class="subtitle">
				{{ t('chorestrike', 'How your household is really doing') }}
			</p>
		</header>

		<div v-if="loading" class="insights-loading" data-testid="insights-loading">
			<div class="skeleton skeleton-header" />
			<div class="skeleton skeleton-section" />
			<div class="skeleton skeleton-section" />
		</div>

		<div v-else-if="houseStore.error" class="insights-error" data-testid="insights-error">
			<ErrorState @retry="retryInsights" />
		</div>

		<template v-else>
			<section class="insights-section" :aria-label="t('chorestrike', 'House Overview')">
				<HouseStatsCards :stats="stats" />
			</section>

			<section class="insights-section">
				<h2>{{ t('chorestrike', 'Fairness') }}</h2>
				<ErrorState
					v-if="usersError"
					@retry="retryHouseUsers" />
				<p v-else-if="members.length === 0" class="empty-hint" data-testid="fairness-empty">
					{{ t('chorestrike', 'No members yet — invite your family to get started.') }}
				</p>
				<MemberFairnessTable
					v-else
					:members="members"
					:completedEnergyMinorByUser="completedEnergyMinorByUser" />
			</section>

			<!-- The opt-in ranking with its medals (CS-PLAN-090 WP-E): the
			     Leaderboard page became this section; `/leaderboard` leads here. -->
			<section id="ranking"
				ref="ranking"
				class="insights-section"
				data-testid="insights-ranking">
				<h2>{{ t('chorestrike', 'Ranking') }}</h2>
				<RankingPanel />
			</section>

			<section class="insights-section">
				<h2>{{ t('chorestrike', 'Recent Activity') }}</h2>
				<p v-if="houseStore.activityError" class="error-hint" data-testid="activity-error">
					{{ houseStore.activityError }}
					<button type="button" data-testid="activity-retry" @click="refreshActivity">
						{{ t('chorestrike', 'Refresh') }}
					</button>
				</p>
				<!-- Only before the first read: a refresh keeps the list, and
				     with it the line that is open, in place. -->
				<p v-else-if="activityLoading && houseStore.activity.length === 0" class="empty-hint" data-testid="activity-loading">
					{{ t('chorestrike', 'Loading activity…') }}
				</p>
				<ActivityFeed
					v-else
					:items="houseStore.activity"
					:events="houseStore.activityEvents"
					:timezone="houseStore.timezone"
					:busyId="undoingId"
					:blockedIds="blockedUndoIds"
					@correct="startCorrection"
					@undo="undoCompletion" />
			</section>

			<CorrectionReview
				v-if="correction && (correction.conflict || correction.uncertain)"
				:correction="correction"
				:draft="correctionDraft"
				:members="memberOptions"
				@cancel="cancelCorrection"
				@refresh="reloadCurrentSplit(correction)"
				@review="reviewCurrentSplit"
				@retry="confirmCorrection()" />

			<ParticipationDialog
				v-if="correction && !correction.conflict && !correction.uncertain"
				:members="memberOptions"
				:initialParticipants="correctionDraft"
				:heading="t('chorestrike', 'Correct the work split')"
				:hint="correctionHint"
				:confirmLabel="t('chorestrike', 'Save correction')"
				@close="cancelCorrection"
				@confirm="confirmCorrection" />
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import ErrorState from '../components/Common/ErrorState.vue'
import ParticipationDialog from '../components/Common/ParticipationDialog.vue'
import ActivityFeed from '../components/Insights/ActivityFeed.vue'
import CorrectionReview from '../components/Insights/CorrectionReview.vue'
import HouseStatsCards from '../components/Insights/HouseStatsCards.vue'
import MemberFairnessTable from '../components/Insights/MemberFairnessTable.vue'
import RankingPanel from '../components/Insights/RankingPanel.vue'
import { useHouseStore } from '../store/house.js'
import { useRoomTasksStore } from '../store/roomTasks.js'
import { useUserStore } from '../store/user.js'
import { showErrorWithReference } from '../utils/errorToast.js'
import { logger } from '../utils/logger.js'

export default {
	name: 'InsightsView',

	components: {
		ErrorState,
		CorrectionReview,
		HouseStatsCards,
		MemberFairnessTable,
		RankingPanel,
		ActivityFeed,
		ParticipationDialog,
	},

	setup() {
		const houseStore = useHouseStore()
		const userStore = useUserStore()
		const roomTasksStore = useRoomTasksStore()
		return { houseStore, userStore, roomTasksStore }
	},

	data() {
		return {
			loading: false,
			// Tracks a failed house-users fetch separately (CS-PLAN-051 D1):
			// the fairness section must never render the "No members yet"
			// hint for data it could not load.
			usersError: false,
			// The WP3 §3.6 history correction session: the activity item
			// plus its fetched completion read model (version + recorded
			// allocation pre-fill the reused participation form).
			correction: null,
			undoingId: null,
			blockedUndoIds: [],
		}
	},

	computed: {
		stats() {
			return this.houseStore.insights?.stats || {}
		},

		members() {
			return this.userStore.houseUsers
		},

		/**
		 * The participation dialog candidates: the current house members as
		 * `{userId, displayName}` pairs (the correter redistributes the
		 * recorded split within the CURRENT membership — departed recorded
		 * participants are not re-addable).
		 */
		memberOptions() {
			return (this.userStore.houseUsers ?? []).map((member) => ({
				userId: member.nextcloudUserId,
				displayName: member.displayName ?? member.nextcloudUserId,
			}))
		},

		activityLoading() {
			return this.houseStore.activityLoading
		},

		/**
		 * Per-member completed energy in exact minor units over the activity
		 * window, normalized against each member's weekly capacity in the
		 * fairness table. It adds up the frozen shares of the valid
		 * completions, so an energy edit never changes past work.
		 */
		completedEnergyMinorByUser() {
			const memberIds = new Map(this.members.map((member) => [member.nextcloudUserId, member.id]))
			const byUser = {}
			for (const item of this.houseStore.activity) {
				if (item.undoneAt) {
					continue
				}
				for (const share of item.allocation ?? []) {
					const memberId = memberIds.get(share.userId)
					if (memberId !== undefined) {
						byUser[memberId] = (byUser[memberId] ?? 0) + share.energyMinor
					}
				}
			}
			return byUser
		},

		/**
		 * The recorded split pre-filling the correction dialog: still-member
		 * participants with their exact percents (departed recorded
		 * participants serialize with a null userId and are filtered — the
		 * corrector redistributes their share).
		 */
		correctionDraft() {
			if (this.correction?.draft) {
				return this.correction.draft
			}
			const allocation = this.correction?.completion?.allocation ?? []
			return allocation
				.filter((entry) => (entry.userId ?? null) !== null)
				.map((entry) => ({ userId: entry.userId, sharePct: entry.sharePct }))
		},

		correctionHint() {
			const hasFormerShare = (this.correction?.completion?.allocation ?? [])
				.some((entry) => (entry.userId ?? null) === null)
			return hasFormerShare
				? t('chorestrike', 'A former member was part of this split — reassign their share. Shares must total exactly 100%.')
				: t('chorestrike', 'Adjust who actually did the work. Shares must total exactly 100%.')
		},
	},

	async mounted() {
		this.loading = true
		// allSettled so a members-fetch failure stays visible in its own
		// section instead of being swallowed by the shared catch (which
		// left `houseUsers` empty and rendered a false empty hint).
		const [insightsResult, usersResult] = await Promise.allSettled([
			this.houseStore.fetchInsights(),
			this.userStore.fetchHouseUsers(),
		])
		if (insightsResult.status === 'rejected') {
			logger.error('Failed to fetch insights', insightsResult.reason, { view: 'InsightsView', action: 'mounted' })
		}
		if (usersResult.status === 'rejected') {
			this.usersError = true
			logger.error('Failed to load house members', usersResult.reason, { view: 'InsightsView', action: 'mounted' })
		}
		this.loading = false
		await this.showLinkedSection()

		// Activity loads independently so a failure cannot blank the stats.
		try {
			await this.houseStore.fetchActivity()
		} catch (error) {
			logger.error('Failed to load activity feed', error, { view: 'InsightsView', action: 'mounted' })
		}
	},

	methods: {
		t,

		/**
		 * Bring the ranking into view when the address names it: the old
		 * Leaderboard address redirects to `#ranking` (D-090-54), and with
		 * hash routing the browser cannot follow that anchor by itself.
		 */
		async showLinkedSection() {
			if (this.$route?.hash !== '#ranking') {
				return
			}
			await this.$nextTick()
			this.$refs.ranking?.scrollIntoView?.({ block: 'start' })
		},

		/** User-triggered retry of the failed house-users fetch. */
		async retryHouseUsers() {
			this.usersError = false
			try {
				await this.userStore.fetchHouseUsers()
			} catch (error) {
				this.usersError = true
				logger.error('Failed to reload house members', error, { view: 'InsightsView', action: 'retryHouseUsers' })
			}
		},

		/** User-triggered retry of the failed insights fetch (CS-PLAN-051). */
		async retryInsights() {
			this.loading = true
			try {
				await this.houseStore.fetchInsights()
			} catch (error) {
				logger.error('Failed to reload insights', error, { view: 'InsightsView', action: 'retryInsights' })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the attribution correction for one history item (WP3 §3.6;
		 * the owner's alone, ADR-021 decision 9): fetch the versioned
		 * completion read model, then mount the participation form pre-filled
		 * with the recorded split. Members resolve in parallel.
		 * @param {object} item The activity feed row.
		 */
		async startCorrection(item) {
			if ((this.userStore.houseUsers ?? []).length === 0) {
				await this.userStore.fetchHouseUsers().catch(() => {})
			}
			try {
				const completion = await this.roomTasksStore.fetchCompletion(item.id)
				if (completion.canAttribute && !completion.undoneAt) {
					const pending = this.roomTasksStore.pendingAttributions[item.id]
					this.correction = {
						item,
						completion,
						expectedVersion: pending?.requested.expectedVersion ?? completion.version,
						draft: pending?.requested.participants ?? null,
						current: null,
						conflict: false,
						uncertain: Boolean(pending),
						saving: false,
						loadingCurrent: false,
					}
				}
			} catch (error) {
				showErrorWithReference(t('chorestrike', 'Could not load the completion'), error)
				logger.error('Failed to load completion for correction', error, { view: 'InsightsView', action: 'startCorrection', completionId: item.id })
			}
		},

		cancelCorrection() {
			this.correction = null
		},

		/**
		 * Submit the corrected split (PUT /completions/{id}/attribution).
		 * A 409 retains the draft separately from the new allocation until
		 * explicit review. An uncertain response offers a retry of the same
		 * receipt instead of creating another revision (WP3 §3.7).
		 * @param {object} payload The dialog confirmation `{participants}`.
		 */
		async confirmCorrection(payload) {
			const session = this.correction
			if (!session || session.saving || session.conflict) {
				return
			}
			const participants = payload?.participants ?? session.draft
			if (!participants) {
				return
			}
			session.saving = true
			try {
				await this.roomTasksStore.attributeCompletion(session.item.id, {
					participants,
					expectedVersion: session.expectedVersion,
				})
				if (this.correction === session) {
					this.correction = null
				}
				await this.refreshActivity()
			} catch (error) {
				if (error?.status === 409) {
					showError(t('chorestrike', 'The completion changed since it was displayed'))
				} else {
					showErrorWithReference(t('chorestrike', 'Could not save the correction'), error)
				}
				logger.error('Failed to attribute completion', error, { view: 'InsightsView', action: 'confirmCorrection', completionId: session.item.id })
				if (this.correction === session) {
					session.draft = participants.map(({ userId, sharePct }) => ({ userId, sharePct }))
					if (error?.code === 'PENDING_ATTRIBUTION') {
						const pending = this.roomTasksStore.pendingAttributions[session.item.id]
						session.draft = pending.requested.participants
						session.expectedVersion = pending.requested.expectedVersion
					}
					if (error?.status === 409) {
						session.uncertain = false
						session.conflict = true
						session.current = null
						await this.reloadCurrentSplit(session)
					} else if ((error?.status ?? null) === null || error.status >= 500) {
						session.uncertain = true
					} else {
						this.correction = null
					}
				}
			} finally {
				session.saving = false
			}
		},

		async reloadCurrentSplit(session) {
			if (session !== this.correction || session.loadingCurrent) {
				return
			}
			session.loadingCurrent = true
			try {
				const current = await this.roomTasksStore.fetchCompletion(session.item.id)
				if (this.correction === session) {
					session.current = current
				}
			} catch (error) {
				showErrorWithReference(t('chorestrike', 'Could not load the completion'), error)
				logger.error('Failed to refresh completion for review', error, { view: 'InsightsView', action: 'reloadCurrentSplit', completionId: session.item.id })
			} finally {
				session.loadingCurrent = false
			}
		},

		reviewCurrentSplit() {
			const session = this.correction
			if (!session?.conflict || !session.current?.canCorrect) {
				return
			}
			session.completion = session.current
			session.expectedVersion = session.current.version
			session.current = null
			session.conflict = false
		},

		async undoCompletion(item) {
			if (!item.canCorrect || item.undoneAt || (this.undoingId ?? null) !== null || this.blockedUndoIds.includes(item.id)) {
				return
			}
			this.undoingId = item.id
			try {
				await this.roomTasksStore.undoRoomTaskCompletion(item.id, item.version)
			} catch (error) {
				this.blockedUndoIds.push(item.id)
				if (error?.status === 409) {
					showError(t('chorestrike', 'The completion changed since it was displayed'))
				} else {
					showErrorWithReference(t('chorestrike', 'Could not undo the completion'), error)
				}
				logger.error('Failed to undo history completion', error, { view: 'InsightsView', action: 'undoCompletion', completionId: item.id })
			} finally {
				await this.refreshActivity()
				this.undoingId = null
			}
		},

		/** Refresh the history list after a committed correction. */
		async refreshActivity() {
			try {
				await this.houseStore.fetchActivity()
				this.blockedUndoIds = []
			} catch (error) {
				logger.error('Failed to refresh activity feed', error, { view: 'InsightsView', action: 'refreshActivity' })
			}
		},
	},
}
</script>

<style scoped>
.insights-view {
	padding: 1rem var(--cs-view-gutter) 2rem;
	max-width: 1000px;
	margin: 0 auto;
}

.insights-header {
	text-align: center;
	margin-bottom: 2rem;
	padding-inline-start: var(--cs-view-heading-inset);
}

.insights-header h1 {
	font-size: 2rem;
	font-weight: bold;
	margin: 0 0 0.5rem 0;
}

.subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Dense (CS-PLAN-090 WP-E): sections are plain blocks under a small heading. */
.insights-section {
	margin-bottom: 1.25rem;
}

.insights-section h2 {
	margin: 0 0 0.4rem 0;
	padding-inline-start: 0.5rem;
	font-size: 0.85rem;
	font-weight: bold;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.empty-hint,
.error-hint {
	color: var(--color-text-maxcontrast);
	padding: 0.5rem 0;
	margin: 0;
}

.error-hint {
	color: var(--color-error-text);
}

.error-hint button {
	min-height: 44px;
	margin-inline-start: 0.75rem;
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.skeleton {
	background: linear-gradient(90deg, var(--color-background-dark) 25%, var(--color-main-background) 50%, var(--color-background-dark) 75%);
	background-size: 200% 100%;
	animation: skeleton-loading 1.5s infinite;
	border-radius: var(--border-radius-large);
}

.skeleton-header {
	width: 300px;
	height: 2rem;
	margin: 0 auto 2rem;
}

.skeleton-section {
	width: 100%;
	height: 160px;
	margin-bottom: 2rem;
}

@keyframes skeleton-loading {
	0% { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

@media (max-width: 768px) {
	.insights-view {
		padding: 1rem var(--cs-view-gutter);
	}

	.insights-section {
		padding: 1rem;
	}
}
</style>
