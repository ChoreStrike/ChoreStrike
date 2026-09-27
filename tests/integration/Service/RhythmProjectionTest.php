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
 * actual work but does not become an on-time success for its past planned
 * day. The currently due occurrence can still advance the run.
 *
 * Fixture model: weekly dynamic tasks anchored on distinct past days
 * materialize exactly ONE pending occurrence at their anchor — the
 * canonical selection resolves it as the catch-up target. (A daily rule
 * would open the frontier at a single pending occurrence too, but
 * completing a task's occurrence COVERS the older missed chain of the
 * same task, so distinct tasks keep the planned days independent.)
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
	 * completion of today's planned occurrence.
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
	 * Four daily dynamic chores anchored -3/-2/-1/today: each materializes
	 * exactly one pending occurrence at its anchor with a ONE-DAY window —
	 * an unfulfilled past day is a miss (the correction scenario), while
	 * today's occurrence stays pending-neutral until its window closes.
	 *
	 * @return array{houseId: int, roomTaskIds: int[]}
	 */
	private function seedStreakFixture(): array {
		// See the class docblock: weekly windows stay open for the whole
		// interval (a past day is pending, never a miss), so the streak
		// fixture uses DAILY dynamic chores with one-day windows.
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
	 * The completion whose recorded occurrence is due on the given day
	 * (the anchored weekly tasks give a 1:1 planned-day mapping).
	 *
	 * @param int[] $roomTaskIds
	 * @return array<string, mixed>
	 */
	private function completionByDueDate(array $roomTaskIds, string $dueDate): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('chorestrike_completed_tasks')
			->where($qb->expr()->in('room_task_id', $qb->createNamedParameter($roomTaskIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('occurrence_due', $qb->createNamedParameter($dueDate)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		self::assertIsArray($row, "completion for occurrence_due {$dueDate} must exist");
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
