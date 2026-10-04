<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\OccurrenceMember;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\RoomTask;
use OCA\ChoreStrike\Db\RoomTaskMapper;
use OCA\ChoreStrike\Db\Task;
use OCA\ChoreStrike\Db\TaskMapper;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Exception\NotFoundException;
use OCA\ChoreStrike\Service\AgreementService;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\DailyCapacityService;
use OCA\ChoreStrike\Service\OccurrenceService;
use OCA\ChoreStrike\Service\TaskAccessPolicy;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The WP2 agreement command gates (CS-PLAN-073 §3.5): closed input
 * shapes, the explicit consent attestation, exact share totals,
 * authorization BEFORE receipt replay (member 403 / foreign 404) and
 * payload replay protection. Domain writers stay behind the mocked
 * {@see OccurrenceService} — their DB-backed behavior is covered by the
 * integration suites.
 *
 * @covers \OCA\ChoreStrike\Service\AgreementService
 */
class AgreementServiceTest extends TestCase {
	private AgreementService $service;
	private RoomTaskMapper|MockObject $roomTaskMapper;
	private TaskMapper|MockObject $taskMapper;
	private HouseUserMapper|MockObject $houseUserMapper;
	private WalletMapper|MockObject $walletMapper;
	private OccurrenceMemberMapper|MockObject $occMemberMapper;
	private OccurrenceService|MockObject $occurrenceService;
	private DailyCapacityService|MockObject $dailyCapacity;
	private TaskAccessPolicy|MockObject $taskAccessPolicy;
	private CommandService|MockObject $commands;
	private bool $commandReplay = false;

	/** @var list<array{action: string, payload: array<string, mixed>}> */
	private array $capturedCommands = [];

	/** @var list<array{dayKey: string, commitments: list<array<string, mixed>>}> */
	private array $capturedCapacityChecks = [];

	/** The token the authoritative recomputation returns (RF-22 seam). */
	private string $authoritativeToken = 'tok';

	/** The suspension state the projection stamp derives (RF-05 seam). */
	private string $stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_NONE;

	/** The state the POST-WRITE re-stamp derives (R6 resumption seam). */
	private ?string $stampedSuspensionAfterWrite = null;

	/** Number of stampProjection calls in the current test. */
	private int $stampCalls = 0;

	/** Persisted suspension stamps (R6 seam). */
	private int $persistedStamps = 0;

	/** The cadence mode of the stubbed target revision. */
	private string $revisionCadence = \OCA\ChoreStrike\Db\ScheduleRevision::CADENCE_FIXED;

	/** @var list<array<string, mixed>> Argument sets the explicit-target date writer received. */
	private array $dateWriterTargets = [];

	protected function setUp(): void {
		parent::setUp();
		$this->capturedCommands = [];
		$this->capturedCapacityChecks = [];
		$this->dateWriterTargets = [];
		$this->authoritativeToken = 'tok';
		$this->stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_NONE;
		$this->stampedSuspensionAfterWrite = null;
		$this->stampCalls = 0;
		$this->persistedStamps = 0;
		$this->revisionCadence = \OCA\ChoreStrike\Db\ScheduleRevision::CADENCE_FIXED;
		$this->roomTaskMapper = $this->createMock(RoomTaskMapper::class);
		$this->taskMapper = $this->createMock(TaskMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->occMemberMapper = $this->createMock(OccurrenceMemberMapper::class);
		$this->occurrenceService = $this->createMock(OccurrenceService::class);
		$this->dailyCapacity = $this->createMock(DailyCapacityService::class);
		$this->dailyCapacity->method('assertCommitmentsFit')->willReturnCallback(
			function (int $houseId, string $dayKey, array $commitments): void {
				$this->capturedCapacityChecks[] = ['dayKey' => $dayKey, 'commitments' => $commitments];
			}
		);
		$this->taskAccessPolicy = $this->createMock(TaskAccessPolicy::class);
		$this->commands = $this->createMock(CommandService::class);
		$this->commands->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorId, string $commandId, string $action, array $payload, callable $fn, ?callable $guard = null): array {
				$this->capturedCommands[] = ['action' => $action, 'payload' => $payload];
				$guard?->__invoke($houseId, $actorId);
				return ['result' => $this->commandReplay ? ['roomTaskId' => 5] : $fn(), 'replay' => $this->commandReplay];
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$houseMapper = $this->createMock(HouseMapper::class);
		$house = new House();
		$house->setId(7);
		$house->setTimezone('UTC');
		$houseMapper->method('find')->willReturn($house);

		$this->service = new AgreementService(
			$this->roomTaskMapper,
			$this->taskMapper,
			$houseMapper,
			$this->walletMapper,
			$this->houseUserMapper,
			$this->occMemberMapper,
			$this->occurrenceService,
			$this->dailyCapacity,
			$this->taskAccessPolicy,
			$this->commands,
			new CalendarService(),
			$this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$l10n,
		);
	}

	private function actor(string $role = 'owner'): HouseUser {
		$actor = new HouseUser();
		$actor->setId(1);
		$actor->setHouseId(7);
		$actor->setNextcloudUserId('dad');
		$actor->setRole($role);
		return $actor;
	}

	private function wireVisibleTask(): RoomTask {
		$roomTask = new RoomTask();
		$roomTask->setId(5);
		$roomTask->setTaskId(10);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->actor());
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$task = new Task();
		$task->setId(10);
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskAccessPolicy->method('canViewTask')->willReturn(true);
		$this->stubResolvableTarget();
		return $roomTask;
	}

