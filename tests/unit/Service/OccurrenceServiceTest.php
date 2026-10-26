<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
use OCA\ChoreStrike\Db\ScheduleRevisionMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\OccurrenceAgreementService;
use OCA\ChoreStrike\Service\OccurrenceCompletionService;
use OCA\ChoreStrike\Service\OccurrenceReconcileService;
use OCA\ChoreStrike\Service\OccurrenceRevisionService;
use OCA\ChoreStrike\Service\OccurrenceRowFactory;
use OCA\ChoreStrike\Service\OccurrenceSelectionService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\PauseProjectionService;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T1-B selection pins (CS-PLAN-072 §4, RF-072-WP1-19): the canonical
 * completion target among agreed entries — smallest window end, then
 * earliest planned date, then canonical identity; fulfilled/covered rows
 * never re-select; without an open window the LATEST miss is the catch-up
 * target ('late'), never a future projection; open voluntary work stays
 * separately recordable. Exercised through the {@see OccurrenceService}
 * facade so the RF-072-WP1-18 delegation is pinned with the semantics.
 *
 * Deterministic: one injected instant, no host-clock assertions.
 *
 * @covers \OCA\ChoreStrike\Service\OccurrenceService
 * @covers \OCA\ChoreStrike\Service\OccurrenceSelectionService
 */
class OccurrenceServiceTest extends TestCase {
	private OccurrenceService $service;

	/** One fixed evaluation instant for every case. */
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();
		$this->now = new \DateTime('2026-09-22 12:00:00');

