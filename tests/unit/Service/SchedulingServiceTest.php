<?php

declare(strict_types=1);

namespace OCA\ChoreStrike\Tests\Unit\Service;

use OCA\ChoreStrike\Db\House;
use OCA\ChoreStrike\Db\HouseMapper;
use OCA\ChoreStrike\Db\HouseUser;
use OCA\ChoreStrike\Db\HouseUserMapper;
use OCA\ChoreStrike\Service\CalendarService;
use OCA\ChoreStrike\Service\SchedulingService;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SchedulingService
 */
class SchedulingServiceTest extends TestCase {
	private SchedulingService $service;
	private HouseUserMapper $houseUserMapper;
	private HouseMapper $houseMapper;
	private \OCA\ChoreStrike\Service\AuthorizationService $authorization;
	private IDBConnection $db;
	private \OCA\ChoreStrike\Service\AppLogger $appLogger;

	protected function setUp(): void {
		parent::setUp();

		$this->houseUserMapper = $this->createMock(HouseUserMapper::class);
		$this->houseMapper = $this->createMock(HouseMapper::class);
		// RF-058-05: owner-only paths gate service-side; default mock lets
		// the gate pass, denial tests override it.
		$this->authorization = $this->createMock(\OCA\ChoreStrike\Service\AuthorizationService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->appLogger = $this->createMock(\OCA\ChoreStrike\Service\AppLogger::class);
		// CS-PLAN-057: today's-energy window resolves the house timezone.
		$utcHouse = new House();
		$utcHouse->setTimezone('UTC');
		$this->houseMapper->method('find')->willReturn($utcHouse);
		// Identity mock: message assertions match the wrapped source text.
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);
		// CS-PLAN-071 WP0: preference writes enter through the house row
		// lock (findForUpdate) — a stub is enough for these unit tests.
		$this->houseMapper->method('findForUpdate')->willReturn($utcHouse);
		// CS-PLAN-065 (D7): the reader-less consumed-energy cluster is gone —
		// the service no longer depends on CompletedTaskMapper at all.
		$this->service = new SchedulingService(
			$this->houseUserMapper,
			$l10n,
			$this->houseMapper,
			new CalendarService(),
			$this->authorization,
			new \OCA\ChoreStrike\Db\TransactionExecutor($this->db),
			$this->appLogger
		);
	}

	/**
	 * CS-PLAN-071 WP0: the under-lock membership re-read returns the same
	 * prepared entity so the locked write targets it.
	 */
	public function testUpdateMemberAvailability_DemotedUnderLock_DeniesWithoutWrite(): void {
		$user = new HouseUser();
		$user->setId(3);
		$this->houseUserMapper->method('findByNextcloudUserId')->willReturn($user);
		$checks = 0;
		$this->authorization->expects($this->exactly(2))->method('requireHouseOwner')->willReturnCallback(
			static function () use (&$checks): HouseUser {
				if (++$checks === 2) {
					throw new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required');
				}
				return new HouseUser();
			}
		);
		$this->houseUserMapper->expects($this->never())->method('update');
		$this->appLogger->expects($this->never())->method('audit');
		$this->db->expects($this->never())->method('commit');
		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->updateMemberAvailabilityByNextcloudUserId(1, 'member', []);
	}

	/** @dataProvider availabilityAuditProvider */
	public function testUpdateMemberAvailability_AuditIsNumericTruthfulAndPostCommit(array $updates, string $outcome): void {
		$user = new HouseUser();
		$user->setId(3);
		$user->setSchedulingPreferences(['energyBudget' => ['monday' => 10], 'maxDailyTasks' => 4]);
		$this->houseUserMapper->method('findByNextcloudUserId')->willReturn($user);
		$this->stubLockedMember($user);
		$actor = new HouseUser();
		$actor->setId(7);
		$this->authorization->method('requireHouseOwner')->willReturn($actor);
		$committed = false;
		$this->db->method('commit')->willReturnCallback(static function () use (&$committed): void {
			$committed = true;
		});
		$this->appLogger->expects($this->once())->method('audit')->with($this->anything(),
			$this->callback(static function (array $context) use (&$committed, $outcome): bool {
				return $committed && $context['actorId'] === 7 && $context['memberId'] === 3
					&& $context['outcome'] === $outcome
					&& !isset($context['preferences'], $context['nextcloudUserId']);
			})
		);
		$this->service->updateMemberAvailabilityByNextcloudUserId(1, 'member', $updates);
	}