	/**
	 * The locked occurrence-key resolution returns an unfulfilled open-
	 * window target (version 0, future window end) with a revision whose
	 * default members carry a 50/50 split. The projection stamp is a
	 * no-op by default and the authoritative token matches the payload's
	 * `tok` — individual tests override either seam.
	 */
	private function stubResolvableTarget(): void {
		$occurrence = new \OCA\ChoreStrike\Db\Occurrence();
		$occurrence->setId(9);
		$occurrence->setHouseId(7);
		$occurrence->setScheduleRevId(3);
		$occurrence->setOrdinal(1);
		$occurrence->setEnergy(2);
		$occurrence->setKind(\OCA\ChoreStrike\Db\Occurrence::KIND_SCHEDULED);
		$occurrence->setPlannedDate(new \DateTime('2026-10-06 00:00:00 UTC'));
		$occurrence->setWindowStart(new \DateTime('2026-10-06 00:00:00 UTC'));
		$occurrence->setWindowEnd(new \DateTime('2999-01-01 00:00:00 UTC'));
		$revision = new \OCA\ChoreStrike\Db\ScheduleRevision();
		$revision->setId(3);
		$revision->setRevision(1);
		$revision->setCadenceMode($this->revisionCadence);
		$revision->setEnergy(2);
		$revision->setDefaultMembers([
			['walletId' => 11, 'userId' => 'dad', 'role' => 'responsible', 'obligated' => true, 'sharePct' => 50],
			['walletId' => 12, 'userId' => 'mom', 'role' => 'joint', 'obligated' => true, 'sharePct' => 50],
		]);
		$this->occurrenceService->method('resolveAgreedKeyUnderLock')->willReturn([
			'occurrence' => $occurrence,
			'revision' => $revision,
		]);
		// The command boundary stamps the effective projection facts and
		// recomputes the authoritative selection token (RF-073-WP2-05/22).
		// The R6 resumption seam: a post-write re-stamp may derive a
		// different state (stampedSuspensionAfterWrite).
		$this->occurrenceService->method('stampProjection')
			->willReturnCallback(function (int $houseId, \OCA\ChoreStrike\Db\Occurrence $target): array {
				$this->stampCalls++;
				$state = $this->stampCalls > 1 && $this->stampedSuspensionAfterWrite !== null
					? $this->stampedSuspensionAfterWrite
					: $this->stampedSuspension;
				$target->setSuspensionState($state);
				return ['date' => null, 'zone' => 'UTC'];
			});
		$this->occurrenceService->method('persistSuspensionStampUnderLock')
			->willReturnCallback(function (\OCA\ChoreStrike\Db\Occurrence $target): void {
				$this->persistedStamps++;
				$target->setVersion($target->getVersion() + 1);
			});
		$this->occurrenceService->method('selectionToken')
			->willReturnCallback(fn (): string => $this->authoritativeToken);
		// The explicit-target date writer returns the moved occurrence with
		// its resulting version (RF-073-WP2-01).
		$this->occurrenceService->method('amendOccurrenceDateForTargetUnderLock')
			->willReturnCallback(function (\OCA\ChoreStrike\Db\Occurrence $target, $revision, $roomTask, $actorId, $affectedWalletIds, $newDateKey, $now, $consent = null): \OCA\ChoreStrike\Db\Occurrence {
				$this->dateWriterTargets[] = [
					'occurrence' => $target,
					'affectedWalletIds' => $affectedWalletIds,
					'consent' => $consent,
				];
				$target->setVersion($target->getVersion() + 1);
				return $target;
			});
		// The catch-up canonical-selection recheck resolves to the SAME
		// public key by default.
		$twin = new \OCA\ChoreStrike\Db\Occurrence();
		$twin->setScheduleRevId(3);
		$twin->setOrdinal(1);
		$this->occurrenceService->method('selectionFor')->willReturn([
			'occurrence' => $twin,
			'revision' => $revision,
			'classification' => 'late',
		]);
		$restart = new \OCA\ChoreStrike\Db\Occurrence();
		$restart->setId(55);
		$restart->setScheduleRevId(3);
		$restart->setOrdinal(2);
		$restart->setPlannedDate(new \DateTime('2026-10-08 00:00:00 UTC'));
		$this->occurrenceService->method('createLinkedRestartUnderLock')->willReturn($restart);
	}

