<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\DigestScheduleService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DST-aware digest slot schedule (CS-PLAN-070 WP5
 * §3.2, C5a/b/c/k/m) with an injected clock.
 *
 * Covers: the gap/fold resolution (Europe/Berlin gap → first valid later
 * instant, fold → first occurrence), the same-day 60-minute window and
 * its local day-end clamp, the opt-in-never-backfills rule, the
 * pref-revision drift re-resolution, the zone-change re-resolution under
 * the 24-elapsed-hour spacing floor derived from retained slot history,
 * the queue-writer cutoff (changes at/after the resolved instant belong
 * to the NEXT slot) and the permanent expiry of missed slots.
 *
 * @covers \OCA\ChoreStrike\Service\DigestScheduleService
 */
class DigestScheduleServiceTest extends TestCase {
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private MemberPreferenceMapper&MockObject $prefMapper;
	private DigestSlotMapper&MockObject $slotMapper;
	private DigestChangeMapper&MockObject $changeMapper;
	private DigestScheduleService $service;
	private House $house;

	protected function setUp(): void {
		parent::setUp();
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->slotMapper = $this->createMock(DigestSlotMapper::class);
		$this->changeMapper = $this->createMock(DigestChangeMapper::class);

		$this->house = new House();
		$this->house->setId(1);
		$this->house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($this->house);
		$this->houseMapper->method('findOneForJobs')->willReturn($this->house);

		$this->service = new DigestScheduleService(
			$this->houseMapper,
			$this->houseUserMapper,
			$this->prefMapper,
			$this->slotMapper,
			$this->changeMapper,
			new CalendarService(),
			new TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
		);
	}

