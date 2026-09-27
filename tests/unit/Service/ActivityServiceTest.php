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
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\ActivityService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
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
	private TaskAssigneeMapper $taskAssigneeMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private WorkShareMapper $workShareMapper;
	private ActivityService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->workShareMapper = $this->createMock(WorkShareMapper::class);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->service = new ActivityService(
			$this->completedTaskMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseUserMapper,
			$this->taskAssigneeMapper,
			$this->taskAccessPolicy,
			$this->workShareMapper,
			$l10n,
		);
	}

	private function makeViewer(): HouseUser {
		$viewer = new HouseUser();
		$viewer->setNextcloudUserId('viewer');
		$viewer->setRole('member');
		return $viewer;
	}

	private function makeCompletion(int $id, int $userId, int $roomTaskId): CompletedTask {
		$completion = new CompletedTask();
		$completion->setId($id);
		$completion->setUserId($userId);
		$completion->setRoomTaskId($roomTaskId);
		$completion->setCompletedAt(new \DateTime('2026-08-20 10:00:00'));
		$completion->setTotalEnergy(3);
		// WP3 frozen-fact columns carry no entity defaults — the writer
		// always sets them; the test fixture mirrors the writer contract.
		$completion->setRateMinor(1000);
		$completion->setCompletionVersion(0);
		$completion->setAllocationRevision(1);

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
		$user7->setNextcloudUserId('alice');
		$user7->setWalletId(77);

		$user8 = new HouseUser();
		$user8->setId(8);
		$user8->setDisplayName('Bob');
		$user8->setNextcloudUserId('bob');
		$user8->setWalletId(88);

		$share = new WorkShare();
		$share->setCompletionId(101);
		$share->setWalletId(77);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);

		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn([$c1, $c2]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(2);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$rt1, $rt2]);
		$this->taskMapper->method('findByIds')->willReturn([5 => $task5, 6 => $task6]);
		$this->roomMapper->method('findByIds')->willReturn([3 => $room3, 4 => $room4]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user7, $user8]);
		$this->houseUserMapper->expects($this->never())->method('findByWalletId');
		$this->workShareMapper->expects($this->once())->method('findSelectedByCompletionIds')
			->with([101, 102])->willReturn([101 => [$share]]);
		$this->workShareMapper->expects($this->never())->method('findByCompletionAndRevision');
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		$result = $this->service->getActivity(1, ActivityService::DEFAULT_DAYS, ActivityService::DEFAULT_LIMIT, null, $this->makeViewer());

		self::assertSame(2, $result['total']);
		self::assertCount(2, $result['completions']);

		$first = $result['completions'][0];
		self::assertSame(101, $first['id']);
		self::assertSame('Alice', $first['displayName']);
		self::assertSame(7, $first['houseUserId']);
		self::assertSame('Vacuum living room', $first['taskTitle']);
		self::assertSame(3, $first['energyCost']);
		self::assertSame('Living Room', $first['roomName']);
		// WP3: frozen work facts instead of reward outputs.
		self::assertSame(3, $first['totalEnergy']);
		self::assertSame([
			['userId' => 'alice', 'displayName' => 'Alice', 'sharePct' => 100, 'energyMinor' => 300],
		], $first['allocation']);

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

		$result = $this->service->getActivity(1, ActivityService::DEFAULT_DAYS, ActivityService::DEFAULT_LIMIT, null, $this->makeViewer());

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

		$result = $this->service->getActivity(1, ActivityService::DEFAULT_DAYS, ActivityService::DEFAULT_LIMIT, null, $this->makeViewer());

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

		$result = $this->service->getActivity(1, 500, 1000, null, $this->makeViewer());

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

		$this->service->getActivity(1, 30, 50, 'alice', $this->makeViewer());

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

		$this->service->getActivity(1, 30, 50, 'outsider', $this->makeViewer());
	}

	/**
	 * GetActivity_InvisibleTaskContent_RedactedToNull
	 *
	 * CS-PLAN-061 review fix: rows stay house-scoped (D9), but the title
	 * and selected work facts of a task the viewer cannot see are redacted.
	 */
	public function testGetActivity_InvisibleTaskContent_RedactedToNull(): void {
		$completion = $this->makeCompletion(301, 7, 31);
		$completion->setTotalEnergy(12);
		$roomTask = $this->makeRoomTask(31, 9, 3);

		$privateTask = new Task();
		$privateTask->setId(9);
		$privateTask->setName('Secret project');
		$privateTask->setEnergyCost(4);
		$privateTask->setVisibilityType(Task::VISIBILITY_PRIVATE);

		$room = new Room();
		$room->setId(3);
		$room->setName('Kitchen');

		$user7 = new HouseUser();
		$user7->setId(7);
		$user7->setDisplayName('Alice');
		$user7->setNextcloudUserId('alice');
		$user7->setWalletId(77);

		$share = new WorkShare();
		$share->setCompletionId(301);
		$share->setWalletId(77);
		$share->setSharePct(100);
		$share->setEnergyMinor(1200);

		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn([$completion]);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(1);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('findByIds')->willReturn([9 => $privateTask]);
		$this->roomMapper->method('findByIds')->willReturn([3 => $room]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user7]);
		$this->workShareMapper->method('findSelectedByCompletionIds')->willReturn([301 => [$share]]);
		$this->taskAccessPolicy->method('canView')->willReturnCallback(
			static fn (Task $task, HouseUser $viewer): bool => $viewer->getId() === 7
		);

		$viewer = $this->makeViewer();
		$viewer->setId(8);
		$result = $this->service->getActivity(1, 30, 50, null, $viewer);

		$item = $result['completions'][0];
		self::assertSame('Alice', $item['displayName'], 'the aggregate row itself stays');
		self::assertSame(9, $item['taskId']);
		self::assertNull($item['taskTitle'], 'invisible task names are redacted');
		self::assertNull($item['energyCost'], 'invisible task energy is redacted');
		self::assertNull($item['totalEnergy'], 'frozen effort is private task content');
		self::assertSame([], $item['allocation'], 'selected participants and shares must not leak');
		self::assertFalse($item['canCorrect']);
		self::assertSame('Kitchen', $item['roomName'], 'rooms are structural and stay visible');

		$owner = $this->makeViewer();
		$owner->setId(9);
		$owner->setRole('owner');
		$ownerItem = $this->service->getActivity(1, 30, 50, null, $owner)['completions'][0];
		self::assertNull($ownerItem['totalEnergy'], 'owner status does not expose another member\'s private task');
		self::assertSame([], $ownerItem['allocation']);
		self::assertFalse($ownerItem['canCorrect'], 'an owner who cannot view the task cannot correct it');

		$creator = $this->makeViewer();
		$creator->setId(7);
		$creatorItem = $this->service->getActivity(1, 30, 50, null, $creator)['completions'][0];
		self::assertSame(12, $creatorItem['totalEnergy']);
		self::assertSame('alice', $creatorItem['allocation'][0]['userId']);
		self::assertSame(100, $creatorItem['allocation'][0]['sharePct']);
		self::assertTrue($creatorItem['canCorrect']);
	}

	/**
	 * GetActivity_MaxPage_BatchesSharesAndMembershipReads
	 */
	public function testGetActivity_MaxPage_BatchesSharesAndMembershipReads(): void {
		$completions = [];
		$shares = [];
		for ($id = 1; $id <= ActivityService::MAX_LIMIT; $id++) {
			$completions[] = $this->makeCompletion($id, 7, 21);
			$share = new WorkShare();
			$share->setCompletionId($id);
			$share->setWalletId(77);
			$share->setSharePct(100);
			$share->setEnergyMinor(300);
			$shares[$id] = [$share];
		}
		$roomTask = $this->makeRoomTask(21, 9, 3);
		$task = new Task();
		$task->setId(9);
		$task->setName('Shared task');
		$member = new HouseUser();
		$member->setId(7);
		$member->setWalletId(77);
		$member->setNextcloudUserId('alice');
		$member->setDisplayName('Alice');

		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturn($completions);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(200);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('findByIds')->willReturn([9 => $task]);
		$this->houseUserMapper->expects($this->once())->method('findByHouseId')->with(1)->willReturn([$member]);
		$this->houseUserMapper->expects($this->never())->method('findByWalletId');
		$this->workShareMapper->expects($this->once())->method('findSelectedByCompletionIds')
			->with(range(1, 200))->willReturn($shares);
		$this->workShareMapper->expects($this->never())->method('findByCompletionAndRevision');
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		$items = $this->service->getActivity(1, 30, 200, null, $this->makeViewer())['completions'];
		self::assertCount(200, $items);
		self::assertSame('alice', $items[199]['allocation'][0]['userId']);
		self::assertSame(300, $items[199]['allocation'][0]['energyMinor']);
	}

	/**
	 * GetActivity_ViewerScopedCorrectionAuthority
	 *
	 * WP3 §3.6: the feed exposes the correction action through the
	 * viewer-scoped `canCorrect` — completer or owner on a still-valid
	 * completion; participation alone and undone rows never qualify.
	 */
	public function testGetActivity_ViewerScopedCorrectionAuthority(): void {
		$completion = $this->makeCompletion(401, 7, 41);
		$roomTask = $this->makeRoomTask(41, 9, 3);

		$task = new Task();
		$task->setId(9);
		$task->setName('Vacuum');
		$task->setEnergyCost(3);

		$room = new Room();
		$room->setId(3);
		$room->setName('Kitchen');

		$user7 = new HouseUser();
		$user7->setId(7);
		$user7->setDisplayName('Alice');

		$rows = [$completion];
		$this->completedTaskMapper->method('findActivityBetweenByHouse')->willReturnCallback(
			static function () use (&$rows): array {
				return $rows;
			}
		);
		$this->completedTaskMapper->method('countActivityByHouse')->willReturn(1);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->taskMapper->method('findByIds')->willReturn([9 => $task]);
		$this->roomMapper->method('findByIds')->willReturn([3 => $room]);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$user7]);
		$this->taskAccessPolicy->method('canView')->willReturn(true);

		// A member who is neither completer nor owner cannot correct.
		$member = $this->makeViewer();
		$member->setId(9);
		$result = $this->service->getActivity(1, 30, 50, null, $member);
		self::assertFalse($result['completions'][0]['canCorrect'], 'participation alone is no correction authority');

		// The completer (house-user id match) can correct.
		$completer = $this->makeViewer();
		$completer->setId(7);
		$result = $this->service->getActivity(1, 30, 50, null, $completer);
		self::assertTrue($result['completions'][0]['canCorrect']);

		// An owner can correct any live completion.
		$owner = $this->makeViewer();
		$owner->setId(9);
		$owner->setRole('owner');
		$result = $this->service->getActivity(1, 30, 50, null, $owner);
		self::assertTrue($result['completions'][0]['canCorrect']);

		// An undone completion is history — not correctable anymore.
		$undone = $this->makeCompletion(402, 7, 41);
		$undone->setUndoneAt(new \DateTime('2026-08-21 10:00:00'));
		$rows = [$undone];
		$result = $this->service->getActivity(1, 30, 50, null, $owner);
		self::assertFalse($result['completions'][0]['canCorrect'], 'an undone target is terminal');
	}
}
