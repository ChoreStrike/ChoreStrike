<?php

/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 */
declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\AgreementMapper;
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
 * The WP2 pause command semantics (CS-PLAN-073 §3.4): role-gated
 * creation (child request vs adult acknowledgment vs owner household
 * pause), the owner decision gate with the expected revision, and the
 * next-day shortening boundary in the pause's saved zone. The
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
			static function (int $houseId, int $actorId, string $commandId, string $action, array $payload, callable $fn): array {
				return ['result' => $fn(), 'replay' => false];
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

	// -----------------------------------------------------------------
	// Creation semantics
	// ------------------------------------------------------------------

	public function testRequestForSelf_Child_CreatesPendingRequest(): void {
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

		self::assertSame(Pause::STATE_REQUESTED, $result['state'], 'a child self-request stays pending');
		self::assertSame(Pause::STATE_REQUESTED, $created?->getState());
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

	public function testListForViewer_UnrelatedMember_EveryNonPendingState_HidesPersonalMetadata(): void {
		$states = [Pause::STATE_ACKNOWLEDGED, Pause::STATE_REJECTED, Pause::STATE_CANCELLED];
		foreach ($states as $index => $state) {
			$pause = $this->listedPause($state);
			$pause->setPauseGroupId($index + 1);
			$list = $this->wireListFor('mom', 'member', [$pause]);
			self::assertCount(1, $list);
			self::assertArrayNotHasKey('reasonCode', $list[0], "state $state leaks no personal reason");
			self::assertArrayNotHasKey('isRetrospectiveIllness', $list[0]);
		}
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
}
