<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\RoomTaskLastDoneService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * FEAT-UX-045 (CS-PLAN-088 D-088-20): "last done on" per room task on a
 * populated house — the room stamp, the interval re-anchor, the refusals
 * and the receipt replay, with no completion, reward or rhythm fact.
 */
class RoomTaskLastDoneIntegrationTest extends IntegrationTestCase {
	private RoomTaskLastDoneService $service;
	private OccurrenceMapper $occurrenceMapper;
	private RoomMapper $roomMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->service = \OC::$server->get(RoomTaskLastDoneService::class);
		$this->occurrenceMapper = \OC::$server->get(OccurrenceMapper::class);
		$this->roomMapper = \OC::$server->get(RoomMapper::class);
	}

	public function testIntervalTask_ReanchorsOneIntervalAfterTheDay_AndStampsTheRoomOnly(): void {
		$f = $this->fixture('dynamic', $this->day(+3));
		$pending = $this->pending($f['roomTaskId']);
		$cleanliness = $this->roomMapper->find($f['roomId'])->getCleanliness();

		$this->actingAs('ld_owner');
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-2), $this->uuid(1));

		$moved = $this->occurrenceMapper->find((int)$pending->getId());
		self::assertSame($this->day(+5), $moved->getPlannedDate()->format('Y-m-d'), 'weekly interval: last done + 7 days');
		self::assertSame($pending->getVersion() + 1, $moved->getVersion());
		self::assertCount(1, $this->rows('chorestrike_agreements', 'occurrence_id', (int)$pending->getId()), 'the move is a traceable date agreement');
		$room = $this->roomMapper->find($f['roomId']);
		self::assertSame($this->day(-2) . ' 00:00', $room->getLastCleaned()->format('Y-m-d H:i'));
		self::assertSame($cleanliness, $room->getCleanliness(), 'no cleanliness effect');
		self::assertSame([], $this->rows('chorestrike_completed_tasks', 'room_task_id', $f['roomTaskId']), 'no completion fact');
	}

	public function testIntervalTask_LongAgo_IsDueToday(): void {
		$f = $this->fixture('dynamic', $this->day(+3));

		$this->actingAs('ld_owner');
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-30), $this->uuid(2));

		self::assertSame($this->day(0), $this->pending($f['roomTaskId'])->getPlannedDate()->format('Y-m-d'));
	}

	public function testFixedCadence_KeepsItsDates(): void {
		$f = $this->fixture('static', $this->day(+3));
		$before = $this->pending($f['roomTaskId']);

		$this->actingAs('ld_owner');
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-1), $this->uuid(3));

		$after = $this->occurrenceMapper->find((int)$before->getId());
		self::assertEquals($before->getPlannedDate(), $after->getPlannedDate());
		self::assertSame($before->getVersion(), $after->getVersion());
		self::assertSame($this->day(-1), $this->roomMapper->find($f['roomId'])->getLastCleaned()->format('Y-m-d'));
	}

	public function testOverdueInterval_IsRefusedWithoutAnyWrite(): void {
		$f = $this->fixture('dynamic', $this->day(-3));

		$this->actingAs('ld_owner');
		try {
			$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-1), $this->uuid(4));
			self::fail('an overdue appointment must refuse the command');
		} catch (ConflictException $e) {
			self::assertStringContainsString('overdue', $e->getMessage());
		}
		self::assertNull($this->roomMapper->find($f['roomId'])->getLastCleaned());
		self::assertSame([], $this->lastDoneEvents($f['roomId']));
	}

	public function testFutureDayAndChild_AreRefused(): void {
		$f = $this->fixture('dynamic', $this->day(+3));
		$this->addHouseMember($f['houseId'], 'ld_child', 'Child', 'child');

		$this->actingAs('ld_owner');
		try {
			$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(+1), $this->uuid(5));
			self::fail('a future day must be refused');
		} catch (\InvalidArgumentException) {
			self::addToAssertionCount(1);
		}
		$this->actingAs('ld_child');
		$this->expectException(ForbiddenException::class);
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-1), $this->uuid(6));
	}

	public function testNewerEntryCorrectsTheStamp_AndAReplayAddsNothing(): void {
		$f = $this->fixture('static', $this->day(+3));

		$this->actingAs('ld_owner');
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-1), $this->uuid(7));
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-6), $this->uuid(8));
		$this->service->recordLastDone($f['roomTaskId'], $f['houseId'], $this->day(-6), $this->uuid(8));

		self::assertSame($this->day(-6), $this->roomMapper->find($f['roomId'])->getLastCleaned()->format('Y-m-d'), 'the newer entry corrects to an earlier day');
		self::assertCount(2, $this->lastDoneEvents($f['roomId']), 'the replayed receipt journals nothing twice');
	}

	/**
	 * House (UTC) + owner + room + weekly task in the given scheduling mode
	 * (`dynamic` = interval after completion, `static` = fixed cadence).
	 *
	 * @return array{houseId: int, roomId: int, roomTaskId: int}
	 */
	private function fixture(string $schedulingMode, string $anchor): array {
		$houseId = $this->createHouse('ld_owner', 'Last Done House');
		$roomId = $this->createRoom($houseId, $this->createFloor($houseId, 'Ground', 1), 'Kitchen');
		$taskId = $this->createTask($houseId, 'Wipe the counters');
		foreach (['frequency_type' => 'weekly', 'frequency_value' => 1, 'scheduling_mode' => $schedulingMode, 'recurrence_mode' => 'recurring'] as $column => $value) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set($column, $qb->createNamedParameter($value))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
		return ['houseId' => $houseId, 'roomId' => $roomId, 'roomTaskId' => $this->assignTaskToRoom($taskId, $roomId, 'ld_owner', $anchor)];
	}

	private function pending(int $roomTaskId): \OCA\ChoreStrike\Db\Occurrence {
		$open = $this->occurrenceMapper->findOpenByRoomTasks([$roomTaskId]);
		self::assertNotEmpty($open, 'precondition: an open occurrence exists');
		return $open[0];
	}

	private function day(int $offset): string {
		return (new \DateTime('today', new \DateTimeZone('UTC')))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
	}

	private function uuid(int $n): string {
		return sprintf('00000000-0000-4000-8000-%012d', $n);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function lastDoneEvents(int $roomId): array {
		return array_values(array_filter(
			$this->rows('chorestrike_room_events', 'room_id', $roomId),
			static fn (array $row): bool => $row['kind'] === 'last_done'
		));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $table, string $column, int $value): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
