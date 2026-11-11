<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\AgreementMapper;
use OCA\ChoreStrike\Db\DigestChange;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Db\OccurrenceMapper;
use OCA\ChoreStrike\Db\OccurrenceMemberMapper;
use OCA\ChoreStrike\Db\Pause;
use OCA\ChoreStrike\Db\PauseMapper;
use OCA\ChoreStrike\Db\TransactionExecutor;
use OCA\ChoreStrike\Db\Wallet;
use OCA\ChoreStrike\Db\WalletMapper;
use OCA\ChoreStrike\Exception\ConflictException;
use OCA\ChoreStrike\Exception\ForbiddenException;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\CommandService;
use OCA\ChoreStrike\Service\PauseProjectionService;
use OCA\ChoreStrike\Service\PauseService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The pause command semantics (CS-PLAN-073 §3.4, ADR-020): direct
 * acknowledgment for every role, the owner household pause, the owner
 * decision gate with the expected revision, the next-day shortening
 * boundary in the pause's saved zone and the deletion. The
 * CommandService mock EXECUTES the aggregate callable directly — the
 * receipt mechanics are CommandService's own contract.
 *
 * @covers \OCA\ChoreStrike\Service\PauseService
 */
class PauseServiceTest extends TestCase {
	private PauseService $service;
	private PauseMapper|MockObject $pauseMapper;
	private HouseUserMapper|MockObject $houseUserMapper;
	private WalletMapper|MockObject $walletMapper;
	private HouseMapper|MockObject $houseMapper;
	private OccurrenceMapper|MockObject $occurrenceMapper;
	private IL10N|MockObject $l10n;
	private \OCA\ChoreStrike\Db\DigestChangeMapper|MockObject $digestChangeMapper;
	private bool $commandReplay = false;

