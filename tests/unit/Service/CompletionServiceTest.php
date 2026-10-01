<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\CompletedTask;
use OCA\ChoreStrike\Db\CompletedTaskMapper;
use OCA\ChoreStrike\Db\CompletionRevisionMapper;
use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\Occurrence;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Room;
use OCA\ChoreStrike\Db\RoomMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\ScheduleRevision;
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
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\CompletionPostCommitService;
use OCA\ChoreStrike\Service\CompletionService;
use OCA\ChoreStrike\Service\EffortAllocationService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\RewardConfigService;
use OCA\ChoreStrike\Service\RhythmService;
use OCA\ChoreStrike\Service\RoomReplayService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCA\ChoreStrike\Service\WalletService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WP3 completion/undo/attribution aggregates
 * (CS-PLAN-070 §3.3): frozen evidence writes, exact wallet credit, room
 * journal, house state version bump, terminal-before-version undo
 * ordering, the one-time reversal of the latest selected allocation and
 * the exact union-delta attribution. Occurrence/timeline effects delegate
 * to a mocked OccurrenceService (real behavior is covered by the Docker
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
	private OccurrenceMemberMapper $occMemberMapper;
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
	private OccurrenceService $occurrenceService;
	private CommandService $commandService;
	private AppLogger $appLogger;
	private CompletionService $service;

	private HouseUser $actor;
	private Wallet $wallet;
	private RoomTask $roomTask;
	private Room $room;
	private Task $task;
	private Occurrence $occurrence;
	private ScheduleRevision $revision;
	private House $house;
	/** The completion row `CompletedTaskMapper::find` resolves (test seam). */
	private ?CompletedTask $completionToFind = null;
	/** House-user members resolvable by id (test seam). */
	private array $membersById = [];
	/** The live membership list of the house (test seam). */
	private array $houseMembers = [];
	/** Wallets resolvable by id (test seam). */
	private array $walletsById = [];
	private array $occurrenceMembers = [];
	private array $lockedMemberIds = [];
	private ?HouseUser $liveActor;
	private ?\Closure $beforeCommand = null;
	private bool $receiptReplay = false;
	private bool $visibilityRevoked = false;
	private array $receiptResult = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->roomMapper = $this->createMock(RoomMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
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
		$this->occurrenceService = $this->createMock(OccurrenceService::class);
		$this->commandService = $this->createMock(CommandService::class);
		$this->appLogger = $this->createMock(AppLogger::class);

		// The command wrapper runs the aggregate callback directly (the
		// transaction/lock discipline is covered by the Docker suites).
		$this->commandService->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorHouseUserId, string $uuid, string $action, array $payload, callable $fn): array {
				if ($this->beforeCommand !== null) {
					($this->beforeCommand)();
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
			static fn (\DateTimeInterface $at, \DateTimeZone $tz): string => $at->setTimezone($tz)->format('Y-m-d')
		);

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

		$this->revision = new ScheduleRevision();
		$this->revision->setId(1);
		$this->revision->setRoomTaskId(11);

		$this->occurrence = new Occurrence();
		$this->occurrence->setId(21);
		$this->occurrence->setRoomTaskId(11);
		$this->occurrence->setScheduleRevId(1);
		$this->occurrence->setOrdinal(1);
		$this->occurrence->setEnergy(3);
		$this->occurrence->setTimezone('UTC');
		$this->occurrence->setPlannedDate(new \DateTime('2026-09-25'));

		$this->postCommitService->method('runForCompletion')->willReturn(['weeklyGoal' => [], 'degraded' => false]);
		$this->postCommitService->method('runForUndo')->willReturn(['weeklyGoal' => [], 'degraded' => false]);
		$this->configService->method('getTitlesEnabled')->willReturn(false);
		$this->roomReplayService->method('resyncRoomCache')->willReturnCallback(
			fn (Room $room): Room => $room
		);

		$this->service = new CompletionService(
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->roomTaskMapper,
			$this->taskMapper,
			$this->roomMapper,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->walletMapper,
			$this->occMemberMapper,
			$this->taskAssigneeMapper,
			$this->completedTaskMapper,
			$this->workShareMapper,
			$this->completionRevisionMapper,
			$this->postCommitService,
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->walletService,
			$this->roomReplayService,
			new EffortAllocationService(),
			$this->rhythmService,
			null,
			$this->configService,
			$this->calendarService,
			$this->taskAccessPolicy,
			$this->occurrenceService,
			$this->commandService,
			$this->createIdentityL10n(),
			$this->appLogger,
		);
	}

	private function createIdentityL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		return $l10n;
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
		$this->taskAssigneeMapper->method('findUserIdsByRoomTask')->willReturn(['mom']);
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
		$this->occMemberMapper->method('findByOccurrence')->willReturnCallback(fn (): array => $this->occurrenceMembers);
		$this->occurrenceService->method('resolveForCompletionUnderLock')->willReturn([
			'occurrence' => $this->occurrence,
			'revision' => $this->revision,
			'classification' => 'agreed',
		]);
		$this->occurrenceService->method('selectionFor')->willReturn(null);

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

		$result = $this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', '1:1', 0, 'token');

		// Frozen evidence: total effort/rate from the occurrence, version 0,
		// allocation revision 1, the command receipt and the audit completer.
		$this->assertNotNull($insertedCompletion);
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

		$this->service->complete(1, 'mom', 11, '11111111-2222-4333-8444-555555555555', '1:1', 0, 'token', [['userId' => 'mom', 'sharePct' => 100]], true);

		$this->assertSame([7, 8], $this->lockedMemberIds);
		$this->assertSame(0, $absent->getCurrentStreak());
	}

	public function testComplete_DeviatingSharesWithoutReview_Conflict(): void {
		$this->soloCompletionContext();

		$this->expectException(ConflictException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'1:1', 0, 'token',
			[['userId' => 'mom', 'sharePct' => 60], ['userId' => 'dad', 'sharePct' => 40]],
			false,
		);
	}

	public function testComplete_ActorNotParticipant_Forbidden(): void {
		$this->soloCompletionContext();

		$dad = new HouseUser();
		$dad->setId(8);
		$dad->setHouseId(1);
		$dad->setNextcloudUserId('dad');
		$dad->setWalletId(501);
		$this->houseMembers = [$this->actor, $dad];
		$this->membersById = [7 => $this->actor, 8 => $dad];

		$this->expectException(ForbiddenException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'1:1', 0, 'token',
			[['userId' => 'dad', 'sharePct' => 100]],
			true,
		);
	}

	public function testComplete_NonUniqueShares_Rejected(): void {
		$this->soloCompletionContext();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->complete(
			1, 'mom', 11,
			'11111111-2222-4333-8444-555555555555',
			'1:1', 0, 'token',
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
		$this->occurrenceService->method('occurrenceOfCompletion')->willReturn(null);

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

	public function testAttribute_WritesExactUnionDelta(): void {
		$this->soloCompletionContext();

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

		// No room replay or occurrence write may happen on attribution —
		// the work time, saved zone, occurrence and room event stay put.
		$this->roomReplayService->expects($this->never())->method('recordCompletion');
		$this->occurrenceService->expects($this->never())->method('releaseCompletionUnderLock');
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
		$this->occurrenceService->method('occurrenceOfCompletion')->willReturn($this->occurrence);
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

		$this->correctAs($attribution);

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
		$obligation = new OccurrenceMember();
		$obligation->setWalletId(501);
		$obligation->setObligated(true);
		$obligation->setDisposition(OccurrenceMember::DISPOSITION_ACTIVE);
		$obligation->setSharePct(100);
		$this->occurrenceMembers = [$obligation];
		return $absent;
	}

	/** @return array<string, array{bool, bool}> */
	public static function correctionPaths(): array {
		return [
			'undo under lock' => [false, false],
			'attribution under lock' => [true, false],
			'undo receipt replay' => [false, true],
			'attribution receipt replay' => [true, true],
		];
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_RoleDowngradedAfterInitialCheck_Forbidden(bool $attribution, bool $replay): void {
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
		$this->correctAs($attribution);
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_VisibilityRevokedAfterInitialCheck_NotFound(bool $attribution, bool $replay): void {
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
		$this->correctAs($attribution);
	}

	/** @dataProvider correctionPaths */
	public function testCorrection_MembershipRemovedAfterInitialCheck_Forbidden(bool $attribution, bool $replay): void {
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
		$this->correctAs($attribution);
	}

	public function testUndo_OlderSameUserCompletion_DoesNotClearLaterClaim(): void {
		$this->soloCompletionContext();
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
		$this->occurrenceService->method('occurrenceOfCompletion')->willReturn(null);

		$this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);

		$this->assertTrue($this->roomTask->getWasClaimed());
		$this->assertSame(10, $this->roomTask->getClaimedSourceCompletionId());
		$this->assertSame('mom', $this->roomTask->getClaimedByUser());
	}

	private function correctAs(bool $attribution): array {
		return $attribution
			? $this->service->attribute(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, [['userId' => 'mom', 'sharePct' => 100]], true, null)
			: $this->service->undo(1, $this->actor, 9, '11111111-2222-4333-8444-555555555555', 0, null);
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

		$data = $this->service->showCompletion(1, $this->actor, 9);

		$this->assertSame(9, $data['id']);
		$this->assertSame(1, $data['version']);
		$this->assertSame(2, $data['allocationRevision']);
		// The selected allocation is viewer-safe (shares only, no foreign
		// wallet totals) and `canCorrect` reflects the completer identity.
		$this->assertSame('mom', $data['allocation'][0]['userId']);
		$this->assertArrayNotHasKey('bookedMinor', $data['allocation'][0]);
		$this->assertTrue($data['canCorrect']);
	}
}
