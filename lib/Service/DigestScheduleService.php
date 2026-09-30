<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Service;

use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;

/**
 * DST-aware digest slot schedule (CS-PLAN-076 §3.2, C5a/b/c/k).
 *
 * Every member's `digest_time` (house-local `HH:mm`) resolves to a UTC
 * instant through {@see CalendarService::localTimeToInstant()} — a spring
 * gap resolves to the first valid later instant, an autumn fold to its
 * first occurrence. `window_end_utc` is the earlier of scheduled + 60
 * elapsed minutes and the local day end (C5c): lateness is absorbed
 * inside the same-day window, never compensated by next-day catch-up.
 *
 * Slots are materialized idempotently (unique (house, membership, day)
 * key) for opted-in members covering today/tomorrow. The slot identity
 * is INDEPENDENT of the opt-in toggles: opt-out never deletes history
 * (the 24-elapsed-hour spacing of C5k survives) and a toggle cycle never
 * creates a new allowance. Opt-in never backfills — the first slot is
 * the next still-open scheduled instant (today only while its window is
 * open, otherwise tomorrow); no slots are created while both categories
 * are off.
 *
 * A pending slot whose pref revision or zone drifted re-resolves; a
 * zone change re-resolves every pending slot of the house under the
 * 24-elapsed-hour spacing rule derived from retained slot history (C5k).
 */
class DigestScheduleService {
	/** The same-day dispatch window (C5c): 60 elapsed minutes. */
	public const WINDOW_MINUTES = 60;

	/** The zone-switched dispatch spacing (C5k): 24 elapsed hours. */
	public const ZONE_CHANGE_SPACING_HOURS = 24;

	public function __construct(
		private HouseMapper $houseMapper,
		private HouseUserMapper $houseUserMapper,
		private MemberPreferenceMapper $prefMapper,
		private DigestSlotMapper $slotMapper,
		private DigestChangeMapper $changeMapper,
		private CalendarService $calendar,
		private TransactionExecutor $transactions,
	) {
	}

	/**
	 * Materialize due/upcoming slots for the instance house (the
	 * five-minute job input). Idempotent; re-resolves pending slots whose
	 * pref revision or zone drifted.
	 *
	 * @return array{materialized: int, reResolved: int}
	 */
	public function materializeSlotsForJobs(\DateTime $now): array {
		$house = $this->houseMapper->findOneForJobs();
		if ($house === null) {
			return ['materialized' => 0, 'reResolved' => 0];
		}
		return $this->materializeSlotsForHouse((int)$house->getId(), $now);
	}

	/**
	 * Materialize the today/tomorrow slots of every opted-in member of
	 * the house. Materialization shares the house-first lock with claims,
	 * preference changes, timezone changes and membership removal.
	 */
	public function materializeSlotsForHouse(int $houseId, \DateTime $now): array {
		return $this->transactions->run(function () use ($houseId, $now): array {
			$this->houseMapper->findForUpdate($houseId);
			return $this->materializeSlotsUnderLock($houseId, $now);
		});
	}

