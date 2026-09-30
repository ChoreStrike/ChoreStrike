<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\DigestChangeMapper;
use OCA\ChoreStrike\Db\DigestSlot;
use OCA\ChoreStrike\Db\DigestSlotMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\MemberPreference;
use OCA\ChoreStrike\Db\MemberPreferenceMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\DigestScheduleService;
use OCA\ChoreStrike\Service\DigestService;
use OCA\ChoreStrike\Service\NotificationService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the digest dispatch engine (CS-PLAN-070 WP5 §3.4,
 * C5c/C5e/D9, parent §3.6 at-most-once).
 *
 * Covers: the pre-claim rechecks (departure, opt-out, quiet hours, empty
 * content), the atomic claim with the combined single summary, the
 * dispatch-time task relevance (obligated active planned + agreed
 * catch-up; free days excluded; suspended work neutral), the outcome
 * contract (accepted → sent + consumed rows; uncertain → uncertain and
 * NEVER resent) and the at-most-once claim bookkeeping.
 *
 * @covers \OCA\ChoreStrike\Service\DigestService
 */
class DigestServiceTest extends TestCase {
	private HouseMapper&MockObject $houseMapper;
	private HouseUserMapper&MockObject $houseUserMapper;
	private MemberPreferenceMapper&MockObject $prefMapper;
	private DigestSlotMapper&MockObject $slotMapper;
	private DigestChangeMapper&MockObject $changeMapper;
	private OccurrenceMapper&MockObject $occurrenceMapper;
	private OccurrenceMemberMapper&MockObject $occMemberMapper;
	private NotificationService&MockObject $notificationService;
	private DigestScheduleService&MockObject $scheduleService;
	private AppLogger&MockObject $appLogger;
	private DigestService $service;
	private House $house;
	/** Slots returned by findDuePending (the pass input). */
	private array $dueSlots = [];
	private ?DigestSlot $freshSlot = null;
	/** Members resolvable by id. */
	private array $membersById = [];
	/** Preferences resolvable by (house, membership). */
	private array $prefsByKey = [];
	/** Queued rows per (membership, slot day). */
	private array $queuedBySlot = [];
	/** The dispatched digest call, if any. */
	private ?array $dispatched = null;
	private ?\Throwable $dispatchThrowable = null;
	private int $claimsWon = 1;
	/** @var array<string, array<int, OccurrenceMember>> */
	private array $occMembersByOccurrence = [];
	/** Occurrences planned on the reference day (test seam). */
	private array $plannedOccurrences = [];
	/** Occurrences with an agreed work appointment today (test seam). */
	private array $workDateOccurrences = [];
	private CompletedTaskMapper&MockObject $completionMapper;
	private RoomTaskReadService&MockObject $readService;
	private CompletedTask $currentCompletion;
	private array $visibleRoomTasks = [];
	private PauseMapper&MockObject $pauseMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->prefMapper = $this->createMock(MemberPreferenceMapper::class);
		$this->slotMapper = $this->createMock(DigestSlotMapper::class);
		$this->changeMapper = $this->createMock(DigestChangeMapper::class);
		$this->occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->scheduleService = $this->createMock(DigestScheduleService::class);
		$this->appLogger = $this->createMock(AppLogger::class);
		$this->completionMapper = $this->createMock(CompletedTaskMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$completion = new CompletedTask();
		$completion->setRoomTaskId(11);
		$this->currentCompletion = $completion;
		$this->completionMapper->method('find')->willReturnCallback(fn (): CompletedTask => $this->currentCompletion);
		$this->readService = $this->createMock(RoomTaskReadService::class);
		$visible = [];
		foreach ([11, 12] as $id) {
			$roomTask = new RoomTask();
			$roomTask->setId($id);
			$visible[] = $roomTask;
		}
		$this->visibleRoomTasks = $visible;
		$this->readService->method('getHouseRoomTasks')->willReturnCallback(fn (): array => $this->visibleRoomTasks);

		$this->house = new House();
		$this->house->setId(1);
		$this->house->setTimezone('UTC');
		$this->houseMapper->method('findOneForJobs')->willReturn($this->house);
		$this->houseMapper->method('findForUpdate')->willReturn($this->house);

		$this->dueSlots = [];
		$this->membersById = [];
		$this->prefsByKey = [];
		$this->queuedBySlot = [];
		$this->dispatched = null;
		$this->dispatchThrowable = null;
		$this->claimsWon = 1;
		$this->occMembersByOccurrence = [];

		$this->houseUserMapper->method('find')->willReturnCallback(
			function (int $id): HouseUser {
				if (!isset($this->membersById[$id])) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
				}
				return $this->membersById[$id];
			}
		);
		$this->prefMapper->method('findByHouseAndUser')->willReturnCallback(
			fn (int $houseId, int $houseUserId): ?MemberPreference => $this->prefsByKey[$houseId . ':' . $houseUserId] ?? null
		);
		$this->changeMapper->method('findBySlot')->willReturnCallback(
			fn (int $houseUserId, string $day): array => $this->queuedBySlot[$houseUserId . ':' . $day] ?? []
		);
		$this->notificationService->method('notifyDigest')->willReturnCallback(
			function (string $user, int $houseId, int $houseUserId, string $day, int $tasks, int $org): void {
				if ($this->dispatchThrowable !== null) {
					throw $this->dispatchThrowable;
				}
				$this->dispatched = [$user, $houseId, $houseUserId, $day, $tasks, $org];
			}
		);
		$this->slotMapper->method('findDuePending')->willReturnCallback(fn (): array => $this->dueSlots);
		$this->slotMapper->method('findById')->willReturnCallback(fn (): ?DigestSlot => $this->freshSlot ?? ($this->dueSlots[0] ?? null));
		$this->slotMapper->method('claimPending')->willReturnCallback(
			function (int $slotId, string $token, \DateTimeInterface $now): ?DigestSlot {
				if ($this->claimsWon < 1) {
					return null;
				}
				$this->claimsWon--;
				return $this->dueSlots[0];
			}
		);
		$this->occurrenceMapper->method('findByHousePlannedBetween')->willReturnCallback(
			fn (): array => $this->plannedOccurrences
		);
		$this->occurrenceMapper->method('findByHouseMemberWorkDate')->willReturnCallback(
			fn (): array => $this->workDateOccurrences
		);
		$this->occMemberMapper->method('findByOccurrenceIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->occMembersByOccurrence, array_flip($ids))
		);

