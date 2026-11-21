<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Migration\Version1036Date20261117000000;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\CycleProjection;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\WindowService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCA\ChoreStrike\Tests\Integration\Support\StoredTimelineFixtures;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Migration\IOutput;

/**
 * The cutover step Version1036 on the real database of every supported
 * provider (CS-PLAN-091 WP-B1, ADR-021 decision 12): a household as the
 * occurrence timeline left it gets the day its derived windows start, the
 * streak base of every member and the anchor of every room task — and
 * nothing that was stored before moves. The first window of each room
 * task is then read through the same service as every other window.
 *
 * The step updates whole tables, so each case runs it inside a
 * transaction that is rolled back: rows of other fixtures and of the
 * instance household stay as they were. The real app upgrade from the
 * retained baseline is the P4 proof of the preservation runner.
 */
class CycleCutoverStepTest extends IntegrationTestCase {
	use StoredTimelineFixtures;

	private const STEPS = ['1035Date20261117000000', '1036Date20261117000000'];
	private const OWNER = 'cut_owner';
	/** The instant the update runs: Friday, 20 November 2026, 11:00 UTC. */
	private const UPDATE_AT = '2026-11-20 11:00:00';

	private int $houseId;
	private int $roomId;

	protected function setUp(): void {
		parent::setUp();
		$this->houseId = $this->oldHouse('Cutover House', 'UTC');
		$this->addHouseMember($this->houseId, self::OWNER, 'Owner', 'owner');
		$this->addHouseMember($this->houseId, 'cut_kid', 'Kid', 'child');
		$this->roomId = $this->createRoom($this->houseId, $this->createFloor($this->houseId, 'Ground', 1), 'Kitchen');
	}

	// ------------------------------------------------------------------
	// Rows as they were before the update
	// ------------------------------------------------------------------

