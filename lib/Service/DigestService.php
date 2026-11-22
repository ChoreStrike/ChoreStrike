<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\RewardRequestMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

/**
 * Digest dispatch — one bounded attempt (CS-PLAN-076 §3.4, C5c/C5e, D9).
 *
 * The five-minute pass (a) materializes due/upcoming slots via
 * {@see DigestScheduleService}, (b) expires missed slots permanently
 * (C5c: the 60-minute same-day window is never extended), then (c)
 * claims ONE due slot atomically and dispatches at most one combined
 * summary per member and day (C5e: a member with both categories
 * enabled receives ONE summary).
 *
 * At-most-once contract (parent §3.6): the claim (status dispatching +
 * token) commits BEFORE the Nextcloud notification is created — no DB
 * transaction is ever held across IManager::notify. The dispatch outcome
 * is explicit: only an ACCEPTED send marks the slot `sent` (and consumes
 * its queued rows); every uncertain result — exception, timeout, crash —
 * marks `uncertain` (or leaves `dispatching` after a hard crash) and the
 * slot is NEVER resent. Pre-claim failures may retry within the window.
 *
 * The recheck BEFORE the final claim decides freshness: membership
 * active, category opt-ins still enabled, currently outside personal
 * quiet hours (cross-midnight ranges), and dispatch-time relevance —
 * task content is computed NOW from the live suggestion (the work it
 * suggests to the member for today plus their overdue tasks, ADR-022
 * decision 7; nothing on free days or during an absence; no offers or
 * future work). The suggestion is computed once per house of a pass:
 * every summary of one pass counts the same plan, which the first slot
 * that needs it reads under its own house lock. A later summary of the
 * pass therefore counts facts read seconds earlier, as if it had been
 * dispatched with the first one (D-091-153). Organizational content
 * comes from the queued rows (rows carry only recipients authorized at
 * write time; the active-membership recheck is the visibility gate for
 * the house-scoped structural facts). Queued agreement rows from before
 * ADR-022 are retained and never counted (D-091-12). If both
 * categories are empty the slot is marked `skipped` — no empty summary
 * is ever sent.
 *
 * Content is category/count-based only: no task names, no foreign
 * request details, no rendered payloads — the notifier resolves no
 * object references that could leak hidden work.
 */
class DigestService {
	public function __construct(
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private MemberPreferenceMapper $prefMapper,
		private DigestSlotMapper $slotMapper,
		private DigestChangeMapper $changeMapper,
		private NotificationService $notificationService,
		private DigestScheduleService $scheduleService,
		private CalendarService $calendar,
		private TransactionExecutor $transactions,
		private AppLogger $appLogger,
		private CompletedTaskMapper $completionMapper,
		private PauseMapper $pauseMapper,
		private RewardRequestMapper $rewardRequestMapper,
		private SuggestionService $suggestionService,
	) {
	}

	/**
	 * The five-minute pass: materialize, expire, dispatch. Triggered by
	 * the reconfigured DailyNotificationJob and the `daily-notification`
	 * TriggerJobs type. Cron lateness never extends a window.
	 *
	 * @return array{materialized: int, reResolved: int, expiredSlots: int, dispatched: int, skipped: int, quiet: int, contended: int, uncertain: int}
	 */
	public function processDueSlots(\DateTime $now): array {
		$stats = ['materialized' => 0, 'reResolved' => 0, 'expiredSlots' => 0, 'dispatched' => 0, 'skipped' => 0, 'quiet' => 0, 'contended' => 0, 'uncertain' => 0];

		try {
			$materialized = $this->scheduleService->materializeSlotsForJobs($now);
			$stats['materialized'] = $materialized['materialized'];
			$stats['reResolved'] = $materialized['reResolved'];

			$house = $this->houseMapper->findOneForJobs();
			if ($house === null) {
				return $stats;
			}
			$tz = $this->calendar->getTimeZone($house);
			$expired = $this->scheduleService->expireMissedSlots($this->calendar->dayKey($now, $tz), $now);
			$stats['expiredSlots'] = $expired['expiredSlots'];
		} catch (\Throwable $e) {
			$this->appLogger->logException('DigestService: schedule pass failed', $e, [
				'event' => 'digest_schedule_pass_failed',
				'service' => 'DigestService',
			]);
			return $stats;
		}

		$views = [];
		foreach ($this->slotMapper->findDuePending($now) as $slot) {
			$outcome = $this->dispatchSlot($slot, $now, $views);
			$stats[$outcome]++;
		}

		$this->appLogger->info('DigestService pass completed', $stats + [
			'event' => 'digest_pass_completed',
			'service' => 'DigestService',
		]);
		return $stats;
	}

