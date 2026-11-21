<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;

/**
 * Day-key bounds of the occurrence read on every provider (CS-PLAN-090
 * RF-090-57). Rows are written through the entity, as production wrote
 * them: Nextcloud binds the date fields as 'Y-m-d 00:00:00', which SQLite
 * keeps as text. Fixed days in 2025 keep the proofs independent of the
 * weekday the suite runs on and clear of the fixture's own rows. One read
 * is left since the derived windows (ADR-021): the recognitions' week.
 */
class OccurrenceDayBoundsTest extends IntegrationTestCase {
	private OccurrenceMapper $mapper;
	private int $houseId;
	private int $roomTaskId;
	private int $revisionId;
	private int $ordinal = 9000;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = \OC::$server->get(OccurrenceMapper::class);
		$this->houseId = $this->createHouse('bounds_owner', 'Day Bounds House');
		$floorId = $this->createFloor($this->houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($this->houseId, $floorId, 'Kitchen');
		$this->roomTaskId = $this->assignTaskToRoom($this->createTask($this->houseId, 'Bounds chore'), $roomId, 'bounds_owner');
		$this->revisionId = random_int(800000000, 899999999);
	}

	public function testAWindowHoldsItsFirstAndItsLastDay(): void {
		$this->occurrenceOn('2025-03-09');
		$first = $this->occurrenceOn('2025-03-10');
		$last = $this->occurrenceOn('2025-03-16');
		$this->occurrenceOn('2025-03-17');

		$expected = [$first, $last];
		self::assertSame($expected, $this->ids($this->mapper->findByHousePlannedBetween($this->houseId, '2025-03-10', '2025-03-16')));
		self::assertSame([$last], $this->ids($this->mapper->findByHousePlannedBetween($this->houseId, '2025-03-16', '2025-03-16')), 'a one-day window');
	}

	private function occurrenceOn(string $dayKey): int {
		$day = RecurrenceCalculator::dayKeyToDate($dayKey);
		$occurrence = new Occurrence();
		$occurrence->setHouseId($this->houseId);
		$occurrence->setRoomTaskId($this->roomTaskId);
		$occurrence->setScheduleRevId($this->revisionId);
		$occurrence->setOrdinal(++$this->ordinal);
		$occurrence->setPlannedDate($day);
		$occurrence->setWeekStart(clone $day);
		$occurrence->setTimezone('UTC');
		$occurrence->setDayStart(clone $day);
		$occurrence->setWindowStart(clone $day);
		$occurrence->setWindowEnd((clone $day)->modify('+1 day'));
		$occurrence->setCreatedAt(new \DateTime());
		return (int)$this->mapper->insert($occurrence)->getId();
	}

	/**
	 * @param Occurrence[] $rows
	 * @return int[]
	 */
	private function ids(array $rows): array {
		return array_map(static fn (Occurrence $row): int => (int)$row->getId(), $rows);
	}
}