	public static function availabilityAuditProvider(): array {
		return [
			'empty' => [[], 'no-change'],
			'identical' => [['energyBudget' => ['Monday' => 10], 'maxDailyTasks' => 4], 'no-change'],
			'changed' => [['energyBudget' => ['monday' => 11]], 'changed'],
			'cleared limit' => [['maxDailyTasks' => null], 'changed'],
		];
	}

	private function stubLockedMember(HouseUser $houseUser): void {
		$this->houseUserMapper->method('findForUpdate')->willReturn($houseUser);
	}

	/**
	 * GetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences
	 */
	public function testGetUserPreferencesByNextcloudUserId_ValidUser_ReturnsResolvedPreferences(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(7);
		$houseUser->setHouseId(2);
		$houseUser->setNextcloudUserId('member-a');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 12],
			'weekdayAvailability' => ['monday' => false],
		]);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(2, 'member-a')
			->willReturn($houseUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('find')
			->with(7)
			->willReturn($houseUser);

		$preferences = $this->service->getUserPreferencesByNextcloudUserId(2, 'member-a');

		$this->assertSame(7, $preferences['userId']);
		$this->assertSame(12, $preferences['energyBudget']['monday']);
		$this->assertFalse($preferences['weekdayAvailability']['monday']);
		// Decision D5: a missing limit serializes as null (unlimited).
		$this->assertNull($preferences['maxDailyTasks']);
		// Decision D7: the reader-less consumed-energy field is gone.
		$this->assertArrayNotHasKey('todayConsumedEnergy', $preferences);
	}

	/**
	 * GetUserPreferences_FiniteStoredLimit_ReturnsTheLimit (decision D5).
	 */
	public function testGetUserPreferences_FiniteStoredLimit_ReturnsTheLimit(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(9);
		$houseUser->setHouseId(2);
		$houseUser->setSchedulingPreferences(['maxDailyTasks' => 5]);

		$this->houseUserMapper->method('find')->willReturn($houseUser);

		$preferences = $this->service->getUserPreferences(9);

		$this->assertSame(5, $preferences['maxDailyTasks']);
	}

	/**
	 * UpdatePreferencesByNextcloudUserId_ProvidedSections_ReplacedWholesale
	 *
	 * Section-granular merge: provided sections replace the stored ones,
	 * absent sections and sibling keys (maxDailyTasks) are preserved.
	 */
	public function testUpdatePreferencesByNextcloudUserId_ProvidedSections_ReplacedAndSiblingsPreserved(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(13);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-d');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10],
			'weekdayAvailability' => ['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
			'maxDailyTasks' => 5,
		]);

		// Scoped lookup by houseId + nextcloud uid enforces membership.
		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(3, 'member-d')
			->willReturn($houseUser);

		$this->stubLockedMember($houseUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($houseUser);

		// The response is built from the in-memory entity — no second find.
		$this->houseUserMapper
			->expects($this->never())
			->method('find');

		$energyBudget = ['monday' => 20, 'tuesday' => 20, 'wednesday' => 20, 'thursday' => 20, 'friday' => 20, 'saturday' => 20, 'sunday' => 20];
		$result = $this->service->updatePreferencesByNextcloudUserId(3, 'member-d', null, $energyBudget);

		$preferences = $houseUser->getSchedulingPreferences();
		$this->assertSame($energyBudget, $preferences['energyBudget']);
		// Absent section unchanged.
		$this->assertTrue($preferences['weekdayAvailability']['monday']);
		// Sibling key preserved.
		$this->assertSame(5, $preferences['maxDailyTasks']);
		// The returned array is the full persisted preferences shape.
		$this->assertSame(13, $result['userId']);
		$this->assertSame($energyBudget, $result['energyBudget']);
	}

	/**
	 * UpdatePreferencesByNextcloudUserId_AvailabilityOnly_ReplacesWeekdaySection
	 */
	public function testUpdatePreferencesByNextcloudUserId_AvailabilityOnly_ReplacesWeekdaySection(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(14);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-e');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 7],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(3, 'member-e')
			->willReturn($houseUser);

		$this->stubLockedMember($houseUser);

		// The response is built from the in-memory entity — no second find.
		$this->houseUserMapper
			->expects($this->never())
			->method('find');

		$weekdayAvailability = ['monday' => false, 'tuesday' => false, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true];
		$this->service->updatePreferencesByNextcloudUserId(3, 'member-e', $weekdayAvailability, null);

		$preferences = $houseUser->getSchedulingPreferences();
		$this->assertSame($weekdayAvailability, $preferences['weekdayAvailability']);
		$this->assertSame(7, $preferences['energyBudget']['monday']);
	}

	/**
	 * Out-of-range energy in a bulk save is rejected and nothing is persisted.
	 */
	public function testUpdatePreferencesByNextcloudUserId_RejectsOutOfRangeEnergy(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(15);
		$houseUser->setHouseId(3);
		$houseUser->setNextcloudUserId('member-f');
		$houseUser->setSchedulingPreferences([]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(3, 'member-f')
			->willReturn($houseUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Energy budget must be between 1 and 20');

		$this->service->updatePreferencesByNextcloudUserId(
			3,
			'member-f',
			['monday' => true, 'tuesday' => true, 'wednesday' => true, 'thursday' => true, 'friday' => true, 'saturday' => true, 'sunday' => true],
			['monday' => 25, 'tuesday' => 10, 'wednesday' => 10, 'thursday' => 10, 'friday' => 10, 'saturday' => 10, 'sunday' => 10]
		);
	}

	/**
	 * GetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId
	 */
	public function testGetMemberAvailability_ReturnsNextcloudUserIdAndHouseUserId(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(42);
		$houseUser->setHouseId(5);
		$houseUser->setNextcloudUserId('member-z');
		$houseUser->setDisplayName('Member Z');
		$houseUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 14],
			'weekdayAvailability' => ['monday' => true],
		]);

		$this->houseUserMapper
			->expects($this->once())
			->method('findByHouseId')
			->with(5)
			->willReturn([$houseUser]);

		$availability = $this->service->getMemberAvailability(5);

		$this->assertCount(1, $availability);
		$this->assertSame('member-z', $availability[0]['userId']);
		$this->assertSame(42, $availability[0]['houseUserId']);
		$this->assertSame('Member Z', $availability[0]['userName']);
		// Decision D5: the owner payload carries the member limit.
		$this->assertNull($availability[0]['maxDailyTasks']);
	}

	/**
	 * GetMemberAvailability_FiniteLimit_ReturnsTheLimit (decision D5).
	 */
	public function testGetMemberAvailability_FiniteLimit_ReturnsTheLimit(): void {
		$houseUser = new HouseUser();
		$houseUser->setId(43);
		$houseUser->setHouseId(5);
		$houseUser->setNextcloudUserId('child-c');
		$houseUser->setDisplayName('Child C');
		$houseUser->setSchedulingPreferences(['maxDailyTasks' => 3]);

		$this->houseUserMapper->method('findByHouseId')->willReturn([$houseUser]);

		$availability = $this->service->getMemberAvailability(5);

		$this->assertSame(3, $availability[0]['maxDailyTasks']);
	}

	/**
	 * GetMemberAvailability_NonOwner_ThrowsForbiddenWithoutRead (RF-058-05)
	 */
	public function testGetMemberAvailability_NonOwner_ThrowsForbiddenWithoutRead(): void {
		$this->authorization->method('requireHouseOwner')
			->with(5)
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required'));

		$this->houseUserMapper->expects($this->never())->method('findByHouseId');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->getMemberAvailability(5);
	}

	/**
	 * UpdateMemberAvailability_NonOwner_ThrowsForbiddenWithoutWrite (RF-058-05)
	 */
	public function testUpdateMemberAvailability_NonOwner_ThrowsForbiddenWithoutWrite(): void {
		$this->authorization->method('requireHouseOwner')
			->with(5)
			->willThrowException(new \OCA\ChoreStrike\Exception\ForbiddenException('Owner role required'));

		$this->houseUserMapper->expects($this->never())->method('findByNextcloudUserId');
		$this->houseUserMapper->expects($this->never())->method('update');

		$this->expectException(\OCA\ChoreStrike\Exception\ForbiddenException::class);
		$this->service->updateMemberAvailabilityByNextcloudUserId(5, 'kid', ['maxDailyTasks' => 3]);
	}

	/**
	 * UpdateMemberAvailabilityByNextcloudUserId_DelegatesToScopedLookupAndAppliesUpdates
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_AppliesUpdatesViaScopedLookup(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		// Scoped lookup by houseId + nextcloud uid enforces membership.
		$this->houseUserMapper
			->expects($this->once())
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		$this->stubLockedMember($targetUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($targetUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['weekdayAvailability' => ['monday' => false], 'energyBudget' => ['monday' => 15]]
		);

		$preferences = $targetUser->getSchedulingPreferences();
		$this->assertFalse($preferences['weekdayAvailability']['monday']);
		$this->assertSame(15, $preferences['energyBudget']['monday']);
	}

	/**
	 * UpdateMemberAvailability_MergesOnLockedEntity_NotTheStalePreRead
	 *
	 * CS-PLAN-071 review fix (TOCTOU): a member self-update committed
	 * behind the owner's pre-read is never clobbered — the update set is
	 * validated and merged on the FRESH locked entity, so the sibling key
	 * the stale pre-read never saw survives.
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_MergesOnLockedEntity_NotStalePreRead(): void {
		$staleUser = new HouseUser();
		$staleUser->setId(22);
		$staleUser->setHouseId(1);
		$staleUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
		]);

		$lockedUser = new HouseUser();
		$lockedUser->setId(22);
		$lockedUser->setHouseId(1);
		// The member's concurrent self-update added maxDailyTasks after the
		// owner's pre-read.
		$lockedUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'maxDailyTasks' => 6,
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($staleUser);
		$this->houseUserMapper
			->method('findForUpdate')
			->willReturn($lockedUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($lockedUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['energyBudget' => ['monday' => 15]]
		);

		$preferences = $lockedUser->getSchedulingPreferences();
		$this->assertSame(15, $preferences['energyBudget']['monday']);
		$this->assertSame(6, $preferences['maxDailyTasks'], 'the locked base is merged, not the stale pre-read');
	}

	/**
	 * Out-of-range energy values in a member update are rejected (not silently
	 * dropped) and nothing is persisted.
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_RejectsOutOfRangeEnergy(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		// CS-PLAN-071 review fix: validation runs on the FRESH locked
		// entity inside the house-first transaction.
		$this->stubLockedMember($targetUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Energy budget must be between 1 and 20');

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['energyBudget' => ['monday' => 25]]
		);
	}

	/**
	 * Non-integer energy values in a member update are rejected.
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_RejectsNonIntegerEnergy(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(22);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([
			'energyBudget' => ['monday' => 10],
			'weekdayAvailability' => ['monday' => true],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'alice')
			->willReturn($targetUser);

		// CS-PLAN-071 review fix: validation runs on the FRESH locked
		// entity inside the house-first transaction.
		$this->stubLockedMember($targetUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->updateMemberAvailabilityByNextcloudUserId(
			1,
			'alice',
			['energyBudget' => ['monday' => '15']]
		);
	}

	/**
	 * AssertValidEnergyBudget_BoundaryValues_Accepted
	 *
	 * CR-016-15 consistency pin: the shared helper accepts exactly the
	 * inclusive 1-20 range every call site delegates to.
	 */
	public function testAssertValidEnergyBudget_BoundaryValues_Accepted(): void {
		$this->service->assertValidEnergyBudget(1);
		$this->service->assertValidEnergyBudget(20);
		$this->service->assertValidEnergyBudget(10);

		// No exception means the boundaries hold.
		$this->assertTrue(true);
	}

	/**
	 * AssertValidEnergyBudget_OutOfRangeValues_RejectedWithStableMessage
	 *
	 * @dataProvider outOfRangeEnergyProvider
	 */
	public function testAssertValidEnergyBudget_OutOfRangeValues_RejectedWithStableMessage($value): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Energy budget must be between 1 and 20');

		$this->service->assertValidEnergyBudget($value);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function outOfRangeEnergyProvider(): array {
		return [
			'zero' => [0],
			'negative' => [-3],
			'above max' => [21],
			'numeric string' => ['15'],
			'float' => [10.5],
			'boolean' => [true],
			'null' => [null],
		];
	}

	/**
	 * UpdateMemberAvailabilityByNextcloudUserId_FiniteLimit_PersistsTheBound
	 * (decision D5).
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_FiniteLimit_PersistsTheBound(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(31);
		$targetUser->setHouseId(1);
		$targetUser->setSchedulingPreferences([]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with(1, 'kid')
			->willReturn($targetUser);

		$this->stubLockedMember($targetUser);

		$this->houseUserMapper
			->expects($this->once())
			->method('update')
			->with($targetUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId(1, 'kid', ['maxDailyTasks' => 4]);

		$preferences = $targetUser->getSchedulingPreferences();
		$this->assertSame(4, $preferences['maxDailyTasks']);
	}

	/**
	 * UpdateMemberAvailabilityByNextcloudUserId_NullLimit_ClearsTheBound
	 * (decision D5: explicit null means unlimited).
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_NullLimit_ClearsTheBound(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(31);
		$houseId = 1;
		$targetUser->setHouseId($houseId);
		$targetUser->setSchedulingPreferences(['maxDailyTasks' => 4]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with($houseId, 'kid')
			->willReturn($targetUser);

		$this->stubLockedMember($targetUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId($houseId, 'kid', ['maxDailyTasks' => null]);

		$preferences = $targetUser->getSchedulingPreferences();
		$this->assertArrayNotHasKey('maxDailyTasks', $preferences);
	}

	/**
	 * UpdateMemberAvailabilityByNextcloudUserId_OmittedLimit_LeavesBoundUnchanged
	 * (decision D5).
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_OmittedLimit_LeavesBoundUnchanged(): void {
		$targetUser = new HouseUser();
		$targetUser->setId(31);
		$houseId = 1;
		$targetUser->setHouseId($houseId);
		$targetUser->setSchedulingPreferences([
			'maxDailyTasks' => 4,
			'energyBudget' => ['monday' => 10],
		]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with($houseId, 'kid')
			->willReturn($targetUser);

		$this->stubLockedMember($targetUser);

		$this->service->updateMemberAvailabilityByNextcloudUserId($houseId, 'kid', ['energyBudget' => ['monday' => 12]]);

		$preferences = $targetUser->getSchedulingPreferences();
		$this->assertSame(4, $preferences['maxDailyTasks']);
		$this->assertSame(12, $preferences['energyBudget']['monday']);
	}

	/**
	 * UpdateMemberAvailabilityByNextcloudUserId_RejectsOutOfBoundsLimit
	 * (decision D5: 0 is a validation error — unavailable weekdays already
	 * express a day off).
	 *
	 * @dataProvider outOfBoundsLimitProvider
	 */
	public function testUpdateMemberAvailabilityByNextcloudUserId_RejectsOutOfBoundsLimit($limit): void {
		$targetUser = new HouseUser();
		$targetUser->setId(31);
		$houseId = 1;
		$targetUser->setHouseId($houseId);
		$targetUser->setSchedulingPreferences([]);

		$this->houseUserMapper
			->method('findByNextcloudUserId')
			->with($houseId, 'kid')
			->willReturn($targetUser);

		// CS-PLAN-071 review fix: validation runs on the FRESH locked
		// entity inside the house-first transaction.
		$this->stubLockedMember($targetUser);

		$this->houseUserMapper
			->expects($this->never())
			->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Max daily tasks must be an integer between 1 and 100');

		$this->service->updateMemberAvailabilityByNextcloudUserId($houseId, 'kid', ['maxDailyTasks' => $limit]);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function outOfBoundsLimitProvider(): array {
		return [
			'zero' => [0],
			'above max' => [101],
			'numeric string' => ['5'],
			'float' => [4.5],
			'boolean' => [true],
		];
	}

	/**
	 * AssertValidMaxDailyTasks_BoundaryValues_Accepted (decision D5).
	 */
	public function testAssertValidMaxDailyTasks_BoundaryValues_Accepted(): void {
		$this->service->assertValidMaxDailyTasks(1);
		$this->service->assertValidMaxDailyTasks(100);
		$this->service->assertValidMaxDailyTasks(50);

		$this->assertTrue(true);
	}

	/**
	 * AssertValidMaxDailyTasks_OutOfBounds_RejectedWithStableMessage.
	 */
	public function testAssertValidMaxDailyTasks_OutOfBounds_RejectedWithStableMessage(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Max daily tasks must be an integer between 1 and 100');

		$this->service->assertValidMaxDailyTasks(0);
	}
}