	private function optedInMember(int $id = 7): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId('mom');
		return $member;
	}

	private function pref(string $digestTime = '18:00', int $version = 1): MemberPreference {
		$pref = new MemberPreference();
		$pref->setHouseId(1);
		$pref->setHouseUserId(7);
		$pref->setVersion($version);
		$pref->setTaskDigestEnabled(true);
		$pref->setDigestTime($digestTime);
		return $pref;
	}

	// ------------------------------------------------------------------
	// DST resolution
	// ------------------------------------------------------------------

	public function testMaterialize_GapTime_ResolvesToFirstValidLaterInstant(): void {
		// Europe/Berlin, 2026-03-29: the wall times 02:00–02:59 do not
		// exist (02:00 CET → 03:00 CEST). '02:30' must resolve to the
		// transition instant itself (03:00 CEST = 01:00 UTC).
		$this->house->setTimezone('Europe/Berlin');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('02:30'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$captured = [];
		$this->slotMapper->method('insertOrGet')->willReturnCallback(
			static function (DigestSlot $slot) use (&$captured): DigestSlot {
				$captured[] = $slot;
				return $slot;
			}
		);

		$this->service->materializeSlotsForHouse(1, new \DateTime('2026-03-29 00:30:00 UTC'));

		$today = $captured[0];
		$this->assertSame('2026-03-29', $today->getLocalDay());
		$this->assertSame('2026-03-29T01:00:00+00:00', $today->getScheduledUtc()->format('c'), 'gap → first valid later instant');
		// 60 elapsed minutes after the transition.
		$this->assertSame('2026-03-29T02:00:00+00:00', $today->getWindowEndUtc()->format('c'));
	}

	public function testMaterialize_FoldTime_ResolvesToFirstOccurrence(): void {
		// Europe/Berlin, 2026-10-25: 02:00–02:59 occurs twice (CEST then
		// CET). '02:30' must resolve to the FIRST occurrence
		// (CEST = 00:30 UTC).
		$this->house->setTimezone('Europe/Berlin');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('02:30'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$captured = [];
		$this->slotMapper->method('insertOrGet')->willReturnCallback(
			static function (DigestSlot $slot) use (&$captured): DigestSlot {
				$captured[] = $slot;
				return $slot;
			}
		);

		$this->service->materializeSlotsForHouse(1, new \DateTime('2026-10-25 00:00:00 UTC'));

		$today = $captured[0];
		$this->assertSame('2026-10-25T00:30:00+00:00', $today->getScheduledUtc()->format('c'), 'fold → first occurrence');
	}

	public function testMaterialize_LateDigestTime_ClampsWindowEndToLocalDayEnd(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('23:30'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$captured = [];
		$this->slotMapper->method('insertOrGet')->willReturnCallback(
			static function (DigestSlot $slot) use (&$captured): DigestSlot {
				$captured[] = $slot;
				return $slot;
			}
		);

		$this->service->materializeSlotsForHouse(1, new \DateTime('2026-09-29 12:00:00 UTC'));

		$today = $captured[0];
		$this->assertSame('2026-09-29T23:30:00+00:00', $today->getScheduledUtc()->format('c'));
		// min(00:30 next day, local day end 2026-09-30T00:00Z) — the
		// elapsed-hour rule would spill into the next local day; the
		// day-end clamp wins.
		$this->assertSame('2026-09-30T00:00:00+00:00', $today->getWindowEndUtc()->format('c'));
	}

	// ------------------------------------------------------------------
	// Opt-in / backfill rules
	// ------------------------------------------------------------------

	public function testMaterialize_TodaysWindowClosed_OnlyTomorrowIsCreated(): void {
		// Digest time 10:00, now 14:00 → today's window ended at 11:00.
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('10:00'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$captured = [];
		$this->slotMapper->method('insertOrGet')->willReturnCallback(
			static function (DigestSlot $slot) use (&$captured): DigestSlot {
				$captured[] = $slot;
				return $slot;
			}
		);

		$this->service->materializeSlotsForHouse(1, new \DateTime('2026-09-29 14:00:00 UTC'));

		$this->assertCount(1, $captured, 'opt-in never backfills today — only tomorrow is materialized');
		$this->assertSame('2026-09-30', $captured[0]->getLocalDay());
	}

	public function testMaterialize_BothCategoriesOff_NoSlots(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$pref = $this->pref('18:00');
		$pref->setTaskDigestEnabled(false);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($pref);
		$this->slotMapper->expects($this->never())->method('insertOrGet');

		$stats = $this->service->materializeSlotsForHouse(1, new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame(0, $stats['materialized']);
	}

	public function testMaterialize_PrefRevisionDrift_ReResolvesPendingSlot(): void {
		$pref = $this->pref('20:00', 5);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($pref);

		$staleSlot = new DigestSlot();
		$staleSlot->setId(33);
		$staleSlot->setHouseId(1);
		$staleSlot->setHouseUserId(7);
		$staleSlot->setLocalDay('2026-09-29');
		$staleSlot->setZone('UTC');
		$staleSlot->setPrefRevision(2);
		$staleSlot->setStatus(DigestSlot::STATUS_PENDING);
		$staleSlot->setScheduledUtc(new \DateTime('2026-09-29 18:00:00 UTC'));
		$staleSlot->setWindowEndUtc(new \DateTime('2026-09-29 19:00:00 UTC'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn($staleSlot);
		$this->slotMapper->expects($this->once())->method('update')->willReturnCallback(
			function (DigestSlot $slot): DigestSlot {
				// The re-resolution follows the CURRENT pref time and
				// revision.
				$this->assertSame('2026-09-29T20:00:00+00:00', $slot->getScheduledUtc()->format('c'));
				$this->assertSame(5, $slot->getPrefRevision());
				return $slot;
			}
		);

		$stats = $this->service->materializeSlotsForHouse(1, new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame(1, $stats['reResolved']);
		$this->assertSame(0, $stats['materialized']);
	}

	// ------------------------------------------------------------------
	// Zone-change re-resolution (C5k)
	// ------------------------------------------------------------------

	public function testReviseForZoneChange_ReResolvesPendingSlotsInTheNewZone(): void {
		$slot = new DigestSlot();
		$slot->setId(44);
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-10-01');
		$slot->setZone('UTC');
		$slot->setPrefRevision(3);
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setScheduledUtc(new \DateTime('2026-10-01 18:00:00 UTC'));
		$this->slotMapper->method('findPendingByHouseId')->willReturn([$slot]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('09:00', 3));
		$this->slotMapper->method('findLastDispatchAt')->willReturn(null);
		$this->slotMapper->expects($this->once())->method('update')->willReturnCallback(
			function (DigestSlot $updated): DigestSlot {
				$this->assertSame('Europe/Berlin', $updated->getZone());
				// 09:00 Berlin (CEST) = 07:00 UTC.
				$this->assertSame('2026-10-01T07:00:00+00:00', $updated->getScheduledUtc()->format('c'));
				return $updated;
			}
		);

		$this->service->reviseForZoneChangeUnderLock(1, 'Europe/Berlin', new \DateTime('2026-09-29 12:00:00 UTC'));
	}

	public function testReviseForZoneChange_PriorDispatchWithin24h_PushesTheResolvedInstant(): void {
		$slot = new DigestSlot();
		$slot->setId(45);
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-09-30');
		$slot->setZone('UTC');
		$slot->setPrefRevision(3);
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setScheduledUtc(new \DateTime('2026-09-30 18:00:00 UTC'));
		$this->slotMapper->method('findPendingByHouseId')->willReturn([$slot]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('09:00', 3));
		// The uncertain prior attempt happened 2026-09-30 05:00 UTC — the
		// 24-elapsed-hour floor (2026-10-01 05:00 UTC) is later than the
		// resolved 07:00 UTC? No: 07:00 > 05:00+24h = ... floor is
		// 2026-10-01T05:00Z, resolved 2026-09-30T07:00Z is EARLIER, so the
		// floor wins.
		$this->slotMapper->method('findLastDispatchAt')->willReturn(new \DateTime('2026-09-30 05:00:00 UTC'));
		$this->slotMapper->expects($this->once())->method('update')->willReturnCallback(
			function (DigestSlot $updated): DigestSlot {
				$this->assertSame('2026-10-01T05:00:00+00:00', $updated->getScheduledUtc()->format('c'), 'the 24-elapsed-hour spacing floor wins');
				return $updated;
			}
		);

		$this->service->reviseForZoneChangeUnderLock(1, 'Europe/Berlin', new \DateTime('2026-09-29 12:00:00 UTC'));
	}

	public function testReviseForZoneChange_OptedOutMember_SlotIsLeftUntouched(): void {
		$slot = new DigestSlot();
		$slot->setId(46);
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-09-30');
		$slot->setZone('UTC');
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$this->slotMapper->method('findPendingByHouseId')->willReturn([$slot]);
		$pref = $this->pref('09:00');
		$pref->setTaskDigestEnabled(false);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($pref);
		$this->slotMapper->expects($this->never())->method('update');

		$this->service->reviseForZoneChangeUnderLock(1, 'Europe/Berlin', new \DateTime('2026-09-29 12:00:00 UTC'));
	}

	// ------------------------------------------------------------------
	// Queue-writer cutoff (C5m)
	// ------------------------------------------------------------------

	public function testResolveTargetSlot_ChangeBeforeResolvedTime_BelongsToTodaysSlot(): void {
		$slot = new DigestSlot();
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-09-29');
		$slot->setScheduledUtc(new \DateTime('2026-09-29 18:00:00 UTC'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn($slot);

		$target = $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 15:00:00 UTC'));

		$this->assertSame('2026-09-29', $target);
	}

	public function testResolveTargetSlot_ChangeAtOrAfterResolvedTime_BelongsToNextSlot(): void {
		$slot = new DigestSlot();
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-09-29');
		$slot->setScheduledUtc(new \DateTime('2026-09-29 18:00:00 UTC'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn($slot);

		$at = $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 18:00:00 UTC'));
		$after = $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 19:30:00 UTC'));

		$this->assertSame('2026-09-30', $at, 'the cutoff is inclusive: at the resolved instant the change rides the next slot');
		$this->assertSame('2026-09-30', $after);
	}

	public function testResolveTargetSlot_NoMaterializedSlot_FallsBackToTheLocalDay(): void {
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);

		$target = $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 15:00:00 UTC'));

		$this->assertSame('2026-09-29', $target);
	}

	public function testResolveTargetSlot_NoMaterializedSlot_UsesThePreferenceCutoff(): void {
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref());
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$this->assertSame('2026-09-29', $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 17:59:00 UTC')));
		$this->assertSame('2026-09-30', $this->service->resolveTargetSlotUnderLock(1, 7, new \DateTime('2026-09-29 18:00:00 UTC')));
	}

	public function testMaterialize_NewSlotAfterZoneSwitch_UsesRetainedDispatchSpacing(): void {
		$this->house->setTimezone('Europe/Berlin');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->optedInMember()]);
		$this->prefMapper->method('findByHouseAndUser')->willReturn($this->pref('09:00'));
		$this->slotMapper->method('findByHouseUserAndDay')->willReturn(null);
		$this->slotMapper->expects($this->exactly(2))->method('findLastDispatchAt')
			->with(1, 7, 'Europe/Berlin')->willReturn(new \DateTime('2026-09-28 18:05:00 UTC'));
		$captured = [];
		$this->slotMapper->method('insertOrGet')->willReturnCallback(static function (DigestSlot $slot) use (&$captured): DigestSlot {
			$captured[] = $slot;
			return $slot;
		});
		$this->service->materializeSlotsForHouse(1, new \DateTime('2026-09-29 06:00:00 UTC'));
		$this->assertSame('2026-09-29T18:05:00+00:00', $captured[0]->getScheduledUtc()->format('c'));
		$this->assertSame('2026-09-30T07:00:00+00:00', $captured[1]->getScheduledUtc()->format('c'));
	}

	// ------------------------------------------------------------------
	// Permanent expiry (C5c)
	// ------------------------------------------------------------------

	public function testExpireMissedSlots_SkipsExpiredSlotsAndExpiresQueuedRows(): void {
		$slot = new DigestSlot();
		$slot->setId(50);
		$slot->setHouseId(1);
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay('2026-09-28');
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setWindowEndUtc(new \DateTime('2026-09-28 19:00:00 UTC'));
		$this->slotMapper->method('findExpiredPending')->willReturn([$slot]);
		$this->slotMapper->method('findById')->willReturn($slot);
		$this->changeMapper->expects($this->once())->method('expireForSlot')
			->with(7, '2026-09-28', $this->anything());
		$this->changeMapper->expects($this->once())->method('expireQueuedBefore')
			->with('2026-09-29', $this->anything());
		$this->slotMapper->expects($this->once())->method('skipPending')->with(50, $this->anything());

		$stats = $this->service->expireMissedSlots('2026-09-29', new \DateTime('2026-09-29 12:00:00 UTC'));

		$this->assertSame(1, $stats['expiredSlots']);
	}

	public function testExpireMissedSlots_ConcurrentClaimWon_DoesNotOverwriteIt(): void {
		$stale = new DigestSlot();
		$stale->setId(50);
		$stale->setHouseId(1);
		$fresh = clone $stale;
		$fresh->setStatus(DigestSlot::STATUS_SENT);
		$this->slotMapper->method('findExpiredPending')->willReturn([$stale]);
		$this->slotMapper->method('findById')->willReturn($fresh);
		$this->slotMapper->expects($this->never())->method('skipPending');
		$this->changeMapper->expects($this->never())->method('expireForSlot');
		$this->assertSame(0, $this->service->expireMissedSlots('2026-09-29', new \DateTime('2026-09-29 12:00:00 UTC'))['expiredSlots']);
	}
}
