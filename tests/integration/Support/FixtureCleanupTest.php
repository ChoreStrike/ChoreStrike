<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Integration\Support;

use OCA\ChoreStrike\Db\FloorMapper;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTaskChecklistProgressMapper;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\TaskChecklistItemMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\RoomReplayService;
use OCA\ChoreStrike\Tests\Integration\IntegrationTestCase;
use OCP\IConfig;

/** RF-080-10: exercise the real base teardown, not a second cleanup implementation. */
class FixtureCleanupTest extends IntegrationTestCase {
	/** @dataProvider ownedSuiteCases */
	public function testTearDown_RealSuiteFixture_ReturnsEveryAppTableToItsPriorState(string $class, string $method): void {
		$foreign = $this->fixtureOwner();
		try {
			$foreign->populate();
			$before = $this->snapshot();
			(new $class($method))->runBare();
			self::assertSame($before, $this->snapshot(), $class . ' must clean its facts without resetting unrelated fixtures');
		} finally {
			$foreign->release();
		}
	}

	public static function ownedSuiteCases(): array {
		return [
			'completion and source-free journal' => [\OCA\ChoreStrike\Tests\Integration\Service\CompletionConcurrencyTest::class, 'testSameRoomCompletion_BlockedOnRoomLock_BuildsOnCommittedWinnerCleanliness'],
			'worker completion' => [\OCA\ChoreStrike\Tests\Integration\Service\ConcurrentCompletionTest::class, 'testTwoUsersSameRoomConcurrentCompletions_SumOfBothDeltasLand'],
			'house mutation' => [\OCA\ChoreStrike\Tests\Integration\Service\HouseMutationConcurrencyTest::class, 'testCompleteVsComplete_BehindHouseLock_CommittedWinnerYieldsOccurrenceConflict409'],
			'worker mount' => [\OCA\ChoreStrike\Tests\Integration\Service\TaskCreationConcurrencyTest::class, 'testConcurrentMountRace_ExactlyOneRow_LoserGets400'],
			'worker floor' => [\OCA\ChoreStrike\Tests\Integration\Setup\SetupApplyConcurrencyTest::class, 'testConcurrentApplyRace_ExactlyOneStructure_LoserGetsConflict'],
			'setup controller' => [\OCA\ChoreStrike\Tests\Integration\Setup\SetupApplyConcurrencyTest::class, 'testControllerMatrix_OwnerMemberNonMemberAndAlreadySetUp'],
			'setup aggregate' => [\OCA\ChoreStrike\Tests\Integration\Setup\HouseSetupIntegrationTest::class, 'testApplySetupCreatesFloorsRoomsTasksAndRoomTasks'],
			'family completion' => [\OCA\ChoreStrike\Tests\Integration\FamilyIntegrationTest::class, 'testFamilyTaskCompletionWorkflow'],
		];
	}

	public function testTearDown_ServiceFactsAndUnregisteredDescendants_LeaveOnlyUnrelatedFixtures(): void {
		$foreign = $this->fixtureOwner();
		$owned = $this->fixtureOwner();
		try {
			$foreignTask = $foreign->populate();
			$before = $this->snapshot();
			// Repeated cleanup also proves cleared tracking and safe empty teardown.
			for ($round = 0; $round < 2; $round++) {
				$owned->populate($foreignTask);
				$populated = $this->snapshot();
				foreach (['houses', 'floors', 'rooms', 'room_tasks', 'completed_tasks',
					'work_shares', 'completion_revs', 'room_events', 'wallet_entries',
					'wallets', 'chk_items', 'chk_progress', 'occurrences', 'occ_members',
					'schedule_revs', 'commands', 'task_assignees'] as $suffix) {
					$table = 'chorestrike_' . $suffix;
					self::assertGreaterThan(count($before[$table]), count($populated[$table]), $table . ' must actually be exercised');
				}
				$owned->release();
				self::assertSame($before, $this->snapshot(), 'No orphan facts; unrelated rows and shared definitions are unchanged');
				$owned->release();
				self::assertSame($before, $this->snapshot(), 'Empty cleanup cannot touch another fixture owner');
			}
		} finally {
			$owned->release();
			$foreign->release();
		}
	}

	private function snapshot(): array {
		$prefix = \OC::$server->get(IConfig::class)->getSystemValue('dbtableprefix', 'oc_');
		$rows = [];
		foreach ($this->db->createSchema()->getTables() as $table) {
			if (!str_starts_with($table->getName(), $prefix . 'chorestrike_')) {
				continue;
			}
			$name = substr($table->getName(), strlen($prefix));
			$result = $this->db->getQueryBuilder()->select('*')->from($name)->orderBy('id')->executeQuery();
			$rows[$name] = $result->fetchAll();
			$result->closeCursor();
		}
		ksort($rows);
		self::assertCount(30, $rows, 'Snapshot every app table, including rows without a house key');
		return $rows;
	}

	private function fixtureOwner(): IntegrationTestCase {
		return new class extends IntegrationTestCase {
			public function populate(?int $foreignTask = null): int {
				parent::setUp();
				$actor = 'fixture_cleanup_owner';
				$houseId = $this->createHouse($actor);
				// Mapper-created structure deliberately bypasses the base ID arrays,
				// just like committed worker inserts and setup-service materialization.
				$floor = \OC::$server->get(FloorMapper::class)->create($houseId, 'Fixture floor');
				$room = \OC::$server->get(RoomMapper::class)->create($floor->getId(), 'Fixture room');
				$taskId = $this->createTask($houseId, 'Fixture completion');
				$roomTaskId = $this->assignTaskToRoom($taskId, $room->getId(), $actor);
				$item = \OC::$server->get(TaskChecklistItemMapper::class)->create($taskId, 'Fixture checklist');
				[, $occurrenceKey] = $this->completionReceiptFor($roomTaskId);
				[$revision, $ordinal] = array_map('intval', explode(':', $occurrenceKey));
				$member = \OC::$server->get(HouseUserMapper::class)->findByNextcloudUserId($houseId, $actor);
				\OC::$server->get(TransactionExecutor::class)->run(static fn ()
					=> \OC::$server->get(RoomTaskChecklistProgressMapper::class)->toggleLocked($roomTaskId, $revision, $ordinal, $item->getId(), $member->getId())
				);
				$replay = \OC::$server->get(RoomReplayService::class);
				$replay->recordBaseline($room);
				$replay->recordManualDelta($room->getId(), 1, '00000000-0000-4000-8000-000000000001', new \DateTime());
				$service = \OC::$server->get(CompletionService::class);
				$this->completeOccurrence($service, $houseId, $actor, $roomTaskId);
				$this->undoLatestForRoomTask($service, $houseId, $actor, $roomTaskId);
				if ($foreignTask !== null) {
					$mount = \OC::$server->get(RoomTaskMapper::class)->create($room->getId(), $foreignTask, new \DateTime());
					$this->initializeScheduleForFixture($room->getId(), $mount->getId(), $foreignTask, $actor);
				}
				return $taskId;
			}

			public function release(): void {
				parent::tearDown();
			}
		};
	}
}