	/**
	 * One bounded dispatch attempt for one due slot. Returns the outcome
	 * key (dispatched / skipped / quiet / contended / uncertain).
	 *
	 * The suggestion of the house is shared by every summary of the pass,
	 * so a household of five does not place its whole plan five times
	 * under the house lock (RF-091-14). A slot that needs it first builds
	 * it inside its transaction, and the pass takes it over only once that
	 * transaction committed: a rolled-back or retried attempt took the
	 * day's stored list with it (D-091-153).
	 *
	 * @param array<int, SuggestionView> $views The pass's committed suggestions, by house id
	 */
	private function dispatchSlot(DigestSlot $slot, \DateTime $now, array &$views): string {
		$token = bin2hex(random_bytes(16));
		$built = [];
		$viewOf = function (House $house) use (&$views, &$built, $now): SuggestionView {
			$houseId = (int)$house->getId();
			return $views[$houseId] ?? ($built[$houseId] ??= $this->suggestionService->view($house, $now));
		};

		// (c) recheck + (claim) inside ONE house-first transaction that
		// commits BEFORE any notification call (persist-before-notify).
		try {
			$decision = $this->transactions->run(function () use ($slot, $now, $token, $viewOf, &$built): array {
				// A retried attempt starts afresh: the aborted one rolled back what its views stored.
				$built = [];
				// FIRST lock of the documented order: the house row
				// serializes claims against opt-out/departure/zone writes.
				$house = $this->houseMapper->findForUpdate((int)$slot->getHouseId());
				$freshSlot = $this->slotMapper->findById((int)$slot->getId());
				if ($freshSlot === null || !$freshSlot->isPending()) {
					return ['outcome' => 'contended'];
				}
				$slot = $freshSlot;
				$tz = $this->calendar->getTimeZone($house);
				$nowLocalTime = $this->calendar->localTimeKey($now, $tz);

				$member = null;
				try {
					$member = $this->houseUserMapper->find((int)$slot->getHouseUserId());
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					// Departed before the claim won (C5h — the membership
					// sweep deletes the slot; a racing claim answers
					// skipped).
					$this->slotMapper->skipPending((int)$slot->getId(), $now);
					return ['outcome' => 'skipped'];
				}
				if ((int)$member->getHouseId() !== (int)$slot->getHouseId()) {
					// Cross-house residue: treat like a departure.
					$this->slotMapper->skipPending((int)$slot->getId(), $now);
					return ['outcome' => 'skipped'];
				}

				$pref = $this->prefMapper->findByHouseAndUser((int)$slot->getHouseId(), (int)$slot->getHouseUserId());
				if ($pref === null || (!$pref->getTaskDigestEnabled() && !$pref->getOrgDigestEnabled())) {
					// Opt-out before the claim wins (parent §3.6).
					$this->slotMapper->skipPending((int)$slot->getId(), $now);
					return ['outcome' => 'skipped'];
				}
				if ((int)$slot->getPrefRevision() !== (int)$pref->getVersion() || $slot->getZone() !== $tz->getName()) {
					$this->scheduleService->reResolvePendingSlotUnderLock($slot, $pref, $tz, $now);
				}
				if ($slot->getScheduledUtc() > $now || $slot->getWindowEndUtc() <= $now
					|| $slot->getLocalDay() !== $this->calendar->dayKey($now, $tz)) {
					return ['outcome' => 'contended'];
				}

				if (PreferenceService::isWithinQuietHours($pref->getQuietStart(), $pref->getQuietEnd(), $nowLocalTime)) {
					// Quiet hours gate dispatch — never shift a slot. Stay
					// pending; a later pass inside the window may still
					// dispatch, otherwise the expire pass skips it (C5c).
					return ['outcome' => 'quiet'];
				}

				// Dispatch-time task relevance (§3.4 (c), ADR-022 decision 7):
				// the count gates free days and absences itself, and a task is
				// only ever suggested to a member who may see it.
				$taskCount = $pref->getTaskDigestEnabled() ? $viewOf($house)->countFor($member) : 0;
				$orgRows = $pref->getOrgDigestEnabled()
					? $this->relevantOrgRows($slot, $member, fn (): array => $this->suggestionService->visibleRoomTasks($viewOf($house)->context, $member))
					: [];

				if ($taskCount === 0 && $orgRows === []) {
					// No empty summary is ever sent (§3.4 (d)).
					$this->slotMapper->skipPending((int)$slot->getId(), $now);
					return ['outcome' => 'skipped'];
				}

				// The atomic claim: conditional pending → dispatching. A
				// concurrent winner (stale read without the house lock in
				// another connection) loses here and never dispatches.
				$claimed = $this->slotMapper->claimPending((int)$slot->getId(), $token, $now);
				if ($claimed === null) {
					return ['outcome' => 'contended'];
				}
				return ['outcome' => 'claimed', 'taskCount' => $taskCount, 'orgRowIds' => array_map(static fn (DigestChange $row): int => (int)$row->getId(), $orgRows)];
			});
		} catch (\Throwable $e) {
			// Pre-claim failure: the slot stays pending and may retry
			// within the window. Bounded, payload-free diagnostics.
			$this->appLogger->logException('DigestService: pre-claim recheck failed', $e, [
				'event' => 'digest_preclaim_failed',
				'service' => 'DigestService',
				'slotId' => (int)$slot->getId(),
			]);
			return 'uncertain';
		}
		// Committed: every later summary of the pass counts the same plan.
		$views += $built;

		if ($decision['outcome'] !== 'claimed') {
			return $decision['outcome'];
		}

		// AFTER the committed claim: the single notification attempt. No
		// DB transaction is open here.
		try {
			$member = $this->houseUserMapper->find((int)$slot->getHouseUserId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->slotMapper->finalizeClaim((int)$slot->getId(), $token, DigestSlot::STATUS_SKIPPED, $now);
			return 'skipped';
		}
		if ($member->getNextcloudUserId() === null || $member->getNextcloudUserId() === '') {
			$this->slotMapper->finalizeClaim((int)$slot->getId(), $token, DigestSlot::STATUS_SKIPPED, $now);
			return 'skipped';
		}

		try {
			$this->notificationService->notifyDigest(
				(string)$member->getNextcloudUserId(),
				(int)$slot->getHouseId(),
				(int)$slot->getHouseUserId(),
				$slot->getLocalDay(),
				$decision['taskCount'],
				count($decision['orgRowIds'])
			);
		} catch (\Throwable $e) {
			// Uncertain: consumed, NEVER resent (parent §3.6). Bounded,
			// payload-free diagnostics.
			$this->appLogger->logException('DigestService: dispatch uncertain', $e, [
				'event' => 'digest_dispatch_uncertain',
				'service' => 'DigestService',
				'slotId' => (int)$slot->getId(),
			]);
			$this->slotMapper->finalizeClaim((int)$slot->getId(), $token, DigestSlot::STATUS_UNCERTAIN, $now);
			return 'uncertain';
		}

		$this->slotMapper->finalizeClaim((int)$slot->getId(), $token, DigestSlot::STATUS_SENT, $now);
		try {
			$this->transactions->run(fn (): mixed => $this->changeMapper->markConsumed($decision['orgRowIds'], $now));
		} catch (\Throwable $e) {
			// Bookkeeping only: the slot is already sent and never resent.
			$this->appLogger->logException('DigestService: consume bookkeeping failed', $e, [
				'event' => 'digest_consume_failed',
				'service' => 'DigestService',
				'slotId' => (int)$slot->getId(),
			]);
		}
		return 'dispatched';
	}

	/**
	 * Current source facts and the existing task visibility policy gate
	 * every count. A row of a type nothing queues any more — the retained
	 * `agreement` rows (D-091-12) — is skipped and stays queued.
	 *
	 * @param \Closure(): array<int, \OCA\ChoreStrike\Db\RoomTask> $visibleRoomTasks The room tasks the member may see, by id;
	 *                                                                               asked only when a row needs them
	 * @return DigestChange[]
	 */
	private function relevantOrgRows(DigestSlot $slot, HouseUser $member, \Closure $visibleRoomTasks): array {
		$visible = null;
		$isVisible = static function (int $roomTaskId) use (&$visible, $visibleRoomTasks): bool {
			$visible ??= $visibleRoomTasks();
			return isset($visible[$roomTaskId]);
		};
		$relevant = [];
		foreach ($this->changeMapper->findBySlot((int)$member->getId(), $slot->getLocalDay()) as $row) {
			if ($row->getDisposition() !== DigestChange::DISPOSITION_QUEUED
				|| (int)$row->getHouseId() !== (int)$member->getHouseId()
				|| $row->getCategory() !== DigestChange::CATEGORY_ORG) {
				continue;
			}
			try {
				if ($this->orgSourceIsRelevant($row, $member, $isVisible)) {
					$relevant[] = $row;
				}
			} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
				// Deleted sources no longer contribute organizational content.
			}
		}
		return $relevant;
	}

