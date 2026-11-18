<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevision;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskAssigneeMapper;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Db\WorkShare;
use OCA\ChoreStrike\Db\WorkShareMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AppLogger;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\ChecklistService;
use OCA\ChoreStrike\Service\CoinMultiplierService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\CompletionAccess;
use OCA\ChoreStrike\Service\CompletionAuthority;
use OCA\ChoreStrike\Service\CompletionDateCommand;
use OCA\ChoreStrike\Service\CompletionDateCorrection;
use OCA\ChoreStrike\Service\CompletionEvidence;
use OCA\ChoreStrike\Service\CompletionLocks;
use OCA\ChoreStrike\Service\CompletionPostCommitService;
use OCA\ChoreStrike\Service\CompletionReadModel;
use OCA\ChoreStrike\Service\CompletionRecognition;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\CreditPolicy;
use OCA\ChoreStrike\Service\CurrentCycle;
use OCA\ChoreStrike\Service\CycleService;
use OCA\ChoreStrike\Service\EffortAllocationService;
use OCA\ChoreStrike\Service\ParticipantAllocation;
use OCA\ChoreStrike\Service\RecurrenceCalculator;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\RoomReplayService;
use OCA\ChoreStrike\Service\RoomTaskReadService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\TaskResponsibility;
use OCA\ChoreStrike\Service\WalletService;
use OCA\ChoreStrike\Service\WindowService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the completion, undo, attribution and date-correction
 * aggregates and their read model (CS-PLAN-070 §3.3, ADR-021), wired from
 * their real collaborators over mocked mappers: frozen evidence writes,
 * exact wallet credit, room
 * journal, house state version bump, terminal-before-version undo
 * ordering, the one-time reversal of the latest selected allocation and
 * the exact union-delta attribution. Cycle targeting and closing delegate
 * to a mocked CycleService (real behavior is covered by the Docker
 * integration suites).
 */
class CompletionServiceTest extends TestCase {
	private IDBConnection $db;
	private RoomTaskMapper $roomTaskMapper;
	private TaskMapper $taskMapper;
	private RoomMapper $roomMapper;
	private HouseMapper $houseMapper;
	private HouseUserMapper $houseUserMapper;
	private WalletMapper $walletMapper;
	private TaskAssigneeMapper $taskAssigneeMapper;
	private CompletedTaskMapper $completedTaskMapper;
	private WorkShareMapper $workShareMapper;
	private CompletionRevisionMapper $completionRevisionMapper;
	private CompletionPostCommitService $postCommitService;
	private WalletService $walletService;
	private RoomReplayService $roomReplayService;
	private RhythmService $rhythmService;
	private RewardConfigService $configService;
	private CalendarService $calendarService;
	private TaskAccessPolicy $taskAccessPolicy;
	private CycleService $cycleService;
	private RoomTaskReadService $roomTaskReads;
	private CommandService $commandService;
	private ChecklistService $checklists;
	private AppLogger $appLogger;
	private CoinMultiplierService $multipliers;
	private CompletionService $service;
	private CompletionDateCommand $dateCommand;
	private CompletionReadModel $readModel;
	/** What a completion freezes (test seam). */
	private array $frozen = ['factors' => [], 'percent' => 100];

	private HouseUser $actor;
	private Wallet $wallet;
	private RoomTask $roomTask;
	private Room $room;
	private Task $task;
	private CurrentCycle $cycle;
	private House $house;
	/** The completion row `CompletedTaskMapper::find` resolves (test seam). */
	private ?CompletedTask $completionToFind = null;
	/** House-user members resolvable by id (test seam). */
	private array $membersById = [];
	/** The live membership list of the house (test seam). */
	private array $houseMembers = [];
	/** Wallets resolvable by id (test seam). */
	private array $walletsById = [];
	/** The room task's assignees (test seam). */
	private array $assigneeUserIds = ['mom'];
	private array $lockedMemberIds = [];
	private ?HouseUser $liveActor;
	private ?\Closure $beforeCommand = null;
	private bool $receiptReplay = false;
	private bool $visibilityRevoked = false;
	/** User ids of members who may not see the task (test seam). */
	private array $invisibleTo = [];
	/** The latest valid completion of the room task (test seam). */
	private ?int $latestValidId = 9;
	/** The valid entries next to the corrected one (test seam). */
	private array $neighbours = ['previous' => null, 'next' => null];
	private array $receiptResult = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->taskAssigneeMapper = $this->createMock(TaskAssigneeMapper::class);
		$this->completedTaskMapper = $this->createMock(CompletedTaskMapper::class);
		$this->workShareMapper = $this->createMock(WorkShareMapper::class);
		$this->completionRevisionMapper = $this->createMock(CompletionRevisionMapper::class);
		$this->postCommitService = $this->createMock(CompletionPostCommitService::class);
		$this->walletService = $this->createMock(WalletService::class);
		// WP7: postEntries now returns the written entry ids (map); the
		// completion aggregate ignores them.
		$this->walletService->method('postEntries')->willReturn([]);
		$this->roomReplayService = $this->createMock(RoomReplayService::class);
		$this->rhythmService = $this->createMock(RhythmService::class);
		$this->configService = $this->createMock(RewardConfigService::class);
		$this->calendarService = $this->createMock(CalendarService::class);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->cycleService = $this->createMock(CycleService::class);
		$this->roomTaskReads = $this->createMock(RoomTaskReadService::class);
		$this->assigneeUserIds = ['mom'];
		$this->commandService = $this->createMock(CommandService::class);
		$this->checklists = $this->createMock(ChecklistService::class);
		$this->appLogger = $this->createMock(AppLogger::class);