	private function materializeSlotsUnderLock(int $houseId, \DateTime $now): array {
		$stats = ['materialized' => 0, 'reResolved' => 0];
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendar->getTimeZone($house);
		$todayKey = $this->calendar->dayKey($now, $tz);
		$localTomorrow = \DateTime::createFromInterface($now)->setTimezone($tz)->modify('+1 day');
		$tomorrowKey = $localTomorrow->format('Y-m-d');

		foreach ($this->houseUserMapper->findByHouseId($houseId) as $member) {
			$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$member->getId());
			if ($pref === null || (!$pref->getTaskDigestEnabled() && !$pref->getOrgDigestEnabled())) {
				// No slots while both categories are off.
				continue;
			}
			foreach ([$todayKey, $tomorrowKey] as $dayKey) {
				$existing = $this->slotMapper->findByHouseUserAndDay($houseId, (int)$member->getId(), $dayKey);
				if ($existing !== null) {
					if ($existing->isPending()
						&& ((int)$existing->getPrefRevision() !== (int)$pref->getVersion()
							|| $existing->getZone() !== $tz->getName())) {
						$this->reResolvePendingSlotUnderLock($existing, $pref, $tz, $now);
						$stats['reResolved']++;
					}
					continue;
				}

				$scheduled = $this->scheduledInstant($houseId, (int)$member->getId(), $dayKey, $pref, $tz);
				$windowEnd = $this->windowEnd($dayKey, $scheduled, $tz);
				if ($dayKey === $todayKey && $now > $windowEnd) {
					// Opt-in never backfills (C5c): today's window is
					// closed — the first slot is tomorrow's.
					continue;
				}
				$this->insertSlot($houseId, (int)$member->getId(), $dayKey, $tz->getName(), (int)$pref->getVersion(), $scheduled, $windowEnd, $now);
				$stats['materialized']++;
			}
		}
		return $stats;
	}

	/**
	 * Zone change (C5k, called INSIDE the owning house-first transaction
	 * of {@see HouseService::updateHouse()}): every pending slot of the
	 * house re-resolves in the new zone; a prior dispatch (sent OR
	 * uncertain) blocks any zone-switched dispatch for at least 24
	 * elapsed hours, derived from the retained slot history.
	 */
	public function reviseForZoneChangeUnderLock(int $houseId, string $newZone, \DateTime $now): void {
		$tz = new \DateTimeZone($newZone);
		foreach ($this->slotMapper->findPendingByHouseId($houseId) as $slot) {
			$pref = $this->prefMapper->findByHouseAndUser($houseId, (int)$slot->getHouseUserId());
			if ($pref === null) {
				continue;
			}
			if (!$pref->getTaskDigestEnabled() && !$pref->getOrgDigestEnabled()) {
				// Opted-out member: the slot can never dispatch (the
				// dispatch recheck marks it skipped); keep the retained
				// history untouched.
				continue;
			}
			$this->reResolvePendingSlotUnderLock($slot, $pref, $tz, $now);
		}
	}

	/**
	 * The queue-writer cutoff (C5m): the house-local day key of the
	 * change time — the NEXT slot when the change lands at or after the
	 * member's resolved slot instant for that day, even when the worker
	 * is late. Callers run inside their source transaction (the house
	 * row lock is already held).
	 */
	public function resolveTargetSlotUnderLock(int $houseId, int $houseUserId, \DateTimeInterface $changeTime): string {
		$house = $this->houseMapper->find($houseId);
		$tz = $this->calendar->getTimeZone($house);
		$dayKey = $this->calendar->dayKey($changeTime, $tz);
		$slot = $this->slotMapper->findByHouseUserAndDay($houseId, $houseUserId, $dayKey);
		$pref = $this->prefMapper->findByHouseAndUser($houseId, $houseUserId);
		$scheduled = $slot?->getScheduledUtc();
		if ($pref !== null && ($slot === null || $slot->isPending())) {
			$scheduled = $this->scheduledInstant($houseId, $houseUserId, $dayKey, $pref, $tz);
		}
		if ($scheduled !== null && $changeTime >= $scheduled) {
			// After this slot's resolved time: the change belongs to the
			// next slot, never to a dispatch already due.
			$next = \DateTime::createFromInterface($changeTime)->setTimezone($tz)->modify('+1 day');
			return $next->format('Y-m-d');
		}
		return $dayKey;
	}

	/**
	 * Expire permanently (C5c/C5m): pending slots whose window has ended
	 * are skipped, their queued rows (and every queued row of an
	 * entirely elapsed day — slots the opted-out member never had) are
	 * expired. In-app state is unaffected.
	 *
	 * @return array{expiredSlots: int}
	 */
	public function expireMissedSlots(string $todayKey, \DateTime $now): array {
		$expired = 0;
		foreach ($this->slotMapper->findExpiredPending($now) as $slot) {
			$expired += $this->transactions->run(function () use ($slot, $now): int {
				$this->houseMapper->findForUpdate((int)$slot->getHouseId());
				$fresh = $this->slotMapper->findById((int)$slot->getId());
				if ($fresh === null || !$fresh->isPending() || $fresh->getWindowEndUtc() >= $now) {
					return 0;
				}
				$this->changeMapper->expireForSlot((int)$fresh->getHouseUserId(), $fresh->getLocalDay(), $now);
				$this->slotMapper->skipPending((int)$fresh->getId(), $now);
				return 1;
			});
		}
		// Queued rows for fully elapsed days without a materialized slot
		// (member was opted out) die with their day.
		$this->changeMapper->expireQueuedBefore($todayKey, $now);
		return ['expiredSlots' => $expired];
	}

	/**
	 * The dispatch window end (C5c): the earlier of scheduled + 60
	 * elapsed minutes and the local day end. Absolute-timestamp
	 * arithmetic — relative minute modify() is wall-clock based across
	 * DST transitions.
	 */
	private function windowEnd(string $dayKey, \DateTime $scheduledUtc, \DateTimeZone $tz): \DateTime {
		$window = (clone $scheduledUtc)->setTimestamp($scheduledUtc->getTimestamp() + self::WINDOW_MINUTES * 60);
		$dayEnd = $this->calendar->dayBounds($this->calendar->dayStartInstant($dayKey, $tz), $tz)[1];
		return $window > $dayEnd ? $dayEnd : $window;
	}

	/**
	 * Re-resolve one pending slot (pref-revision drift or zone change):
	 * recompute scheduled/window in the given zone under the C5k spacing
	 * rule.
	 */
	public function reResolvePendingSlotUnderLock(DigestSlot $slot, MemberPreference $pref, \DateTimeZone $tz, \DateTime $now): void {
		$scheduled = $this->scheduledInstant((int)$slot->getHouseId(), (int)$slot->getHouseUserId(), $slot->getLocalDay(), $pref, $tz);

		$slot->setZone($tz->getName());
		$slot->setPrefRevision((int)$pref->getVersion());
		$slot->setScheduledUtc($scheduled);
		$slot->setWindowEndUtc($this->windowEnd($slot->getLocalDay(), $scheduled, $tz));
		$slot->setUpdatedAt($now);
		$this->slotMapper->update($slot);
	}

	private function scheduledInstant(int $houseId, int $houseUserId, string $dayKey, MemberPreference $pref, \DateTimeZone $tz): \DateTime {
		$scheduled = $this->calendar->localTimeToInstant($dayKey, $pref->getDigestTime(), $tz);
		// Apply the zone-switch floor to newly materialized slots too. Ordinary
		// same-zone days (including 23-hour DST days) keep their chosen time.
		$lastDispatch = $this->slotMapper->findLastDispatchAt($houseId, $houseUserId, $tz->getName());
		if ($lastDispatch !== null) {
			$floor = (clone $lastDispatch)->setTimestamp($lastDispatch->getTimestamp() + self::ZONE_CHANGE_SPACING_HOURS * 3600);
			if ($scheduled < $floor) {
				$scheduled = $floor;
			}
		}
		return $scheduled;
	}

	private function insertSlot(
		int $houseId,
		int $houseUserId,
		string $dayKey,
		string $zone,
		int $prefRevision,
		\DateTime $scheduledUtc,
		\DateTime $windowEndUtc,
		\DateTime $now,
	): DigestSlot {
		$slot = new DigestSlot();
		$slot->setHouseId($houseId);
		$slot->setHouseUserId($houseUserId);
		$slot->setLocalDay($dayKey);
		$slot->setZone($zone);
		$slot->setPrefRevision($prefRevision);
		$slot->setScheduledUtc($scheduledUtc);
		$slot->setWindowEndUtc($windowEndUtc);
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setCreatedAt($now);
		$slot->setUpdatedAt($now);
		return $this->slotMapper->insertOrGet($slot);
	}
}
