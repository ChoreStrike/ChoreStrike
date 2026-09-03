<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Service\HouseService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Half-open week windows (CS-PLAN-060 WP1 §3.6): a completion exactly at the
 * window boundary (next Monday 00:00:00) counts in exactly ONE window, and
 * the weekly-goal totals are unchanged off-boundary.
 */
class WeekWindowBoundaryTest extends IntegrationTestCase {
	private CompletedTaskMapper $completedTaskMapper;

	/** @var int[] */
	private array $createdCompletionIds = [];

	protected function setUp(): void {
		parent::setUp();
		$this->completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
	}

	protected function tearDown(): void {
		if (!empty($this->createdCompletionIds)) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->in('id', $qb->createNamedParameter($this->createdCompletionIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		parent::tearDown();
	}

	/**
	 * CompletionAtNextMondayMidnight_BelongsToNextWindowOnly
	 */
	public function testCompletionAtNextMondayMidnight_BelongsToNextWindowOnly(): void {
		$houseId = $this->createHouse('week_owner', 'Window House');
		$this->addHouseMember($houseId, 'week_member', 'Member', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Boundary chore');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'week_member');

		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$houseUserId = $houseUserMapper->findByNextcloudUserId($houseId, 'week_member')->getId();

		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$calendar = \OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class);
		$tz = $calendar->getTimeZone($house);
		$weekStart = $calendar->weekStart(new \DateTime(), $tz);
		$weekEnd = (clone $weekStart)->modify('+7 days'); // next Monday 00:00:00, exclusive bound

		// Completion exactly AT the boundary: belongs to the NEXT window.
		$this->insertCompletion($roomTaskId, $houseUserId, $weekEnd->format('Y-m-d H:i:s'));

		$thisWeek = $this->completedTaskMapper->findCompletedRoomTaskIdsBetweenByHouse($houseId, $weekStart, $weekEnd);
		self::assertNotContains($roomTaskId, $thisWeek, 'a completion at the exclusive boundary must NOT count in this week');

		$nextWeek = $this->completedTaskMapper->findCompletedRoomTaskIdsBetweenByHouse(
			$houseId,
			$weekEnd,
			(clone $weekEnd)->modify('+7 days')
		);
		self::assertContains($roomTaskId, $nextWeek, 'a completion at the boundary counts in exactly the next window');
	}

	/**
	 * WeeklyGoalProgress_OffBoundaryCompletionsCounted_Unchanged
	 */
	public function testWeeklyGoalProgress_OffBoundaryCompletion_Counted(): void {
		$houseId = $this->createHouse('week_owner2', 'Window House 2');
		$this->addHouseMember($houseId, 'week_member2', 'Member 2', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$taskId = $this->createTask($houseId, 'Midweek chore');
		$roomTaskId = $this->assignTaskToRoom($taskId, $roomId, 'week_member2');

		$houseUserMapper = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class);
		$houseUserId = $houseUserMapper->findByNextcloudUserId($houseId, 'week_member2')->getId();

		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$calendar = \OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class);
		$tz = $calendar->getTimeZone($house);
		$weekStart = $calendar->weekStart(new \DateTime(), $tz);

		// Clearly inside the week (start + 2 days).
		$midWeek = (clone $weekStart)->modify('+2 days');
		$this->insertCompletion($roomTaskId, $houseUserId, $midWeek->format('Y-m-d H:i:s'));

		$houseService = \OC::$server->get(HouseService::class);
		$progress = $houseService->getWeeklyGoalProgress($houseId);

		self::assertSame(1, $progress['completedCount'], 'an off-boundary completion counts once');
		self::assertSame(1, $progress['dueCount'], 'dueSet = active-due ∪ completed');
	}

	/**
	 * Insert a completed_tasks row with an explicit completion instant.
	 */
	private function insertCompletion(int $roomTaskId, int $houseUserId, string $completedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('chorestrike_completed_tasks')
			->values([
				'room_task_id' => $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($houseUserId, IQueryBuilder::PARAM_INT),
				'completed_at' => $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_STR),
				'coins_earned' => $qb->createNamedParameter(10, IQueryBuilder::PARAM_INT),
				'was_combo' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_BOOL),
			]);
		$qb->executeStatement();

		$this->createdCompletionIds[] = (int)$this->db->lastInsertId('chorestrike_completed_tasks');
	}
}
