<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CommandReceipt;
use OCA\ChoreStrike\Db\CommandReceiptMapper;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Service\ActivityEventService;
use OCA\ChoreStrike\Service\PostponeService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The events of the recent activity (CS-PLAN-091 WP-B3, D-091-52): an
 * absence shows only once it was acknowledged, the member filter runs in
 * the reads before their limit, and titles follow the visibility of each
 * room task (ADR-010).
 */
class ActivityEventServiceTest extends TestCase {
	private CommandReceiptMapper $receiptMapper;
	private CompletionRevisionMapper $revisionMapper;
	private PauseMapper $pauseMapper;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private HouseUserMapper $houseUserMapper;
	private TaskAccessPolicy $taskAccessPolicy;
	private ActivityEventService $service;
	private HouseUser $viewer;

	protected function setUp(): void {
		parent::setUp();
		$this->receiptMapper = $this->createMock(CommandReceiptMapper::class);
		$this->revisionMapper = $this->createMock(CompletionRevisionMapper::class);
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->service = new ActivityEventService(
			$this->receiptMapper,
			$this->revisionMapper,
			$this->pauseMapper,
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->houseUserMapper,
			$this->taskAccessPolicy,
			$l10n,
		);
		$this->viewer = new HouseUser();
		$this->viewer->setId(9);
		$this->viewer->setNextcloudUserId('viewer');
	}

	private function revision(int $group, int $revision, string $state, string $createdAt): Pause {
		$pause = new Pause();
		$pause->setPauseGroupId($group);
		$pause->setRevision($revision);
		$pause->setState($state);
		$pause->setScope(Pause::SCOPE_PERSONAL);
		$pause->setSubjectWalletId(70);
		$pause->setRequesterHouseUserId(7);
		$pause->setStartDate(new \DateTime('2026-11-20'));
		$pause->setEndDate(new \DateTime('2026-11-22'));
		$pause->setCreatedAt(new \DateTime($createdAt));
		return $pause;
	}

	/** @return list<string> */
	private function typesOf(array $events): array {
		return array_column($events, 'type');
	}

	public function testAbsence_ARequestThatWasNeverAcknowledged_ShowsNeitherWay(): void {
		// A retained request (before ADR-020), withdrawn: the requester's and the owner's alone.
		$this->pauseMapper->method('findByHouseId')->willReturn([
			$this->revision(1, 1, Pause::STATE_REQUESTED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_CANCELLED, '2026-11-17 09:00:00'),
		]);

		self::assertSame([], $this->service->eventsSince(1, new \DateTime('2026-11-01'), 50, null, $this->viewer));
	}

	public function testAbsence_ARequestAcknowledgedLater_ShowsWhenItWasAcknowledged(): void {
		$this->pauseMapper->method('findByHouseId')->willReturn([
			$this->revision(1, 1, Pause::STATE_REQUESTED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_ACKNOWLEDGED, '2026-11-17 09:00:00'),
			$this->revision(1, 3, Pause::STATE_CANCELLED, '2026-11-17 10:00:00'),
		]);

		$events = $this->service->eventsSince(1, new \DateTime('2026-11-01'), 50, null, $this->viewer);

		self::assertSame([ActivityEventService::TYPE_ABSENCE_REMOVED, ActivityEventService::TYPE_ABSENCE], $this->typesOf($events));
		self::assertSame((new \DateTime('2026-11-17 09:00:00'))->format('c'), $events[1]['at']);
	}

	public function testAbsence_AnEditOfAnAcknowledgedAbsence_IsNoSecondEntry(): void {
		$this->pauseMapper->method('findByHouseId')->willReturn([
			$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00'),
			$this->revision(1, 2, Pause::STATE_ACKNOWLEDGED, '2026-11-17 09:00:00'),
		]);

		self::assertSame([ActivityEventService::TYPE_ABSENCE], $this->typesOf($this->service->eventsSince(1, new \DateTime('2026-11-01'), 50, null, $this->viewer)));
	}

	public function testMemberFilter_RunsInTheReadsBeforeTheirLimit(): void {
		$this->receiptMapper->expects($this->once())->method('findByHouseActionSince')
			->with(1, PostponeService::ACTION, $this->isInstanceOf(\DateTimeInterface::class), 50, 7)
			->willReturn([]);
		$this->revisionMapper->expects($this->once())->method('findByHouseAndActionsSince')
			->with(1, $this->anything(), $this->isInstanceOf(\DateTimeInterface::class), 50, 7)
			->willReturn([]);
		$other = $this->revision(2, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00');
		$other->setRequesterHouseUserId(8);
		$this->pauseMapper->method('findByHouseId')->willReturn([$this->revision(1, 1, Pause::STATE_ACKNOWLEDGED, '2026-11-17 08:00:00'), $other]);

		$events = $this->service->eventsSince(1, new \DateTime('2026-11-01'), 50, 7, $this->viewer);

		self::assertSame([7], array_column($events, 'houseUserId'), 'only the filtered member\'s absence');
	}

	public function testTitles_FollowTheVisibilityOfEachRoomTask(): void {
		$receipts = [];
		foreach ([21, 22] as $index => $roomTaskId) {
			$receipt = new CommandReceipt();
			$receipt->setActorHouseUserId(7);
			$receipt->setResultRefs(['roomTaskId' => $roomTaskId, 'postponeCount' => 1]);
			$receipt->setCreatedAt(new \DateTime('2026-11-17 0' . ($index + 8) . ':00:00'));
			$receipts[] = $receipt;
		}
		$this->receiptMapper->method('findByHouseActionSince')->willReturn($receipts);
		$this->revisionMapper->method('findByHouseAndActionsSince')->willReturn([]);
		$this->pauseMapper->method('findByHouseId')->willReturn([]);
		// One private definition mounted in two rooms; the viewer is assigned to room task 21 only.
		$roomTasks = [];
		foreach ([21, 22] as $roomTaskId) {
			$roomTask = new RoomTask();
			$roomTask->setId($roomTaskId);
			$roomTask->setTaskId(5);
			$roomTasks[] = $roomTask;
		}
		$task = new Task();
		$task->setId(5);
		$task->setName('Secret');
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn($roomTasks);
		$this->taskMapper->method('findByIds')->willReturn([5 => $task]);
		$this->taskAccessPolicy->expects($this->once())->method('filterVisibleRoomTasks')
			->with($roomTasks, [5 => $task], $this->viewer)
			->willReturn([$roomTasks[0]]);

		$events = $this->service->eventsSince(1, new \DateTime('2026-11-01'), 50, null, $this->viewer);

		self::assertSame([22 => null, 21 => 'Secret'], array_column($events, 'taskTitle', 'roomTaskId'));
	}
}