	/**
	 * @param \Closure(int): bool $isVisible Whether the member may see a room task
	 */
	private function orgSourceIsRelevant(DigestChange $row, HouseUser $member, \Closure $isVisible): bool {
		switch ($row->getObjectType()) {
			case 'takeover':
				$completion = $this->completionMapper->find((int)$row->getObjectId());
				return $completion->getUndoneAt() === null && $isVisible((int)$completion->getRoomTaskId());
			case 'pause':
				$pause = $this->pauseMapper->findCurrentByGroup((int)$row->getObjectId());
				return (int)$pause->getHouseId() === (int)$member->getHouseId()
					&& (int)$pause->getRevision() === (int)$row->getObjectRevision()
					&& in_array($pause->getState(), [Pause::STATE_ACKNOWLEDGED, Pause::STATE_REJECTED, Pause::STATE_CANCELLED], true)
					&& ($pause->getScope() === Pause::SCOPE_HOUSE || ($member->getWalletId() !== null && (int)$pause->getSubjectWalletId() === (int)$member->getWalletId()));
			case 'reward_request':
				// WP7 (CS-PLAN-078 §3.5): the request must still exist in
				// the same house at the queued revision and the recipient
				// must NOW be its active requester or a current owner.
				// Demoted owners and departed/erased requesters lose
				// eligibility; archive/disable/personal navigation hiding
				// does not remove authorized processing updates.
				try {
					$request = $this->rewardRequestMapper->find((int)$row->getObjectId());
				} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
					return false;
				}
				return (int)$request->getHouseId() === (int)$member->getHouseId()
					&& (int)$request->getVersion() === (int)$row->getObjectRevision()
					&& ($member->isOwner() || ($member->getWalletId() !== null && (int)$request->getWalletId() === (int)$member->getWalletId()));
			default:
				return false;
		}
	}
}
