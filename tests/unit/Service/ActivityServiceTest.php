<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ActivityService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ActivityService (CS-PLAN-040 / FEAT-UX-016)
 */
class ActivityServiceTest extends TestCase {
	private CompletedTaskMapper $completedTaskMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseUserMapper $houseUserMapper;
	private ActivityService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new ActivityService(
			$this->completedTaskMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseUserMapper,
			$l10n,
		);
	}

	private function makeCompletion(int $id, int $userId, int $roomTaskId): CompletedTask {
		$completion = new CompletedTask();
		$completion->setId($id);
		$completion->setUserId($userId);
		$completion->setRoomTaskId($roomTaskId);
		$completion->setCompletedAt(new \DateTime('2026-08-20 10:00:00'));
		$completion->setCoinsEarned(12);
		$completion->setWasCombo(true);

		return $completion;
	}

	private function makeRoomTask(int $id, int $taskId, int $roomId): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId($id);
		$roomTask->setTaskId($taskId);
		$roomTask->setRoomId($roomId);

		return $roomTask;
	}

	/**
	 * GetActivity_EnrichesCompletionsInBatch
	 *
	 * Titles, names, energy cost, and display names come from batch IN-map
	 * fetches; each item carries actor + task + room + reward fields.
	 */
	public function testGetActivity_EnrichesCompletionsInBatch(): void {
		$c1 = $this->makeCompletion(101, 7, 21);
		$c2 = $this->makeCompletion(102, 8, 22);

		$rt1 = $this->makeRoomTask(21, 5, 3);
		$rt2 = $this->makeRoomTask(22, 6, 4);

		$task5 = new Task();
		$task5->setId(5);
		$task5->setName('Vacuum living room');
		$task5->setEnergyCost(3);

		$task6 = new Task();
		$task6->setId(6);
		$task6->setName('Clean bathroom');
		$task6->setEnergyCost(5);

		$room3 = new Room();
		$room3->setId(3);
		$room3->setName('Living Room');

		$room4 = new Room();
		$room4->setId(4);
		$room4->setName('Bathroom');

		$user7 = new HouseUser();
		$user7->setId(7);
		$user7->setDisplayName('Alice');

		$user8 = new HouseUser();
		$user8->setId(8);
		$user8->setDisplayName('Bob');

		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn([$c1, $c2]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(2);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$rt1, $rt2]);
		$this->taskMapper->method('findByIds')->willReturn([5 => $task5, 6 => $task6]);
		$this->roomMapper->method('findByIds')->willReturn([3 => $room3, 4 => $room4]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user7, $user8]);

		$result = $this->service->getActivity(1);

		self::assertSame(2, $result['total']);
		self::assertCount(2, $result['completions']);

		$first = $result['completions'][0];
		self::assertSame(101, $first['id']);
		self::assertSame('Alice', $first['displayName']);
		self::assertSame(7, $first['houseUserId']);
		self::assertSame('Vacuum living room', $first['taskTitle']);
		self::assertSame(3, $first['energyCost']);
		self::assertSame('Living Room', $first['roomName']);
		self::assertTrue($first['wasCombo']);
		self::assertSame(12, $first['coinsEarned']);

		self::assertSame('Bob', $result['completions'][1]['displayName']);
		self::assertSame('Bathroom', $result['completions'][1]['roomName']);
	}

	/**
	 * GetActivity_DanglingReferences_FallsBackToNull
	 *
	 * Single-task deletion does not cascade its completions and there is no
	 * FK cascade — a completion whose room task (or task definition) is gone
	 * must serialize with null references instead of erroring.
	 */
	public function testGetActivity_DanglingReferences_FallBackToNull(): void {
		$dangling = $this->makeCompletion(201, 7, 99);

		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn([$dangling]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(1);
		// Skip-on-missing: the dangling room task id is absent from the map.
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([]);

		$user7 = new HouseUser();
		$user7->setId(7);
		$user7->setDisplayName('Alice');
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user7]);

		$result = $this->service->getActivity(1);

		self::assertCount(1, $result['completions']);
		$item = $result['completions'][0];
		self::assertSame('Alice', $item['displayName']);
		self::assertNull($item['taskId']);
		self::assertNull($item['taskTitle']);
		self::assertNull($item['energyCost']);
		self::assertNull($item['roomId']);
		self::assertNull($item['roomName']);
	}

	/**
	 * GetActivity_EmptyPeriod_ReturnsEmptyList
	 */
	public function testGetActivity_EmptyPeriod_ReturnsEmptyList(): void {
		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn([]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(0);

		$result = $this->service->getActivity(1);

		self::assertSame([], $result['completions']);
		self::assertSame(0, $result['total']);
	}

	/**
	 * GetActivity_ClampsPeriodAndLimit
	 *
	 * days > 90 → 90, limit > 200 → 200; days < 1 → 1, limit < 1 → 1.
	 */
	public function testGetActivity_ClampsPeriodAndLimit(): void {
		$capturedLimit = null;
		$this->completedTaskMapper
			->expects($this->once())
			->method('findActivityBetweenByHouse')
			->with(
				1,
				$this->callback(function (\DateTimeInterface $start): bool {
					// 90 days window ending "now".
					return $start <= new \DateTimeImmutable();
				}),
				$this->anything(),
				$this->callback(function ($limit) use (&$capturedLimit): bool {
					$capturedLimit = $limit;

					return true;
				}),
				null
			)
			->willReturn([]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(0);

		$result = $this->service->getActivity(1, 500, 1000);

		self::assertSame(ActivityService::MAX_LIMIT, $capturedLimit);
		self::assertSame([], $result['completions']);
	}

	/**
	 * GetActivity_PerUserIdFilter_ResolvesMemberAndScopesQuery
	 */
	public function testGetActivity_PerUserFilter_ScopesQuery(): void {
		$member = new HouseUser();
		$member->setId(42);
		$member->setNextcloudUserId('alice');

		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($member);

		$capturedFilter = null;
		$this->completedTaskMapper
			->expects($this->once())
			->method('findActivityBetweenByHouse')
			->with(
				1,
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->callback(function ($filter) use (&$capturedFilter): bool {
					$capturedFilter = $filter;

					return true;
				})
			)
			->willReturn([]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(0);

		$this->service->getActivity(1, 30, 50, 'alice');

		self::assertSame(42, $capturedFilter);
	}

	/**
	 * GetActivity_NonMemberTarget_ThrowsNotFound
	 */
	public function testGetActivity_NonMemberTarget_ThrowsNotFound(): void {
		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no row'));

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('User is not a member of this house');

		$this->service->getActivity(1, 30, 50, 'outsider');
	}
}
