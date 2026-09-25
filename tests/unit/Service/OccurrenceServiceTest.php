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

	public function testSelectFromAgreed_FutureWindowIsNeverSelected(): void {
		$future = $this->entry(4, 1, '2026-09-25', '2026-09-25 00:00:00', '2026-09-27 00:00:00');

		self::assertNull(
			$this->service->selectFromAgreed([$future], $this->now),
			'a future projection is never silently chosen instead of the shown target'
		);
	}
}