		$this->scheduleService->method('materializeSlotsForJobs')->willReturn(['materialized' => 0, 'reResolved' => 0]);
		$this->scheduleService->method('expireMissedSlots')->willReturn(['expiredSlots' => 0]);

		$this->service = new DigestService(
			$this->houseMapper,
			$this->houseUserMapper,
			$this->prefMapper,
			$this->slotMapper,
			$this->changeMapper,
			$this->occurrenceMapper,
			$this->occMemberMapper,
			$this->notificationService,
			$this->scheduleService,
			new CalendarService(),
			new TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			$this->appLogger,
			$this->completionMapper,
			$this->pauseMapper,
			$this->readService,
		);
	}

	private function member(int $id = 7): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId('mom');
		return $member;
	}

	private function pref(?string $quietStart = null, ?string $quietEnd = null): MemberPreference {
		$pref = new MemberPreference();
		$pref->setHouseId(1);
		$pref->setHouseUserId(7);
		$pref->setVersion(2);
		$pref->setTaskDigestEnabled(true);
		$pref->setOrgDigestEnabled(true);
		$pref->setDigestTime('18:00');
		$pref->setQuietStart($quietStart);
		$pref->setQuietEnd($quietEnd);
		return $pref;
	}

	private function slot(string $day = '2026-09-29', int $id = 50): DigestSlot {
		$slot = new DigestSlot();
		$slot->setId($id);
		$slot->setHouseId(1);
		$slot->setHouseUserId(7);
		$slot->setLocalDay($day);
		$slot->setZone('UTC');
		$slot->setPrefRevision(2);
		$slot->setStatus(DigestSlot::STATUS_PENDING);
		$slot->setScheduledUtc(new \DateTime($day . ' 18:00:00 UTC'));
		$slot->setWindowEndUtc(new \DateTime($day . ' 19:00:00 UTC'));
		return $slot;
	}

	private function queuedRow(int $id): DigestChange {
		$row = new DigestChange();
		$row->setId($id);
		$row->setHouseId(1);
		$row->setHouseUserId(7);
		$row->setObjectType('takeover');
		$row->setObjectId('11');
		$row->setTargetSlot('2026-09-29');
		$row->setDisposition(DigestChange::DISPOSITION_QUEUED);
		return $row;
	}

	private function obligatedMemberRow(int $walletId = 500): OccurrenceMember {
		$row = new OccurrenceMember();
		$row->setOccurrenceId(21);
		$row->setWalletId($walletId);
		$row->setObligated(true);
		$row->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
		return $row;
	}

	// ------------------------------------------------------------------
	// Pre-claim rechecks
	// ------------------------------------------------------------------

	public function testPass_OptOutBeforeClaim_MarksSlotSkippedAndNeverNotifies(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = null; // opted out (row deleted)
		$this->dueSlots = [$this->slot()];
		$this->slotMapper->expects($this->once())->method('skipPending');

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_DepartedMemberBeforeClaim_MarksSlotSkipped(): void {
		$this->membersById = []; // houseUserMapper->find throws DoesNotExistException
		$this->dueSlots = [$this->slot()];

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_QuietHoursAtClaimTime_LeavesPendingAndNeverDispatches(): void {
		$this->membersById[7] = $this->member();
		// A cross-midnight quiet range covers the due instant.
		$this->prefsByKey['1:7'] = $this->pref('17:00', '08:00');
		$this->dueSlots = [$this->slot()];

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['quiet']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_EmptyContent_MarksSkipped_NoEmptySummaryIsEverSent(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		// No occurrences, no queued rows.
		$this->slotMapper->expects($this->once())->method('skipPending');

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_LostClaimRace_NeverDispatches(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		$this->claimsWon = 0; // another worker won

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['contended']);
		$this->assertNull($this->dispatched);
	}

	// ------------------------------------------------------------------
	// Dispatch + relevance
	// ------------------------------------------------------------------

	public function testPass_RelevantWorkAndQueuedRows_DispatchesOneCombinedSummaryAndConsumes(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1), $this->queuedRow(2)];
		$this->dueSlots = [$this->slot()];

		// One planned occurrence today with an obligated active member row.
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setHouseId(1);
		$occurrence->setRoomTaskId(11);
		$occurrence->setScheduleRevId(1);
		$occurrence->setOrdinal(1);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime('2026-09-29'));
		$occurrence->setTimezone('UTC');
		$occurrence->setSuspensionState(Occurrence::SUSPENSION_NONE);
		$this->plannedOccurrences = [$occurrence];
		$this->occMembersByOccurrence[21] = [500 => $this->obligatedMemberRow()];
		$this->membersById[7]->setWalletId(500);

		$this->slotMapper->expects($this->once())->method('finalizeClaim')
			->with(50, $this->isType('string'), DigestSlot::STATUS_SENT, $this->anything());
		$this->changeMapper->expects($this->once())->method('markConsumed')
			->with([1, 2], $this->anything());

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['dispatched']);
		$this->assertSame(['mom', 1, 7, '2026-09-29', 1, 2], $this->dispatched, 'ONE combined summary with both counts');
	}

	public function testPass_FreeDay_NoTaskContentCounts(): void {
		$this->membersById[7] = $this->member();
		$member = $this->membersById[7];
		$member->setWalletId(500);
		$member->setSchedulingPreferences(['weekdayAvailability' => ['tuesday' => false]]);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		// 2026-09-29 is a Tuesday; the occurrence would be planned today —
		// but a free day contributes NOTHING to the task content.
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setHouseId(1);
		$occurrence->setRoomTaskId(11);
		$occurrence->setScheduleRevId(1);
		$occurrence->setOrdinal(1);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime('2026-09-29'));
		$occurrence->setTimezone('UTC');
		$occurrence->setSuspensionState(Occurrence::SUSPENSION_NONE);
		$this->plannedOccurrences = [$occurrence];
		$this->occMembersByOccurrence[21] = [500 => $this->obligatedMemberRow()];

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['dispatched']);
		$this->assertSame(['mom', 1, 7, '2026-09-29', 0, 1], $this->dispatched, 'free day: task count is zero, only org rides along');
	}

	public function testPass_SuspendedWork_IsNeutralAndNotCounted(): void {
		$this->membersById[7] = $this->member();
		$this->membersById[7]->setWalletId(500);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setHouseId(1);
		$occurrence->setRoomTaskId(11);
		$occurrence->setScheduleRevId(1);
		$occurrence->setOrdinal(1);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime('2026-09-29'));
		$occurrence->setTimezone('UTC');
		$occurrence->setSuspensionState(Occurrence::SUSPENSION_SUSPENDED);
		$this->plannedOccurrences = [$occurrence];
		$this->occMembersByOccurrence[21] = [500 => $this->obligatedMemberRow()];

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		// Both categories empty → no empty summary.
		$this->assertSame(1, $stats['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_AgreedCatchUpToday_CountsEvenWhenPlannedAnotherDay(): void {
		$this->membersById[7] = $this->member();
		$this->membersById[7]->setWalletId(500);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		$occurrence = new Occurrence();
		$occurrence->setId(22);
		$occurrence->setHouseId(1);
		$occurrence->setRoomTaskId(12);
		$occurrence->setScheduleRevId(1);
		$occurrence->setOrdinal(1);
		$occurrence->setKind(Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime('2026-09-28')); // planned yesterday
		$occurrence->setTimezone('UTC');
		$occurrence->setSuspensionState(Occurrence::SUSPENSION_NONE);
		$this->workDateOccurrences = [$occurrence];
		$this->occMembersByOccurrence[22] = [500 => $this->obligatedMemberRow()];

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['dispatched']);
		$this->assertSame(['mom', 1, 7, '2026-09-29', 1, 0], $this->dispatched, 'the explicitly agreed catch-up counts');
	}

	/** @dataProvider irrelevantWork */
	public function testPass_CompletedCoveredOrRescheduledWork_IsNotCounted(?int $completionId, ?int $coveredId, ?string $workDate): void {
		$this->membersById[7] = $this->member();
		$this->membersById[7]->setWalletId(500);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setCompletionId($completionId);
		$occurrence->setRoomTaskId(11);
		$occurrence->setCoveredByCompletionId($coveredId);
		$this->plannedOccurrences = [$occurrence];
		$row = $this->obligatedMemberRow();
		$row->setWorkDate($workDate === null ? null : new \DateTime($workDate));
		$this->occMembersByOccurrence[21] = [$row];
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['skipped']);
		$this->assertNull($this->dispatched);
	}

	public static function irrelevantWork(): array {
		return [[42, null, null], [null, 42, null], [null, null, '2026-09-30']];
	}

	public function testPass_SlotMovedAfterDueScan_RechecksTheFreshSchedule(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		$this->freshSlot = clone $this->dueSlots[0];
		$this->freshSlot->setScheduledUtc(new \DateTime('2026-09-29 20:00:00 UTC'));
		$this->freshSlot->setWindowEndUtc(new \DateTime('2026-09-29 21:00:00 UTC'));
		$this->slotMapper->expects($this->never())->method('claimPending');
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['contended']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_PreferenceChangedAfterDueScan_ReResolvesBeforeClaim(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->prefsByKey['1:7']->setVersion(3);
		$this->dueSlots = [$this->slot()];
		$this->scheduleService->expects($this->once())->method('reResolvePendingSlotUnderLock')
			->willReturnCallback(static function (DigestSlot $slot): void {
				$slot->setScheduledUtc(new \DateTime('2026-09-29 20:00:00 UTC'));
			});
		$this->slotMapper->expects($this->never())->method('claimPending');
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['contended']);
	}

	public function testPass_PreClaimFailure_RetriesWithinTheWindow(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		$reads = 0;
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () use (&$reads): House {
			if ($reads++ === 0) {
				throw new \RuntimeException('pre-claim failure');
			}
			return $this->house;
		});
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['uncertain']);
		$this->assertNull($this->dispatched);
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:10:00 UTC'))['dispatched']);
	}

	public function testPass_UndoneTakeover_DoesNotContributeToOrgSummary(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		$undone = new CompletedTask();
		$undone->setRoomTaskId(11);
		$undone->setUndoneAt(new \DateTime('2026-09-29 17:00:00 UTC'));
		$this->currentCompletion = $undone;
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_NoLongerVisibleWork_DropsTaskAndOrgContent(): void {
		$this->membersById[7] = $this->member();
		$this->membersById[7]->setWalletId(500);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setRoomTaskId(11);
		$this->plannedOccurrences = [$occurrence];
		$this->occMembersByOccurrence[21] = [$this->obligatedMemberRow()];
		$this->visibleRoomTasks = [];
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['skipped']);
		$this->assertNull($this->dispatched);
	}

	public function testPass_PausedToday_CatchUpFromAnotherPlannedDayIsSuppressed(): void {
		$this->membersById[7] = $this->member();
		$this->membersById[7]->setWalletId(500);
		$this->prefsByKey['1:7'] = $this->pref();
		$this->dueSlots = [$this->slot()];
		$occurrence = new Occurrence();
		$occurrence->setId(21);
		$occurrence->setRoomTaskId(11);
		$this->workDateOccurrences = [$occurrence];
		$this->occMembersByOccurrence[21] = [$this->obligatedMemberRow()];
		$pause = new Pause();
		$pause->setPauseGroupId(1);
		$pause->setScope(Pause::SCOPE_HOUSE);
		$pause->setState(Pause::STATE_ACKNOWLEDGED);
		$pause->setStartDate(new \DateTime('2026-09-29'));
		$pause->setEndDate(new \DateTime('2026-09-29'));
		$pause->setCreatedAt(new \DateTime('2026-09-28 10:00:00 UTC'));
		$pause->setUpdatedAt(new \DateTime('2026-09-28 10:00:00 UTC'));
		$this->pauseMapper->method('findByHouseId')->willReturn([$pause]);
		$this->assertSame(1, $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'))['skipped']);
		$this->assertNull($this->dispatched);
	}

	// ------------------------------------------------------------------
	// Outcome contract
	// ------------------------------------------------------------------

	public function testPass_NotificationFails_MarksUncertainAndNeverConsumes(): void {
		$this->membersById[7] = $this->member();
		$this->prefsByKey['1:7'] = $this->pref();
		$this->queuedBySlot['7:2026-09-29'] = [$this->queuedRow(1)];
		$this->dueSlots = [$this->slot()];
		$this->dispatchThrowable = new \RuntimeException('transport down');

		$this->slotMapper->expects($this->once())->method('finalizeClaim')
			->with(50, $this->isType('string'), DigestSlot::STATUS_UNCERTAIN, $this->anything());
		$this->changeMapper->expects($this->never())->method('markConsumed');
		$this->appLogger->expects($this->once())->method('logException');

		$stats = $this->service->processDueSlots(new \DateTime('2026-09-29 18:05:00 UTC'));

		$this->assertSame(1, $stats['uncertain'], 'an uncertain outcome is consumed and NEVER resent');
		$this->assertNull($this->dispatched);
	}
}