	/** A house without the day its derived windows start. */
	private function oldHouse(string $name, string $timezone): int {
		$houseId = $this->createBareHouse($name);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_houses')
			->set('cycles_from', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('timezone', $qb->createNamedParameter($timezone))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		return $houseId;
	}

	/**
	 * A room task without any derived-window state, under the given
	 * definition columns.
	 */
	private function oldRoomTask(string $name, array $taskColumns = []): int {
		$taskId = $this->createTask($this->houseId, $name);
		foreach ($taskColumns as $column => $value) {
			$this->setColumn('chorestrike_tasks', $taskId, $column, $value);
		}
		$roomTaskId = $this->assignTaskToRoom($taskId, $this->createRoom($this->houseId, $this->createFloor($this->houseId, $name, 2), $name), self::OWNER);
		// Mounted in September, long before the work the cases record.
		foreach (['anchor_on' => null, 'cycle_key' => null, 'cycle_version' => 0, 'created_at' => '2026-09-01 00:00:00'] as $column => $value) {
			$this->setColumn('chorestrike_room_tasks', $roomTaskId, $column, $value);
		}
		return $roomTaskId;
	}

	private function setColumn(string $table, int $id, string $column, mixed $value): void {
		$qb = $this->db->getQueryBuilder();
		$type = $value === null ? IQueryBuilder::PARAM_NULL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
		$qb->update($table)
			->set($column, $qb->createNamedParameter($value, $type))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** A stored completion of the room task, valid unless `$undone`. */
	private function storedCompletion(int $roomTaskId, string $workDay, string $plannedDay, bool $undone = false): int {
		$completionId = $this->insertCompletionRow($roomTaskId, null, $workDay . ' 12:00:00', 3, $plannedDay);
		if ($undone) {
			$this->setColumn('chorestrike_completed_tasks', $completionId, 'undone_at', $workDay . ' 13:00:00');
		}
		return $completionId;
	}

	/**
	 * The household the cases share: one room task per situation the
	 * occurrence timeline could leave behind.
	 *
	 * @return array<string, int> Room task ids by case
	 */
	private function seedHousehold(): array {
		$ids = [];

		// An after-completion task that was never done; its one open
		// appointment had been moved to the 24th.
		$ids['moved'] = $this->oldRoomTask('Moved');
		$this->insertStoredRevision($ids['moved'], '2026-11-10');
		$this->insertStoredOccurrence($this->houseId, $ids['moved'], '2026-11-24');

		// Never done; an absence suspended its appointment of the 12th and
		// the old model's restart replaced it with one on the 21st (review
		// regression: the replaced appointment is no longer the open work).
		$ids['restarted'] = $this->oldRoomTask('Restarted');
		$this->insertStoredRevision($ids['restarted'], '2026-11-05');
		$suspended = $this->insertStoredOccurrence($this->houseId, $ids['restarted'], '2026-11-12', ['suspension_state' => 'suspended']);
		$this->insertStoredOccurrence($this->houseId, $ids['restarted'], '2026-11-21', ['restart_of_id' => $suspended]);

		// Done on the 11th; the timeline holds the fulfilled appointment
		// and an open successor.
		$ids['done'] = $this->oldRoomTask('Done');
		$this->insertStoredRevision($ids['done'], '2026-11-03');
		$doneId = $this->storedCompletion($ids['done'], '2026-11-11', '2026-11-10');
		$this->insertStoredOccurrence($this->houseId, $ids['done'], '2026-11-10', ['completion_id' => $doneId]);
		$this->insertStoredOccurrence($this->houseId, $ids['done'], '2026-11-19');

		// Its only completion was undone: it counts as never done.
		$ids['undone'] = $this->oldRoomTask('Undone');
		$this->insertStoredRevision($ids['undone'], '2026-11-05');
		$this->storedCompletion($ids['undone'], '2026-11-12', '2026-11-12', true);
		$this->insertStoredOccurrence($this->houseId, $ids['undone'], '2026-11-12');

		// A fixed rule on Fridays, done last Friday: it keeps its anchor.
		$ids['fixed'] = $this->oldRoomTask('Fixed', ['scheduling_mode' => 'static', 'fixed_schedule_days' => '["friday"]']);
		$this->insertStoredRevision($ids['fixed'], '2026-11-06', ['cadence_mode' => 'fixed', 'weekdays' => '5']);
		$fixedId = $this->storedCompletion($ids['fixed'], '2026-11-13', '2026-11-13');
		$this->insertStoredOccurrence($this->houseId, $ids['fixed'], '2026-11-13', ['completion_id' => $fixedId]);
		$this->insertStoredOccurrence($this->houseId, $ids['fixed'], '2026-11-20');

		// A fixed monthly rule that ran on the 31st.
		$ids['monthly'] = $this->oldRoomTask('Monthly', ['frequency_type' => 'monthly', 'scheduling_mode' => 'static']);
		$this->insertStoredRevision($ids['monthly'], '2026-10-31', ['cadence_mode' => 'fixed', 'cadence_unit' => 'month']);
		$this->insertStoredOccurrence($this->houseId, $ids['monthly'], '2026-11-30');

		// A one-time task planned for the 25th whose row was never materialized.
		$ids['once'] = $this->oldRoomTask('Once', ['recurrence_mode' => 'once']);
		$this->insertStoredRevision($ids['once'], '2026-11-25', ['lifetime' => 'once']);

		// A voluntary definition with its voluntary appointment.
		$ids['voluntary'] = $this->oldRoomTask('Voluntary', ['is_voluntary' => 1]);
		$this->insertStoredRevision($ids['voluntary'], '2026-11-01', ['is_voluntary' => true]);
		$this->insertStoredOccurrence($this->houseId, $ids['voluntary'], '2026-11-01', ['kind' => 'voluntary']);

		// A room task whose schedule ended: inactive, only a closed revision.
		$ids['ended'] = $this->oldRoomTask('Ended');
		$this->insertStoredRevision($ids['ended'], '2026-09-01', ['closed_at' => '2026-10-01 00:00:00']);
		$this->setColumn('chorestrike_room_tasks', $ids['ended'], 'is_active', 0);

		foreach ([self::OWNER => [5, 9], 'cut_kid' => [0, 4]] as $userId => [$current, $longest]) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_house_users')
				->set('current_streak', $qb->createNamedParameter($current, IQueryBuilder::PARAM_INT))
				->set('longest_streak', $qb->createNamedParameter($longest, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter($userId)))
				->executeStatement();
		}
		return $ids;
	}

	// ------------------------------------------------------------------
	// Running and reading
	// ------------------------------------------------------------------

	private function runStep(string $at = self::UPDATE_AT, ?IOutput $output = null): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($at, new \DateTimeZone('UTC')));
		(new Version1036Date20261117000000($this->db, $time))
			->postSchemaChange($output ?? $this->createMock(IOutput::class), static fn () => null, []);
	}

	/** @return list<array<string, mixed>> */
	private function rowsOf(string $table, string $column, array $ids): array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('*')->from($table)
			->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC')
			->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Everything the occurrence timeline stored for the fixture, raw.
	 *
	 * @param int[] $roomTaskIds
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function storedFacts(array $roomTaskIds): array {
		$occurrences = $this->rowsOf('chorestrike_occurrences', 'room_task_id', $roomTaskIds);
		$members = $this->rowsOf('chorestrike_house_users', 'house_id', [$this->houseId]);
		return [
			'occurrences' => $occurrences,
			'schedule_revs' => $this->rowsOf('chorestrike_schedule_revs', 'room_task_id', $roomTaskIds),
			'completed_tasks' => $this->rowsOf('chorestrike_completed_tasks', 'room_task_id', $roomTaskIds),
			'cycles' => $this->rowsOf('chorestrike_cycles', 'room_task_id', $roomTaskIds),
			'streaks' => array_map(static fn (array $row): array => [(int)$row['current_streak'], (int)$row['longest_streak']], $members),
			'projection' => array_map(
				static fn (array $row): array => [$row['next_due'], $row['ideal_due_date'], $row['is_active'], $row['last_completed']],
				$this->rowsOf('chorestrike_room_tasks', 'id', $roomTaskIds)
			),
		];
	}

	/** @return array<int, ?string> Room task id to its stored anchor day */
	private function anchors(array $roomTaskIds): array {
		$anchors = [];
		foreach ($this->rowsOf('chorestrike_room_tasks', 'id', $roomTaskIds) as $row) {
			$anchors[(int)$row['id']] = $row['anchor_on'] === null ? null : substr((string)$row['anchor_on'], 0, 10);
		}
		return $anchors;
	}

	/** The derived window of a room task, read at the instant of the update unless another is given. */
	private function firstCycle(int $roomTaskId, string $at = self::UPDATE_AT): ?CurrentCycle {
		$roomTask = \OC::$server->get(RoomTaskMapper::class)->find($roomTaskId);
		return \OC::$server->get(CycleProjection::class)->currentFor(
			$roomTask,
			\OC::$server->get(TaskMapper::class)->find($roomTask->getTaskId()),
			new \DateTimeZone('UTC'),
			new \DateTime($at, new \DateTimeZone('UTC'))
		);
	}

	// ------------------------------------------------------------------
	// Cases
	// ------------------------------------------------------------------

	public function testStep_AnchorsEveryRoomTask_AndMovesNothingThatWasStored(): void {
		$ids = $this->seedHousehold();
		$before = $this->storedFacts(array_values($ids));
		self::assertSame(array_fill_keys(array_values($ids), null), $this->anchors(array_values($ids)), 'the fixture is in the shape before the update');

		$this->db->beginTransaction();
		try {
			$this->runStep();
			$anchors = $this->anchors(array_values($ids));
			$after = $this->storedFacts(array_values($ids));
			$monthDay = \OC::$server->get(TaskMapper::class)->find(\OC::$server->get(RoomTaskMapper::class)->find($ids['monthly'])->getTaskId())->getFixedMonthDay();
			$otherMonthDays = array_filter(array_column($this->rowsOf('chorestrike_tasks', 'id', $this->taskIds($ids, ['monthly'])), 'fixed_month_day'));
			$cycles = array_map(fn (int $roomTaskId): ?CurrentCycle => $this->firstCycle($roomTaskId), $ids);
			$monthEnd = $this->firstCycle($ids['monthly'], '2026-11-30 11:00:00');
			$voluntary = \OC::$server->get(RoomTaskReadService::class)->cycleFacts(
				$this->houseId,
				[\OC::$server->get(RoomTaskMapper::class)->find($ids['voluntary'])]
			)[$ids['voluntary']]['block'];
		} finally {
			$this->db->rollBack();
		}

		self::assertSame($before, $after, 'occurrences, revisions, completions, streaks and the projection stay as stored; no cycle row is written');
		self::assertSame([
			$ids['moved'] => '2026-11-24',
			$ids['restarted'] => '2026-11-21',
			$ids['done'] => null,
			$ids['undone'] => '2026-11-12',
			$ids['fixed'] => '2026-11-06',
			$ids['monthly'] => '2026-10-31',
			$ids['once'] => '2026-11-25',
			$ids['voluntary'] => '2026-11-01',
			$ids['ended'] => null,
		], $anchors);
		self::assertSame(31, $monthDay, 'the monthly rule keeps the day of the month it ran on');
		self::assertSame([], $otherMonthDays, 'no other definition gets one');

		// Each room task's first derived window.
		self::assertSame(['d2026-11-24', '2026-11-24'], [$cycles['moved']->key, $cycles['moved']->endOn], 'a never-done task starts from its open appointment');
		self::assertSame('d2026-11-21', $cycles['restarted']->key, 'a restart successor replaces the appointment it restarted');
		self::assertStringStartsWith('c', $cycles['done']->key, 'a done task counts from its latest valid completion');
		self::assertSame('2026-11-18', $cycles['done']->plannedOn(), 'one week after the work of the 11th');
		self::assertSame('d2026-11-12', $cycles['undone']->key, 'an undone completion anchors nothing');
		self::assertTrue($cycles['undone']->isOverdue('2026-11-20'));
		self::assertSame('d2026-11-20', $cycles['fixed']->key, 'the fixed rule goes on with the Friday after the one that was done');
		self::assertSame('d2026-11-25', $cycles['once']->key);
		self::assertSame('d2026-10-31', $cycles['monthly']->key, 'the day it last ran stays the one red entry until the next window opens');
		self::assertSame('d2026-11-30', $monthEnd?->key, 'the 31st, clamped to the end of a shorter month');
		self::assertNull($cycles['ended'], 'a room task without a live schedule gets no window');

		// ADR-021 decision 8: a voluntary definition is a bonus task from now on.
		self::assertSame(WindowService::STATE_BONUS, $voluntary['state']);
		self::assertNull($cycles['voluntary'], 'it has no window and no cycle');
	}

	/** @return int[] The definition ids of every case except the named ones */
	private function taskIds(array $ids, array $except): array {
		$roomTasks = $this->rowsOf('chorestrike_room_tasks', 'id', array_values(array_diff_key($ids, array_flip($except))));
		return array_map('intval', array_column($roomTasks, 'task_id'));
	}

	/** D-091-41: the derived model starts on the house-local day of the update. */
	public function testStep_StartsTheDerivedModelOnTheHouseLocalUpdateDay_AndCopiesTheStreakBases(): void {
		$this->seedHousehold();
		$kiritimati = $this->oldHouse('Line Islands House', 'Pacific/Kiritimati');
		$broken = $this->oldHouse('Broken Zone House', 'Not/AZone');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::matchesRegularExpression(
			'/^Derived windows start in \d+ houses; \d+ streak bases, \d+ room-task anchors and \d+ monthly days set$/'
		));

		$this->db->beginTransaction();
		try {
			$this->runStep(self::UPDATE_AT, $output);
			$houses = array_column($this->rowsOf('chorestrike_houses', 'id', [$this->houseId, $kiritimati, $broken]), 'cycles_from', 'id');
			$members = $this->rowsOf('chorestrike_house_users', 'house_id', [$this->houseId]);
		} finally {
			$this->db->rollBack();
		}

		$days = array_map(static fn (?string $day): ?string => $day === null ? null : substr($day, 0, 10), $houses);
		self::assertSame('2026-11-20', $days[$this->houseId], 'the day of the update in UTC');
		self::assertSame('2026-11-21', $days[$kiritimati], 'where it is already the 21st, the 21st');
		self::assertSame('2026-11-20', $days[$broken], 'an unreadable zone falls back to UTC');
		self::assertSame(
			[[5, 9, 5, 9], [0, 4, 0, 4]],
			array_map(static fn (array $row): array => [(int)$row['current_streak'], (int)$row['longest_streak'], (int)$row['streak_base'], (int)$row['longest_streak_base']], $members),
			'D-091-04: the streak a member holds is where the derived model continues'
		);
	}

	public function testStep_Repeated_ChangesNothingItAlreadySet(): void {
		$ids = $this->seedHousehold();

		$this->db->beginTransaction();
		try {
			$this->runStep();
			$first = [$this->anchors(array_values($ids)), $this->rowsOf('chorestrike_houses', 'id', [$this->houseId]), $this->rowsOf('chorestrike_house_users', 'house_id', [$this->houseId])];
			// The household goes on: a streak grows and an appointment would
			// read differently ten days later.
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_house_users')
				->set('current_streak', $qb->createNamedParameter(6, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($this->houseId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('nextcloud_user_id', $qb->createNamedParameter(self::OWNER)))
				->executeStatement();
			$this->insertStoredOccurrence($this->houseId, $ids['moved'], '2026-11-17');

			$this->runStep('2026-11-30 11:00:00');
			$second = [$this->anchors(array_values($ids)), $this->rowsOf('chorestrike_houses', 'id', [$this->houseId]), $this->rowsOf('chorestrike_house_users', 'house_id', [$this->houseId])];
		} finally {
			$this->db->rollBack();
		}

		self::assertSame($first[0], $second[0], 'anchors are set once');
		self::assertSame($first[1], $second[1], 'the cutover day is set once');
		self::assertSame(
			array_column($first[2], 'streak_base'),
			array_column($second[2], 'streak_base'),
			'a streak base is the streak at the cutover, not a later one'
		);
	}

	public function testStep_EmptyInstallation_SetsNothing(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with('Derived windows start in 0 houses; 0 streak bases, 0 room-task anchors and 0 monthly days set');

		$this->db->beginTransaction();
		try {
			foreach (['chorestrike_schedule_revs', 'chorestrike_house_users', 'chorestrike_houses'] as $table) {
				$this->db->getQueryBuilder()->delete($table)->executeStatement();
			}
			$this->runStep(self::UPDATE_AT, $output);
		} finally {
			$this->db->rollBack();
		}
	}

	public function testRunner_DoesNotApplyTheRecordedStepsAgain(): void {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('chorestrike')))
			->andWhere($qb->expr()->in('version', $qb->createNamedParameter(self::STEPS, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('version', 'ASC')
			->executeQuery();
		$recorded = array_column($result->fetchAll(), 'version');
		$result->closeCursor();
		self::assertSame(self::STEPS, $recorded, 'the installation records each step exactly once');

		// A house created after the update without a cutover day would get
		// one if the data step ran again.
		$ids = $this->seedHousehold();
		$before = [$this->rowsOf('chorestrike_houses', 'id', [$this->houseId]), $this->anchors(array_values($ids))];

		(new MigrationService('chorestrike', \OC::$server->get(Connection::class)))->migrate();

		self::assertSame($before, [$this->rowsOf('chorestrike_houses', 'id', [$this->houseId]), $this->anchors(array_values($ids))]);
	}
}