	private function wireVisibleTaskAs(string $role): void {
		$roomTask = new RoomTask();
		$roomTask->setId(5);
		$roomTask->setTaskId(10);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->actor($role));
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$this->roomTaskMapper->method('lockForUpdate')->willReturn($roomTask);
		$task = new Task();
		$task->setId(10);
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskAccessPolicy->method('canViewTask')->willReturn(true);
	}

	/** @dataProvider authorityLossProvider */
	public function testCommand_AuthorityChangedUnderLock_DeniesBeforeAggregateAndReplay(string $method, string $loss, bool $replay): void {
		$actor = $this->actor();
		$fresh = clone $actor;
		if ($loss === 'departed') {
			$fresh = null;
		} elseif ($loss === 'rejoined') {
			$fresh->setId(99);
		} elseif ($loss === 'demoted') {
			$fresh->setRole('member');
		}
		$this->commandReplay = $replay;
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnOnConsecutiveCalls($actor, $fresh);
		$roomTask = new RoomTask();
		$roomTask->setId(5);
		$roomTask->setTaskId(10);
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturnOnConsecutiveCalls([$roomTask], $loss === 'foreign' ? [] : [$roomTask]);
		$this->roomTaskMapper->expects($this->never())->method('lockForUpdate');
		$this->taskMapper->method('find')->willReturn(new Task());
		$this->taskAccessPolicy->method('canViewTask')->willReturnOnConsecutiveCalls(true, $loss !== 'invisible');
		$this->occurrenceService->expects($this->never())->method('resolveAgreedKeyUnderLock');
		$this->expectException(in_array($loss, ['foreign', 'invisible'], true) ? NotFoundException::class : ForbiddenException::class);
		$payload = ['commandId' => $this->amendPayload()['commandId'], 'occurrenceId' => '3:1',
			'expectedVersion' => 0, 'selectionToken' => 'tok', 'consent' => true, 'workDate' => '2026-10-07', 'workZone' => 'UTC'];
		match ($method) {
			'amend' => $this->service->amend(7, 'dad', 5, $this->amendPayload()),
			'catchUp' => $this->service->catchUp(7, 'dad', 5, $payload),
			'restart' => $this->service->restart(7, 'dad', 5, array_diff_key($payload, ['selectionToken' => true, 'workZone' => true])),
		};
	}

	public static function authorityLossProvider(): array {
		$cases = [];
		foreach (['amend', 'catchUp', 'restart'] as $method) {
			foreach (['demoted', 'departed', 'rejoined', 'foreign', 'invisible'] as $loss) {
				foreach ([false, true] as $replay) {
					$cases[$method . ' ' . $loss . ' replay=' . (int)$replay] = [$method, $loss, $replay];
				}
			}
		}
		return $cases;
	}

	public function testAmend_MemberActor_Forbidden(): void {
		$this->wireVisibleTaskAs('member');

		$this->expectException(ForbiddenException::class);
		$this->service->amend(7, 'mom', 5, $this->amendPayload());
	}

	public function testAmend_InvisibleTask_NotFound(): void {
		$roomTask = new RoomTask();
		$roomTask->setId(5);
		$roomTask->setTaskId(10);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->actor());
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([$roomTask]);
		$task = new Task();
		$task->setId(10);
		$this->taskMapper->method('find')->willReturn($task);
		$this->taskAccessPolicy->method('canViewTask')->willReturn(false);

		$this->expectException(NotFoundException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload());
	}

	public function testAmend_ForeignRoomTask_NotFound(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->actor());
		$this->roomTaskMapper->method('findByIdsScopedToHouse')->willReturn([]);

		$this->expectException(NotFoundException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload());
	}

	private function amendPayload(array $overrides = []): array {
		return array_merge([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'selectionToken' => 'tok',
			'consent' => true,
			'date' => '2026-10-08',
		], $overrides);
	}

	public function testAmend_MissingConsent_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload(['consent' => false]));
	}

	public function testAmend_MissingSelectionToken_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload(['selectionToken' => '']));
	}

	public function testAmend_UnknownField_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload(['sharePct' => 100]));
	}

	public function testAmend_WithoutDateOrParticipants_Rejected(): void {
		$this->wireVisibleTask();
		$payload = $this->amendPayload();
		unset($payload['date']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $payload);
	}

	public function testAmend_InvalidDate_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload(['date' => '2026-13-45']));
	}

	public function testAmend_ParticipantsNotSummingTo100_Rejected(): void {
		$this->wireVisibleTask();
		$this->walletMapper->method('findByHouseAndUser')->willReturnCallback(function (int $houseId, string $userId): Wallet {
			$wallet = new Wallet();
			$wallet->setId($userId === 'dad' ? 11 : 12);
			$wallet->setHouseId($houseId);
			$wallet->setNextcloudUserId($userId);
			return $wallet;
		});

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => null,
			'participants' => [
				['userId' => 'dad', 'role' => 'responsible', 'sharePct' => 60],
				['userId' => 'mom', 'role' => 'joint', 'sharePct' => 30],
			],
		]));
	}

	public function testAmend_DuplicateParticipants_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => null,
			'participants' => [
				['userId' => 'dad', 'role' => 'responsible', 'sharePct' => 100],
				['userId' => 'dad', 'role' => 'joint', 'sharePct' => 50],
			],
		]));
	}

	public function testAmend_UnknownRole_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => null,
			'participants' => [
				['userId' => 'dad', 'role' => 'boss', 'sharePct' => 100],
			],
		]));
	}

	public function testCatchUp_MissingWorkDate_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->catchUp(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'selectionToken' => 'tok',
			'consent' => true,
		]);
	}

	public function testRestart_MissingConsent_Rejected(): void {
		$this->wireVisibleTask();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->restart(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'consent' => false,
			'workDate' => '2026-10-08',
		]);
	}

	// ------------------------------------------------------------------
	// Fix-round regression pins (RF-073-WP2-01/05/07/19/22)
	// ------------------------------------------------------------------

	private function stubWallets(): void {
		$this->walletMapper->method('findByHouseAndUser')->willReturnCallback(
			static function (int $houseId, string $userId): Wallet {
				$wallet = new Wallet();
				$wallet->setId(['dad' => 11, 'mom' => 12, 'kid' => 13][$userId] ?? 99);
				$wallet->setHouseId($houseId);
				$wallet->setNextcloudUserId($userId);
				return $wallet;
			}
		);
	}

	/**
	 * RF-073-WP2-19: EVERY normalized semantic input rides the receipt
	 * payload under the command's action identity — the same UUID with a
	 * changed date/participant set/work date must conflict on replay
	 * instead of returning the original result.
	 */
	public function testAmend_ReceiptCarriesNormalizedSemanticInputs(): void {
		$this->wireVisibleTask();
		$this->stubWallets();

		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => '2026-10-08',
			'participants' => [
				['userId' => 'mom', 'role' => 'responsible', 'sharePct' => 60],
				['userId' => 'dad', 'role' => 'joint', 'sharePct' => 40],
			],
		]));

		self::assertCount(1, $this->capturedCommands);
		$command = $this->capturedCommands[0];
		self::assertSame(AgreementService::ACTION_AMEND, $command['action']);
		self::assertSame('2026-10-08', $command['payload']['date']);
		// Canonical participant shape: sorted by user id, so a reordered
		// but identical set replays while any changed fact conflicts.
		self::assertSame([
			['userId' => 'dad', 'role' => 'joint', 'sharePct' => 40, 'obligated' => true],
			['userId' => 'mom', 'role' => 'responsible', 'sharePct' => 60, 'obligated' => true],
		], $command['payload']['participants']);
	}

	public function testCatchUp_ReceiptCarriesAppointmentFacts(): void {
		$this->wireVisibleTask();

		$this->service->catchUp(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'selectionToken' => 'tok',
			'consent' => true,
			'workDate' => '2026-10-07',
			'workZone' => 'Europe/Berlin',
			'participants' => null,
		]);

		self::assertCount(1, $this->capturedCommands);
		$command = $this->capturedCommands[0];
		self::assertSame(AgreementService::ACTION_CATCH_UP, $command['action']);
		self::assertSame('2026-10-07', $command['payload']['workDate']);
		self::assertSame('Europe/Berlin', $command['payload']['workZone']);
		self::assertNull($command['payload']['participants']);
	}

	public function testRestart_ReceiptCarriesWorkDate(): void {
		$this->revisionCadence = \OCA\ChoreStrike\Db\ScheduleRevision::CADENCE_INTERVAL;
		$this->stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_SUSPENDED;
		$this->wireVisibleTask();

		$this->service->restart(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'consent' => true,
			'workDate' => '2026-10-09',
		]);

		self::assertCount(1, $this->capturedCommands);
		self::assertSame(AgreementService::ACTION_RESTART, $this->capturedCommands[0]['action']);
		self::assertSame('2026-10-09', $this->capturedCommands[0]['payload']['workDate']);
	}

	/**
	 * RF-073-WP2-22: a shown token that no longer matches the authoritative
	 * recomputation under the lock is a 409 with zero writes — version
	 * equality alone must not pass.
	 */
	public function testAmend_StaleSelectionToken_ConflictsWithoutWrites(): void {
		$this->wireVisibleTask();
		$this->authoritativeToken = 'drifted-authoritative-token';

		$this->expectException(\OCA\ChoreStrike\Exception\ConflictException::class);
		try {
			$this->service->amend(7, 'dad', 5, $this->amendPayload());
		} finally {
			// No writer ran before the token gate.
			self::assertSame([], $this->capturedCapacityChecks);
		}
	}

	/**
	 * RF-073-WP2-05: the restart gate reads the EFFECTIVE (projection-
	 * stamped) suspension state — a legitimately suspended target whose
	 * persisted row still carries the materialization default restarts.
	 */
	public function testRestart_SuspensionDerivedByProjectionStamp_Proceeds(): void {
		$this->revisionCadence = \OCA\ChoreStrike\Db\ScheduleRevision::CADENCE_INTERVAL;
		$this->stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_SUSPENDED;
		$this->wireVisibleTask();
		$this->authoritativeToken = '';

		$result = $this->service->restart(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'consent' => true,
			'workDate' => '2026-10-09',
		]);

		self::assertSame('3:2', $result['occurrenceId'], 'the linked restart successor is the reported result identity');
	}

	/**
	 * RF-073-WP2-07: a concretely agreed helper commitment is capacity-
	 * checked with its exact share — helpers are not skipped.
	 */
	public function testAmend_HelperCommitment_IsCapacityChecked(): void {
		$this->wireVisibleTask();
		$this->stubWallets();

		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => '2026-10-08',
			'participants' => [
				['userId' => 'dad', 'role' => 'responsible', 'sharePct' => 100],
				['userId' => 'kid', 'role' => 'helper', 'sharePct' => 25],
			],
		]));

		self::assertCount(1, $this->capturedCapacityChecks);
		$check = $this->capturedCapacityChecks[0];
		self::assertSame('2026-10-08', $check['dayKey']);
		$byUser = [];
		foreach ($check['commitments'] as $commitment) {
			$byUser[$commitment['userId']] = $commitment['energyMinor'];
		}
		// Revision energy 2 → dad 100% = 200 minor, kid helper 25% = 50.
		self::assertSame(['dad' => 200, 'kid' => 50], $byUser);
	}

	/**
	 * RF-073-WP2-01: the date path moves EXACTLY the resolved occurrence
	 * through the explicit-target writer and reports the RESULTING version.
	 */
	public function testAmend_DatePath_UsesExplicitTargetWriterAndReportsResultingVersion(): void {
		$this->wireVisibleTask();

		$result = $this->service->amend(7, 'dad', 5, $this->amendPayload(['date' => '2026-10-08']));

		self::assertCount(1, $this->dateWriterTargets, 'the explicit-target writer ran exactly once');
		self::assertSame(9, $this->dateWriterTargets[0]['occurrence']->getId(), 'the resolved occurrence itself was moved, never a re-selected one');
		self::assertSame(1, $result['expectedVersion'], 'the receipt refs report the resulting version after the write');
	}

	// ------------------------------------------------------------------
	// Concrete-arrangement validation (R5, RF-073-WP2-27) and joint
	// resumption re-stamping (R6, RF-073-WP2-24)
	// ------------------------------------------------------------------

	private function stubHouseMembers(): void {
		$this->houseUserMapper->method('findByHouseId')->willReturnCallback(function (): array {
			$dad = new HouseUser();
			$dad->setId(1);
			$dad->setNextcloudUserId('dad');
			$dad->setWalletId(11);
			$mom = new HouseUser();
			$mom->setId(2);
			$mom->setNextcloudUserId('mom');
			$mom->setWalletId(12);
			return [$dad, $mom];
		});
	}

	private function stubConcreteMembers(OccurrenceMember ...$members): void {
		$this->occMemberMapper->method('findByOccurrence')->willReturn($members);
	}

	private function concreteMember(int $walletId, string $role = OccurrenceMember::ROLE_RESPONSIBLE, bool $obligated = true, int $sharePct = 100, string $disposition = OccurrenceMember::DISPOSITION_ACTIVE): OccurrenceMember {
		$member = new OccurrenceMember();
		$member->setWalletId($walletId);
		$member->setRole($role);
		$member->setObligated($obligated);
		$member->setSharePct($sharePct);
		$member->setDisposition($disposition);
		return $member;
	}

	/**
	 * RF-073-WP2-27: after a concrete A-to-B handover the follow-up
	 * date-only amendment validates and records consent against the
	 * ACTUAL participant rows (B), never the revision defaults (A+B).
	 */
	public function testAmend_DateOnly_AfterHandover_ValidatesConcreteArrangement(): void {
		$this->wireVisibleTask();
		$this->stubHouseMembers();
		// Concrete arrangement: dad (wallet 11) released, mom (wallet 12)
		// is the actual responsible — while the revision defaults still
		// name the 50/50 dad+mom split.
		$this->stubConcreteMembers(
			$this->concreteMember(11, disposition: OccurrenceMember::DISPOSITION_RELEASED),
			$this->concreteMember(12)
		);

		$this->service->amend(7, 'dad', 5, $this->amendPayload(['date' => '2026-10-08']));

		self::assertCount(1, $this->capturedCapacityChecks);
		$check = $this->capturedCapacityChecks[0];
		self::assertSame('2026-10-08', $check['dayKey']);
		self::assertSame(
			[['userId' => 'mom', 'energyMinor' => 200, 'count' => 1]],
			$check['commitments'],
			'only the concrete responsible is charged (occurrence energy 2 x 100%)'
		);
		self::assertSame([12], $this->dateWriterTargets[0]['affectedWalletIds'], 'the consent evidence names the actual responsible, not the replaced default');
	}

	/**
	 * RF-073-WP2-27 (catch-up path): a catch-up WITHOUT a participant
	 * payload binds the concrete current participants on the new work day.
	 */
	public function testCatchUp_WithoutParticipants_ValidatesConcreteArrangement(): void {
		$this->wireVisibleTask();
		$this->stubHouseMembers();
		$this->stubConcreteMembers(
			$this->concreteMember(12, role: OccurrenceMember::ROLE_JOINT, sharePct: 60),
			$this->concreteMember(11, role: OccurrenceMember::ROLE_JOINT, sharePct: 40)
		);

		$this->service->catchUp(7, 'dad', 5, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'occurrenceId' => '3:1',
			'expectedVersion' => 0,
			'selectionToken' => 'tok',
			'consent' => true,
			'workDate' => '2026-10-07',
		]);

		self::assertCount(1, $this->capturedCapacityChecks);
		$byUser = [];
		foreach ($this->capturedCapacityChecks[0]['commitments'] as $commitment) {
			$byUser[$commitment['userId']] = $commitment['energyMinor'];
		}
		// Occurrence energy 2: mom 60% = 120 minor, dad 40% = 80 minor —
		// the exact concrete shares, not the 50/50 revision defaults.
		self::assertSame(['mom' => 120, 'dad' => 80], $byUser);
	}

	/**
	 * R5 fallback: a virtual arrangement (no materialized rows) still
	 * derives from the revision defaults.
	 */
	public function testAmend_DateOnly_WithoutConcreteRows_FallsBackToRevisionDefaults(): void {
		$this->wireVisibleTask();
		$this->stubHouseMembers();
		$this->stubConcreteMembers();

		$this->service->amend(7, 'dad', 5, $this->amendPayload(['date' => '2026-10-08']));

		self::assertCount(1, $this->capturedCapacityChecks);
		$byUser = [];
		foreach ($this->capturedCapacityChecks[0]['commitments'] as $commitment) {
			$byUser[$commitment['userId']] = $commitment['energyMinor'];
		}
		self::assertSame(['dad' => 100, 'mom' => 100], $byUser, 'the revision 50/50 defaults are the derivation basis without concrete rows');
	}

	/**
	 * R6/RF-073-WP2-24: an explicit feasible agreement that resumes a
	 * suspended occurrence re-derives the effective suspension from the
	 * NEW member rows and persists the drift, so the next GET/job agrees
	 * with the accepted arrangement.
	 */
	public function testAmend_Participants_ResumingSuspendedTarget_PersistsRestampedFacts(): void {
		$this->stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_SUSPENDED;
		$this->stampedSuspensionAfterWrite = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_NONE;
		$this->wireVisibleTask();
		$this->stubWallets();

		$result = $this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => null,
			'participants' => [
				['userId' => 'mom', 'role' => 'responsible', 'sharePct' => 100],
			],
		]));

		// Stamp write 1 is the RF-05 command-boundary drift persist (the
		// stubbed row still carried the materialization default); stamp
		// write 2 is the R6 post-write restamp of the resumed arrangement.
		self::assertSame(2, $this->persistedStamps, 'the post-write re-stamp persisted the resumed state');
		self::assertSame(2, $result['expectedVersion'], 'the receipt refs report the version after the restamp write');
	}

	/**
	 * R6: when the accepted arrangement keeps the occurrence suspended
	 * (unchanged effective facts), no extra stamp write happens.
	 */
	public function testAmend_Participants_UnchangedSuspension_NoExtraStampWrite(): void {
		$this->stampedSuspension = \OCA\ChoreStrike\Db\Occurrence::SUSPENSION_SUSPENDED;
		$this->wireVisibleTask();
		$this->stubWallets();

		$this->service->amend(7, 'dad', 5, $this->amendPayload([
			'date' => null,
			'participants' => [
				['userId' => 'mom', 'role' => 'responsible', 'sharePct' => 100],
			],
		]));

		// Only the RF-05 command-boundary drift persist ran; the post-write
		// restamp derived the SAME suspended state and wrote nothing extra.
		self::assertSame(1, $this->persistedStamps);
	}
}
