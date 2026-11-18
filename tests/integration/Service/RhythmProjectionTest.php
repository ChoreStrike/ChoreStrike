<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Service;

use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\WeeklyGoalService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Real-DB correction projection proofs. Late catch-up remains useful
 * actual work but does not become an on-time success for the day its
 * window ended. The cycle that is open today can still advance the run.
 *
 * Fixture model: daily tasks with distinct first dates each have ONE
 * current cycle with a one-day window (ADR-021 decision 1) — a task done
 * late moves on from the day of the work, so distinct tasks keep the
 * days independent.
 */
class RhythmProjectionTest extends IntegrationTestCase {
	private array $cleanupRoomTaskIds = [];
	private array $cleanupHouseIds = [];

	protected function tearDown(): void {
		foreach ($this->cleanupRoomTaskIds as $roomTaskId) {
			$completionIds = $this->completionIdsForRoomTask($roomTaskId);
			if ($completionIds !== []) {
				$intArray = IQueryBuilder::PARAM_INT_ARRAY;
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_work_shares')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_completion_revs')
					->where($qb->expr()->in('completion_id', $qb->createNamedParameter($completionIds, $intArray)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_completed_tasks')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_chk_progress')
				->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		foreach ($this->cleanupHouseIds as $houseId) {
			$walletIds = $this->walletIdsForHouse($houseId);
			if ($walletIds !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete('chorestrike_wallet_entries')
					->where($qb->expr()->in('wallet_id', $qb->createNamedParameter($walletIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('chorestrike_wallets')
				->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}

		parent::tearDown();
	}

	/**
	 * Streak_LateCatchUp_DoesNotManufactureHistoricalSuccess
	 *
	 * Three past one-day windows are completed late and one current window
	 * is completed on time; only the latter earns a planned-day step.
	 */
	public function testStreak_LateCatchUp_DoesNotManufactureHistoricalSuccess(): void {
		$f = $this->seedStreakFixture();
		$service = \OC::$server->get(CompletionService::class);

		foreach ($f['roomTaskIds'] as $roomTaskId) {
			$this->completeOccurrence($service, $f['houseId'], 'rp_owner', $roomTaskId);
		}

		$member = $this->houseUser($f['houseId'], 'rp_owner');
		self::assertSame(1, $member->getCurrentStreak());
		self::assertSame(1, $member->getLongestStreak());
	}

	/**
	 * Streak_UndoLateCatchUp_DoesNotRemoveIndependentOnTimeWork
	 *
	 * Undoing an older late catch-up never erases the independent on-time
	 * completion of today's cycle.
	 */
	public function testStreak_UndoLateCatchUp_DoesNotRemoveIndependentOnTimeWork(): void {
		$f = $this->seedStreakFixture();
		$service = \OC::$server->get(CompletionService::class);

		foreach ($f['roomTaskIds'] as $roomTaskId) {
			$this->completeOccurrence($service, $f['houseId'], 'rp_owner', $roomTaskId);
		}

		// Targeted undo of the -2 catch-up completion.
		$twoDaysBackKey = (new \DateTime('-2 days'))->format('Y-m-d');
		$completion = $this->completionByDueDate($f['roomTaskIds'], $twoDaysBackKey);
		$owner = $this->houseUser($f['houseId'], 'rp_owner');
		$service->undo($f['houseId'], $owner, (int)$completion['id'], $this->uuid(), (int)$completion['completion_version'], 'not_done');

		$member = $this->houseUser($f['houseId'], 'rp_owner');
		self::assertSame(1, $member->getCurrentStreak(), 'only today was performed inside its agreed window');
		self::assertSame(1, $member->getLongestStreak());
	}

	/**
	 * Streak_UndoOfToday_KeepsEarlierRun
	 *
	 * Undoing today's sole on-time completion leaves an open pending day.
	 * Older late catch-up work does not counterfeit a retained record.
	 */
	public function testStreak_UndoOfToday_KeepsEarlierRun(): void {
		$f = $this->seedStreakFixture();
		$service = \OC::$server->get(CompletionService::class);

		foreach ($f['roomTaskIds'] as $roomTaskId) {
			$this->completeOccurrence($service, $f['houseId'], 'rp_owner', $roomTaskId);
		}

		$this->undoLatestForRoomTask($service, $f['houseId'], 'rp_owner', $f['roomTaskIds'][3]);

		$member = $this->houseUser($f['houseId'], 'rp_owner');
		self::assertSame(0, $member->getCurrentStreak());
		self::assertSame(0, $member->getLongestStreak());
	}

	/**
	 * WeeklyGoal_PercentAndAchievedFollowTheValidCompletionSet
	 *
	 * Two tasks due this week: one completion gives 50%, the second gives
	 * 100% provisional progress; undoing the second returns to 50%.
	 */
	public function testWeeklyGoal_PercentAndAchievedFollowTheValidCompletionSet(): void {
		$houseId = $this->createHouse('rp_owner', 'Rhythm Weekly House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');
		$this->cleanupHouseIds[] = $houseId;

		$today = (new \DateTime())->format('Y-m-d 08:00:00');
		$roomTaskA = $this->assignTaskToRoom($this->createTask($houseId, 'Weekly A', '', 5, 2), $roomId, 'rp_owner', $today);
		$roomTaskB = $this->assignTaskToRoom($this->createTask($houseId, 'Weekly B', '', 5, 2), $roomId, 'rp_owner', $today);
		$this->cleanupRoomTaskIds[] = $roomTaskA;
		$this->cleanupRoomTaskIds[] = $roomTaskB;

		$service = \OC::$server->get(CompletionService::class);
		$weeklyGoal = \OC::$server->get(WeeklyGoalService::class);

		$this->completeOccurrence($service, $houseId, 'rp_owner', $roomTaskA);
		$progress = $weeklyGoal->getWeeklyGoalProgress($houseId);
		self::assertSame(2, $progress['dueCount']);
		self::assertSame(1, $progress['completedCount']);
		self::assertSame(50, $progress['percent']);
		self::assertFalse($progress['achieved'], '50% is below the 80% target');

		$this->completeOccurrence($service, $houseId, 'rp_owner', $roomTaskB);
		$progress = $weeklyGoal->getWeeklyGoalProgress($houseId);
		self::assertSame(100, $progress['percent']);
		self::assertTrue($progress['provisional']);
		self::assertFalse($progress['achieved'], 'the current planned week is not final yet');

		$this->undoLatestForRoomTask($service, $houseId, 'rp_owner', $roomTaskB);
		$progress = $weeklyGoal->getWeeklyGoalProgress($houseId);
		self::assertSame(1, $progress['completedCount']);
		self::assertSame(50, $progress['percent'], 'the undo re-evaluates the projection from the valid set');
		self::assertFalse($progress['achieved']);
	}

	/**
	 * WeeklyGoal_EmptyWeek_ReportsZeroActivity
	 */
	public function testWeeklyGoal_EmptyWeek_ReportsZeroActivity(): void {
		$houseId = $this->createHouse('rp_owner', 'Rhythm Empty House');
		$this->cleanupHouseIds[] = $houseId;

		$progress = \OC::$server->get(WeeklyGoalService::class)->getWeeklyGoalProgress($houseId);
		self::assertSame(0, $progress['dueCount']);
		self::assertSame(0, $progress['completedCount']);
		self::assertSame(0, $progress['percent']);
		self::assertFalse($progress['achieved'], 'no activity, no achievement');
	}

	// ========== Helpers ==========

	/**
	 * Four daily chores with first dates -3/-2/-1/today: each has one
	 * current cycle with a ONE-DAY window — a past day that was not done
	 * is a red, missed cycle (the correction scenario), while today's
	 * cycle stays open until its window closes.
	 *
	 * @return array{houseId: int, roomTaskIds: int[]}
	 */
	private function seedStreakFixture(): array {
		$houseId = $this->createHouse('rp_owner', 'Rhythm House');
		$floorId = $this->createFloor($houseId, 'Ground Floor', 1);
		$roomId = $this->createRoom($houseId, $floorId, 'Kitchen');

		$roomTaskIds = [];
		foreach ([3, 2, 1, 0] as $daysBack) {
			$taskId = $this->createTask($houseId, 'Rhythm chore ' . $daysBack, '', 5, 2);
			$qb = $this->db->getQueryBuilder();
			$qb->update('chorestrike_tasks')
				->set('frequency_type', $qb->createNamedParameter('daily'))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($taskId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$anchor = (new \DateTime("-{$daysBack} days"))->format('Y-m-d 08:00:00');
			$roomTaskIds[] = $this->assignTaskToRoom($taskId, $roomId, 'rp_owner', $anchor);
		}

		$this->cleanupRoomTaskIds = $roomTaskIds;
		$this->cleanupHouseIds[] = $houseId;

		return ['houseId' => $houseId, 'roomTaskIds' => $roomTaskIds];
	}

	private function houseUser(int $houseId, string $ncUserId): \OCA\ChoreStrike\Db\HouseUser {
		return \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $ncUserId);
	}

	private function uuid(): string {
		return sprintf('%08x-%04x-4%03x-8%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
	}

	/**
	 * The completion that closed the cycle whose window ended on the given
	 * day (the distinct first dates give a 1:1 mapping).
	 *
	 * @param int[] $roomTaskIds
	 * @return array<string, mixed>
	 */
	private function completionByDueDate(array $roomTaskIds, string $dueDate): array {
		$nextDay = (new \DateTimeImmutable($dueDate))->modify('+1 day')->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gte('window_end_on', $qb->createNamedParameter($dueDate)))
			->andWhere($qb->expr()->lt('window_end_on', $qb->createNamedParameter($nextDay)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "completion of the cycle ending {$dueDate} must exist");
		return $row;
	}

	/**
	 * @return array<int, int>
	 */
	private function completionIdsForRoomTask(int $roomTaskId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->eq('room_task_id', $qb->createNamedParameter($roomTaskId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return int[]
	 */
	private function walletIdsForHouse(int $houseId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('chorestrike_wallets')
			->where($qb->expr()->eq('house_id', $qb->createNamedParameter($houseId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
