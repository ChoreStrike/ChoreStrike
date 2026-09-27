<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Db;

use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Raw completion timestamps use half-open week windows (CS-PLAN-060 WP1
 * §3.6), while the weekly goal follows the agreed occurrence's planned
 * week and counts only valid work inside its saved window (C1e/C2g).
 */
class WeekWindowBoundaryTest extends IntegrationTestCase {
	private CompletedTaskMapper $completedTaskMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->completedTaskMapper = \OC::$server->get(CompletedTaskMapper::class);
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
	 * WeeklyGoalProgress_PlannedWeekCountsOnTimeButNotLateWork
	 */
	public function testWeeklyGoalProgress_PlannedWeekCountsOnTimeButNotLateWork(): void {
		$houseId = $this->createHouse('week_owner2', 'Window House 2');
		$this->addHouseMember($houseId, 'week_member2', 'Member 2', 'member');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$house = \OC::$server->get(\OCA\ChoreStrike\Db\HouseMapper::class)->find($houseId);
		$calendar = \OC::$server->get(\OCA\ChoreStrike\Service\CalendarService::class);
		$weekStart = $calendar->weekStart(new \DateTime(), $calendar->getTimeZone($house));
		$midWeek = (clone $weekStart)->modify('+2 days');
		$previousMidWeek = (clone $midWeek)->modify('-7 days');
		$onTimeTaskId = $this->createTask($houseId, 'This week chore');
		$onTimeRoomTaskId = $this->assignTaskToRoom($onTimeTaskId, $roomId, 'week_member2', $midWeek->format('Y-m-d H:i:s'));
		$lateTaskId = $this->createTask($houseId, 'Past week chore');
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_tasks')
			->set('recurrence_mode', $qb->createNamedParameter('once'))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($lateTaskId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$lateRoomTaskId = $this->assignTaskToRoom($lateTaskId, $roomId, 'week_member2', $previousMidWeek->format('Y-m-d H:i:s'));
		$houseUserId = \OC::$server->get(\OCA\ChoreStrike\Db\HouseUserMapper::class)
			->findByNextcloudUserId($houseId, 'week_member2')->getId();

		$this->recordOccurrenceCompletion($onTimeRoomTaskId, $houseUserId, $midWeek, $midWeek->format('Y-m-d'), 'agreed');
		$this->recordOccurrenceCompletion($lateRoomTaskId, $houseUserId, $midWeek, $previousMidWeek->format('Y-m-d'), 'late');

		$goal = \OC::$server->get(WeeklyGoalService::class);
		$reference = (clone $midWeek)->modify('+1 day');
		$current = $goal->progressForWeek($house, $reference);
		$previous = $goal->progressForPlannedWeek($house, (clone $weekStart)->modify('-7 days')->format('Y-m-d'), $reference);
		self::assertSame(1, $current['dueCount'], 'only this week\'s agreed occurrence is due this week');
		self::assertSame(1, $current['completedCount'], 'its off-boundary, on-time work counts once');
		self::assertSame(1, $previous['dueCount'], 'the older occurrence stays in its planned week');
		self::assertSame(0, $previous['completedCount'], 'late work does not repair last week or move to this week');
		self::assertTrue($current['provisional']);
		self::assertFalse($current['achieved'], 'the current week has not finalized');
	}

	private function recordOccurrenceCompletion(int $roomTaskId, int $houseUserId, \DateTime $at, string $plannedKey, string $class): void {
		$mapper = \OC::$server->get(OccurrenceMapper::class);
		$occurrence = null;
		foreach ($mapper->findByRoomTask($roomTaskId) as $row) {
			if ($row->getPlannedDate()->format('Y-m-d') === $plannedKey) {
				$occurrence = $row;
				break;
			}
		}
		self::assertNotNull($occurrence, 'the saved planned occurrence must exist');
		$completionId = $this->insertCompletionRow($roomTaskId, $houseUserId, $at->format('Y-m-d 12:00:00'), 3, $plannedKey);
		$qb = $this->db->getQueryBuilder();
		$qb->update('chorestrike_completed_tasks')
			->set('completion_class', $qb->createNamedParameter($class))
			->set('schedule_rev_id', $qb->createNamedParameter($occurrence->getScheduleRevId(), IQueryBuilder::PARAM_INT))
			->set('occurrence_ordinal', $qb->createNamedParameter($occurrence->getOrdinal(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($completionId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
		$occurrence->setCompletionId($completionId);
		$mapper->update($occurrence);
	}

	/**
	 * Insert a completed_tasks row with an explicit completion instant.
	 */
	private function insertCompletion(int $roomTaskId, int $houseUserId, string $completedAt): void {
		$this->insertCompletionRow($roomTaskId, $houseUserId, $completedAt);
	}
}