	protected function setUp(): void {
		parent::setUp();
		$this->pauseMapper = $this->createMock(PauseMapper::class);
		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->walletMapper = $this->createMock(WalletMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		$this->occurrenceMapper = $this->createMock(OccurrenceMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$projection = new PauseProjectionService(
			$this->pauseMapper,
			$this->houseUserMapper,
			$this->createMock(OccurrenceMemberMapper::class),
		);

		// The executing command service: the aggregate runs immediately,
		// no receipt round-trip.
		$commands = $this->createMock(CommandService::class);
		$commands->method('runCommand')->willReturnCallback(
			function (int $houseId, int $actorId, string $commandId, string $action, array $payload, callable $fn, ?callable $guard = null): array {
				$guard?->__invoke($houseId, $actorId);
				return ['result' => $this->commandReplay ? ['pauseGroupId' => 5] : $fn(), 'replay' => $this->commandReplay];
			}
		);

		$house = new \OCA\ChoreStrike\Db\House();
		$house->setId(7);
		$house->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($house);

		$this->service = new PauseService(
			$commands,
			$this->pauseMapper,
			$projection,
			$this->houseMapper,
			$this->houseUserMapper,
			$this->walletMapper,
			$this->occurrenceMapper,
			$this->createMock(OccurrenceMemberMapper::class),
			$this->createMock(AgreementMapper::class),
			new TransactionExecutor($this->createMock(\OCP\IDBConnection::class)),
			new CalendarService(),
			$this->digestChangeMapper = $this->createMock(\OCA\ChoreStrike\Db\DigestChangeMapper::class),
			$this->createMock(\OCA\ChoreStrike\Service\DigestScheduleService::class),
			$this->l10n,
		);
	}

	private function member(string $userId, string $role, int $walletId = 11): HouseUser {
		$member = new HouseUser();
		$member->setId(1);
		$member->setHouseId(7);
		$member->setNextcloudUserId($userId);
		$member->setRole($role);
		$member->setWalletId($walletId);
		return $member;
	}

	private function wallet(int $walletId): Wallet {
		$wallet = new Wallet();
		$wallet->setId($walletId);
		$wallet->setHouseId(7);
		$wallet->setNextcloudUserId('kid');
		return $wallet;
	}

	private function payload(array $overrides = []): array {
		return array_merge([
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'startDate' => '2026-10-05',
			'endDate' => '2026-10-09',
			'reasonCode' => Pause::REASON_TRAVEL,
		], $overrides);
	}

	/** @dataProvider lostAuthorityProvider */
	public function testCommand_AuthorityChangedBeforeHouseLock_DeniesExecutionAndReplay(string $method, string $loss, bool $replay): void {
		$actor = $this->member('dad', 'owner');
		$fresh = clone $actor;
		if ($loss === 'departed') {
			$fresh = null;
		} elseif ($loss === 'rejoined') {
			$fresh->setId(99);
		} else {
			$fresh->setRole('member');
		}
		$this->commandReplay = $replay;
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnOnConsecutiveCalls($actor, $fresh);
		$this->pauseMapper->expects($this->never())->method('appendRevision');
		$this->pauseMapper->expects($this->never())->method('update');
		$this->occurrenceMapper->expects($this->never())->method('findOpenByHouseId');
		$this->expectException(ForbiddenException::class);
		match ($method) {
			'createHousePause' => $this->service->createHousePause(7, 'dad', $this->payload()),
			'recognizeIllness' => $this->service->recognizeIllness(7, 'dad', $this->payload(['subjectUserId' => 'kid'])),
			'approve' => $this->service->approve(7, 'dad', 5, ['commandId' => $this->payload()['commandId'], 'expectedRevision' => 1]),
			'reject' => $this->service->reject(7, 'dad', 5, ['commandId' => $this->payload()['commandId'], 'expectedRevision' => 1]),
		};
	}

	public static function lostAuthorityProvider(): array {
		$cases = [];
		foreach (['createHousePause', 'recognizeIllness', 'approve', 'reject'] as $method) {
			foreach (['demoted', 'departed', 'rejoined'] as $loss) {
				foreach ([false, true] as $replay) {
					$cases[$method . ' ' . $loss . ' replay=' . (int)$replay] = [$method, $loss, $replay];
				}
			}
		}
		return $cases;
	}

	public function testRequestForSelf_RoleChangedUnderLock_StillAcknowledged(): void {
		$actor = $this->member('dad', 'owner');
		$fresh = clone $actor;
		$fresh->setRole('child');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnOnConsecutiveCalls($actor, $fresh);
		$this->walletMapper->method('findByHouseAndUser')->willReturn($this->wallet(11));
		$this->pauseMapper->method('maxGroupId')->willReturn(1);
		$this->pauseMapper->method('appendRevision')->willReturnCallback(static function (Pause $pause): Pause {
			self::assertSame(Pause::STATE_ACKNOWLEDGED, $pause->getState());
			$pause->setId(1);
			$pause->setRevision(1);
			return $pause;
		});
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $this->service->requestForSelf(7, 'dad', $this->payload())['state']);
	}

	/** @dataProvider shortenReplayProvider */
	public function testShorten_ReplayAfterAuthorityLoss_DeniesWithoutNewRevision(string $loss): void {
		$actor = $this->member('dad', 'owner');
		$fresh = clone $actor;
		if ($loss === 'departed') {
			$fresh = null;
		} else {
			$fresh->setRole('member');
		}
		$this->commandReplay = true;
		$this->houseUserMapper->method('findByHouseAndUser')->willReturnOnConsecutiveCalls($actor, $fresh);
		$pause = $this->pause();
		$pause->setScope(Pause::SCOPE_HOUSE);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($pause);
		$this->pauseMapper->expects($this->never())->method('appendRevision');
		$this->expectException(ForbiddenException::class);
		$this->service->shorten(7, 'dad', 5, ['commandId' => $this->payload()['commandId'], 'expectedRevision' => 1, 'endDate' => '2026-10-06']);
	}

	public static function shortenReplayProvider(): array {
		return ['demoted' => ['demoted'], 'departed' => ['departed']];
	}

	// -----------------------------------------------------------------
	// Creation semantics
	// ------------------------------------------------------------------

	public function testRequestForSelf_Child_CreatesAcknowledged(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('kid', 'child'));
		$this->walletMapper->method('findByHouseAndUser')->willReturn($this->wallet(11));
		$created = null;
		$this->pauseMapper->method('maxGroupId')->willReturn(1);
		$this->pauseMapper->method('appendRevision')->willReturnCallback(function (Pause $pause) use (&$created): Pause {
			$pause->setId(1);
			$pause->setRevision(1);
			$created = $pause;
			return $pause;
		});

		$result = $this->service->requestForSelf(7, 'kid', $this->payload());

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state'], 'a child needs no request (ADR-020)');
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $created?->getState());
		self::assertSame(11, $created?->getSubjectWalletId());
		self::assertSame(Pause::SCOPE_PERSONAL, $created?->getScope());
	}

	public function testRequestForSelf_Member_CreatesAcknowledged(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('mom', 'member'));
		$this->walletMapper->method('findByHouseAndUser')->willReturn($this->wallet(11));
		$this->pauseMapper->method('maxGroupId')->willReturn(1);
		$created = null;
		$this->pauseMapper->method('appendRevision')->willReturnCallback(function (Pause $pause) use (&$created): Pause {
			$pause->setId(1);
			$pause->setRevision(1);
			$created = $pause;
			return $pause;
		});

		$result = $this->service->requestForSelf(7, 'mom', $this->payload());

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state']);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $created?->getState());
	}

	public function testCreateHousePause_MemberActor_Forbidden(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('mom', 'member'));

		$this->expectException(ForbiddenException::class);
		$this->service->createHousePause(7, 'mom', $this->payload());
	}

	public function testCreateHousePause_Owner_HouseScopeAcknowledged(): void {
		$owner = $this->member('dad', 'owner');
		$owner->setId(2);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$this->pauseMapper->method('maxGroupId')->willReturn(1);
		$created = null;
		$this->pauseMapper->method('appendRevision')->willReturnCallback(function (Pause $pause) use (&$created): Pause {
			$pause->setId(2);
			$pause->setRevision(1);
			$created = $pause;
			return $pause;
		});

		$this->service->createHousePause(7, 'dad', $this->payload());

		self::assertSame(Pause::SCOPE_HOUSE, $created?->getScope());
		self::assertNull($created?->getSubjectWalletId());
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $created?->getState());
	}

	// -----------------------------------------------------------------
	// Validation
	// ------------------------------------------------------------------

	public function testRequestForSelf_InvalidRange_Rejected(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('kid', 'child'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->requestForSelf(7, 'kid', $this->payload(['endDate' => '2026-10-04']));
	}

	public function testRequestForSelf_UnknownReason_Rejected(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('kid', 'child'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->requestForSelf(7, 'kid', $this->payload(['reasonCode' => 'migraine', 'commandId' => '11111111-2222-4333-8444-555555555555']));
	}

	public function testRequestForSelf_UnknownField_Rejected(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('kid', 'child'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->requestForSelf(7, 'kid', $this->payload(['note' => 'free text is never stored']));
	}

	// -----------------------------------------------------------------
	// Decision gate
	// ------------------------------------------------------------------

	private function pendingPause(int $revision = 1): Pause {
		$pause = $this->pause();
		$pause->setState(Pause::STATE_REQUESTED);
		$pause->setRevision($revision);
		return $pause;
	}

	private function pause(): Pause {
		$pause = new Pause();
		$pause->setId(1);
		$pause->setHouseId(7);
		$pause->setPauseGroupId(1);
		$pause->setScope(Pause::SCOPE_PERSONAL);
		$pause->setSubjectWalletId(11);
		$pause->setReasonCode(Pause::REASON_TRAVEL);
		$pause->setStartDate(new \DateTime('2026-10-05'));
		$pause->setEndDate(new \DateTime('2026-10-09'));
		$pause->setTimezone('UTC');
		return $pause;
	}

	public function testApprove_NonOwnerActor_Forbidden(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('mom', 'member'));

		$this->expectException(ForbiddenException::class);
		$this->service->approve(7, 'mom', 1, ['commandId' => '11111111-2222-4333-8444-555555555555', 'expectedRevision' => 1]);
	}

	public function testApprove_StaleRevision_Conflict(): void {
		$owner = $this->member('dad', 'owner');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($this->pendingPause(3));

		$this->expectException(ConflictException::class);
		$this->service->approve(7, 'dad', 1, ['commandId' => '11111111-2222-4333-8444-555555555555', 'expectedRevision' => 1]);
	}

	public function testApprove_StampsDecisionAndAppliesEffects(): void {
		$owner = $this->member('dad', 'owner');
		$owner->setId(2);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$pending = $this->pendingPause(1);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($pending);
		$updated = null;
		$this->pauseMapper->method('update')->willReturnCallback(function (Pause $pause) use (&$updated): void {
			$updated = $pause;
		});

		$result = $this->service->approve(7, 'dad', 1, ['commandId' => '11111111-2222-4333-8444-555555555555', 'expectedRevision' => 1]);

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state']);
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $updated?->getState());
		self::assertSame(2, $updated?->getDeciderHouseUserId());
	}

	// -----------------------------------------------------------------
	// Shortening boundary (next-day, saved zone)
	// ------------------------------------------------------------------

	public function testShorten_TodayPreserved_NextDayEarliestEffective(): void {
		$owner = $this->member('dad', 'owner');
		$owner->setId(2);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$acknowledged = $this->pause();
		$acknowledged->setState(Pause::STATE_ACKNOWLEDGED);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($acknowledged);
		$this->pauseMapper->method('nextGroupRevision')->willReturn(2);
		$this->pauseMapper->method('nextGroupRevision')->willReturn(2);
		$created = null;
		$this->pauseMapper->method('appendRevision')->willReturnCallback(function (Pause $pause) use (&$created): Pause {
			$pause->setId(9);
			$pause->setRevision(2);
			$created = $pause;
			return $pause;
		});

		// Attempting to cut to YESTERDAY must preserve today: the
		// effective end clamps to today (the invariant tested is
		// `effectiveEnd >= today` and `< old end`).
		$todayKey = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$acknowledged->setStartDate(new \DateTime('2020-01-01'));
		$acknowledged->setEndDate(new \DateTime('2999-12-31'));

		$result = $this->service->shorten(7, 'dad', 1, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'expectedRevision' => 1,
			'endDate' => '2000-01-01',
		]);

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state']);
		self::assertGreaterThanOrEqual(
			strtotime($todayKey),
			$created?->getEndDate()?->getTimestamp(),
			'the shortened coverage never cuts today\'s recognized excuse'
		);
	}

	public function testShorten_NoEarlierEnd_Conflict(): void {
		$owner = $this->member('dad', 'owner');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$acknowledged = $this->pause();
		$acknowledged->setState(Pause::STATE_ACKNOWLEDGED);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($acknowledged);

		$this->expectException(ConflictException::class);
		$this->service->shorten(7, 'dad', 1, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'expectedRevision' => 1,
			'endDate' => '2026-10-09',
		]);
	}

	public function testShorten_StaleRevision_Conflict(): void {
		$owner = $this->member('dad', 'owner');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$acknowledged = $this->pause();
		$acknowledged->setState(Pause::STATE_ACKNOWLEDGED);
		$acknowledged->setRevision(4);
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($acknowledged);

		$this->expectException(ConflictException::class);
		$this->service->shorten(7, 'dad', 1, [
			'commandId' => '11111111-2222-4333-8444-555555555555',
			'expectedRevision' => 1,
			'endDate' => '2026-10-08',
		]);
	}

	public function testShorten_ChildSubject_AcknowledgedWithoutOwnerDecision(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('kid', 'child'));
		$this->houseUserMapper->method('findByHouseId')->willReturn([$this->member('kid', 'child')]);
		$acknowledged = $this->pause();
		$acknowledged->setState(Pause::STATE_ACKNOWLEDGED);
		$acknowledged->setStartDate(new \DateTime('2020-01-01'));
		$acknowledged->setEndDate(new \DateTime('2999-12-31'));
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($acknowledged);
		$created = null;
		$this->captureAppended($created);

		$result = $this->service->shorten(7, 'kid', 1, ['endDate' => '2999-01-01'] + self::REVISION_PAYLOAD);

		self::assertSame(Pause::STATE_ACKNOWLEDGED, $result['state']);
		self::assertSame(1, $created?->getDeciderHouseUserId(), 'the child decides for itself');
	}

	// -----------------------------------------------------------------
	// Deletion (ADR-020, D-090-22)
	// ------------------------------------------------------------------

	private const REVISION_PAYLOAD = ['commandId' => '11111111-2222-4333-8444-555555555555', 'expectedRevision' => 1];

	/**
	 * A personal absence of wallet 11 ('kid') as the current revision of
	 * group 1; by default it runs today.
	 *
	 * @param Pause[] $earlier
	 */
	private function wireDeletable(string $state, string $start = '2020-01-01', string $end = '2999-12-31', array $earlier = []): Pause {
		$current = $this->pause();
		$current->setState($state);
		$current->setRevision(count($earlier) + 1);
		$current->setStartDate(new \DateTime($start));
		$current->setEndDate(new \DateTime($end));
		$current->setCreatedAt(new \DateTime('2020-01-01 08:00:00'));
		$current->setUpdatedAt(new \DateTime('2020-01-01 08:00:00'));
		$this->pauseMapper->method('findCurrentByGroup')->willReturn($current);
		$this->pauseMapper->method('findByGroup')->willReturn([...$earlier, $current]);
		return $current;
	}

	private function captureAppended(?Pause &$created): void {
		$this->pauseMapper->method('appendRevision')->willReturnCallback(function (Pause $pause) use (&$created): Pause {
			$pause->setRevision(2);
			$created = $pause;
			return $pause;
		});
	}

	public function testDelete_Subject_AppendsCancelledRevisionWithSameFacts(): void {
		$kid = $this->member('kid', 'child');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($kid);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$kid]);
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED);
		$created = null;
		$this->captureAppended($created);
		$this->pauseMapper->expects($this->never())->method('update');

		$result = $this->service->delete(7, 'kid', 1, self::REVISION_PAYLOAD);

		self::assertSame(['pauseGroupId' => 1, 'revision' => 2, 'state' => Pause::STATE_CANCELLED], $result);
		self::assertSame(Pause::STATE_CANCELLED, $created?->getState());
		self::assertSame(1, $created?->getPauseGroupId());
		self::assertSame(11, $created?->getSubjectWalletId());
		self::assertSame('2020-01-01', $created?->getStartDate()->format('Y-m-d'), 'the range is kept, the state ends it');
		self::assertSame('2999-12-31', $created?->getEndDate()->format('Y-m-d'));
		self::assertNotNull($created?->getUpdatedAt(), 'the decision time carries the next-day boundary');
	}

	public function testDelete_Owner_DeletesSomeoneElsesAbsenceAndQueuesDigestForSubject(): void {
		$owner = $this->member('dad', 'owner', 12);
		$owner->setId(2);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$this->houseUserMapper->method('findByWalletId')->with(11)->willReturn($this->member('kid', 'child'));
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED);
		$created = null;
		$this->captureAppended($created);
		$queued = [];
		$this->digestChangeMapper->method('queueOrCoalesce')->willReturnCallback(function (DigestChange $change) use (&$queued): DigestChange {
			$queued[] = [$change->getHouseUserId(), $change->getObjectType(), $change->getObjectRevision()];
			return $change;
		});

		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);

		self::assertSame(Pause::STATE_CANCELLED, $created?->getState());
		self::assertSame([[1, 'pause', 2]], $queued, 'the subject learns about the deletion by the owner');
	}

	public function testDelete_OwnAbsence_QueuesNoDigest(): void {
		$kid = $this->member('kid', 'child');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($kid);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$kid]);
		$this->houseUserMapper->method('findByWalletId')->willReturn($kid);
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED);
		$created = null;
		$this->captureAppended($created);
		$this->digestChangeMapper->expects($this->never())->method('queueOrCoalesce');

		$this->service->delete(7, 'kid', 1, self::REVISION_PAYLOAD);
	}

	public function testDelete_HouseAbsence_QueuesDigestForEveryOtherMember(): void {
		$owner = $this->member('dad', 'owner', 12);
		$owner->setId(2);
		$mom = $this->member('mom', 'member', 13);
		$mom->setId(3);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($owner);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$owner, $mom, $this->member('kid', 'child')]);
		$house = $this->wireDeletable(Pause::STATE_ACKNOWLEDGED);
		$house->setScope(Pause::SCOPE_HOUSE);
		$house->setSubjectWalletId(null);
		$created = null;
		$this->captureAppended($created);
		$recipients = [];
		$this->digestChangeMapper->method('queueOrCoalesce')->willReturnCallback(function (DigestChange $change) use (&$recipients): DigestChange {
			$recipients[] = $change->getHouseUserId();
			return $change;
		});

		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);

		self::assertSame([3, 1], $recipients);
	}

	/** @dataProvider foreignDeleteProvider */
	public function testDelete_NeitherSubjectNorOwner_Forbidden(string $role, string $scope): void {
		$other = $this->member('sis', $role, 13);
		$other->setId(4);
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($other);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$other, $this->member('kid', 'child')]);
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED)->setScope($scope);
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		$this->expectException(ForbiddenException::class);
		$this->service->delete(7, 'sis', 1, self::REVISION_PAYLOAD);
	}

	public static function foreignDeleteProvider(): array {
		return [
			'child, personal' => ['child', Pause::SCOPE_PERSONAL],
			'member, personal' => ['member', Pause::SCOPE_PERSONAL],
			'member, house' => ['member', Pause::SCOPE_HOUSE],
		];
	}

	public function testDelete_StaleRevision_Conflict(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED)->setRevision(3);
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		$this->expectException(ConflictException::class);
		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);
	}

	public function testDelete_ForeignHouse_NotFound(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED)->setHouseId(8);

		$this->expectException(\OCA\ChoreStrike\Exception\NotFoundException::class);
		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);
	}

	public function testDelete_Replay_ReturnsReceiptWithoutNewRevision(): void {
		$this->commandReplay = true;
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->wireDeletable(Pause::STATE_CANCELLED);
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		self::assertSame(['pauseGroupId' => 5], $this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD));
	}

	public function testDelete_PendingRequest_Withdrawn(): void {
		$kid = $this->member('kid', 'child');
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($kid);
		$this->houseUserMapper->method('findByHouseId')->willReturn([$kid]);
		// Also when its range lies in the past: nothing was ever covered.
		$this->wireDeletable(Pause::STATE_REQUESTED, '2020-01-01', '2020-01-05');
		$created = null;
		$this->captureAppended($created);

		$result = $this->service->delete(7, 'kid', 1, self::REVISION_PAYLOAD);

		self::assertSame(Pause::STATE_CANCELLED, $result['state']);
		self::assertSame(Pause::STATE_CANCELLED, $created?->getState());
	}

	public function testDelete_EndedAbsence_Conflict(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->wireDeletable(Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2020-01-05');
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('An absence that has ended cannot be deleted');
		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);
	}

	public function testDelete_EndedAbsenceBehindPendingShortening_Conflict(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$ended = $this->pause();
		$ended->setState(Pause::STATE_ACKNOWLEDGED);
		$ended->setStartDate(new \DateTime('2020-01-01'));
		$ended->setEndDate(new \DateTime('2020-01-05'));
		$ended->setUpdatedAt(new \DateTime('2019-12-30 08:00:00'));
		$this->wireDeletable(Pause::STATE_REQUESTED, '2020-01-01', '2020-01-03', [$ended]);
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('An absence that has ended cannot be deleted');
		$this->service->delete(7, 'dad', 1, ['expectedRevision' => 2] + self::REVISION_PAYLOAD);
	}

	public function testDelete_DeclinedShortening_DeletesTheAbsenceItLeftInForce(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->houseUserMapper->method('findByWalletId')->willReturn($this->member('kid', 'child'));
		$running = $this->revisionOf(1, Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2999-12-31');
		$this->wireDeletable(Pause::STATE_REJECTED, '2020-01-01', '2020-01-03', [$running]);
		$created = null;
		$this->captureAppended($created);

		$this->service->delete(7, 'dad', 1, ['expectedRevision' => 2] + self::REVISION_PAYLOAD);

		self::assertSame(Pause::STATE_CANCELLED, $created?->getState(), 'the declined shortening does not block the deletion');
		self::assertSame('2999-12-31', $created?->getEndDate()->format('Y-m-d'), 'the cancelled revision carries the absence that stood');
	}

	/** @dataProvider closedStateProvider */
	public function testDelete_AlreadyClosed_Conflict(string $state): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));
		$this->wireDeletable($state);
		$this->pauseMapper->expects($this->never())->method('appendRevision');

		$this->expectException(ConflictException::class);
		$this->service->delete(7, 'dad', 1, self::REVISION_PAYLOAD);
	}

	public static function closedStateProvider(): array {
		return ['cancelled' => [Pause::STATE_CANCELLED], 'rejected' => [Pause::STATE_REJECTED]];
	}

	public function testDelete_MalformedPayload_Rejected(): void {
		$this->houseUserMapper->method('findByHouseAndUser')->willReturn($this->member('dad', 'owner'));

		$this->expectException(\InvalidArgumentException::class);
		$this->service->delete(7, 'dad', 1, ['commandId' => '11111111-2222-4333-8444-555555555555']);
	}

	// -----------------------------------------------------------------
	// Viewer projection (RF-073-WP2-28): personal metadata is self/owner
	// data in EVERY state; unrelated members receive only neutral facts.
	// ------------------------------------------------------------------

	private function listedPause(string $state, string $scope = Pause::SCOPE_PERSONAL, ?int $subjectWalletId = 11, bool $retrospective = false): Pause {
		$pause = $this->pause();
		$pause->setState($state);
		$pause->setScope($scope);
		$pause->setSubjectWalletId($subjectWalletId);
		$pause->setReasonCode(Pause::REASON_ILLNESS);
		$pause->setIsRetrospectiveIllness($retrospective);
		$pause->setCreatedAt(new \DateTime('2026-10-01 08:00:00'));
		$pause->setUpdatedAt(new \DateTime('2026-10-01 08:00:00'));
		return $pause;
	}

	private function wireListFor(string $viewerUserId, string $viewerRole, array $pauses, int $viewerWalletId = 12, ?array $extraMembers = null): array {
		$members = [$this->member($viewerUserId, $viewerRole, $viewerWalletId)];
		if ($viewerUserId !== 'kid') {
			$members[] = $this->member('kid', 'child', 11);
		}
		foreach ($extraMembers ?? [] as $extra) {
			$members[] = $extra;
		}
		$this->houseUserMapper->method('findByHouseId')->willReturn($members);
		$this->pauseMapper->method('findByHouseId')->willReturn($pauses);
		return $this->service->listForViewer(7, $viewerUserId);
	}

	public function testListForViewer_UnrelatedMember_AcknowledgedPersonalPause_HidesPersonalMetadata(): void {
		$list = $this->wireListFor('mom', 'member', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED, retrospective: true),
		]);

		self::assertCount(1, $list, 'the recognized coverage fact stays visible');
		self::assertArrayNotHasKey('reasonCode', $list[0], 'the personal reason is self/owner data');
		self::assertArrayNotHasKey('isRetrospectiveIllness', $list[0], 'the illness marker is self/owner data');
		self::assertArrayNotHasKey('id', $list[0], 'explicit whitelist — no surrogate row id on the wire');
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $list[0]['state']);
		self::assertSame('2026-10-05', $list[0]['startDate']);
		self::assertSame('2026-10-09', $list[0]['endDate']);
		self::assertSame('kid', $list[0]['subjectUserId']);
	}

	public function testListForViewer_UnrelatedMember_EveryListedState_HidesPersonalMetadata(): void {
		$pauses = [];
		foreach ([Pause::STATE_ACKNOWLEDGED, Pause::STATE_REJECTED] as $index => $state) {
			$pause = $this->listedPause($state);
			$pause->setPauseGroupId($index + 1);
			$pauses[] = $pause;
		}
		$list = $this->wireListFor('mom', 'member', $pauses);

		self::assertSame([Pause::STATE_ACKNOWLEDGED, Pause::STATE_REJECTED], array_column($list, 'state'));
		foreach ($list as $entry) {
			self::assertArrayNotHasKey('reasonCode', $entry, "state {$entry['state']} leaks no personal reason");
			self::assertArrayNotHasKey('isRetrospectiveIllness', $entry);
		}
	}

	public function testListForViewer_CancelledCurrentRevision_LeftOutForEveryRole(): void {
		$deleted = $this->listedPause(Pause::STATE_CANCELLED);
		$deleted->setRevision(2);
		$first = $this->listedPause(Pause::STATE_ACKNOWLEDGED);
		$kept = $this->listedPause(Pause::STATE_ACKNOWLEDGED);
		$kept->setPauseGroupId(2);

		$list = $this->wireListFor('dad', 'owner', [$first, $deleted, $kept]);

		self::assertSame([2], array_column($list, 'pauseGroupId'), 'a deleted absence is gone, also for the owner');
	}

	public function testListForViewer_UnrelatedChild_HidesPersonalMetadataOfOthers(): void {
		$list = $this->wireListFor('sis', 'child', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED),
		], viewerWalletId: 13, extraMembers: [$this->member('sis', 'child', 13)]);

		self::assertCount(1, $list, 'the recognized coverage fact stays visible to the sibling');
		self::assertArrayNotHasKey('reasonCode', $list[0], 'an unrelated child receives no personal reason');
		self::assertArrayNotHasKey('isRetrospectiveIllness', $list[0]);
	}

	public function testListForViewer_Owner_ReceivesPersonalMetadata(): void {
		$list = $this->wireListFor('dad', 'owner', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED, retrospective: true),
		], viewerWalletId: 12);

		self::assertCount(1, $list);
		self::assertSame(Pause::REASON_ILLNESS, $list[0]['reasonCode']);
		self::assertTrue($list[0]['isRetrospectiveIllness']);
	}

	public function testListForViewer_Self_ReceivesOwnPersonalMetadata(): void {
		$list = $this->wireListFor('kid', 'child', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED),
		], viewerWalletId: 11);

		self::assertCount(1, $list);
		self::assertSame(Pause::REASON_ILLNESS, $list[0]['reasonCode'], 'the subject sees its own reason');
	}

	public function testListForViewer_HouseScope_StaysFullyVisible(): void {
		$list = $this->wireListFor('mom', 'member', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED, scope: Pause::SCOPE_HOUSE, subjectWalletId: null),
		]);

		self::assertCount(1, $list);
		self::assertSame(Pause::REASON_ILLNESS, $list[0]['reasonCode'], 'a household pause is household data');
		self::assertNull($list[0]['subjectUserId']);
	}

	public function testListForViewer_PendingPersonalRequest_HiddenFromUnrelatedMember(): void {
		$list = $this->wireListFor('mom', 'member', [
			$this->listedPause(Pause::STATE_REQUESTED),
		]);

		self::assertSame([], $list, 'a pending request of another member is invisible');
	}

	public function testListForViewer_DepartedSubject_ResolvesToNoUserAndNoPersonalMetadata(): void {
		// Wallet 99 departed: only 'mom' and 'kid' are current members, so
		// the retained wallet resolves to nobody and no personal metadata
		// can be reattached (Q-073-03).
		$list = $this->wireListFor('mom', 'member', [
			$this->listedPause(Pause::STATE_ACKNOWLEDGED, subjectWalletId: 99),
		]);

		self::assertCount(1, $list);
		self::assertNull($list[0]['subjectUserId']);
		self::assertArrayNotHasKey('reasonCode', $list[0]);
	}

	/** One revision of a personal absence of wallet 11 ('kid'), decided long before today. */
	private function revisionOf(int $revision, string $state, string $start, string $end, int $group = 1): Pause {
		$pause = $this->listedPause($state);
		$pause->setPauseGroupId($group);
		$pause->setRevision($revision);
		$pause->setStartDate(new \DateTime($start));
		$pause->setEndDate(new \DateTime($end));
		$pause->setCreatedAt(new \DateTime('2019-12-30 08:00:00'));
		$pause->setUpdatedAt(new \DateTime('2019-12-30 08:00:00'));
		return $pause;
	}

	public function testListForViewer_DeclinedShortening_ShowsTheAbsenceItLeftInForce(): void {
		$list = $this->wireListFor('mom', 'member', [
			$this->revisionOf(1, Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2999-12-31'),
			$this->revisionOf(2, Pause::STATE_REJECTED, '2020-01-01', '2020-01-03'),
		]);

		self::assertCount(1, $list, 'the absence still counts, so everyone sees it');
		self::assertSame(Pause::STATE_ACKNOWLEDGED, $list[0]['state']);
		self::assertSame('2999-12-31', $list[0]['endDate'], 'the range of the absence that stands, not of the declined request');
		self::assertSame(2, $list[0]['revision'], 'the next command expects the latest revision');
		self::assertFalse($list[0]['deletable'], 'neither subject nor owner');
	}

	/** @dataProvider deletableProvider */
	public function testListForViewer_Deletable_FollowsTheDeleteRuleForThatViewer(string $viewer, string $role, array $expected): void {
		$extra = $viewer === 'mom' ? null : [$this->member('mom', 'member', 13)];
		$list = $this->wireListFor($viewer, $role, [
			$this->revisionOf(1, Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2999-12-31', 1),
			$this->revisionOf(1, Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2020-01-05', 2),
			$this->revisionOf(1, Pause::STATE_REQUESTED, '2020-01-01', '2020-01-05', 3),
			$this->revisionOf(1, Pause::STATE_ACKNOWLEDGED, '2020-01-01', '2020-01-05', 4),
			$this->revisionOf(2, Pause::STATE_REQUESTED, '2020-01-01', '2020-01-03', 4),
		], viewerWalletId: $viewer === 'kid' ? 11 : 12, extraMembers: $extra);

		self::assertSame($expected, array_column($list, 'deletable', 'pauseGroupId'));
	}

	public static function deletableProvider(): array {
		// 1 running · 2 ended · 3 a pending first request with past dates ·
		// 4 a pending shortening behind an ended absence (D-090-25).
		return [
			'owner' => ['dad', 'owner', [1 => true, 2 => false, 3 => true, 4 => false]],
			'subject' => ['kid', 'child', [1 => true, 2 => false, 3 => true, 4 => false]],
			'anyone else' => ['mom', 'member', [1 => false, 2 => false]],
		];
	}
}