		// The command wrapper runs the aggregate callback directly (the
		// transaction/lock discipline is covered by the Docker suites).
		$this->commandService->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorHouseUserId, string $uuid, string $action, array $payload, callable $fn, ?callable $authorizeUnderLock = null): array {
				if ($this->beforeCommand !== null) {
					($this->beforeCommand)();
				}
				// As CommandService: the guard runs under the house lock on
				// execution and replay alike, before the receipt lookup.
				if ($authorizeUnderLock !== null) {
					$authorizeUnderLock($houseId, $actorHouseUserId);
				}
				if ($this->receiptReplay) {
					return ['result' => ['completionId' => 9], 'receipt' => null, 'replay' => true];
				}
				$this->receiptResult = (new \OCA\ChoreStrike\Db\TransactionExecutor($this->db))->run($fn);
				return [
					'result' => $this->receiptResult,
					'receipt' => null,
					'replay' => false,
				];
			}
		);

		$this->house = new House();
		$this->house->setId(1);
		$this->house->setStateVersion(4);
		$this->houseMapper->method('find')->willReturn($this->house);
		$this->houseMapper->method('findForUpdate')->willReturnCallback(function () {
			return $this->house;
		});
		$this->calendarService->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$this->calendarService->method('dayKey')->willReturnCallback(
			static fn (\DateTimeInterface $at, \DateTimeZone $tz): string => \DateTime::createFromInterface($at)->setTimezone($tz)->format('Y-m-d')
		);
		$this->calendarService->method('isDayKey')->willReturn(true);

		$this->actor = new HouseUser();
		$this->actor->setId(7);
		$this->actor->setHouseId(1);
		$this->actor->setNextcloudUserId('mom');
		$this->actor->setWalletId(500);
		$this->liveActor = $this->actor;
		$this->taskAccessPolicy->method('assertCanView')->willReturnCallback(function (): void {
			if ($this->visibilityRevoked) {
				throw new NotFoundException('Task not found');
			}
		});
		$this->taskAccessPolicy->method('canView')->willReturnCallback(
			fn (Task $task, HouseUser $member): bool => !in_array($member->getNextcloudUserId(), $this->invisibleTo, true)
		);
		$this->completedTaskMapper->method('findLatestValidIdsByRoomTasks')->willReturnCallback(
			fn (): array => $this->latestValidId === null ? [] : [11 => $this->latestValidId]
		);
		$this->completedTaskMapper->method('findValidNeighbours')->willReturnCallback(fn (): array => $this->neighbours);
		$this->taskAssigneeMapper->method('findUserIdsByRoomTasks')->willReturnCallback(fn (): array => [11 => $this->assigneeUserIds]);

		$this->wallet = new Wallet();
		$this->wallet->setId(500);
		$this->wallet->setHouseId(1);
		$this->wallet->setNextcloudUserId('mom');

		$this->task = new Task();
		$this->task->setId(5);
		$this->task->setName('Vacuum');
		$this->task->setEnergyCost(3);

		$this->room = new Room();
		$this->room->setId(10);
		$this->room->setFloorId(2);
		$this->room->setName('Living Room');

		$this->roomTask = new RoomTask();
		$this->roomTask->setId(11);
		$this->roomTask->setRoomId(10);
		$this->roomTask->setTaskId(5);
		$this->roomTask->setIsActive(true);

		// The shown cycle: a one-day window far ahead, so today's work is early (on time).
		$this->cycle = new CurrentCycle('d2099-01-05', '2099-01-05', '2099-01-04', '2099-01-06');

		$this->postCommitService->method('runForCompletion')->willReturn(['weeklyGoal' => [], 'degraded' => false]);
		$this->postCommitService->method('runForCorrection')->willReturn(['weeklyGoal' => [], 'degraded' => false]);
		$this->configService->method('getTitlesEnabled')->willReturn(false);
		$this->roomReplayService->method('resyncRoomCache')->willReturnCallback(
			fn (Room $room): Room => $room
		);

		$this->multipliers = $this->createMock(CoinMultiplierService::class);
		$this->multipliers->method('freeze')->willReturnCallback(fn (): array => $this->frozen);

		// The real collaborators over the mocked mappers.
		$l10n = $this->createIdentityL10n();
		$authority = new CompletionAuthority($this->completedTaskMapper, $l10n);
		$access = new CompletionAccess(
			$this->completedTaskMapper, $this->roomTaskMapper, $this->taskMapper, $this->roomMapper, $this->houseUserMapper,
			$this->taskAssigneeMapper, $this->taskAccessPolicy, $authority, $l10n
		);
		$creditPolicy = new CreditPolicy($this->taskAccessPolicy, $this->workShareMapper, $this->houseUserMapper, $l10n);
		$locks = new CompletionLocks($this->houseUserMapper, $this->walletMapper, $this->walletService, $creditPolicy, $l10n);
		$allocationService = new EffortAllocationService();
		$evidence = new CompletionEvidence($this->workShareMapper, $this->completionRevisionMapper, $allocationService);
		$recognition = new CompletionRecognition($this->houseUserMapper, $this->houseMapper, $this->rhythmService, null, $this->configService);
		$responsibility = new TaskResponsibility($this->taskAssigneeMapper, $this->houseUserMapper);
		$this->readModel = new CompletionReadModel(
			$access, $this->completedTaskMapper, $this->workShareMapper, $this->completionRevisionMapper, $this->houseUserMapper,
			$this->walletMapper, $this->taskAssigneeMapper, $this->taskAccessPolicy, $authority, $this->walletService,
			$this->roomTaskReads, $this->calendarService, $l10n
		);
		$this->service = new CompletionService(
			$this->commandService, $access, $locks, $evidence, $recognition, $this->readModel, $this->postCommitService,
			new ParticipantAllocation($allocationService, $l10n), $creditPolicy, $this->multipliers,
			$this->roomTaskMapper, $this->roomMapper, $this->houseMapper, $this->houseUserMapper, $this->taskAssigneeMapper,
			$this->completedTaskMapper, $this->workShareMapper,
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->walletService, $this->roomReplayService, $this->calendarService, $this->taskAccessPolicy, $this->cycleService,
			$responsibility, $this->checklists, $l10n, $this->appLogger
		);
		$this->dateCommand = new CompletionDateCommand(
			$this->commandService, $access, $locks, $evidence, $recognition, $this->readModel,
			new CompletionDateCorrection(
				$this->completedTaskMapper, new WindowService(new RecurrenceCalculator(), $this->completedTaskMapper), $this->calendarService, $l10n
			),
			$this->postCommitService, $this->completedTaskMapper, $this->workShareMapper, $this->houseMapper, $this->roomReplayService,
			$this->cycleService, $responsibility, $this->calendarService, $l10n, $this->appLogger
		);
	}

	private function member(int $id, string $userId, int $walletId): HouseUser {
		$member = new HouseUser();
		$member->setId($id);
		$member->setHouseId(1);
		$member->setNextcloudUserId($userId);
		$member->setWalletId($walletId);
		$this->houseMembers[] = $member;
		$this->membersById[$id] = $member;
		$wallet = new Wallet();
		$wallet->setId($walletId);
		$wallet->setHouseId(1);
		$wallet->setNextcloudUserId($userId);
		$this->walletsById[$walletId] = $wallet;
		return $member;
	}

	private function entry(int $id, int $recordedBy, string $workDay = '2026-09-20'): CompletedTask {
		$entry = new CompletedTask();
		$entry->setId($id);
		$entry->setRoomTaskId(11);
		$entry->setUserId($recordedBy);
		$entry->setTotalEnergy(3);
		$entry->setCompletionVersion(0);
		$entry->setAllocationRevision(1);
		$entry->setCompletedAt(new \DateTime($workDay . ' 08:00:00', new \DateTimeZone('UTC')));
		$entry->setWorkDate(new \DateTime($workDay, new \DateTimeZone('UTC')));
		$entry->setWorkZone('UTC');
		$entry->setCompletionClass(CompletedTask::CLASS_ON_TIME);
		return $entry;
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
	}

	/** The cycle the target resolution answers; a test may return null (bonus task). */
	private function resolvedCycle(): ?CurrentCycle {
		return $this->cycle;
	}

	/**
	 * Wire the default solo-completion mocks (one assignee = the actor).
	 */
	private function soloCompletionContext(): void {
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$this->roomTask]);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($this->roomTask);
		$this->roomTaskMapper->method('find')->willReturn($this->roomTask);
		$this->taskMapper->method('find')->willReturn($this->task);
		$this->roomMapper->method('lockForUpdate')->willReturn($this->room);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnCallback(fn (): ?HouseUser => $this->liveActor);
		$this->houseUserMapper->method('findByHouseId')->willReturnCallback(
			fn (): array => $this->houseMembers
		);
		$this->houseUserMapper->method('findForUpdate')->willReturnCallback(function (int $id): HouseUser {
			$this->lockedMemberIds[] = $id;
			return $this->membersById[$id];
		});
		$this->houseUserMapper->method('find')->willReturnCallback(
			function (int $id): HouseUser {
				if (!isset($this->membersById[$id])) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('no rows');
				}
				return $this->membersById[$id];
			}
		);
		$this->houseUserMapper->method('findByWalletId')->willReturn($this->actor);
		$this->houseMembers = [$this->actor];
		$this->membersById = [7 => $this->actor];
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturnCallback(fn (): array => $this->assigneeUserIds);
		$this->houseUserMapper->method('findByHouseIdAndNextcloudUserIds')->willReturnCallback(
			fn (int $houseId, array $userIds): array => array_values(array_filter(
				$this->houseMembers,
				static fn (HouseUser $member): bool => in_array($member->getNextcloudUserId(), $userIds, true)
			))
		);
		$this->walletMapper->method('findForUpdate')->willReturnCallback(
			fn (int $id): Wallet => $this->walletsById[$id] ?? $this->wallet
		);
		$this->walletMapper->method('find')->willReturn($this->wallet);
		$this->walletMapper->method('findByHouseAndUser')->willReturn($this->wallet);
		$this->walletsById = [500 => $this->wallet];
		// The binding helper passes the membership through unchanged (the
		// binding already exists on the seeded rows).
		$this->walletService->method('ensureBinding')->willReturnCallback(
			static fn (int $houseId, HouseUser $member): HouseUser => $member
		);
		// The caller-safe wallet block is the serializer's contract (covered
		// by WalletServiceTest); pin the shape here.
		$this->walletService->method('serializeOwnWallet')->willReturn(
			['bookedMinor' => 0, 'reservedMinor' => 0, 'availableMinor' => 0, 'fundingHold' => false]
		);
		$this->cycleService->method('resolveTargetUnderLock')->willReturnCallback(fn (): ?CurrentCycle => $this->resolvedCycle());
		$this->cycleService->method('currentFor')->willReturn(null);

		$insertedIds = 99;
		$this->completedTaskMapper->method('insert')->willReturnCallback(
			function (CompletedTask $row) use (&$insertedIds): CompletedTask {
				$row->setId($insertedIds);
				return $row;
			}
		);
		$this->completedTaskMapper->method('find')->willReturnCallback(
			function (int $id): CompletedTask {
				if ($this->completionToFind !== null) {
					return $this->completionToFind;
				}
				$row = new CompletedTask();
				$row->setId($id);
				$row->setRoomTaskId(11);
				$row->setUserId(7);
				$row->setTotalEnergy(3);
				$row->setRateMinor(1000);
				$row->setCompletionVersion(0);
				$row->setAllocationRevision(1);
				$row->setCompletedAt(new \DateTime());
				return $row;
			}
		);
		$this->workShareMapper->method('insert')->willReturnArgument(0);
		$this->completionRevisionMapper->method('insert')->willReturnArgument(0);
		// Mapper UPDATEs mutate the seam row so the aggregate's final read
		// reflects the corrected state (as the real UPDATE would).
		$this->completedTaskMapper->method('markUndone')->willReturnCallback(
			function (int $id, \DateTime $at): void {
				$this->completionToFind?->setUndoneAt($at);
			}
		);
		$this->completedTaskMapper->method('advanceVersion')->willReturnCallback(
			function (int $id, int $newVersion, int $newAllocationRevision): void {
				$this->completionToFind?->setCompletionVersion($newVersion);
				$this->completionToFind?->setAllocationRevision($newAllocationRevision);
			}
		);
	}

	public function testComplete_SoloDefault_WritesFrozenEvidenceAndCreditsWallet(): void {
		$this->soloCompletionContext();

		$insertedCompletion = null;
		$this->completedTaskMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (CompletedTask $row) use (&$insertedIds, &$insertedCompletion): CompletedTask {
				$insertedCompletion = $row;
				$row->setId(99);
				return $row;
			});

		// The initial credit is the selected allocation: 3 energy solo =
		// 300 energy minor / 3000 coin minor for the one wallet.
		$this->walletService->expects($this->once())
			->method('postEntries')
			->with(
				1,
				WalletService::SOURCE_COMPLETION,
				99,
				1,
				[500 => 3000],
				WalletService::REASON_COMPLETION,
				7,
			);
		$this->walletService->expects($this->once())->method('recalcContributionCache')->with([500]);

		// The total-room effect journals once with the frozen delta E*10.
		$this->roomReplayService->expects($this->once())
			->method('recordCompletion')
			->with(10, 99, 30, $this->anything());

		// The house state version bumps so previews become stale.
		$this->houseMapper->expects($this->atLeastOnce())->method('update');

		// ADR-021 decision 4: the completion targets the shown cycle and
		// closes exactly that one in the same transaction.
		$this->cycleService->expects($this->once())->method('resolveTargetUnderLock')
			->with(1, $this->roomTask, $this->task, 'd2099-01-05', 0, $this->isInstanceOf(\DateTimeZone::class), $this->isInstanceOf(\DateTime::class));
		$this->cycleService->expects($this->once())->method('closeUnderLock')
			->with(1, $this->roomTask, $this->task, $this->cycle, $this->isInstanceOf(CompletedTask::class), $this->isInstanceOf(\DateTimeZone::class), $this->isInstanceOf(\DateTime::class));
		// Every sub-task of the cycle is done with it, ticked by the recorder.
		$this->checklists->expects($this->once())->method('markCycleDoneUnderLock')->with(11, 5, 'd2099-01-05', 7);

		$result = $this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'd2099-01-05', 0);

		// Frozen evidence: the definition's energy at completion and the
		// rate, version 0, allocation revision 1, the command receipt, the
		// audit completer — and the cycle with its window and classification.
		$this->assertNotNull($insertedCompletion);
		$this->assertSame(
			['d2099-01-05', '2099-01-04', '2099-01-06', CompletedTask::CLASS_ON_TIME, 'UTC'],
			[
				$insertedCompletion->getCycleKey(), $insertedCompletion->getWindowStartOn()->format('Y-m-d'),
				$insertedCompletion->getWindowEndOn()->format('Y-m-d'), $insertedCompletion->getCompletionClass(), $insertedCompletion->getWorkZone(),
			]
		);
		$this->assertNull($insertedCompletion->getScheduleRevId(), 'no occurrence key on a completion of the derived model');
		$this->assertSame(3, $insertedCompletion->getTotalEnergy());
		$this->assertSame(1000, $insertedCompletion->getRateMinor());
		$this->assertSame(0, $insertedCompletion->getCompletionVersion());
		$this->assertSame(1, $insertedCompletion->getAllocationRevision());
		$this->assertSame('11111111-2222-4333-8444-555555555555', $insertedCompletion->getCommandUuid());
		$this->assertSame(7, $insertedCompletion->getUserId());
		$this->assertSame(99, $result['completionId']);
		$this->assertSame(['completionId' => 99], $this->receiptResult, 'only stable identity belongs in the receipt');
		// WP3 shape: no Coin/combo promises anywhere in the rewards block.
		$this->assertArrayNotHasKey('coins', $result['rewards']);
		$this->assertArrayNotHasKey('isCombo', $result['rewards']);
		$this->assertSame(30, $result['rewards']['cleanlinessChange']);
		// The caller's OWN wallet rides along in minor units.
		$this->assertSame(
			['bookedMinor' => 0, 'reservedMinor' => 0, 'availableMinor' => 0, 'fundingHold' => false],
			$result['rewards']['wallet']
		);
	}

	public function testComplete_AbsentObligatedMember_RecomputesWithoutWalletCredit(): void {
		$this->soloCompletionContext();
		$absent = $this->addObligatedNonWorker();
		$absent->setCurrentStreak(2);
		$this->rhythmService->expects($this->once())->method('recomputeForMembers')
			->willReturnCallback(function (int $houseId, array $members) use ($absent): array {
				$this->assertSame([7, 8], array_map(static fn (HouseUser $m): int => (int)$m->getId(), $members));
				$absent->setCurrentStreak(0);
				return [];
			});
		$this->walletService->expects($this->once())->method('postEntries')
			->with(1, WalletService::SOURCE_COMPLETION, 99, 1, [500 => 3000], WalletService::REASON_COMPLETION, 7);

		$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'd2099-01-05', 0, [['userId' => 'mom', 'sharePct' => 100]], true);

		$this->assertSame([7, 8], $this->lockedMemberIds);
		$this->assertSame(0, $absent->getCurrentStreak());
	}

	public function testComplete_AfterItsWindow_IsLateAndANonAssigneeTakesItOver(): void {
		$this->soloCompletionContext();
		$this->cycle = new CurrentCycle('c41', '2026-09-01', '2026-09-01', '2026-09-02');
		$this->assigneeUserIds = ['dad'];
		$inserted = null;
		$this->completedTaskMapper->method('insert')->willReturnCallback(function (CompletedTask $row) use (&$inserted): CompletedTask {
			$inserted = $row;
			$row->setId(99);
			return $row;
		});

		$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'c41', 0, [['userId' => 'mom', 'sharePct' => 100]], true);

		$this->assertSame(CompletedTask::CLASS_LATE, $inserted->getCompletionClass(), 'classified once, against the end of the window');
		$this->assertTrue($this->roomTask->getWasClaimed());
		$this->assertSame(['dad', 'mom', 99], [$this->roomTask->getOriginalAssignee(), $this->roomTask->getClaimedByUser(), $this->roomTask->getClaimedSourceCompletionId()]);
	}

	public function testComplete_BonusTask_HasNoCycleAndClosesNone(): void {
		$this->soloCompletionContext();
		$bonus = $this->createMock(CycleService::class);
		$bonus->method('resolveTargetUnderLock')->willReturn(null);
		$bonus->method('currentFor')->willReturn(null);
		$bonus->expects($this->never())->method('closeUnderLock');
		// No cycle changed, so the version stays (D-091-84): only the last-completed mark moves.
		$bonus->expects($this->never())->method('changedUnderLock');
		$bonus->expects($this->once())->method('bonusDoneUnderLock')->with($this->roomTask, $this->isInstanceOf(\DateTime::class));
		$this->checklists->expects($this->never())->method('markCycleDoneUnderLock');
		$inserted = null;
		$this->completedTaskMapper->method('insert')->willReturnCallback(function (CompletedTask $row) use (&$inserted): CompletedTask {
			$inserted = $row;
			$row->setId(99);
			return $row;
		});
		$property = new \ReflectionProperty(CompletionService::class, 'cycles');
		$property->setAccessible(true);
		$property->setValue($this->service, $bonus);

		$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', null, 0);

		$this->assertSame([CompletedTask::CLASS_BONUS, null, null], [$inserted->getCompletionClass(), $inserted->getCycleKey(), $inserted->getWindowEndOn()]);
	}

	/** ADR-021 decision 11: the completion freezes both multipliers and pays its shares with them. */
	public function testComplete_FreezesTheMultipliersAndPaysTheSharesWithThem(): void {
		$this->soloCompletionContext();
		$this->member(8, 'dad', 501);
		// Mom at × 2, dad at × 3, on a bonus day.
		$this->frozen = ['factors' => [500 => 2, 501 => 3], 'percent' => 150];
		$this->multipliers->expects($this->once())->method('freeze')
			->with(1, $this->houseMembers, $this->callback(static fn (array $wallets): bool => array_keys($wallets) === ['mom', 'dad']), $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'));
		$inserted = null;
		$this->completedTaskMapper->method('insert')->willReturnCallback(function (CompletedTask $row) use (&$inserted): CompletedTask {
			$inserted = $row;
			$row->setId(99);
			return $row;
		});
		$shares = [];
		$this->workShareMapper->method('insert')->willReturnCallback(function (WorkShare $share) use (&$shares): WorkShare {
			$shares[$share->getWalletId()] = [$share->getEnergyMinor(), $share->getCoinMinor()];
			return $share;
		});
		// Energy 3, half each: 10 × 3 × 50 = 1500, × 2 × 1.5 and × 3 × 1.5.
		$this->walletService->expects($this->once())->method('postEntries')
			->with(1, WalletService::SOURCE_COMPLETION, 99, 1, [500 => 4500, 501 => 6750], WalletService::REASON_COMPLETION, 7);

		$this->service->complete(
			1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'd2099-01-05', 0,
			[['userId' => 'mom', 'sharePct' => 50], ['userId' => 'dad', 'sharePct' => 50]], true
		);

		$this->assertSame([150, [500 => 2, 501 => 3]], [$inserted->getBonusPercent(), $inserted->getCoinFactors()]);
		ksort($shares);
		$this->assertSame([500 => [150, 4500], 501 => [150, 6750]], $shares, 'the energy never sees a multiplier');
	}

	/** Decision 8: a bonus task pays a person once per day; the refusal writes nothing. */
	public function testComplete_BonusTaskThatPaidThePersonToday_Conflict(): void {
		$this->soloCompletionContext();
		$bonus = $this->createMock(CycleService::class);
		$bonus->method('resolveTargetUnderLock')->willReturn(null);
		$property = new \ReflectionProperty(CompletionService::class, 'cycles');
		$property->setAccessible(true);
		$property->setValue($this->service, $bonus);
		$this->workShareMapper->expects($this->once())->method('findWalletIdsCreditedOn')
			->with(11, $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/'), null)->willReturn([500]);
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->walletService->expects($this->never())->method('postEntries');

		try {
			$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', null, 0);
			$this->fail('the second credit of the day must be refused');
		} catch (ConflictException $e) {
			$this->assertSame(ConflictException::REASON_BONUS_ALREADY_TODAY, $e->getReason());
		}
	}

	/** A task with a cycle has no per-person day rule: the cycle is its limit. */
	public function testComplete_TaskWithACycle_AsksNoBonusRule(): void {
		$this->soloCompletionContext();
		$this->workShareMapper->expects($this->never())->method('findWalletIdsCreditedOn');

		$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'd2099-01-05', 0);
	}

	/** Decision 11: a change of people is priced with the multipliers frozen on the completion. */
	public function testAttribute_PricesTheNewSharesWithTheFrozenMultipliers(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');
		$dad = $this->member(8, 'dad', 501);
		// Dad's factor is × 1 today; the completion froze × 3 for him and a bonus day.
		$dad->setCoinFactor(1);
		$live = $this->entry(9, 7);
		$live->setCoinFactors([500 => 1, 501 => 3]);
		$live->setBonusPercent(150);
		$this->completionToFind = $live;

		$previous = new WorkShare();
		$previous->setCompletionId(9);
		$previous->setAllocationRevision(1);
		$previous->setWalletId(500);
		$previous->setSharePct(100);
		$previous->setEnergyMinor(300);
		// What the share was paid: 10 × 3 × 100 × 1 × 1.5.
		$previous->setCoinMinor(4500);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$previous]);
		$this->multipliers->expects($this->never())->method('freeze');

		// Mom 100% → 0 (−4500, exactly what she was paid); dad 0 → 100% at the frozen × 3 × 1.5.
		$this->walletService->expects($this->once())->method('postEntries')
			->with(1, WalletService::SOURCE_COMPLETION, 9, 2, [500 => -4500, 501 => 13500], WalletService::REASON_ATTRIBUTION, 7);

		$this->service->attribute(
			1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, [['userId' => 'dad', 'sharePct' => 100]], true, null
		);
	}

	/** Decision 8: the owner cannot move bonus work onto someone the task paid that day. */
	public function testAttribute_BonusEntryOntoSomeoneItPaidThatDay_Conflict(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');
		$this->member(8, 'dad', 501);
		$live = $this->entry(9, 7);
		$live->setCompletionClass(CompletedTask::CLASS_BONUS);
		$this->completionToFind = $live;
		$previous = new WorkShare();
		$previous->setCompletionId(9);
		$previous->setAllocationRevision(1);
		$previous->setWalletId(500);
		$previous->setSharePct(100);
		$previous->setEnergyMinor(300);
		$previous->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$previous]);
		// Only who joins is asked about, and this entry itself does not count.
		$this->workShareMapper->expects($this->once())->method('findWalletIdsCreditedOn')->with(11, '2026-09-20', 9)->willReturn([501]);
		$this->walletService->expects($this->never())->method('postEntries');

		$this->expectException(ConflictException::class);
		$this->service->attribute(
			1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0,
			[['userId' => 'mom', 'sharePct' => 50], ['userId' => 'dad', 'sharePct' => 50]], true, null
		);
	}

	/** A reversal is what the share rows recorded, whatever the factors are today. */
	public function testUndo_ReversesTheStoredShareAmounts_NotTodaysRate(): void {
		$this->soloCompletionContext();
		$this->actor->setCoinFactor(1);
		$live = $this->entry(9, 7);
		$live->setCoinFactors([500 => 3]);
		$live->setBonusPercent(150);
		$this->completionToFind = $live;
		$share = new WorkShare();
		$share->setCompletionId(9);
		$share->setAllocationRevision(1);
		$share->setWalletId(500);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);
		$share->setCoinMinor(13500);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$share]);

		$this->walletService->expects($this->once())->method('postEntries')
			->with(1, WalletService::SOURCE_COMPLETION_UNDO, 9, 1, [500 => -13500], WalletService::REASON_UNDO, 7);

		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);
	}

	public function testShowCompletion_NamesTheFrozenMultipliers_AndADepartedRecorder(): void {
		$this->soloCompletionContext();
		// Recorded by a member who left since (house user 8 is gone).
		$entry = $this->entry(9, 8);
		$entry->setCoinFactors([500 => 2]);
		$entry->setBonusPercent(150);
		$this->completionToFind = $entry;
		$share = new WorkShare();
		$share->setCompletionId(9);
		$share->setAllocationRevision(1);
		$share->setWalletId(500);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);
		$share->setCoinMinor(9000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$share]);

		$data = $this->readModel->show(1, $this->actor, 9);

		$this->assertSame([150, 2], [$data['bonusPercent'], $data['allocation'][0]['coinFactor']]);
		// One rule with the activity feed: a recorder who left still recorded it for others.
		$this->assertSame(['userId' => null, 'displayName' => 'Removed member'], $data['recordedBy']);
	}

	public function testComplete_RefusedTarget_WritesNothing(): void {
		$this->soloCompletionContext();
		$refusing = $this->createMock(CycleService::class);
		$refusing->method('resolveTargetUnderLock')
			->willThrowException(new ConflictException('This task was already completed today', ConflictException::REASON_SAME_DAY));
		$property = new \ReflectionProperty(CompletionService::class, 'cycles');
		$property->setAccessible(true);
		$property->setValue($this->service, $refusing);
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->walletService->expects($this->never())->method('postEntries');

		try {
			$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'd2099-01-05', 0);
			$this->fail('the same-day refusal must surface');
		} catch (ConflictException $e) {
			$this->assertSame('SAME_DAY', $e->getReason());
		}
	}

	public function testComplete_DeviatingSharesWithoutReview_Conflict(): void {
		$this->soloCompletionContext();

		$this->expectException(ConflictException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'd2099-01-05', 0,
			[['userId' => 'mom', 'sharePct' => 60], ['userId' => 'dad', 'sharePct' => 40]],
			false,
		);
	}

	public function testComplete_ForSomeoneElse_CreditsThemAndKeepsTheRecorder(): void {
		$this->soloCompletionContext();
		$this->member(8, 'dad', 501);
		$inserted = null;
		$this->completedTaskMapper->method('insert')->willReturnCallback(function (CompletedTask $row) use (&$inserted): CompletedTask {
			$inserted = $row;
			$row->setId(99);
			return $row;
		});
		// Only the credited member is paid; the recorder gets nothing.
		$this->walletService->expects($this->once())->method('postEntries')
			->with(1, WalletService::SOURCE_COMPLETION, 99, 1, [501 => 3000], WalletService::REASON_COMPLETION, 7);

		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'd2099-01-05', 0,
			[['userId' => 'dad', 'sharePct' => 100]],
			true,
		);

		$this->assertSame(7, $inserted->getUserId(), 'the entry keeps who recorded it');
		$this->assertFalse($this->roomTask->getWasClaimed(), 'work recorded on time is no takeover');
	}

	public function testComplete_CreditedMemberWhoMayNotSeeTheTask_Forbidden(): void {
		$this->soloCompletionContext();
		$this->member(8, 'dad', 501);
		$this->invisibleTo = ['dad'];
		$this->completedTaskMapper->expects($this->never())->method('insert');
		$this->walletService->expects($this->never())->method('postEntries');

		$this->expectException(ForbiddenException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'd2099-01-05', 0,
			[['userId' => 'dad', 'sharePct' => 100]],
			true,
		);
	}

	/**
	 * A red task counts as taken over when none of its assignees is
	 * credited: by the recorder, or by whom the recorder credits.
	 *
	 * @return array<string, array{list<string>, string, ?string}>
	 */
	public static function lateCredits(): array {
		return [
			'recorded for the assignee' => [['dad'], 'dad', null],
			'the assignee shares the work' => [['dad', 'mom'], 'dad', null],
			'recorded for a third person' => [['kid'], 'dad', 'kid'],
			'done by the recorder' => [['mom'], 'dad', 'mom'],
		];
	}

	/**
	 * @dataProvider lateCredits
	 * @param list<string> $credited
	 */
	public function testComplete_LateWork_IsATakeoverOnlyWithoutAnAssignee(array $credited, string $assignee, ?string $claimedBy): void {
		$this->soloCompletionContext();
		$this->member(8, 'dad', 501);
		$this->member(9, 'kid', 502);
		$this->cycle = new CurrentCycle('c41', '2026-09-01', '2026-09-01', '2026-09-02');
		$this->assigneeUserIds = [$assignee];
		$shares = count($credited) === 1 ? [100] : [50, 50];

		$this->service->complete(
			1, 'mom', 11, '11111111-2222-4333-8444-555555555555', 'c41', 0,
			array_map(static fn (string $userId, int $pct): array => ['userId' => $userId, 'sharePct' => $pct], $credited, $shares),
			true,
		);

		$this->assertSame($claimedBy !== null, $this->roomTask->getWasClaimed());
		$this->assertSame($claimedBy, $this->roomTask->getClaimedByUser());
	}

	public function testComplete_NonUniqueShares_Rejected(): void {
		$this->soloCompletionContext();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'd2099-01-05', 0,
			[['userId' => 'mom', 'sharePct' => 50], ['userId' => 'mom', 'sharePct' => 50]],
			true,
		);
	}

	public function testUndo_TerminalAlreadyUndone_StableNoOp(): void {
		$this->soloCompletionContext();

		$done = new CompletedTask();
		$done->setId(9);
		$done->setRoomTaskId(11);
		$done->setUserId(7);
		$done->setTotalEnergy(3);
		$done->setCompletionVersion(2);
		$done->setAllocationRevision(1);
		$done->setUndoneAt(new \DateTime('2026-09-20 08:00:00'));
		$this->completionToFind = $done;

		// Terminal handling BEFORE version handling: an already-undone
		// target is a stable no-op even against a stale expectedVersion —
		// and the no-op answers `undone: false` (§3.4: THIS command undid
		// nothing) without post-commit effects.
		$result = $this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);

		$this->assertFalse($result['undone']);
		$this->assertSame(['completionId' => 9, 'noop' => true], $this->receiptResult);
	}

	public function testUndo_StaleLiveTarget_Conflict(): void {
		$this->soloCompletionContext();

		$live = new CompletedTask();
		$live->setId(9);
		$live->setRoomTaskId(11);
		$live->setUserId(7);
		$live->setTotalEnergy(3);
		$live->setCompletionVersion(2);
		$live->setAllocationRevision(1);
		$this->completionToFind = $live;

		$this->expectException(ConflictException::class);
		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 1, null);
	}

	public function testUndo_ReversesLatestSelectedAllocationOnce(): void {
		$this->soloCompletionContext();

		$live = new CompletedTask();
		$live->setId(9);
		$live->setRoomTaskId(11);
		$live->setUserId(7);
		$live->setTotalEnergy(3);
		$live->setCompletionVersion(1);
		$live->setAllocationRevision(2);
		$this->completionToFind = $live;

		$share = new WorkShare();
		$share->setCompletionId(9);
		$share->setAllocationRevision(2);
		$share->setWalletId(500);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);
		$share->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$share]);

		// The mapper UPDATE marks the row undone — reflect it on the seam
		// row so the aggregate's final read sees the corrected state.
		// (Undo reversal assertions are pinned below.)

		// The LATEST selected allocation reverses once (negative entries with
		// the new completion version as source revision).
		$this->walletService->expects($this->once())
			->method('postEntries')
			->with(
				1,
				WalletService::SOURCE_COMPLETION_UNDO,
				9,
				2,
				[500 => -3000],
				WalletService::REASON_UNDO,
				7,
			);

		$this->completedTaskMapper->expects($this->once())->method('markUndone')->with(9, $this->anything());
		$this->completedTaskMapper->expects($this->once())->method('advanceVersion')->with(9, 2, 2);

		$result = $this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 1, 'test_reason');
		$this->assertTrue($result['undone']);
		$this->assertSame(['completionId' => 9, 'noop' => false, 'version' => 2], $this->receiptResult);
	}

	public function testUndo_ForeignCompletion_Forbidden(): void {
		$this->soloCompletionContext();

		$foreign = new CompletedTask();
		$foreign->setId(9);
		$foreign->setRoomTaskId(11);
		$foreign->setUserId(8); // someone else's completion
		$foreign->setCompletionVersion(0);
		$foreign->setAllocationRevision(1);
		$this->completionToFind = $foreign;

		$this->expectException(ForbiddenException::class);
		// A non-owner actor (participation alone is no correction authority).
		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);
	}

	public function testUndo_OwnEntryThatIsNoLongerTheLatest_Forbidden(): void {
		$this->soloCompletionContext();
		$this->completionToFind = $this->entry(9, 7);
		$this->latestValidId = 10;
		$this->walletService->expects($this->never())->method('postEntries');

		// D-091-23: a member deletes only the latest entry of a task.
		$this->expectException(ForbiddenException::class);
		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);
	}

	public function testUndo_Owner_DeletesAnyEntry(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');
		$this->completionToFind = $this->entry(9, 8);
		$this->latestValidId = 10;
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([]);

		$result = $this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);

		$this->assertTrue($result['undone']);
	}

	public function testAttribute_ByTheRecorderWhoIsNotTheOwner_Forbidden(): void {
		$this->soloCompletionContext();
		$this->completionToFind = $this->entry(9, 7);
		$this->walletService->expects($this->never())->method('postEntries');

		// D-091-23: changing the people is the owner's alone.
		$this->expectException(ForbiddenException::class);
		$this->service->attribute(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, [['userId' => 'mom', 'sharePct' => 100]], true, null);
	}

	public function testChangeDate_MovesTheWorkDayAndRederivesWhatHangsOnIt(): void {
		$this->soloCompletionContext();
		$entry = $this->entry(9, 7);
		$entry->setCycleKey('c8');
		$entry->setWindowStartOn(new \DateTime('2026-09-19'));
		$entry->setWindowEndOn(new \DateTime('2026-09-19'));
		$entry->setCompletionClass(CompletedTask::CLASS_LATE);
		$this->completionToFind = $entry;
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([]);

		$this->completedTaskMapper->expects($this->once())->method('update')->with($entry)->willReturnArgument(0);
		$audit = null;
		$this->completionRevisionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (CompletionRevision $revision) use (&$audit): CompletionRevision {
				$audit = $revision;
				return $revision;
			});
		// The room journal folds the work at the corrected instant.
		$this->roomReplayService->expects($this->once())->method('moveCompletion')
			->with(9, $this->callback(static fn (\DateTime $at): bool => $at->format('Y-m-d H:i:s') === '2026-09-19 08:00:00'));
		$this->cycleService->expects($this->once())->method('redatedUnderLock')
			->with($this->roomTask, $this->task, $entry, null, $this->isInstanceOf(\DateTimeZone::class), $this->isInstanceOf(\DateTime::class));
		// Shares, energy and coins stay as recorded.
		$this->walletService->expects($this->never())->method('postEntries');

		$result = $this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-19');

		$this->assertSame('2026-09-19', $entry->getWorkDate()->format('Y-m-d'));
		$this->assertSame(CompletedTask::CLASS_ON_TIME, $entry->getCompletionClass(), 'classified again against the frozen window');
		$this->assertSame([CompletionRevision::ACTION_DATE, 1, 1, 7], [$audit->getAction(), $audit->getRevision(), $audit->getAllocationRevision(), $audit->getActorHouseUserId()]);
		$this->assertSame(['completionId' => 9, 'version' => 1], $this->receiptResult);
		$this->assertSame(1, $result['rewards']['completion']['version']);
		$this->assertArrayHasKey('cycle', $result);
	}

	public function testChangeDate_ADayTheEntryCannotTake_WritesNothing(): void {
		$this->soloCompletionContext();
		$this->completionToFind = $this->entry(9, 7);
		$this->neighbours = ['previous' => $this->entry(8, 7, '2026-09-18'), 'next' => null];
		$this->completedTaskMapper->expects($this->never())->method('update');
		$this->completionRevisionMapper->expects($this->never())->method('insert');
		$this->cycleService->expects($this->never())->method('redatedUnderLock');

		$this->expectException(\InvalidArgumentException::class);
		$this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-18');
	}

	public function testChangeDate_DeletedEntry_Conflict(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');
		$entry = $this->entry(9, 7);
		$entry->setUndoneAt(new \DateTime('2026-09-21 08:00:00'));
		$this->completionToFind = $entry;
		$this->completedTaskMapper->expects($this->never())->method('update');

		$this->expectException(ConflictException::class);
		$this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-19');
	}

	public function testChangeDate_OwnEntryThatIsNoLongerTheLatest_Forbidden(): void {
		$this->soloCompletionContext();
		$this->completionToFind = $this->entry(9, 7);
		$this->latestValidId = 10;
		$this->completedTaskMapper->expects($this->never())->method('update');

		// D-091-23: the state part of the right is decided under the lock.
		$this->expectException(ForbiddenException::class);
		$this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-19');
	}

	public function testChangeDate_ReplayAfterANewerEntry_AnswersTheCommittedResult(): void {
		$this->soloCompletionContext();
		$this->completionToFind = $this->entry(9, 7);
		// Someone recorded a newer entry after the committed correction.
		$this->latestValidId = 10;
		$this->receiptReplay = true;
		$this->completedTaskMapper->expects($this->never())->method('update');

		$result = $this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-19');

		$this->assertTrue($result['replay'], 'a replay never re-tests the state precondition');
	}

	/** D-091-56: the owner's correction credits nobody who may not see the task. */
	public function testAttribute_CreditedMemberWhoMayNotSeeTheTask_Forbidden(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');
		$this->completionToFind = $this->entry(9, 7);
		$previous = new WorkShare();
		$previous->setCompletionId(9);
		$previous->setAllocationRevision(1);
		$previous->setWalletId(500);
		$previous->setSharePct(100);
		$previous->setEnergyMinor(300);
		$previous->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$previous]);
		$this->member(8, 'dad', 501);
		$this->invisibleTo = ['dad'];
		$this->walletService->expects($this->never())->method('postEntries');
		$this->completedTaskMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->attribute(
			1, $this->actor, 9,
			'11111111-2222-4333-8444-555555555555',
			0,
			[['userId' => 'dad', 'sharePct' => 100]],
			true,
			null,
		);
	}

	public function testAttribute_WritesExactUnionDelta(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');

		$live = new CompletedTask();
		$live->setId(9);
		$live->setRoomTaskId(11);
		$live->setUserId(7);
		$live->setTotalEnergy(3);
		$live->setCompletionVersion(1);
		$live->setAllocationRevision(1);
		$this->completionToFind = $live;

		$previous = new WorkShare();
		$previous->setCompletionId(9);
		$previous->setAllocationRevision(1);
		$previous->setWalletId(500);
		$previous->setSharePct(100);
		$previous->setEnergyMinor(300);
		$previous->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$previous]);

		$coMember = new HouseUser();
		$coMember->setId(8);
		$coMember->setHouseId(1);
		$coMember->setNextcloudUserId('dad');
		$coMember->setWalletId(501);
		$this->houseMembers = [$this->actor, $coMember];
		$this->membersById = [7 => $this->actor, 8 => $coMember];

		$coWallet = new Wallet();
		$coWallet->setId(501);
		$coWallet->setHouseId(1);
		$coWallet->setNextcloudUserId('dad');
		$this->walletsById = [500 => $this->wallet, 501 => $coWallet];

		// Attribution writes ONLY newAllocation - oldAllocation for the
		// union: mom 100% → 50/50 (−1500 coin minor), dad 0% → 50% (+1500).
		$deltas = null;
		$this->walletService->expects($this->once())
			->method('postEntries')
			->with(
				1,
				WalletService::SOURCE_COMPLETION,
				9,
				2,
				$this->callback(function (array $d) use (&$deltas): bool {
					$deltas = $d;
					return true;
				}),
				WalletService::REASON_ATTRIBUTION,
				7,
			);

		$result = $this->service->attribute(
			1, $this->actor, 9,
			'11111111-2222-4333-8444-555555555555',
			1,
			[['userId' => 'mom', 'sharePct' => 50], ['userId' => 'dad', 'sharePct' => 50]],
			true,
			'test_reason',
		);

		$this->assertSame([500 => -1500, 501 => 1500], $deltas);
		$this->assertSame(2, $result['rewards']['completion']['version']);
		$this->assertSame(['completionId' => 9, 'noop' => false, 'version' => 2], $this->receiptResult);
	}

	public function testAttribute_TimeAndEnergyStayImmutable(): void {
		$this->soloCompletionContext();
		$this->actor->setRole('owner');

		$live = new CompletedTask();
		$live->setId(9);
		$live->setRoomTaskId(11);
		$live->setUserId(7);
		$live->setTotalEnergy(3);
		$live->setCompletedAt(new \DateTime('2026-09-20 08:00:00'));
		$live->setCompletionVersion(0);
		$live->setAllocationRevision(1);
		$this->completionToFind = $live;
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([]);
		$this->houseMembers = [$this->actor];

		// No room replay or cycle write may happen on attribution — the
		// work time, saved zone, cycle and room event stay put.
		$this->roomReplayService->expects($this->never())->method('recordCompletion');
		$this->cycleService->expects($this->never())->method('reopenUnderLock');
		$this->cycleService->expects($this->never())->method('closeUnderLock');
		$this->completedTaskMapper->expects($this->never())->method('markUndone');

		$this->service->attribute(
			1, $this->actor, 9,
			'11111111-2222-4333-8444-555555555555',
			0,
			[['userId' => 'mom', 'sharePct' => 100]],
			true,
			null,
		);
	}

	/** @return array<string, array{bool}> */
	public static function liveCorrectionPaths(): array {
		return ['undo' => [false], 'attribution' => [true]];
	}

	/** @dataProvider liveCorrectionPaths */
	public function testCorrection_AbsentObligatedMember_RecomputesWithoutWalletCredit(bool $attribution): void {
		$this->soloCompletionContext();
		$absent = $this->addObligatedNonWorker();
		$absent->setCurrentStreak(2);
		$live = new CompletedTask();
		$live->setId(9);
		$live->setRoomTaskId(11);
		$live->setUserId(7);
		$live->setTotalEnergy(3);
		$live->setCompletionVersion(0);
		$live->setAllocationRevision(1);
		$this->completionToFind = $live;
		$previous = new WorkShare();
		$previous->setWalletId(500);
		$previous->setSharePct(100);
		$previous->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$previous]);
		$this->rhythmService->expects($this->once())->method('recomputeForMembers')
			->willReturnCallback(function (int $houseId, array $members) use ($absent): array {
				$this->assertSame([7, 8], array_map(static fn (HouseUser $m): int => (int)$m->getId(), $members));
				$absent->setCurrentStreak(0);
				return [];
			});
		$this->walletService->expects($this->once())->method('postEntries')
			->willReturnCallback(function (int $houseId, string $source, int $id, int $version, array $deltas): array {
				$this->assertSame([500], array_keys($deltas));
				return [];
			});

		$this->correctAs($attribution ? 'attribution' : 'undo');

		$this->assertSame([7, 8], $this->lockedMemberIds);
		$this->assertSame(0, $absent->getCurrentStreak());
	}

	private function addObligatedNonWorker(): HouseUser {
		$absent = new HouseUser();
		$absent->setId(8);
		$absent->setHouseId(1);
		$absent->setNextcloudUserId('dad');
		$absent->setWalletId(501);
		$this->houseMembers[] = $absent;
		$this->membersById[8] = $absent;
		// Responsible for the task without working on it.
		$this->assigneeUserIds = ['mom', 'dad'];
		return $absent;
	}

	/** @return array<string, array{string, bool}> */
	public static function correctionPaths(): array {
		return [
			'undo under lock' => ['undo', false],
			'attribution under lock' => ['attribution', false],
			'date correction under lock' => ['date', false],
			'undo receipt replay' => ['undo', true],
			'attribution receipt replay' => ['attribution', true],
			'date correction receipt replay' => ['date', true],
		];
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_RoleDowngradedAfterInitialCheck_Forbidden(string $kind, bool $replay): void {
		$this->soloCompletionContext();
		$owner = clone $this->actor;
		$owner->setRole('owner');
		$member = clone $owner;
		$member->setRole('member');
		$this->liveActor = $owner;
		$this->receiptReplay = $replay;
		$this->beforeCommand = function () use ($member): void {
			$this->liveActor = $member;
		};
		$completion = new CompletedTask();
		$completion->setId(9);
		$completion->setRoomTaskId(11);
		$completion->setUserId(8);
		$completion->setCompletionVersion(0);
		$completion->setAllocationRevision(1);
		$this->completionToFind = $completion;
		$this->walletService->expects($this->never())->method('postEntries');

		$this->expectException(ForbiddenException::class);
		$this->correctAs($kind);
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_VisibilityRevokedAfterInitialCheck_NotFound(string $kind, bool $replay): void {
		$this->soloCompletionContext();
		$this->receiptReplay = $replay;
		$this->beforeCommand = function (): void {
			$this->visibilityRevoked = true;
		};
		$completion = new CompletedTask();
		$completion->setId(9);
		$completion->setRoomTaskId(11);
		$completion->setUserId(7);
		$completion->setCompletionVersion(0);
		$completion->setAllocationRevision(1);
		$this->completionToFind = $completion;
		$this->walletService->expects($this->never())->method('postEntries');

		$this->expectException(NotFoundException::class);
		$this->correctAs($kind);
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_MembershipRemovedAfterInitialCheck_Forbidden(string $kind, bool $replay): void {
		$this->soloCompletionContext();
		$this->receiptReplay = $replay;
		$this->beforeCommand = function (): void {
			$this->liveActor = null;
		};
		$completion = new CompletedTask();
		$completion->setId(9);
		$completion->setRoomTaskId(11);
		$completion->setUserId(7);
		$completion->setCompletionVersion(0);
		$completion->setAllocationRevision(1);
		$this->completionToFind = $completion;
		$this->walletService->expects($this->never())->method('postEntries');

		$this->expectException(ForbiddenException::class);
		$this->correctAs($kind);
	}

	public function testUndo_OlderSameUserCompletion_DoesNotClearLaterClaim(): void {
		$this->soloCompletionContext();
		// Only the owner deletes an entry that is not the latest one.
		$this->actor->setRole('owner');
		$old = new CompletedTask();
		$old->setId(9);
		$old->setRoomTaskId(11);
		$old->setUserId(7);
		$old->setTotalEnergy(3);
		$old->setCompletionVersion(0);
		$old->setAllocationRevision(1);
		$old->setCompletedAt(new \DateTime('2026-09-20 08:00:00'));
		$this->completionToFind = $old;
		$this->roomTask->setWasClaimed(true);
		$this->roomTask->setClaimedAt(new \DateTime('2026-09-20 08:00:00'));
		$this->roomTask->setClaimedByUser('mom');
		$this->roomTask->setClaimedSourceCompletionId(10);
		$this->roomTaskMapper->expects($this->never())->method('update');
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([]);

		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);

		$this->assertTrue($this->roomTask->getWasClaimed());
		$this->assertSame(10, $this->roomTask->getClaimedSourceCompletionId());
		$this->assertSame('mom', $this->roomTask->getClaimedByUser());
	}

	private function correctAs(string $kind): array {
		// Changing the people is the owner's alone (D-091-23).
		if ($kind === 'attribution') {
			$this->actor->setRole('owner');
		}
		return match ($kind) {
			'attribution' => $this->service->attribute(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, [['userId' => 'mom', 'sharePct' => 100]], true, null),
			'date' => $this->dateCommand->changeDate(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, '2026-09-19'),
			default => $this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null),
		};
	}

	public function testShowCompletion_BuildsViewerSafeReadModel(): void {
		$this->soloCompletionContext();

		$done = new CompletedTask();
		$done->setId(9);
		$done->setRoomTaskId(11);
		$done->setUserId(7);
		$done->setTotalEnergy(3);
		$done->setCompletionVersion(1);
		$done->setAllocationRevision(2);
		$done->setCompletedAt(new \DateTime('2026-09-20 08:00:00'));
		$this->completionToFind = $done;

		$share = new WorkShare();
		$share->setCompletionId(9);
		$share->setAllocationRevision(2);
		$share->setWalletId(500);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);
		$share->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$share]);

		$data = $this->readModel->show(1, $this->actor, 9);

		$this->assertSame(9, $data['id']);
		$this->assertSame(1, $data['version']);
		$this->assertSame(2, $data['allocationRevision']);
		// The selected allocation is viewer-safe (shares only, no foreign
		// wallet totals) and `canCorrect` reflects the completer identity.
		$this->assertSame('mom', $data['allocation'][0]['userId']);
		$this->assertArrayNotHasKey('bookedMinor', $data['allocation'][0]);
		$this->assertTrue($data['canCorrect']);
		$this->assertFalse($data['canAttribute'], 'changing the people is the owner\'s alone');
		$this->assertNull($data['recordedBy'], 'the recorder is one of the people credited');
	}

	public function testShowCompletion_NamesTheRecorderOfWorkDoneByOthers(): void {
		$this->soloCompletionContext();
		$this->member(8, 'dad', 501)->setDisplayName('Dad');
		$this->completionToFind = $this->entry(9, 8);
		$this->latestValidId = 10;

		$share = new WorkShare();
		$share->setCompletionId(9);
		$share->setAllocationRevision(1);
		$share->setWalletId(500);
		$share->setSharePct(100);
		$share->setEnergyMinor(300);
		$share->setCoinMinor(3000);
		$this->workShareMapper->method('findByCompletionAndRevision')->willReturn([$share]);

		$data = $this->readModel->show(1, $this->actor, 9);

		$this->assertSame(['userId' => 'dad', 'displayName' => 'Dad'], $data['recordedBy']);
		$this->assertFalse($data['canCorrect'], 'credited, but neither the recorder nor the owner');
	}
}