		$calendar = new CalendarService();
		$reconcile = $this->createMock(OccurrenceReconcileService::class);
		$pauseProjection = new PauseProjectionService(
			$this->createMock(PauseMapper::class),
			$this->createMock(HouseUserMapper::class),
			$this->createMock(OccurrenceMemberMapper::class),
		);
		$selection = new OccurrenceSelectionService(
			$this->createMock(ScheduleRevisionMapper::class),
			$this->createMock(OccurrenceMapper::class),
			new RecurrenceCalculator(),
			$calendar,
			new OccurrenceRowFactory($calendar),
			$reconcile,
			$pauseProjection,
		);
		$this->service = new OccurrenceService(
			$reconcile,
			$selection,
			$this->createMock(OccurrenceRevisionService::class),
			$this->createMock(OccurrenceCompletionService::class),
			$this->createMock(OccurrenceAgreementService::class),
		);
	}

	private function revision(int $id): ScheduleRevision {
		$revision = new ScheduleRevision();
		$revision->setId($id);
		return $revision;
	}

	/**
	 * @return array{occurrence: Occurrence, revision: ScheduleRevision}
	 */
	private function entry(
		int $revId,
		int $ordinal,
		string $plannedDate,
		string $windowStart,
		string $windowEnd,
		string $kind = Occurrence::KIND_SCHEDULED,
		?int $completionId = null,
		?int $coveredByCompletionId = null,
	): array {
		$occurrence = new Occurrence();
		$occurrence->setScheduleRevId($revId);
		$occurrence->setOrdinal($ordinal);
		$occurrence->setKind($kind);
		$occurrence->setPlannedDate(new \DateTime($plannedDate));
		$occurrence->setWindowStart(new \DateTime($windowStart));
		$occurrence->setWindowEnd(new \DateTime($windowEnd));
		$occurrence->setCompletionId($completionId);
		$occurrence->setCoveredByCompletionId($coveredByCompletionId);
		$revision = $this->revision($revId);
		return ['occurrence' => $occurrence, 'revision' => $revision];
	}

	public function testSelectFromAgreed_ChoosesOpenWindowWithSmallestEnd(): void {
		$sooner = $this->entry(4, 2, '2026-09-21', '2026-09-21 00:00:00', '2026-09-23 00:00:00');
		$wider = $this->entry(4, 3, '2026-09-22', '2026-09-20 00:00:00', '2026-09-26 00:00:00');

		$selection = $this->service->selectFromAgreed([$wider, $sooner], $this->now);

		self::assertNotNull($selection);
		self::assertSame('agreed', $selection['classification']);
		self::assertSame('4:2', $selection['occurrence']->toPublicKey(), 'the earliest deadline wins');
	}

	public function testSelectFromAgreed_SameWindowEnd_BreaksTieByPlannedDate(): void {
		$earlierPlanned = $this->entry(4, 2, '2026-09-20', '2026-09-20 00:00:00', '2026-09-24 00:00:00');
		$laterPlanned = $this->entry(4, 3, '2026-09-22', '2026-09-20 00:00:00', '2026-09-24 00:00:00');

		$selection = $this->service->selectFromAgreed([$laterPlanned, $earlierPlanned], $this->now);

		self::assertNotNull($selection);
		self::assertSame('4:2', $selection['occurrence']->toPublicKey(), 'the earliest planned date wins');
	}

	public function testSelectFromAgreed_SameEndAndPlannedDate_BreaksTieByCanonicalIdentity(): void {
		$lower = $this->entry(4, 2, '2026-09-22', '2026-09-20 00:00:00', '2026-09-24 00:00:00');
		$higher = $this->entry(4, 7, '2026-09-22', '2026-09-20 00:00:00', '2026-09-24 00:00:00');

		$selection = $this->service->selectFromAgreed([$higher, $lower], $this->now);

		self::assertNotNull($selection);
		self::assertSame('4:2', $selection['occurrence']->toPublicKey(), 'the smallest canonical key wins');
	}

	public function testSelectFromAgreed_SkipsFulfilledCoveredAndVoluntaryRows(): void {
		$fulfilled = $this->entry(4, 1, '2026-09-22', '2026-09-21 00:00:00', '2026-09-23 00:00:00', Occurrence::KIND_SCHEDULED, 11);
		$covered = $this->entry(4, 2, '2026-09-22', '2026-09-21 00:00:00', '2026-09-23 00:00:00', Occurrence::KIND_SCHEDULED, null, 12);
		$voluntary = $this->entry(4, 3, '2026-09-22', '2026-09-21 00:00:00', '2026-09-23 00:00:00', Occurrence::KIND_VOLUNTARY);
		$open = $this->entry(4, 4, '2026-09-22', '2026-09-22 00:00:00', '2026-09-24 00:00:00');

		$selection = $this->service->selectFromAgreed([$fulfilled, $covered, $voluntary, $open], $this->now);

		self::assertNotNull($selection);
		self::assertSame('agreed', $selection['classification']);
		self::assertSame('4:4', $selection['occurrence']->toPublicKey(), 'only the open unfulfilled scheduled row is selectable');
	}

	public function testSelectFromAgreed_NoOpenWindow_SelectsLatestMissAsLate(): void {
		$olderMiss = $this->entry(4, 1, '2026-09-18', '2026-09-18 00:00:00', '2026-09-19 00:00:00');
		$latestMiss = $this->entry(4, 2, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00');

		$selection = $this->service->selectFromAgreed([$olderMiss, $latestMiss], $this->now);

		self::assertNotNull($selection);
		self::assertSame('late', $selection['classification']);
		self::assertSame('4:2', $selection['occurrence']->toPublicKey(), 'the latest miss is the catch-up target; earlier misses remain');
	}

	public function testSelectFromAgreed_CoveredMissIsNotACatchUpTarget(): void {
		$coveredMiss = $this->entry(4, 1, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00', Occurrence::KIND_SCHEDULED, null, 12);

		self::assertNull($this->service->selectFromAgreed([$coveredMiss], $this->now));
	}

	public function testSelectFromAgreed_OnlyOpenVoluntary_RemainsRecordable(): void {
		$voluntary = $this->entry(4, 1, '2026-09-22', '2026-09-21 00:00:00', '2026-09-23 00:00:00', Occurrence::KIND_VOLUNTARY);

		$selection = $this->service->selectFromAgreed([$voluntary], $this->now);

		self::assertNotNull($selection);
		self::assertSame('voluntary', $selection['classification']);
		self::assertSame('4:1', $selection['occurrence']->toPublicKey());
	}

	/**
	 * FEAT-UX-039 (D-088-12): with the rounded-up cap, neighbours of an
	 * every-two-weeks rule with 7 flex days share one day. On that day the
	 * earlier occurrence (smaller window end) is the target; the next day
	 * the later one is.
	 */
	public function testSelectFromAgreed_NeighboursSharingADay_StayDeterministic(): void {
		$earlier = $this->entry(4, 1, '2026-09-15', '2026-09-08 00:00:00', '2026-09-23 00:00:00');
		$later = $this->entry(4, 2, '2026-09-29', '2026-09-22 00:00:00', '2026-10-07 00:00:00');

		$sharedDay = $this->service->selectFromAgreed([$later, $earlier], $this->now);
		self::assertSame('4:1', $sharedDay['occurrence']->toPublicKey(), 'the earlier deadline wins on the shared day');

		$nextDay = $this->service->selectFromAgreed([$later, $earlier], new \DateTime('2026-09-23 08:00:00'));
		self::assertSame('4:2', $nextDay['occurrence']->toPublicKey());
		self::assertSame('agreed', $nextDay['classification'], 'the later window is open, the earlier one now a miss');
	}

	/**
	 * FEAT-UX-039: the revision mapping no longer clamps flex to 7 days —
	 * a stored long-period value becomes symmetric window days; only the
	 * sanity bound guards the read side.
	 */
	public function testRuleInputsFromTask_KeepsLongFlexWindows(): void {
		$revisions = new OccurrenceRevisionService(
			$this->createMock(\OCA\ChoreStrike\Db\TransactionExecutor::class),
			$this->createMock(ScheduleRevisionMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\RoomTaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\HouseMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\TaskMapper::class),
			$this->createMock(\OCA\ChoreStrike\Db\WalletMapper::class),
			new RecurrenceCalculator(),
			new CalendarService(),
			$this->createMock(OccurrenceReconcileService::class),
		);
		$task = new \OCA\ChoreStrike\Db\Task();
		$task->setFrequencyType('monthly');
		$task->setFrequencyValue(6);
		$task->setFlexDays(90);

		$inputs = $revisions->ruleInputsFromTask($task);
		self::assertSame(90, $inputs['early']);
		self::assertSame(90, $inputs['late']);

		$task->setFlexDays(500);
		self::assertSame(\OCA\ChoreStrike\Service\FlexDaysPolicy::SANITY_MAX, $revisions->ruleInputsFromTask($task)['late']);
	}

	public function testSelectFromAgreed_FutureWindowIsNeverSelected(): void {
		$future = $this->entry(4, 1, '2026-09-25', '2026-09-25 00:00:00', '2026-09-27 00:00:00');

		self::assertNull(
			$this->service->selectFromAgreed([$future], $this->now),
			'a future projection is never silently chosen instead of the shown target'
		);
	}

	/**
	 * CR-084-01: the per-occurrence miss rule the calendar marks — the saved
	 * window has ended without fulfilment, cover or neutral suspension; a
	 * past planned day inside an open window is not missed.
	 */
	public function testIsMissed_OnlyAnEndedUnfulfilledScheduledWindow(): void {
		$miss = $this->entry(4, 1, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00')['occurrence'];
		$openFlexWindow = $this->entry(4, 2, '2026-09-21', '2026-09-19 00:00:00', '2026-09-24 00:00:00')['occurrence'];
		$fulfilled = $this->entry(4, 3, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00', Occurrence::KIND_SCHEDULED, 11)['occurrence'];
		$covered = $this->entry(4, 4, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00', Occurrence::KIND_SCHEDULED, null, 12)['occurrence'];
		$voluntary = $this->entry(4, 5, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00', Occurrence::KIND_VOLUNTARY)['occurrence'];
		$suspended = $this->entry(4, 6, '2026-09-20', '2026-09-20 00:00:00', '2026-09-21 00:00:00')['occurrence'];
		$suspended->setSuspensionState(Occurrence::SUSPENSION_SUSPENDED);
		$endsNow = $this->entry(4, 7, '2026-09-21', '2026-09-21 00:00:00', '2026-09-22 12:00:00')['occurrence'];

		self::assertTrue($this->service->isMissed($miss, $this->now));
		self::assertFalse($this->service->isMissed($openFlexWindow, $this->now), 'a past planned day inside an open window is still due');
		self::assertFalse($this->service->isMissed($fulfilled, $this->now));
		self::assertFalse($this->service->isMissed($covered, $this->now));
		self::assertFalse($this->service->isMissed($voluntary, $this->now));
		self::assertFalse($this->service->isMissed($suspended, $this->now));
		self::assertTrue($this->service->isMissed($endsNow, $this->now), 'the window end is exclusive, like the selection');
	}
}
